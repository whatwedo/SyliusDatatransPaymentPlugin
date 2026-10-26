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
use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\DatatransTestTrait;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\Action\StatusAction;

final class StatusActionTest extends TestCase
{
    use DatatransTestTrait;

    /**
     * @dataProvider statusProvider
     */
    #[DataProvider('statusProvider')]
    public function testStatusMapping(string $datatransStatus, string $expected): void
    {
        $response = self::statusResponse($datatransStatus);
        $request = new GetHumanStatus(self::createPayment(['transactionId' => 'tx-1']));

        (new StatusAction('https://api.sandbox.datatrans.com/', 'credentials', new MockHttpClient([$response])))->execute($request);

        self::assertSame($expected, $request->getValue());
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://api.sandbox.datatrans.com/v1/transactions/tx-1', $response->getRequestUrl());
        self::assertContains('Authorization: Basic credentials', $response->getRequestOptions()['headers']);
    }

    public static function statusProvider(): iterable
    {
        yield 'settled' => ['settled', GetHumanStatus::STATUS_CAPTURED];
        yield 'transmitted' => ['transmitted', GetHumanStatus::STATUS_CAPTURED];
        yield 'authorized' => ['authorized', GetHumanStatus::STATUS_AUTHORIZED];
        yield 'canceled' => ['canceled', GetHumanStatus::STATUS_CANCELED];
        yield 'failed' => ['failed', GetHumanStatus::STATUS_FAILED];
        yield 'initialized' => ['initialized', GetHumanStatus::STATUS_NEW];
        yield 'challenge_required' => ['challenge_required', GetHumanStatus::STATUS_UNKNOWN];
        yield 'challenge_ongoing' => ['challenge_ongoing', GetHumanStatus::STATUS_UNKNOWN];
        yield 'authenticated' => ['authenticated', GetHumanStatus::STATUS_UNKNOWN];
        yield 'unknown' => ['something_new', GetHumanStatus::STATUS_UNKNOWN];
    }

    /**
     * @dataProvider mismatchProvider
     */
    #[DataProvider('mismatchProvider')]
    public function testPaidStatusIsNotTrustedOnMismatch(MockResponse $response): void
    {
        $request = new GetHumanStatus(self::createPayment(['transactionId' => 'tx-1']));

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', new MockHttpClient([$response])))->execute($request);

        self::assertTrue($request->isUnknown());
    }

    public static function mismatchProvider(): iterable
    {
        yield 'settled currency mismatch' => [self::statusResponse('settled', 'EUR')];
        yield 'settled amount mismatch' => [self::statusResponse('settled', 'CHF', 1)];
        yield 'transmitted amount mismatch' => [self::statusResponse('transmitted', 'CHF', 1049)];
        yield 'authorized amount mismatch' => [self::jsonResponse([
            'status' => 'authorized',
            'currency' => 'CHF',
            'detail' => ['authorize' => ['amount' => 99]],
        ])];
    }

