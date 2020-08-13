<?php
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

namespace Whatwedo\SyliusDatatransPaymentPlugin\Controller;

use Sylius\Component\Core\Model\Payment;
use Doctrine\Persistence\ManagerRegistry;
use Payum\Core\Reply\HttpPostRedirect;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransApi;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\DatatransPaymentGatewayFactory;

class PayFromLinkController extends AbstractController
{

    /**
     * @var ManagerRegistry
     */
    protected $doctrine;

    public function __construct(ManagerRegistry $registry)
    {
        $this->doctrine = $registry;
    }

    public function payFromLinkAction($payment)
    {
        $payment = $this->doctrine->getRepository(Payment::class)->find($payment);
        if (!$payment) {
            throw new NotFoundHttpException();
        }
        $details = $payment->getDetails();
        if (!isset($details['payment-link'])) {
            throw new NotFoundHttpException();
        }
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $payment->getMethod();
        if (!$paymentMethod->getGatewayConfig()->getFactoryName() === DatatransPaymentGatewayFactory::FACTORY_NAME) {
            throw new NotFoundHttpException();
        }
        $config = $paymentMethod->getGatewayConfig()->getConfig();
        $api = new DatatransApi(
            $config['merchant_id'],
            $config['endpoint'],
            $config['sign'],
            $config['generate_link'],
            $config['payment_methods']
        );
        if (!$api->isGenerateLink()) {
            throw new NotFoundHttpException();
        }
        throw new HttpPostRedirect(
            $api->getEndpoint(),
            $api->getPostParams($payment, $details['payment-link'])
        );
    }

}
