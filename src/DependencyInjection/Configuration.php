<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sfs_cms_ai');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('seo_analysis')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('platform')->defaultValue('gemini')->end()
                        ->scalarNode('model')->defaultValue('gemini-2.5-flash')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
