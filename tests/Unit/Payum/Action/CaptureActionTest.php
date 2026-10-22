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

namespace Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\Payum\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\DatatransTestTrait;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\Action\CaptureAction;

final class CaptureActionTest extends TestCase
{
    use DatatransTestTrait;

    public function testCreatesTransactionAndRedirectsToPayPage(): void
    {
        $response = self::jsonResponse(['transactionId' => 'tx-new'], 201);
        $payment = self::createPayment();

        try {
            $this->capture($payment, new MockHttpClient([$response]));
            self::fail('Expected redirect to the Datatrans payment page.');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://pay.sandbox.datatrans.com/v1/start/tx-new', $redirect->getUrl());
        }

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://shop.test/after', json_decode($response->getRequestOptions()['body'], true)['redirect']['successUrl']);
        self::assertSame('tx-new', $payment->getDetails()['transactionId']);
    }

    public function testGenerateLinkModeDoesNotRedirect(): void
    {
        $httpClient = new MockHttpClient([self::jsonResponse(['transactionId' => 'tx-new'], 201)]);
        $payment = self::createPayment(['payment-link' => 'https://shop.test/link']);

        $this->capture($payment, $httpClient, ['generate_link' => true]);

        self::assertSame(['payment-link' => 'https://shop.test/link', 'transactionId' => 'tx-new'], $payment->getDetails());
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testExistingPaidTransactionIsNotReplaced(): void
    {
        $response = self::statusResponse('settled');
        $httpClient = new MockHttpClient([$response]);
        $payment = self::createPayment(['transactionId' => 'tx-1']);

        $this->capture($payment, $httpClient);

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame(['transactionId' => 'tx-1'], $payment->getDetails());
    }

    public function testExistingUnpaidTransactionIsReplaced(): void
    {
        $httpClient = new MockHttpClient([
            self::statusResponse('initialized'),
            self::jsonResponse(['transactionId' => 'tx-new'], 201),
        ]);
        $payment = self::createPayment(['transactionId' => 'tx-1']);

        try {
            $this->capture($payment, $httpClient);
            self::fail('Expected redirect to the Datatrans payment page.');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://pay.sandbox.datatrans.com/v1/start/tx-new', $redirect->getUrl());
        }

        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertSame(['previousTransactionIds' => ['tx-1'], 'transactionId' => 'tx-new'], $payment->getDetails());
    }

    /**
     * @dataProvider unknownStatusProvider
     */
    #[DataProvider('unknownStatusProvider')]
    public function testExistingTransactionWithUnknownStatusIsNotReplaced(MockResponse $response): void
    {
        $httpClient = new MockHttpClient([$response]);
        $payment = self::createPayment(['transactionId' => 'paid-tx']);

        $this->capture($payment, $httpClient);

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame(['transactionId' => 'paid-tx'], $payment->getDetails());
    }

    public static function unknownStatusProvider(): iterable
    {
        yield 'unauthorized' => [self::jsonResponse(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'x']], 401)];
        yield 'server error' => [self::jsonResponse(['error' => ['code' => 'SERVER_ERROR', 'message' => 'x']], 503)];
        yield 'transport error' => [new MockResponse('', ['error' => 'Connection refused'])];
    }

    public function testPaidPreviousTransactionIsNotReplaced(): void
    {
        $httpClient = new MockHttpClient([self::statusResponse('canceled'), self::statusResponse('settled')]);
        $payment = self::createPayment(['transactionId' => 'tx-2', 'previousTransactionIds' => ['tx-1']]);

        $this->capture($payment, $httpClient);

        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertSame(['transactionId' => 'tx-2', 'previousTransactionIds' => ['tx-1']], $payment->getDetails());
    }

    public function testInitErrorIsStored(): void
    {
        $error = ['code' => 'UNAUTHORIZED', 'message' => 'Invalid credentials'];
        $payment = self::createPayment();

        $this->capture($payment, new MockHttpClient([self::jsonResponse(['error' => $error], 401)]));

        self::assertSame(['error' => $error], $payment->getDetails());
    }

    public function testSupports(): void
    {
        $action = new CaptureAction();

        self::assertTrue($action->supports(new Capture(self::createPayment())));
        self::assertFalse($action->supports(new Capture(new ArrayObject())));
    }

    public function testRejectsForeignApi(): void
    {
        $this->expectException(UnsupportedApiException::class);

        (new CaptureAction())->setApi(new \stdClass());
    }

    private function capture(PaymentInterface $payment, MockHttpClient $httpClient, array $config = []): void
    {
        $token = new Token();
        $token->setAfterUrl('https://shop.test/after');
        $request = new Capture($token);
        $request->setModel($payment);

        $action = new CaptureAction();
        $action->setApi(self::createApi($httpClient, $config));
        $action->execute($request);
    }
}
