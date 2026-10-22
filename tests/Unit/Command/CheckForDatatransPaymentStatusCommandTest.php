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

namespace Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SM\Factory\Factory;
use SM\StateMachine\StateMachineInterface as WinzouStateMachineInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\PaymentRepository;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\DatatransTestTrait;
use Whatwedo\SyliusDatatransPaymentPlugin\Command\CheckForDatatransPaymentStatusCommand;

final class CheckForDatatransPaymentStatusCommandTest extends TestCase
{
    use DatatransTestTrait;

    /**
     * @var array<string, string>
     */
    private array $applied = [];

    public function testQueryIsLimitedToDatatransPaymentsInNewOrAuthorizedState(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects(self::exactly(2))->method('innerJoin')->willReturnSelf();
        $queryBuilder->expects(self::once())->method('where')->with('p.state IN (:states)')->willReturnSelf();
        $queryBuilder->expects(self::once())->method('andWhere')->with('gc.factoryName = :factoryName')->willReturnSelf();
        $parameters = [];
        $queryBuilder->method('setParameter')->willReturnCallback(function (string $key, $value) use (&$parameters, $queryBuilder) {
            $parameters[$key] = $value;

            return $queryBuilder;
        });
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([]);
        $queryBuilder->method('getQuery')->willReturn($query);
        $repository = $this->createMock(PaymentRepository::class);
        $repository->method('createQueryBuilder')->with('p')->willReturn($queryBuilder);

        $command = new CheckForDatatransPaymentStatusCommand($repository, $this->createAbstractionStateMachine(), $this->createMock(EntityManagerInterface::class), new MockHttpClient([]));
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertSame([
            'states' => [PaymentInterface::STATE_NEW, PaymentInterface::STATE_AUTHORIZED],
            'factoryName' => 'datatrans_payment',
        ], $parameters);
        self::assertSame('whatwedo:sylius-datatrans-payment:check-payment-status', $command->getName());
    }

    /**
     * @dataProvider transitionProvider
     */
    #[DataProvider('transitionProvider')]
    public function testTransitionsWithAbstractionStateMachine(string $datatransStatus, string $state, ?string $transition): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1'], $state);

        $this->runCommand([$payment], [self::statusResponse($datatransStatus)], $this->createAbstractionStateMachine());

