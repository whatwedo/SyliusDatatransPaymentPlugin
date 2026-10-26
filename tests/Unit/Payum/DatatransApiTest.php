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

namespace Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\Payum;

use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\DatatransTestTrait;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransApi;

final class DatatransApiTest extends TestCase
{
    use DatatransTestTrait;

    public function testGetPostParams(): void
    {
        $api = self::createApi(new MockHttpClient());
        $payment = self::createPayment();
        $payment->setCurrencyCode('EUR');

        self::assertSame([
            'autoSettle' => true,
            'language' => 'fr',
            'currency' => 'EUR',
            'refno' => '000123',
            'amount' => 1050,
            'redirect' => [
                'successUrl' => 'https://shop.test/after',
                'cancelUrl' => 'https://shop.test/after',
                'errorUrl' => 'https://shop.test/after',
            ],
            'paymentMethods' => ['VIS', 'TWI'],
        ], $api->getPostParams($payment, 'https://shop.test/after'));
    }

    /**
     * @dataProvider languageProvider
     */
    #[DataProvider('languageProvider')]
    public function testLanguageIsDerivedFromOrderLocale(?string $locale, string $expected): void
    {
        $api = self::createApi(new MockHttpClient());
        $payment = self::createPayment();
        $order = new Order();
        $order->setNumber('000123');
        if ($locale !== null) {
            $order->setLocaleCode($locale);
        }
        $payment->setOrder($order);

        self::assertSame($expected, $api->getPostParams($payment, 'https://shop.test')['language']);
    }

    public static function languageProvider(): iterable
    {
        yield 'german' => ['de_CH', 'de'];
        yield 'english' => ['en_US', 'en'];
        yield 'italian without region' => ['it', 'it'];
        yield 'uppercase' => ['FR_FR', 'fr'];
        yield 'unsupported falls back' => ['rm_CH', 'de'];
        yield 'missing falls back' => [null, 'de'];
    }

    public function testGetPayUrl(): void
    {
        $sandbox = self::createApi(new MockHttpClient(), ['endpoint' => 'https://api.sandbox.datatrans.com/']);
        $production = self::createApi(new MockHttpClient(), ['endpoint' => 'https://api.datatrans.com']);

        self::assertSame('https://pay.sandbox.datatrans.com/v1/start/tx-1', $sandbox->getPayUrl('tx-1'));
        self::assertSame('https://pay.datatrans.com/v1/start/tx-1', $production->getPayUrl('tx-1'));
        self::assertSame('https://api.sandbox.datatrans.com', $sandbox->getEndpoint());
    }

    public function testGetCredentials(): void
    {
        $api = self::createApi(new MockHttpClient());

        self::assertSame(base64_encode('1100000000:secret'), $api->getCredentials());
    }

    public function testFromConfig(): void
    {
        $api = DatatransApi::fromConfig(new ArrayObject(self::gatewayConfig(['generate_link' => true, 'hmac_sha256' => true])));

        self::assertSame('1100000000', $api->getMerchantId());
        self::assertSame('secret', $api->getPassword());
        self::assertSame('', $api->getSign());
        self::assertTrue($api->isGenerateLink());
        self::assertTrue($api->isHmacSHA256());
        self::assertSame(['VIS', 'TWI'], $api->getPaymentMethods());
    }

