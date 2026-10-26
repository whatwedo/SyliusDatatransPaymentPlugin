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

namespace Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\Form\Type;

use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;
use Whatwedo\SyliusDatatransPaymentPlugin\Form\Type\DatatransPaymentGatewayConfigurationType;

final class DatatransPaymentGatewayConfigurationTypeTest extends TypeTestCase
{
    public function testStoredPasswordIsKeptWhenSubmittedEmpty(): void
    {
        $form = $this->factory->create(DatatransPaymentGatewayConfigurationType::class, self::config(), ['validation_groups' => ['sylius']]);

        $form->submit(['merchant_id' => '1100000000', 'password' => '', 'endpoint' => 'https://api.datatrans.com', 'payment_methods' => ['TWI']]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertSame('secret', $form->getData()['password']);
        self::assertSame('https://api.datatrans.com', $form->getData()['endpoint']);
        self::assertSame('', $form->createView()['password']->vars['value']);
    }

    public function testMissingPasswordIsInvalid(): void
    {
        $form = $this->factory->create(DatatransPaymentGatewayConfigurationType::class, null, ['validation_groups' => ['sylius']]);

        $form->submit(['merchant_id' => '1100000000', 'password' => '', 'endpoint' => 'https://api.datatrans.com']);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('password')->getErrors());
    }

    public function testTypedPasswordSurvivesReRender(): void
    {
        $form = $this->factory->create(DatatransPaymentGatewayConfigurationType::class, null, ['validation_groups' => ['sylius']]);

        $form->submit(['merchant_id' => '1100000000', 'password' => 'typed-secret', 'endpoint' => 'https://api.datatrans.com']);

        self::assertTrue($form->isValid());
        self::assertSame('typed-secret', $form->getData()['password']);
        self::assertSame('typed-secret', $form->createView()['password']->vars['value']);
    }

    public function testSignIsOptional(): void
    {
        $view = $this->factory->create(DatatransPaymentGatewayConfigurationType::class)->createView();

        self::assertFalse($view['sign']->vars['required']);
    }

    public function testPasswordCanBeChanged(): void
    {
        $form = $this->factory->create(DatatransPaymentGatewayConfigurationType::class, self::config());

        $form->submit(['merchant_id' => '1100000000', 'password' => 'new-secret', 'endpoint' => 'https://api.datatrans.com']);

        self::assertSame('new-secret', $form->getData()['password']);
    }

    public function testPasswordIsNotRendered(): void
    {
        $view = $this->factory->create(DatatransPaymentGatewayConfigurationType::class, self::config())->createView();

        self::assertSame('', $view['password']->vars['value']);
        self::assertContains('password', $view['password']->vars['block_prefixes']);
    }

    public function testPaymentMethodChoices(): void
    {
        $view = $this->factory->create(DatatransPaymentGatewayConfigurationType::class)->createView();
        $choices = array_map(fn ($choice) => $choice->value, $view['payment_methods']->vars['choices']);

        foreach (['PFP', 'PFC', 'PEF', 'TWI', 'VIS', 'ECA'] as $method) {
            self::assertContains($method, $choices);
        }
    }

    protected function getExtensions(): array
    {
        return [new ValidatorExtension(Validation::createValidator())];
    }

    private static function config(): array
    {
        return [
            'merchant_id' => '1100000000',
            'password' => 'secret',
            'endpoint' => 'https://api.sandbox.datatrans.com',
            'sign' => null,
            'hmac_sha256' => false,
            'generate_link' => false,
            'payment_methods' => ['VIS'],
        ];
    }
}
