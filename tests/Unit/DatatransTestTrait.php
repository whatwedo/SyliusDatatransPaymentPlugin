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

namespace Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit;

use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransApi;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransPaymentGatewayFactory;

trait DatatransTestTrait
{
    private static function gatewayConfig(array $overrides = []): array
    {
        return array_merge([
            'merchant_id' => '1100000000',
            'password' => 'secret',
            'endpoint' => 'https://api.sandbox.datatrans.com',
            'sign' => null,
            'hmac_sha256' => false,
            'generate_link' => false,
            'payment_methods' => ['VIS', 'TWI'],
        ], $overrides);
    }

    private static function createApi(MockHttpClient $httpClient, array $overrides = []): DatatransApi
    {
        return DatatransApi::fromConfig(self::gatewayConfig($overrides), $httpClient);
    }

    private static function createPayment(
        array $details = [],
        string $state = PaymentInterface::STATE_NEW,
        array $config = [],
        string $factoryName = DatatransPaymentGatewayFactory::FACTORY_NAME,
    ): Payment {
        $order = new Order();
        $order->setNumber('000123');
        $order->setLocaleCode('fr_CH');
        $order->setTokenValue('order-token');

        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName('datatrans');
        $gatewayConfig->setConfig(self::gatewayConfig($config));

        $method = new PaymentMethod();
        $method->setGatewayConfig($gatewayConfig);

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setMethod($method);
        $payment->setCurrencyCode('CHF');
        $payment->setAmount(1050);
        $payment->setState($state);
        $payment->setDetails($details);

        return $payment;
    }

    private static function jsonResponse(array $body, int $code = 200): MockResponse
    {
        return new MockResponse((string) json_encode($body), [
            'http_code' => $code,
            'response_headers' => ['Content-Type: application/json'],
        ]);
    }

    private static function statusResponse(string $status, string $currency = 'CHF', int $amount = 1050): MockResponse
    {
        return self::jsonResponse([
            'transactionId' => 'tx-1',
            'status' => $status,
            'currency' => $currency,
            'refno' => '000123',
            'detail' => [
                'settle' => ['amount' => $amount],
            ],
        ]);
    }
}
