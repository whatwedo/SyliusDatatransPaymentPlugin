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

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\Action\StatusAction;

class DatatransPaymentGatewayFactory extends GatewayFactory
{
    public const FACTORY_NAME = 'datatrans_payment';

    protected function populateConfig(ArrayObject $config): void
    {
        $api = $this->createDatatransApi($config);
        $config->defaults([
            'payum.factory_name' => self::FACTORY_NAME,
            'payum.factory_title' => 'Datatrans Payment',
            'payum.action.status' => new StatusAction($api->getEndpoint(), $api->getCredentials()),
        ]);

        $config['payum.api'] = function (ArrayObject $config) {
            return $this->createDatatransApi($config);
        };
    }

    private function createDatatransApi(ArrayObject $config): DatatransApi
    {
        return new DatatransApi(
            $config['merchant_id'],
            $config['password'] ?? throw new \InvalidArgumentException('Since 2.0.0 The "password" config option is required. To get the password, login to the dashboard (https://admin.sandbox.datatrans.com/) and navigate to the security settings under UPP Administration > Security.'),
            $config['endpoint'],
            empty($config['sign']) ? '' : $config['sign'],
            $config['generate_link'],
            $config['payment_methods'],
            $config['hmac_sha256'] ?? false,
        );
    }
}
