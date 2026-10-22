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

namespace Whatwedo\SyliusDatatransPaymentPlugin\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;
use Sylius\Component\Core\Model\PaymentInterface as SyliusPaymentInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransApi;

class StatusAction implements ActionInterface
{
    /**
     * @var string $endpoint
     */
    private $endpoint;

    /**
     * @var string $credentials
     */
    private $credentials;

    private ?HttpClientInterface $httpClient;

    public function __construct(string $endpoint, string $credentials, ?HttpClientInterface $httpClient = null)
    {
        $this->endpoint = $endpoint;
        $this->credentials = $credentials;
        $this->httpClient = $httpClient;
    }

    /**
     * @param GetStatusInterface $request
     * @return void
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var SyliusPaymentInterface $payment */
        $payment = $request->getFirstModel();
        $details = $payment->getDetails();
        $transactionId = $details['transactionId'] ?? null;

        $response = $transactionId === null ? null : $this->fetchTransaction((string) $transactionId);
        if (!$this->isPaid($response['status'] ?? null)) {
            $previousUnknown = false;
            $previous = $this->findPaidPreviousTransaction($payment, $details, (string) $transactionId, $previousUnknown);
            if ($previous === null && $previousUnknown) {
                $request->markUnknown();
                return;
            }
            $response = $previous ?? $response;
        }

        if ($response === null) {
            if ($transactionId === null && isset($details['error']) && DatatransApi::getTransactionIds($details) === []) {
                $request->markFailed();
            } else {
                $request->markUnknown();
            }
            return;
        }

        $details['datatrans'] = $response;
        $payment->setDetails($details);
        $model = $request->getModel();
        if ($model instanceof \ArrayAccess) {
            $model['datatrans'] = $response;
        }

        $status = $response['status'] ?? null;
        $verified = $this->matchesPayment($payment, $response);

        match (true) {
            DatatransApi::isCapturedStatus($status) && $verified => $request->markCaptured(),
            DatatransApi::isAuthorizedStatus($status) && $verified => $request->markAuthorized(),
            $status === 'canceled' => $request->markCanceled(),
            $status === 'failed' => $request->markFailed(),
            $status === 'initialized' => $request->markNew(),
            default => $request->markUnknown(),
        };
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface
            && method_exists($request, 'getFirstModel')
            && $request->getFirstModel() instanceof SyliusPaymentInterface
        ;
    }

    private function findPaidPreviousTransaction(SyliusPaymentInterface $payment, array $details, string $transactionId, bool &$unknown): ?array
    {
        $paid = null;
        foreach (DatatransApi::getTransactionIds($details) as $previousTransactionId) {
            if ($previousTransactionId === $transactionId) {
                continue;
            }
            $response = $this->fetchTransaction($previousTransactionId);
            if (!is_string($response['status'] ?? null)) {
                $unknown = true;
                continue;
            }
            if (!$this->isPaid($response['status'])) {
                continue;
            }
            if ($this->matchesPayment($payment, $response)) {
                return $response;
            }
            $paid ??= $response;
        }

        return $paid;
    }

    private function fetchTransaction(string $transactionId): ?array
    {
        try {
            return $this->getHttpClient()->request('GET', '/v1/transactions/' . rawurlencode($transactionId), [
                'base_uri' => rtrim($this->endpoint, '/'),
                'headers' => [
                    'Authorization' => 'Basic ' . $this->credentials,
                ],
            ])->toArray(false);
        } catch (ExceptionInterface $exception) {
            return null;
        }
    }

    private function isPaid(mixed $status): bool
    {
        return is_string($status) && (DatatransApi::isCapturedStatus($status) || DatatransApi::isAuthorizedStatus($status));
    }

    private function matchesPayment(SyliusPaymentInterface $payment, array $response): bool
    {
        if (isset($response['currency']) && $response['currency'] !== $payment->getCurrencyCode()) {
            return false;
        }
        $amount = $response['detail']['settle']['amount'] ?? $response['detail']['authorize']['amount'] ?? null;

        return $amount === null || (int) $amount === $payment->getAmount();
    }

    private function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient ??= HttpClient::create();
    }
}
