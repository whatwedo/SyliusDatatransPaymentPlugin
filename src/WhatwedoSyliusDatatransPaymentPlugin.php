<?php

declare(strict_types=1);

namespace Whatwedo\SyliusDatatransPaymentPlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Whatwedo\SyliusDatatransPaymentPlugin\DependencyInjection\Compiler\StateMachinePass;

final class WhatwedoSyliusDatatransPaymentPlugin extends Bundle
{
    use SyliusPluginTrait;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new StateMachinePass());
    }
}
