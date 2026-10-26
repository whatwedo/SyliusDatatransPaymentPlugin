<?php
/*
 * Copyright (c) 2025, whatwedo GmbH
 * All rights reserved
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT
 * NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
 * PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
 * WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace Whatwedo\SyliusDatatransPaymentPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\Request\GetHumanStatus;
use SM\Factory\Factory;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\Action\StatusAction;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransApi;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransPaymentGatewayFactory;

#[AsCommand('whatwedo:sylius-datatrans-payment:check-payment-status', 'Check the payment status for Datatrans')]
class CheckForDatatransPaymentStatusCommand extends Command
{
    private PaymentRepositoryInterface $paymentRepository;

    private Factory|StateMachineInterface $smFactory;

    private EntityManagerInterface $entityManager;

    private ?HttpClientInterface $httpClient;

    public function __construct(
        PaymentRepositoryInterface $paymentRepository,
        Factory|StateMachineInterface $smFactory,
        EntityManagerInterface $entityManager,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct();
        $this->paymentRepository = $paymentRepository;
        $this->smFactory = $smFactory;
        $this->entityManager = $entityManager;
        $this->httpClient = $httpClient;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payments = $this->paymentRepository->createQueryBuilder('p')
            ->innerJoin('p.method', 'm')
            ->innerJoin('m.gatewayConfig', 'gc')
            ->where('p.state IN (:states)')
            ->andWhere('gc.factoryName = :factoryName')
            ->setParameter('states', [PaymentInterface::STATE_NEW, PaymentInterface::STATE_AUTHORIZED])
            ->setParameter('factoryName', DatatransPaymentGatewayFactory::FACTORY_NAME)
            ->getQuery()
            ->getResult()
        ;

        /** @var PaymentInterface $payment */
        foreach ($payments as $payment) {
            if (DatatransApi::getTransactionIds($payment->getDetails()) === []) {
                continue;
            }

            $output->writeln('processing payment: ' . $payment->getId());
            try {
                $this->checkPayment($payment, $output);
                $this->entityManager->flush();
            } catch (\Throwable $exception) {
                $output->writeln('<error>Payment ' . $payment->getId() . ' could not be checked: ' . $exception->getMessage() . '</error>');
                if (!$this->entityManager->isOpen()) {
                    $output->writeln('<error>The entity manager is closed, remaining payments are not checked.</error>');

                    return self::FAILURE;
                }
                $this->discardChanges($payment);
            }
        }

        return self::SUCCESS;
    }

    private function checkPayment(PaymentInterface $payment, OutputInterface $output): void
    {
        $api = DatatransApi::fromConfig($payment->getMethod()->getGatewayConfig()->getConfig(), $this->httpClient);

        $details = $payment->getDetails();
        unset($details['datatrans']);
        $payment->setDetails($details);

        $status = new GetHumanStatus($payment);
        (new StatusAction($api->getEndpoint(), $api->getCredentials(), $this->httpClient))->execute($status);

        $response = $payment->getDetails()['datatrans'] ?? null;
        $datatransStatus = $response['status'] ?? null;

        $transition = match (true) {
            $status->isCaptured() => PaymentTransitions::TRANSITION_COMPLETE,
            $status->isAuthorized() && $payment->getState() === PaymentInterface::STATE_NEW => PaymentTransitions::TRANSITION_AUTHORIZE,
            $status->isCanceled() => PaymentTransitions::TRANSITION_CANCEL,
            $status->isFailed() => PaymentTransitions::TRANSITION_FAIL,
            default => null,
        };
        if ($transition !== null && $this->applyTransition($payment, $transition)) {
            $output->writeln('Payment ' . $payment->getId() . ' ' . $transition . ' applied (' . $datatransStatus . ').');
            return;
        }

        if (
            $payment->getState() === PaymentInterface::STATE_NEW
            && DatatransApi::isPendingStatus($datatransStatus)
            && $this->isTimedOut($payment, $response)
            && $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL)
        ) {
            $output->writeln('Payment ' . $payment->getId() . ' canceled due to timeout.');
            return;
        }

        $output->writeln('Payment ' . $payment->getId() . ' status waiting ... (' . $datatransStatus . ')');
    }

    private function isTimedOut(PaymentInterface $payment, array $response): bool
    {
        $start = $payment->getCreatedAt();
        $expires = $response['detail']['init']['expires'] ?? null;
        if (is_string($expires)) {
            try {
                $expires = new \DateTimeImmutable($expires);
            } catch (\Exception) {
                $expires = null;
            }
            if ($expires !== null && ($start === null || $expires > $start)) {
                $start = $expires;
            }
        }

        return $start !== null && $start < new \DateTimeImmutable('-2 weeks');
    }

    private function discardChanges(PaymentInterface $payment): void
    {
        try {
            $this->entityManager->refresh($payment);
            if ($payment->getOrder() !== null) {
                $this->entityManager->refresh($payment->getOrder());
            }
        } catch (\Throwable) {
        }
    }

    private function applyTransition(PaymentInterface $payment, string $transition): bool
    {
        if ($this->smFactory instanceof StateMachineInterface) {
            if (!$this->smFactory->can($payment, PaymentTransitions::GRAPH, $transition)) {
                return false;
            }
            $this->smFactory->apply($payment, PaymentTransitions::GRAPH, $transition);

            return true;
        }

        $machine = $this->smFactory->get($payment, PaymentTransitions::GRAPH);
        if (!$machine->can($transition)) {
            return false;
        }
        $machine->apply($transition);

        return true;
    }
}