    public function testPaidStatusWithoutAmountIsCaptured(): void
    {
        $request = new GetHumanStatus(self::createPayment(['transactionId' => 'tx-1']));
        $httpClient = new MockHttpClient([self::jsonResponse(['status' => 'settled', 'currency' => 'CHF'])]);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isCaptured());
    }

    /**
     * @dataProvider twintTransmittedProvider
     */
    #[DataProvider('twintTransmittedProvider')]
    public function testTwintTransmittedIsCapturedAndKeepsDetails(array $detail): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1', 'payment-link' => 'https://shop.test/link']);
        $request = new GetHumanStatus($payment);
        $httpClient = new MockHttpClient([self::jsonResponse([
            'transactionId' => 'tx-1',
            'type' => 'payment',
            'status' => 'transmitted',
            'currency' => 'CHF',
            'refno' => '000123',
            'paymentMethod' => 'TWI',
            'detail' => $detail,
        ])]);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isCaptured());
        self::assertSame('tx-1', $payment->getDetails()['transactionId']);
        self::assertSame('https://shop.test/link', $payment->getDetails()['payment-link']);
        self::assertSame('TWI', $payment->getDetails()['datatrans']['paymentMethod']);
    }

    public static function twintTransmittedProvider(): iterable
    {
        yield 'with settle block' => [['authorize' => ['amount' => 1050], 'settle' => ['amount' => 1050]]];
        yield 'authorize block only' => [['authorize' => ['amount' => 1050]]];
        yield 'without amounts' => [[]];
    }

    public function testWithoutTransactionIdIsUnknown(): void
    {
        $httpClient = new MockHttpClient([]);
        $request = new GetHumanStatus(self::createPayment());

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isUnknown());
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testWithoutTransactionIdButInitErrorIsFailed(): void
    {
        $httpClient = new MockHttpClient([]);
        $request = new GetHumanStatus(self::createPayment(['error' => ['code' => 'UNAUTHORIZED']]));

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isFailed());
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testTransportErrorIsUnknown(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1']);
        $request = new GetHumanStatus($payment);
        $httpClient = new MockHttpClient([new MockResponse('', ['error' => 'Connection refused'])]);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isUnknown());
        self::assertArrayNotHasKey('datatrans', $payment->getDetails());
    }

    public function testInvalidJsonIsUnknown(): void
    {
        $request = new GetHumanStatus(self::createPayment(['transactionId' => 'tx-1']));
        $httpClient = new MockHttpClient([new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502])]);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isUnknown());
    }

    public function testErrorResponseIsUnknown(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1']);
        $request = new GetHumanStatus($payment);
        $error = ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'x']];

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', new MockHttpClient([self::jsonResponse($error, 401)])))->execute($request);

        self::assertTrue($request->isUnknown());
        self::assertSame($error, $payment->getDetails()['datatrans']);
    }

    /**
     * @dataProvider paidPreviousTransactionProvider
     */
    #[DataProvider('paidPreviousTransactionProvider')]
    public function testPaidPreviousTransactionIsUsed(array $details, array $responses, string $expected): void
    {
        $payment = self::createPayment($details);
        $request = new GetHumanStatus($payment);
        $httpClient = new MockHttpClient($responses);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertSame($expected, $request->getValue());
        self::assertSame(count($responses), $httpClient->getRequestsCount());
        self::assertSame('settled', $payment->getDetails()['datatrans']['status']);
    }

    public static function paidPreviousTransactionProvider(): iterable
    {
        $details = ['transactionId' => 'tx-3', 'previousTransactionIds' => ['tx-1', 'tx-2']];

        yield 'current canceled' => [$details, [self::statusResponse('canceled'), self::statusResponse('initialized'), self::statusResponse('settled')], GetHumanStatus::STATUS_CAPTURED];
        yield 'current initialized' => [$details, [self::statusResponse('initialized'), self::statusResponse('settled')], GetHumanStatus::STATUS_CAPTURED];
        yield 'current unreachable' => [$details, [new MockResponse('', ['error' => 'Connection refused']), self::statusResponse('settled')], GetHumanStatus::STATUS_CAPTURED];
        yield 'replacement init failed' => [['previousTransactionIds' => ['tx-1'], 'error' => ['code' => 'x']], [self::statusResponse('settled')], GetHumanStatus::STATUS_CAPTURED];
        yield 'mismatching previous is left for review' => [$details, [self::statusResponse('canceled'), self::statusResponse('settled', 'CHF', 1), self::statusResponse('canceled')], GetHumanStatus::STATUS_UNKNOWN];
        yield 'matching previous wins over mismatching' => [$details, [self::statusResponse('canceled'), self::statusResponse('settled', 'CHF', 1), self::statusResponse('settled')], GetHumanStatus::STATUS_CAPTURED];
    }

    public function testAuthorizedPreviousTransactionIsAuthorized(): void
    {
        $request = new GetHumanStatus(self::createPayment(['transactionId' => 'tx-2', 'previousTransactionIds' => ['tx-1']]));
        $httpClient = new MockHttpClient([self::statusResponse('failed'), self::jsonResponse(['status' => 'authorized', 'currency' => 'CHF', 'detail' => ['authorize' => ['amount' => 1050]]])]);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isAuthorized());
    }

    public function testPreviousTransactionsAreNotCheckedWhenCurrentIsPaid(): void
    {
        $request = new GetHumanStatus(self::createPayment(['transactionId' => 'tx-2', 'previousTransactionIds' => ['tx-1']]));
        $httpClient = new MockHttpClient([self::statusResponse('settled')]);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isCaptured());
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testUnpaidPreviousTransactionsDoNotChangeOutcome(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-4', 'previousTransactionIds' => ['tx-1', 'tx-2', 'tx-3']]);
        $request = new GetHumanStatus($payment);
        $httpClient = new MockHttpClient([
            self::statusResponse('canceled'),
            self::statusResponse('failed'),
            self::statusResponse('initialized'),
            self::statusResponse('canceled'),
        ]);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isCanceled());
        self::assertSame(4, $httpClient->getRequestsCount());
        self::assertSame('canceled', $payment->getDetails()['datatrans']['status']);
    }

    /**
     * @dataProvider unknownPreviousTransactionProvider
     */
    #[DataProvider('unknownPreviousTransactionProvider')]
    public function testUnknownPreviousTransactionMakesStatusUnknown(string $currentStatus, MockResponse $previous): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-3', 'previousTransactionIds' => ['tx-1', 'tx-2']]);
        $request = new GetHumanStatus($payment);
        $httpClient = new MockHttpClient([self::statusResponse($currentStatus), $previous, self::statusResponse('canceled')]);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isUnknown());
        self::assertSame(3, $httpClient->getRequestsCount());
        self::assertArrayNotHasKey('datatrans', $payment->getDetails());
    }

    public static function unknownPreviousTransactionProvider(): iterable
    {
        foreach (['canceled', 'failed', 'initialized'] as $status) {
            yield $status . ' transport error' => [$status, new MockResponse('', ['error' => 'Connection refused'])];
            yield $status . ' error response' => [$status, self::jsonResponse(['error' => ['code' => 'SERVER_ERROR', 'message' => 'x']], 503)];
            yield $status . ' missing status' => [$status, self::jsonResponse(['transactionId' => 'tx-1'])];
        }
    }

    /**
     * @dataProvider initErrorWithPreviousTransactionProvider
     */
    #[DataProvider('initErrorWithPreviousTransactionProvider')]
    public function testInitErrorWithPreviousTransactionIsNotFailed(MockResponse $previous): void
    {
        $payment = self::createPayment(['previousTransactionIds' => ['tx-1'], 'error' => ['code' => 'UNAUTHORIZED']]);
        $request = new GetHumanStatus($payment);
        $httpClient = new MockHttpClient([$previous]);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        self::assertTrue($request->isUnknown());
        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertArrayNotHasKey('datatrans', $payment->getDetails());
    }

    public static function initErrorWithPreviousTransactionProvider(): iterable
    {
        yield 'unpaid' => [self::statusResponse('canceled')];
        yield 'unknown' => [self::jsonResponse(['error' => ['code' => 'SERVER_ERROR', 'message' => 'x']], 503)];
    }

    public function testResponseIsStoredOnPaymentAndWrappedDetails(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1', 'payment-link' => 'https://shop.test/link']);
        $request = new GetHumanStatus($payment);
        $request->setModel(ArrayObject::ensureArrayObject($payment->getDetails()));
        $httpClient = new MockHttpClient([self::statusResponse('settled')]);

        (new StatusAction('https://api.sandbox.datatrans.com', 'credentials', $httpClient))->execute($request);

        $expected = [
            'transactionId' => 'tx-1',
            'status' => 'settled',
            'currency' => 'CHF',
            'refno' => '000123',
            'detail' => ['settle' => ['amount' => 1050]],
        ];
        self::assertTrue($request->isCaptured());
        self::assertSame($payment, $request->getFirstModel());
        self::assertSame($expected, $payment->getDetails()['datatrans']);
        self::assertSame('https://shop.test/link', $payment->getDetails()['payment-link']);
        self::assertSame($expected, $request->getModel()['datatrans']);
    }

    public function testLegacyTwoArgumentConstructor(): void
    {
        $constructor = new \ReflectionMethod(StatusAction::class, '__construct');
        self::assertSame(2, $constructor->getNumberOfRequiredParameters());

        $action = new StatusAction('https://api.sandbox.datatrans.com', 'credentials');
        $request = new GetHumanStatus(self::createPayment());
        $action->execute($request);

        self::assertTrue($request->isUnknown());
    }

    public function testSupports(): void
    {
        $action = new StatusAction('https://api.sandbox.datatrans.com', 'credentials');

        self::assertTrue($action->supports(new GetHumanStatus(self::createPayment())));
        self::assertFalse($action->supports(new GetHumanStatus(new ArrayObject())));
        self::assertFalse($action->supports(new \stdClass()));
    }
}
