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

namespace Whatwedo\SyliusDatatransPaymentPlugin\Payum;

use Sylius\Component\Core\Model\PaymentInterface as SyliusPaymentInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DatatransApi
{
    public const CAPTURED_STATUSES = ['settled', 'transmitted'];

    public const AUTHORIZED_STATUS = 'authorized';

    public const PENDING_STATUSES = ['initialized', 'challenge_required', 'challenge_ongoing', 'authenticated'];

    public const LANGUAGES = ['en', 'de', 'fr', 'it', 'es', 'el', 'fi', 'hu', 'ko', 'nl', 'no', 'da', 'pl', 'pt', 'ru', 'ja', 'sk', 'sl', 'sv', 'tr', 'zh'];

    public const DEFAULT_LANGUAGE = 'de';

    /**
     * @var string
     */
    private $merchantId;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $sign;

    /**
     * @var string
     */
    private $endpoint;

    /**
     * @var bool
     */
    private $generateLink;

    /**
     * @var array
     */
    private $paymentMethods;

    /**
     * @var bool
     */
    private $hmacSHA256;

    private ?HttpClientInterface $httpClient;

    public function __construct(string $merchantId, string $password, string $endpoint, string $sign, bool $generateLink, array $paymentMethods, bool $hmacSHA256, ?HttpClientInterface $httpClient = null)
    {
        $this->merchantId = $merchantId;
        $this->password = $password;
        $this->endpoint = $endpoint;
        $this->sign = $sign;
        $this->generateLink = $generateLink;
        $this->paymentMethods = $paymentMethods;
        $this->hmacSHA256 = $hmacSHA256;
        $this->httpClient = $httpClient;
    }

    public static function fromConfig(array|\ArrayAccess $config, ?HttpClientInterface $httpClient = null): self
    {
        return new self(
            (string) ($config['merchant_id'] ?? ''),
            $config['password'] ?? throw new \InvalidArgumentException('Since 2.0.0 The "password" config option is required. To get the password, login to the dashboard (https://admin.sandbox.datatrans.com/) and navigate to the security settings under UPP Administration > Security.'),
            (string) ($config['endpoint'] ?? ''),
            (string) ($config['sign'] ?? ''),
            (bool) ($config['generate_link'] ?? false),
            (array) ($config['payment_methods'] ?? []),
            (bool) ($config['hmac_sha256'] ?? false),
            $httpClient,
        );
    }

    public static function isCapturedStatus(?string $status): bool
    {
        return in_array($status, self::CAPTURED_STATUSES, true);
    }

    public static function isAuthorizedStatus(?string $status): bool
    {
        return $status === self::AUTHORIZED_STATUS;
    }

    public static function isPendingStatus(?string $status): bool
    {
        return in_array($status, self::PENDING_STATUSES, true);
    }

    public static function getTransactionIds(array $details): array
    {
        $transactionIds = [];
        foreach (array_merge([$details['transactionId'] ?? null], (array) ($details['previousTransactionIds'] ?? [])) as $transactionId) {
            if (!empty($transactionId) && !in_array((string) $transactionId, $transactionIds, true)) {
                $transactionIds[] = (string) $transactionId;
            }
        }

        return $transactionIds;
    }

    public function getPostParams(SyliusPaymentInterface $payment, string $returnUrl): array
    {
        return [
            'autoSettle' => true,
            'language' => $this->getLanguage($payment),
            'currency' => $payment->getCurrencyCode(),
            'refno' => $payment->getOrder()->getNumber(),
            'amount' => $payment->getAmount(),
            'redirect' => [
                'successUrl' => $returnUrl,
                'cancelUrl' => $returnUrl,
                'errorUrl' => $returnUrl,
            ],
            'paymentMethods' => $this->getPaymentMethods(),
        ];
    }

    public function getEndpoint(): string
    {
        return rtrim($this->endpoint, '/');
    }

    public function getCredentials(): string
    {
        return base64_encode($this->getMerchantId() . ':' . $this->getPassword());
    }

    public function getPayUrl(string $transactionId): string
    {
        if (str_contains($this->getEndpoint(), 'sandbox')) {
            $pay = 'https://pay.sandbox.datatrans.com/v1/start/';
        } else {
            $pay = 'https://pay.datatrans.com/v1/start/';
        }
        return $pay . $transactionId;
    }

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getSign(): string
    {
        return $this->sign;
    }

    public function isHmacSHA256(): bool
    {
        return $this->hmacSHA256;
    }

    public function generateHmacSign(string $amount, string $currency, string $refNo): string
    {
        $hmac = $this->getMerchantId() . $amount . $currency . $refNo;
        return strtolower(bin2hex(hash_hmac('sha256', $hmac, pack('H*', $this->getSign()), true)));
    }

    public function isGenerateLink(): bool
    {
        return $this->generateLink;
    }

    public function getPaymentMethods(): array
    {
        return $this->paymentMethods;
    }

    public function createTransaction(SyliusPaymentInterface $payment, string $returnUrl): array
    {
        return $this->request('POST', '/v1/transactions', [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF-8',
            ],
            'body' => json_encode($this->getPostParams($payment, $returnUrl)),
        ]);
    }

    public function getTransaction(string $transactionId): array
    {
        return $this->request('GET', '/v1/transactions/' . rawurlencode($transactionId));
    }

    public function getTransactionStatus(string $transactionId): string
    {
        $response = $this->getTransaction($transactionId);
        if (!isset($response['status']) || !is_string($response['status'])) {
            throw new \RuntimeException(sprintf('Status of Datatrans transaction "%s" is unknown: %s', $transactionId, json_encode($response['error'] ?? $response)));
        }

        return $response['status'];
    }

    public function hasPaidTransaction(array $details): bool
    {
        foreach (self::getTransactionIds($details) as $transactionId) {
            $status = $this->getTransactionStatus($transactionId);
            if (self::isCapturedStatus($status) || self::isAuthorizedStatus($status)) {
                return true;
            }
        }

        return false;
    }

    public function initTransaction(SyliusPaymentInterface $payment, string $returnUrl): ?string
    {
        $details = $payment->getDetails();
        $previousTransactionIds = (array) ($details['previousTransactionIds'] ?? []);
        $previousTransactionIds[] = $details['transactionId'] ?? null;
        $previousTransactionIds = array_values(array_unique(array_map('strval', array_filter($previousTransactionIds))));
        unset($details['transactionId'], $details['previousTransactionIds'], $details['error'], $details['datatrans']);
        if ($previousTransactionIds !== []) {
            $details['previousTransactionIds'] = $previousTransactionIds;
        }

        try {
            $response = $this->createTransaction($payment, $returnUrl);
        } catch (ExceptionInterface $exception) {
            $response = ['error' => ['message' => $exception->getMessage()]];
        }

        if (!isset($response['transactionId'])) {
            $details['error'] = $response['error'] ?? $response;
            $payment->setDetails($details);

            return null;
        }
        $payment->setDetails(array_merge($details, $response));

        return (string) $response['transactionId'];
    }

    private function getLanguage(SyliusPaymentInterface $payment): string
    {
        $language = strtolower(substr((string) $payment->getOrder()?->getLocaleCode(), 0, 2));

        return in_array($language, self::LANGUAGES, true) ? $language : self::DEFAULT_LANGUAGE;
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $options['base_uri'] = $this->getEndpoint();
        $options['headers']['Authorization'] = 'Basic ' . $this->getCredentials();

        return $this->getHttpClient()->request($method, $path, $options)->toArray(false);
    }

    private function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient ??= HttpClient::create();
    }
}
