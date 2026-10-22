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

namespace Whatwedo\SyliusDatatransPaymentPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

class DatatransPaymentGatewayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('merchant_id', TextType::class)
            ->add('password', PasswordType::class, [
                'always_empty' => false,
                'required' => false,
                'constraints' => [
                    new NotBlank(groups: ['sylius']),
                ],
            ])
            ->add('endpoint', TextType::class, [
                'constraints' => [
                    new NotBlank(groups: ['sylius']),
                    new Url(protocols: ['https'], groups: ['sylius']),
                ],
            ])
            ->add('sign', TextType::class, [
                'required' => false,
            ])
            ->add('hmac_sha256', CheckboxType::class, [
                'required' => false,
            ])
            ->add('generate_link', CheckboxType::class, [
                'required' => false,
            ])
            ->add('payment_methods', ChoiceType::class, [
                'choices' => [
                    'American Express' => 'AMX',
                    'China UnionPay' => 'CUP',
                    'Diners' => 'DIN',
                    'Discover' => 'DIS',
                    'JCB' => 'JCB',
                    'Mastercard' => 'ECA',
                    'Visa' => 'VIS',
                    'Amazon Pay' => 'AZP',
                    'Apple Pay' => 'APL',
                    'Google Pay' => 'PAY',
                    'PostFinance Card' => 'PFC',
                    'PostFinance E-Finance' => 'PEF',
                    'PostFinance Pay' => 'PFP',
                    'TWINT' => 'TWI',
                    'PayPal' => 'PAP',
                ],
                'expanded' => true,
                'multiple' => true,
            ])
        ;

        $storedPassword = null;
        $builder->get('password')->addModelTransformer(new CallbackTransformer(
            function ($password) use (&$storedPassword) {
                $storedPassword = $password;

                return $password;
            },
            function ($password) use (&$storedPassword) {
                return ($password ?? '') === '' ? $storedPassword : $password;
            },
        ));
    }
}
