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

class DatatransApi
{
    /**
     * @var string
     */
    private $merchantId;

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

    public function __construct(string $merchantId, string $endpoint, string $sign, bool $generateLink, array $paymentMethods, bool $hmacSHA256)
    {
        $this->merchantId = $merchantId;
        $this->endpoint = $endpoint;
        $this->sign = $sign;
        $this->generateLink = $generateLink;
        $this->paymentMethods = $paymentMethods;
        $this->hmacSHA256 = $hmacSHA256;
    }

    public function getPostParams(SyliusPaymentInterface $payment, string $returnUrl): array
    {
        $sign = $this->getSign();
        if ($this->isHmacSHA256()) {
            $sign = $this->generateHmacSign((string) $payment->getAmount(), $payment->getCurrencyCode(), $payment->getOrder()->getNumber());
        }
        return [
            'merchantId' => $this->getMerchantId(),
            'refno' => $payment->getOrder()->getNumber(),
            'amount' => $payment->getAmount(),
            'currency' => 'CHF',
            'sign' => $sign,
            'successUrl' => $returnUrl,
            'cancelUrl' => $returnUrl,
            'errorUrl' => $returnUrl,
        ];
    }

    public function getEndpoint(): string
    {
        return $this->endpoint.'?'.implode('&', array_map(function ($m) {
            return 'paymentmethod='.$m;
        }, $this->getPaymentMethods()));
    }

    public function getMerchantId(): string
    {
        return $this->merchantId;
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
        return hash_hmac('sha256', $hmac, pack('H*', $this->getSign()));
    }

    public function isGenerateLink(): bool
    {
        return $this->generateLink;
    }

    public function getPaymentMethods(): array
    {
        return $this->paymentMethods;
    }
}
