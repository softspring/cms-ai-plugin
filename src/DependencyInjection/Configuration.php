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
                ->arrayNode('http_client')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('timeout')->defaultValue(180.0)->end()
                        ->floatNode('max_duration')->defaultValue(240.0)->end()
                    ->end()
                ->end()
                ->arrayNode('content_editor')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_execution_time')->defaultValue(240)->end()
                    ->end()
                ->end()
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
