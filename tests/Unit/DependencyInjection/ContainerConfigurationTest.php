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

namespace Tests\Whatwedo\SyliusDatatransPaymentPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Whatwedo\SyliusDatatransPaymentPlugin\Command\CheckForDatatransPaymentStatusCommand;
use Whatwedo\SyliusDatatransPaymentPlugin\Controller\PayFromLinkController;
use Whatwedo\SyliusDatatransPaymentPlugin\DependencyInjection\Compiler\StateMachinePass;
use Whatwedo\SyliusDatatransPaymentPlugin\DependencyInjection\WhatwedoSyliusDatatransPaymentExtension;
use Whatwedo\SyliusDatatransPaymentPlugin\Form\Type\DatatransPaymentGatewayConfigurationType;
use Whatwedo\SyliusDatatransPaymentPlugin\Payum\Action\CaptureAction;
use Whatwedo\SyliusDatatransPaymentPlugin\WhatwedoSyliusDatatransPaymentPlugin;

final class ContainerConfigurationTest extends TestCase
{
    public function testServicesAreRegistered(): void
    {
        $container = $this->loadContainer();

        self::assertSame(
            [['factory' => 'datatrans_payment']],
            $container->getDefinition('whatwedo.datatrans_payment')->getTag('payum.gateway_factory_builder'),
        );
        self::assertSame(
            [['factory' => 'datatrans_payment', 'alias' => 'payum.action.capture']],
            $container->getDefinition(CaptureAction::class)->getTag('payum.action'),
        );
        self::assertSame(
            [['type' => 'datatrans_payment', 'label' => 'Datatrans Payment']],
            $container->getDefinition(DatatransPaymentGatewayConfigurationType::class)->getTag('sylius.gateway_configuration_type'),
        );
        $controller = $container->getDefinition(PayFromLinkController::class);
        self::assertTrue($controller->hasTag('controller.service_arguments'));
        self::assertTrue($controller->hasTag('container.service_subscriber'));
        self::assertTrue($container->getDefinition(CheckForDatatransPaymentStatusCommand::class)->hasTag('console.command'));
        self::assertSame('SM\Factory\Factory', (string) $container->getDefinition(CheckForDatatransPaymentStatusCommand::class)->getArgument(1));
    }

    public function testStateMachinePassUsesSyliusAbstractionWhenAvailable(): void
    {
        $container = $this->loadContainer();
        $container->setDefinition('sylius_abstraction.state_machine', new Definition(\stdClass::class));

        (new StateMachinePass())->process($container);

        $argument = $container->getDefinition(CheckForDatatransPaymentStatusCommand::class)->getArgument(1);
        self::assertInstanceOf(Reference::class, $argument);
        self::assertSame('sylius_abstraction.state_machine', (string) $argument);
    }

    public function testStateMachinePassKeepsWinzouOtherwise(): void
    {
        $container = $this->loadContainer();

        (new StateMachinePass())->process($container);

        self::assertSame('SM\Factory\Factory', (string) $container->getDefinition(CheckForDatatransPaymentStatusCommand::class)->getArgument(1));
    }

    public function testPluginRegistersStateMachinePass(): void
    {
        $container = new ContainerBuilder();

        (new WhatwedoSyliusDatatransPaymentPlugin())->build($container);

        $passes = array_filter(
            $container->getCompilerPassConfig()->getBeforeOptimizationPasses(),
            fn ($pass) => $pass instanceof StateMachinePass,
        );
        self::assertCount(1, $passes);
    }

    private function loadContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new WhatwedoSyliusDatatransPaymentExtension())->load([], $container);

        return $container;
    }
}
