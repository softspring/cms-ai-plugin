<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use function dirname;

class SfsCmsAiExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config/services'));
        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (interface_exists(AssetMapperInterface::class)) {
            $container->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        dirname(__DIR__, 2).'/assets/dist' => '@softspring/cms-ai-plugin',
                    ],
                ],
            ]);
        }
    }
}
