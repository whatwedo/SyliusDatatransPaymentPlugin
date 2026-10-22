<?php

declare(strict_types=1);
/*
 * Copyright (c) 2020, whatwedo GmbH
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

namespace Whatwedo\SyliusDatatransPaymentPlugin\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Payum\Core\Reply\HttpRedirect;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransApi;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransPaymentGatewayFactory;

class PayFromLinkController extends AbstractController
{
    /**
     * @var ManagerRegistry
     */
    protected $doctrine;

    private ?HttpClientInterface $httpClient;

    private ?PaymentRepositoryInterface $paymentRepository;

    public function __construct(ManagerRegistry $registry, ?HttpClientInterface $httpClient = null, ?PaymentRepositoryInterface $paymentRepository = null)
    {
        $this->doctrine = $registry;
        $this->httpClient = $httpClient;
        $this->paymentRepository = $paymentRepository;
    }

    public function payFromLinkAction(int $payment, ?Request $request = null): void
    {
        $payment = ($this->paymentRepository ?? $this->doctrine->getRepository(Payment::class))->find($payment);
        if (!$payment instanceof PaymentInterface || $payment->getState() !== PaymentInterface::STATE_NEW) {
            throw new NotFoundHttpException();
        }
        $token = $request ? $request->query->get('token') : null;
        $tokenValue = $payment->getOrder()?->getTokenValue();
        if (!is_string($token) || !is_string($tokenValue) || !hash_equals($tokenValue, $token)) {
            throw new NotFoundHttpException();
        }
        $details = $payment->getDetails();
        if (!isset($details['payment-link'])) {
            throw new NotFoundHttpException();
        }
        $gatewayConfig = $payment->getMethod()?->getGatewayConfig();
        if ($gatewayConfig === null || $gatewayConfig->getFactoryName() !== DatatransPaymentGatewayFactory::FACTORY_NAME) {
            throw new NotFoundHttpException();
        }
        $api = DatatransApi::fromConfig($gatewayConfig->getConfig(), $this->httpClient);
        if (!$api->isGenerateLink()) {
            throw new NotFoundHttpException();
        }
        try {
            $paid = $api->hasPaidTransaction($details);
        } catch (\RuntimeException|ExceptionInterface $exception) {
            throw new HttpRedirect($details['payment-link']);
        }
        if ($paid) {
            throw new HttpRedirect($details['payment-link']);
        }
        $transactionId = $api->initTransaction($payment, $details['payment-link']);
        $this->doctrine->getManager()->flush();
        throw new HttpRedirect($transactionId === null ? $details['payment-link'] : $api->getPayUrl($transactionId));
    }
}
