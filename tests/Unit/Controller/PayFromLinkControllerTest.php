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

namespace Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Payum\Core\Reply\HttpRedirect;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\DatatransTestTrait;
use Whatwedo\SyliusDatatransPaymentPlugin\Controller\PayFromLinkController;

final class PayFromLinkControllerTest extends TestCase
{
    use DatatransTestTrait;

    private const LINK = 'https://shop.test/order/order-token';

    public function testUnknownPaymentIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->pay(null, new MockHttpClient([]), 'order-token');
    }

    public function testMissingTokenIsNotFound(): void
    {
        $httpClient = new MockHttpClient([]);

        $this->assertNotFound(self::linkPayment(), $httpClient, null);
        $this->assertNotFound(self::linkPayment(), $httpClient, '');
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testWrongTokenIsNotFound(): void
    {
        $httpClient = new MockHttpClient([]);

        $this->assertNotFound(self::linkPayment(), $httpClient, 'guessed-token');
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testNonNewPaymentIsNotFound(): void
    {
        $httpClient = new MockHttpClient([]);

        foreach ([PaymentInterface::STATE_COMPLETED, PaymentInterface::STATE_AUTHORIZED, PaymentInterface::STATE_CANCELLED] as $state) {
            $this->assertNotFound(self::createPayment(['payment-link' => self::LINK], $state, ['generate_link' => true]), $httpClient, 'order-token');
        }
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testPaymentWithoutLinkIsNotFound(): void
    {
        $this->assertNotFound(self::createPayment([], PaymentInterface::STATE_NEW, ['generate_link' => true]), new MockHttpClient([]), 'order-token');
    }

    public function testForeignGatewayIsNotFound(): void
    {
        $payment = self::createPayment(['payment-link' => self::LINK], PaymentInterface::STATE_NEW, ['generate_link' => true], 'offline');

        $this->assertNotFound($payment, new MockHttpClient([]), 'order-token');
    }

    public function testGatewayWithoutGenerateLinkIsNotFound(): void
    {
        $this->assertNotFound(self::createPayment(['payment-link' => self::LINK]), new MockHttpClient([]), 'order-token');
    }

    public function testRedirectsToNewTransaction(): void
    {
        $response = self::jsonResponse(['transactionId' => 'tx-new'], 201);
        $payment = self::linkPayment();

        $redirect = $this->pay($payment, new MockHttpClient([$response]), 'order-token', 1);

        self::assertSame('https://pay.sandbox.datatrans.com/v1/start/tx-new', $redirect->getUrl());
        self::assertSame(['payment-link' => self::LINK, 'transactionId' => 'tx-new'], $payment->getDetails());
        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::LINK, json_decode($response->getRequestOptions()['body'], true)['redirect']['successUrl']);
    }

    public function testAlreadyPaidTransactionIsNotReplaced(): void
    {
        $httpClient = new MockHttpClient([self::statusResponse('settled')]);
        $payment = self::linkPayment(['transactionId' => 'tx-1']);

        $redirect = $this->pay($payment, $httpClient, 'order-token', 0);

        self::assertSame(self::LINK, $redirect->getUrl());
        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame('tx-1', $payment->getDetails()['transactionId']);
    }

    public function testUnknownStatusOfExistingTransactionIsNotReplaced(): void
    {
        $httpClient = new MockHttpClient([self::jsonResponse(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'x']], 401)]);
        $payment = self::linkPayment(['transactionId' => 'tx-1']);

        $redirect = $this->pay($payment, $httpClient, 'order-token', 0);

        self::assertSame(self::LINK, $redirect->getUrl());
        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame(['payment-link' => self::LINK, 'transactionId' => 'tx-1'], $payment->getDetails());
    }

    public function testUnpaidTransactionIsReplacedAndKeptAsPrevious(): void
    {
        $httpClient = new MockHttpClient([self::statusResponse('canceled'), self::jsonResponse(['transactionId' => 'tx-new'], 201)]);
        $payment = self::linkPayment(['transactionId' => 'tx-1']);

        $redirect = $this->pay($payment, $httpClient, 'order-token', 1);

        self::assertSame('https://pay.sandbox.datatrans.com/v1/start/tx-new', $redirect->getUrl());
        self::assertSame(['payment-link' => self::LINK, 'previousTransactionIds' => ['tx-1'], 'transactionId' => 'tx-new'], $payment->getDetails());
    }

    public function testInitErrorRedirectsBackToLink(): void
    {
        $error = ['code' => 'UNAUTHORIZED', 'message' => 'Invalid credentials'];
        $payment = self::linkPayment();

        $redirect = $this->pay($payment, new MockHttpClient([self::jsonResponse(['error' => $error], 401)]), 'order-token', 1);

        self::assertSame(self::LINK, $redirect->getUrl());
        self::assertSame(['payment-link' => self::LINK, 'error' => $error], $payment->getDetails());
    }

    private static function linkPayment(array $details = []): Payment
    {
        return self::createPayment(['payment-link' => self::LINK] + $details, PaymentInterface::STATE_NEW, ['generate_link' => true]);
    }

    private function assertNotFound(Payment $payment, MockHttpClient $httpClient, ?string $token): void
    {
        try {
            $this->pay($payment, $httpClient, $token);
            self::fail('Expected a 404.');
        } catch (NotFoundHttpException $exception) {
            self::assertInstanceOf(NotFoundHttpException::class, $exception);
        }
    }

    private function pay(?Payment $payment, MockHttpClient $httpClient, ?string $token, int $expectedFlushes = 0): HttpRedirect
    {
        $repository = $this->createMock(PaymentRepositoryInterface::class);
        $repository->method('find')->with(42)->willReturn($payment);
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly($expectedFlushes))->method('flush');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        $controller = new PayFromLinkController($registry, $httpClient, $repository);
        $query = $token === null ? [] : ['token' => $token];

        try {
            $controller->payFromLinkAction(42, new Request($query));
        } catch (HttpRedirect $redirect) {
            return $redirect;
        }
        self::fail('Expected a redirect.');
    }
}