        self::assertSame($transition === null ? [] : [spl_object_hash($payment) => $transition], $this->applied);
        self::assertSame($datatransStatus, $payment->getDetails()['datatrans']['status']);
    }

    /**
     * @dataProvider transitionProvider
     */
    #[DataProvider('transitionProvider')]
    public function testTransitionsWithWinzouStateMachine(string $datatransStatus, string $state, ?string $transition): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1'], $state);

        $this->runCommand([$payment], [self::statusResponse($datatransStatus)], $this->createWinzouFactory());

        self::assertSame($transition === null ? [] : [spl_object_hash($payment) => $transition], $this->applied);
    }

    public static function transitionProvider(): iterable
    {
        yield 'settled' => ['settled', PaymentInterface::STATE_NEW, PaymentTransitions::TRANSITION_COMPLETE];
        yield 'transmitted' => ['transmitted', PaymentInterface::STATE_NEW, PaymentTransitions::TRANSITION_COMPLETE];
        yield 'settled after authorize' => ['settled', PaymentInterface::STATE_AUTHORIZED, PaymentTransitions::TRANSITION_COMPLETE];
        yield 'authorized from new' => ['authorized', PaymentInterface::STATE_NEW, PaymentTransitions::TRANSITION_AUTHORIZE];
        yield 'authorized stays authorized' => ['authorized', PaymentInterface::STATE_AUTHORIZED, null];
        yield 'canceled' => ['canceled', PaymentInterface::STATE_NEW, PaymentTransitions::TRANSITION_CANCEL];
        yield 'failed' => ['failed', PaymentInterface::STATE_NEW, PaymentTransitions::TRANSITION_FAIL];
        yield 'initialized waits' => ['initialized', PaymentInterface::STATE_NEW, null];
        yield 'challenge waits' => ['challenge_required', PaymentInterface::STATE_NEW, null];
    }

    public function testAmountMismatchDoesNotComplete(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1']);

        $this->runCommand([$payment], [self::statusResponse('settled', 'CHF', 1)], $this->createAbstractionStateMachine());

        self::assertSame([], $this->applied);
    }

    public function testTimeoutCancelsStalePendingPayment(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1']);
        $payment->setCreatedAt(new \DateTime('-15 days'));

        $output = $this->runCommand([$payment], [self::statusResponse('initialized')], $this->createAbstractionStateMachine());

        self::assertSame([spl_object_hash($payment) => PaymentTransitions::TRANSITION_CANCEL], $this->applied);
        self::assertStringContainsString('canceled due to timeout', $output);
    }

    public function testTimeoutDoesNotCancelPaidPayment(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1']);
        $payment->setCreatedAt(new \DateTime('-15 days'));

        $this->runCommand([$payment], [self::statusResponse('settled', 'CHF', 1)], $this->createAbstractionStateMachine());

        self::assertSame([], $this->applied);
    }

    /**
     * @dataProvider pendingStatusProvider
     */
    #[DataProvider('pendingStatusProvider')]
    public function testTimeoutCancelsStalePendingStatuses(string $datatransStatus): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1']);
        $payment->setCreatedAt(new \DateTime('-15 days'));

        $this->runCommand([$payment], [self::statusResponse($datatransStatus)], $this->createAbstractionStateMachine());

        self::assertSame([spl_object_hash($payment) => PaymentTransitions::TRANSITION_CANCEL], $this->applied);
    }

    /**
     * @dataProvider pendingStatusProvider
     */
    #[DataProvider('pendingStatusProvider')]
    public function testTimeoutNeverCancelsAuthorizedPayment(string $datatransStatus): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1'], PaymentInterface::STATE_AUTHORIZED);
        $payment->setCreatedAt(new \DateTime('-15 days'));

        $this->runCommand([$payment], [self::statusResponse($datatransStatus)], $this->createAbstractionStateMachine());

        self::assertSame([], $this->applied);
    }

    public static function pendingStatusProvider(): iterable
    {
        yield ['initialized'];
        yield ['challenge_required'];
        yield ['challenge_ongoing'];
        yield ['authenticated'];
    }

    /**
     * @dataProvider errorResponseProvider
     */
    #[DataProvider('errorResponseProvider')]
    public function testTimeoutDoesNotCancelOnErrorResponse(string $state, MockResponse $response): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1'], $state);
        $payment->setCreatedAt(new \DateTime('-15 days'));

        $output = $this->runCommand([$payment], [$response], $this->createAbstractionStateMachine());

        self::assertSame([], $this->applied);
        self::assertStringContainsString('status waiting', $output);
    }

    public static function errorResponseProvider(): iterable
    {
        foreach ([PaymentInterface::STATE_NEW, PaymentInterface::STATE_AUTHORIZED] as $state) {
            yield $state . ' server error' => [$state, self::jsonResponse(['error' => ['code' => 'SERVER_ERROR', 'message' => 'x']], 503)];
            yield $state . ' unauthorized' => [$state, self::jsonResponse(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'x']], 401)];
            yield $state . ' unknown transaction' => [$state, self::jsonResponse(['error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => 'x']], 400)];
        }
    }

    /**
     * @dataProvider expiryProvider
     */
    #[DataProvider('expiryProvider')]
    public function testTimeoutIsMeasuredFromLaterOfCreationAndExpiry(string $createdAt, ?string $expires, bool $canceled): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1']);
        $payment->setCreatedAt(new \DateTime($createdAt));
        $response = ['status' => 'initialized', 'currency' => 'CHF'];
        if ($expires !== null) {
            $response['detail']['init']['expires'] = $expires;
        }

        $this->runCommand([$payment], [self::jsonResponse($response)], $this->createAbstractionStateMachine());

        self::assertSame($canceled ? [spl_object_hash($payment) => PaymentTransitions::TRANSITION_CANCEL] : [], $this->applied);
    }

    public static function expiryProvider(): iterable
    {
        yield 'recent expiry' => ['-30 days', (new \DateTimeImmutable('-10 days'))->format(\DATE_ATOM), false];
        yield 'old expiry' => ['-30 days', (new \DateTimeImmutable('-15 days'))->format(\DATE_ATOM), true];
        yield 'expiry before creation' => ['-10 days', (new \DateTimeImmutable('-30 days'))->format(\DATE_ATOM), false];
        yield 'unparsable expiry' => ['-15 days', 'not a date', true];
        yield 'no expiry' => ['-15 days', null, true];
    }

    public function testPaidPreviousTransactionCompletesStalePayment(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-2', 'previousTransactionIds' => ['tx-1']]);
        $payment->setCreatedAt(new \DateTime('-15 days'));

        $this->runCommand([$payment], [self::statusResponse('initialized'), self::statusResponse('settled')], $this->createAbstractionStateMachine());

        self::assertSame([spl_object_hash($payment) => PaymentTransitions::TRANSITION_COMPLETE], $this->applied);
    }

    public function testMismatchingPreviousTransactionIsNeitherCompletedNorCanceled(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-2', 'previousTransactionIds' => ['tx-1']]);
        $payment->setCreatedAt(new \DateTime('-15 days'));

        $this->runCommand([$payment], [self::statusResponse('initialized'), self::statusResponse('settled', 'EUR')], $this->createAbstractionStateMachine());

        self::assertSame([], $this->applied);
        self::assertSame('settled', $payment->getDetails()['datatrans']['status']);
    }

    /**
     * @dataProvider unknownPreviousTransactionProvider
     */
    #[DataProvider('unknownPreviousTransactionProvider')]
    public function testUnknownPreviousTransactionPreventsCancel(string $currentStatus): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-2', 'previousTransactionIds' => ['tx-1']]);
        $payment->setCreatedAt(new \DateTime('-15 days'));
        $error = self::jsonResponse(['error' => ['code' => 'SERVER_ERROR', 'message' => 'x']], 503);

        $this->runCommand([$payment], [self::statusResponse($currentStatus), $error], $this->createAbstractionStateMachine());

        self::assertSame([], $this->applied);
        self::assertArrayNotHasKey('datatrans', $payment->getDetails());
    }

    public static function unknownPreviousTransactionProvider(): iterable
    {
        yield ['initialized'];
        yield ['canceled'];
        yield ['failed'];
    }

    public function testPaymentWithOnlyPreviousTransactionIdsIsChecked(): void
    {
        $payment = self::createPayment(['previousTransactionIds' => ['tx-1'], 'error' => ['code' => 'UNAUTHORIZED']]);

        $this->runCommand([$payment], [self::statusResponse('settled')], $this->createAbstractionStateMachine());

        self::assertSame([spl_object_hash($payment) => PaymentTransitions::TRANSITION_COMPLETE], $this->applied);
    }

    public function testTimeoutDoesNotCancelWithoutFreshResponse(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1', 'datatrans' => ['status' => 'initialized']]);
        $payment->setCreatedAt(new \DateTime('-15 days'));

        $this->runCommand([$payment], [new MockResponse('', ['error' => 'Connection refused'])], $this->createAbstractionStateMachine());

        self::assertSame([], $this->applied);
    }

    public function testRecentPendingPaymentIsNotCanceled(): void
    {
        $payment = self::createPayment(['transactionId' => 'tx-1']);
        $payment->setCreatedAt(new \DateTime('-13 days'));

        $output = $this->runCommand([$payment], [self::statusResponse('initialized')], $this->createAbstractionStateMachine());

        self::assertSame([], $this->applied);
        self::assertStringContainsString('status waiting', $output);
    }

    public function testFailingPaymentDoesNotStopOthers(): void
    {
        $broken = self::createPayment(['transactionId' => 'tx-0']);
        $broken->getMethod()->getGatewayConfig()->setConfig(['merchant_id' => '1100000000']);
        $paid = self::createPayment(['transactionId' => 'tx-1']);

        $output = $this->runCommand([$broken, $paid], [self::statusResponse('settled')], $this->createAbstractionStateMachine(), 1);

        self::assertSame([spl_object_hash($paid) => PaymentTransitions::TRANSITION_COMPLETE], $this->applied);
        self::assertStringContainsString('could not be checked', $output);
        self::assertStringContainsString('"password" config option is required', $output);
    }

    public function testChangesOfFailedPaymentAreDiscarded(): void
    {
        $first = self::createPayment(['transactionId' => 'tx-1']);
        $second = self::createPayment(['transactionId' => 'tx-2']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $flushes = 0;
        $entityManager->expects(self::exactly(2))->method('flush')->willReturnCallback(function () use (&$flushes): void {
            if (++$flushes === 1) {
                throw new \RuntimeException('Deadlock found');
            }
        });
        $refreshed = [];
        $entityManager->method('refresh')->willReturnCallback(function (object $entity) use (&$refreshed): void {
            $refreshed[] = $entity;
        });

        $output = $this->runCommand([$first, $second], [self::statusResponse('settled'), self::statusResponse('settled')], $this->createAbstractionStateMachine(), null, $entityManager);

        self::assertSame([$first, $first->getOrder()], $refreshed);
        self::assertStringContainsString('could not be checked: Deadlock found', $output);
        self::assertArrayHasKey(spl_object_hash($second), $this->applied);
    }

    public function testClosedEntityManagerStopsProcessing(): void
    {
        $first = self::createPayment(['transactionId' => 'tx-1']);
        $second = self::createPayment(['transactionId' => 'tx-2']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);
        $entityManager->expects(self::once())->method('flush')->willThrowException(new \RuntimeException('Deadlock found'));
        $entityManager->expects(self::never())->method('refresh');

        $output = $this->runCommand([$first, $second], [self::statusResponse('settled')], $this->createAbstractionStateMachine(), null, $entityManager, 1);

        self::assertStringContainsString('entity manager is closed', $output);
        self::assertSame(1, substr_count($output, 'processing payment'));
    }

    public function testPaymentsWithoutTransactionIdAreSkipped(): void
    {
        $payment = self::createPayment(['payment-link' => 'https://shop.test/link']);

        $output = $this->runCommand([$payment], [], $this->createAbstractionStateMachine(), 0);

        self::assertSame([], $this->applied);
        self::assertStringNotContainsString('processing payment', $output);
    }

    /**
     * @param Payment[] $payments
     * @param ResponseInterface[] $responses
     */
    private function runCommand(
        array $payments,
        array $responses,
        Factory|StateMachineInterface $stateMachine,
        ?int $expectedFlushes = null,
        ?EntityManagerInterface $entityManager = null,
        int $expectedExitCode = 0,
    ): string
    {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn($payments);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('getQuery')->willReturn($query);
        $repository = $this->createMock(PaymentRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        if ($entityManager === null) {
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager->method('isOpen')->willReturn(true);
            $entityManager->expects(self::exactly($expectedFlushes ?? count($payments)))->method('flush');
        }

        $httpClient = new MockHttpClient($responses);
        $tester = new CommandTester(new CheckForDatatransPaymentStatusCommand($repository, $stateMachine, $entityManager, $httpClient));

        self::assertSame($expectedExitCode, $tester->execute([]));
        self::assertSame(count($responses), $httpClient->getRequestsCount());

        return $tester->getDisplay();
    }

    private function createAbstractionStateMachine(): StateMachineInterface
    {
        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->method('can')->willReturnCallback(function (object $subject, string $graph, string $transition): bool {
            self::assertSame(PaymentTransitions::GRAPH, $graph);

            return self::canTransition($subject, $transition);
        });
        $stateMachine->method('apply')->willReturnCallback(function (object $subject, string $graph, string $transition): void {
            $this->applied[spl_object_hash($subject)] = $transition;
        });

        return $stateMachine;
    }

    private function createWinzouFactory(): Factory
    {
        $factory = $this->createMock(Factory::class);
        $factory->method('get')->willReturnCallback(function (object $subject, string $graph): WinzouStateMachineInterface {
            self::assertSame(PaymentTransitions::GRAPH, $graph);
            $stateMachine = $this->createMock(WinzouStateMachineInterface::class);
            $stateMachine->method('can')->willReturnCallback(fn (string $transition): bool => self::canTransition($subject, $transition));
            $stateMachine->method('apply')->willReturnCallback(function (string $transition) use ($subject): bool {
                $this->applied[spl_object_hash($subject)] = $transition;

                return true;
            });

            return $stateMachine;
        });

        return $factory;
    }

    private static function canTransition(object $subject, string $transition): bool
    {
        self::assertInstanceOf(PaymentInterface::class, $subject);
        $allowed = [
            PaymentInterface::STATE_NEW => [
                PaymentTransitions::TRANSITION_AUTHORIZE,
                PaymentTransitions::TRANSITION_COMPLETE,
                PaymentTransitions::TRANSITION_CANCEL,
                PaymentTransitions::TRANSITION_FAIL,
            ],
            PaymentInterface::STATE_AUTHORIZED => [
                PaymentTransitions::TRANSITION_COMPLETE,
                PaymentTransitions::TRANSITION_CANCEL,
            ],
        ];

        return in_array($transition, $allowed[$subject->getState()] ?? [], true);
    }
}
