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
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Reply\HttpPostRedirect;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Sylius\Component\Core\Model\PaymentInterface as SyliusPaymentInterface;
use Symfony\Component\HttpClient\HttpClient;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransApi;

class CaptureAction implements ActionInterface, ApiAwareInterface
{
    /**
     * @var DatatransApi
     */
    private $api;

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        /** @var SyliusPaymentInterface $payment */
        $payment = $request->getModel();
        $returnUrl = $request->getToken()->getAfterUrl();

        $webClient = HttpClient::createForBaseUri($this->api->getEndpoint());

        $response = $webClient->request('POST', '/v1/transactions', [
            'headers' => [
                'Authorization' => 'Basic ' . $this->api->getCredentials(),
                'Content-Type' => 'application/json; charset=UTF-8',
            ],
            'body' => json_encode($this->api->getPostParams($payment, $returnUrl)),
        ]);
        $responseData = $response->toArray(false);
        if (isset($responseData['transactionId'])) {
            $payment->setDetails($responseData);
            if (!$this->api->isGenerateLink()) {
                throw new HttpRedirect($this->api->getPayUrl($responseData['transactionId']));
            }
        }
    }

    public function supports($request)
    {
        return $request instanceof Capture &&
            $request->getModel() instanceof SyliusPaymentInterface
        ;
    }

    public function setApi($api): void
    {
        if (!$api instanceof DatatransApi) {
            throw new UnsupportedApiException('Not supported. Expected an instance of ' . DatatransApi::class);
        }
        $this->api = $api;
    }
}
