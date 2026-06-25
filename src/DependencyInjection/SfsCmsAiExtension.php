<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\DependencyInjection;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function dirname;

class SfsCmsAiExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('sfs_cms_ai.seo_analysis.platform', $config['seo_analysis']['platform']);
        $container->setParameter('sfs_cms_ai.seo_analysis.model', $config['seo_analysis']['model']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config/services'));
        $loader->load('services.yaml');

        if ($this->isCmsSeoPluginAvailable()) {
            $loader->load('seo.yaml');
        }
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

    private function isCmsSeoPluginAvailable(): bool
    {
        return interface_exists('Softspring\\CmsSeoPlugin\\Content\\Check\\CheckInterface');
    }
}