    public function testFromConfigWithoutPasswordThrows(): void
    {
        $config = self::gatewayConfig();
        unset($config['password']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"password" config option is required');

        DatatransApi::fromConfig($config);
    }

    public function testInitTransaction(): void
    {
        $response = self::jsonResponse(['transactionId' => 'tx-new'], 201);
        $httpClient = new MockHttpClient([$response]);
        $api = self::createApi($httpClient);
        $payment = self::createPayment([
            'payment-link' => 'https://shop.test/link',
            'transactionId' => 'tx-old',
            'error' => ['message' => 'old'],
            'datatrans' => ['status' => 'canceled'],
        ]);

        self::assertSame('tx-new', $api->initTransaction($payment, 'https://shop.test/after'));
        self::assertSame([
            'payment-link' => 'https://shop.test/link',
            'previousTransactionIds' => ['tx-old'],
            'transactionId' => 'tx-new',
        ], $payment->getDetails());

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://api.sandbox.datatrans.com/v1/transactions', $response->getRequestUrl());
        $options = $response->getRequestOptions();
        self::assertContains('Authorization: Basic ' . base64_encode('1100000000:secret'), $options['headers']);
        self::assertSame($api->getPostParams($payment, 'https://shop.test/after'), json_decode($options['body'], true));
    }

    public function testInitTransactionStoresErrorResponse(): void
    {
        $error = ['code' => 'INVALID_PROPERTY', 'message' => 'init.currency'];
        $api = self::createApi(new MockHttpClient([self::jsonResponse(['error' => $error], 400)]));
        $payment = self::createPayment(['payment-link' => 'https://shop.test/link', 'transactionId' => 'tx-old']);

        self::assertNull($api->initTransaction($payment, 'https://shop.test/after'));
        self::assertSame([
            'payment-link' => 'https://shop.test/link',
            'previousTransactionIds' => ['tx-old'],
            'error' => $error,
        ], $payment->getDetails());
    }

    public function testInitTransactionKeepsAllPreviousTransactionIds(): void
    {
        $api = self::createApi(new MockHttpClient([
            self::jsonResponse(['transactionId' => 'tx-3'], 201),
            self::jsonResponse(['transactionId' => 'tx-4'], 201),
        ]));
        $payment = self::createPayment(['transactionId' => 'tx-2', 'previousTransactionIds' => ['tx-1', 'tx-2']]);

        $api->initTransaction($payment, 'https://shop.test/after');
        self::assertSame(['previousTransactionIds' => ['tx-1', 'tx-2'], 'transactionId' => 'tx-3'], $payment->getDetails());

        $api->initTransaction($payment, 'https://shop.test/after');
        self::assertSame(['previousTransactionIds' => ['tx-1', 'tx-2', 'tx-3'], 'transactionId' => 'tx-4'], $payment->getDetails());
    }

    public function testInitTransactionStoresTransportError(): void
    {
        $api = self::createApi(new MockHttpClient([new MockResponse('', ['error' => 'Connection refused'])]));
        $payment = self::createPayment();

        self::assertNull($api->initTransaction($payment, 'https://shop.test/after'));
        self::assertArrayNotHasKey('transactionId', $payment->getDetails());
        self::assertStringContainsString('Connection refused', $payment->getDetails()['error']['message']);
    }

    public function testHasPaidTransactionWithoutTransactionId(): void
    {
        $httpClient = new MockHttpClient([]);
        $api = self::createApi($httpClient);

        self::assertFalse($api->hasPaidTransaction([]));
        self::assertFalse($api->hasPaidTransaction(['transactionId' => '']));
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    /**
     * @dataProvider paidStatusProvider
     */
    #[DataProvider('paidStatusProvider')]
    public function testHasPaidTransaction(string $status, bool $paid): void
    {
        $response = self::statusResponse($status);
        $api = self::createApi(new MockHttpClient([$response]));

        self::assertSame($paid, $api->hasPaidTransaction(['transactionId' => 'tx-1']));
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://api.sandbox.datatrans.com/v1/transactions/tx-1', $response->getRequestUrl());
    }

    public static function paidStatusProvider(): iterable
    {
        yield ['settled', true];
        yield ['transmitted', true];
        yield ['authorized', true];
        yield ['initialized', false];
        yield ['canceled', false];
        yield ['failed', false];
        yield ['challenge_required', false];
    }

    /**
     * @dataProvider unknownStatusProvider
     */
    #[DataProvider('unknownStatusProvider')]
    public function testHasPaidTransactionFailsClosedOnUnknownStatus(MockResponse $response, string $exception): void
    {
        $api = self::createApi(new MockHttpClient([$response]));

        $this->expectException($exception);

        $api->hasPaidTransaction(['transactionId' => 'tx-1']);
    }

    public static function unknownStatusProvider(): iterable
    {
        yield 'unauthorized' => [self::jsonResponse(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'x']], 401), \RuntimeException::class];
        yield 'unknown transaction' => [self::jsonResponse(['error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => 'x']], 400), \RuntimeException::class];
        yield 'server error' => [self::jsonResponse(['error' => ['code' => 'SERVER_ERROR', 'message' => 'x']], 503), \RuntimeException::class];
        yield 'missing status' => [self::jsonResponse(['transactionId' => 'tx-1']), \RuntimeException::class];
        yield 'invalid json' => [new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502]), ExceptionInterface::class];
        yield 'transport error' => [new MockResponse('', ['error' => 'Connection refused']), ExceptionInterface::class];
    }

    public function testHasPaidTransactionChecksPreviousTransactionIds(): void
    {
        $httpClient = new MockHttpClient([
            self::statusResponse('initialized'),
            self::statusResponse('canceled'),
            self::statusResponse('transmitted'),
        ]);
        $api = self::createApi($httpClient);

        self::assertTrue($api->hasPaidTransaction(['transactionId' => 'tx-3', 'previousTransactionIds' => ['tx-1', 'tx-2']]));
        self::assertSame(3, $httpClient->getRequestsCount());
    }

    public function testHasPaidTransactionStopsAtCurrentPaidTransaction(): void
    {
        $httpClient = new MockHttpClient([self::statusResponse('settled')]);
        $api = self::createApi($httpClient);

        self::assertTrue($api->hasPaidTransaction(['transactionId' => 'tx-2', 'previousTransactionIds' => ['tx-1']]));
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testHasPaidTransactionFailsClosedOnUnknownPreviousStatus(): void
    {
        $api = self::createApi(new MockHttpClient([
            self::statusResponse('canceled'),
            self::jsonResponse(['error' => ['code' => 'SERVER_ERROR', 'message' => 'x']], 503),
        ]));

        $this->expectException(\RuntimeException::class);

        $api->hasPaidTransaction(['transactionId' => 'tx-2', 'previousTransactionIds' => ['tx-1']]);
    }

    public function testHasPaidTransactionWithOnlyPreviousTransactionIds(): void
    {
        $api = self::createApi(new MockHttpClient([self::statusResponse('authorized')]));

        self::assertTrue($api->hasPaidTransaction(['previousTransactionIds' => ['tx-1'], 'error' => ['code' => 'x']]));
    }
}
