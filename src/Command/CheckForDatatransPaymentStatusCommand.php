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
use SM\Factory\Factory;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransApi;

#[AsCommand('whatwedo:sylius-datatrans-payment:check-payment-status', 'Check the payment status for Datatrans')]
class CheckForDatatransPaymentStatusCommand extends Command
{
    private PaymentRepositoryInterface $paymentRepository;

    private Factory $smFactory;

    private EntityManagerInterface $entityManager;

    public function __construct(
        PaymentRepositoryInterface $paymentRepository,
        Factory $smFactory,
        EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
        $this->paymentRepository = $paymentRepository;
        $this->smFactory = $smFactory;
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $payments = $this->paymentRepository->createQueryBuilder('p')
            ->where('p.state = :status')
            ->andWhere('p.details LIKE :details')
            ->setParameters([
                'status' => PaymentInterface::STATE_NEW,
                'details' => '%"transactionId":"%"%',
            ])->getQuery()
            ->getResult()
        ;

        /** @var PaymentInterface $payment */
        foreach ($payments as $payment) {
            $output->writeln('processing payment: ' . $payment->getId());
            $config = $payment->getMethod()->getGatewayConfig()->getConfig();
            $api = new DatatransApi(
                $config['merchant_id'],
                $config['password'] ?? throw new \InvalidArgumentException('Since 2.0.0 The "password" config option is required. To get the password, login to the dashboard (https://admin.sandbox.datatrans.com/) and navigate to the security settings under UPP Administration > Security.'),
                $config['endpoint'],
                $config['sign'],
                $config['generate_link'],
                $config['payment_methods'],
                $config['hmac_sha256'] ?? false,
            );
            $client = HttpClient::createForBaseUri($api->getEndpoint());
            $requestData = $client->request('GET', '/v1/transactions/' . $payment->getDetails()['transactionId'], [
                'headers' => [
                    'Authorization' => 'Basic ' . $api->getCredentials(),
                ],
            ])->toArray(false);
            $payment->addDetail('datatrans', $requestData);
            $machine = $this->smFactory->get($payment, PaymentTransitions::GRAPH);
            $status = $requestData['status'] ?? '';
            if ($status === 'settled' && $machine->can(PaymentTransitions::TRANSITION_COMPLETE)) {
                $machine->apply(PaymentTransitions::TRANSITION_COMPLETE);
                $output->writeln('Payment ' . $payment->getId() . ' completed.');
                continue;
            }

            if ($status === 'canceled' && $machine->can(PaymentTransitions::TRANSITION_CANCEL)) {
                $machine->apply(PaymentTransitions::TRANSITION_CANCEL);
                $output->writeln('Payment ' . $payment->getId() . ' canceled.');
                continue;
            }
            if ($status === 'failed' && $machine->can(PaymentTransitions::TRANSITION_FAIL)) {
                $machine->apply(PaymentTransitions::TRANSITION_FAIL);
                $output->writeln('Payment ' . $payment->getId() . ' failed.');
                continue;
            }

            // if older than 2 weeks, cancel the payment
            if ($payment->getCreatedAt() < new \DateTime('-2 weeks') && $machine->can(PaymentTransitions::TRANSITION_CANCEL)) {
                $machine->apply(PaymentTransitions::TRANSITION_CANCEL);
                $output->writeln('Payment ' . $payment->getId() . ' canceled due to timeout.');
            } else {
                $output->writeln('Payment ' . $payment->getId() . ' status waiting ... ('.$status.')');
            }
        }

        $this->entityManager->flush();

        return self::SUCCESS;
    }
}
