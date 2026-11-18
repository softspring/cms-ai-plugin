<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\DependencyInjection;

use Softspring\CmsAiPlugin\Lab\ChatModelChoices;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sfs_cms_ai');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('assistant_models')
                    ->useAttributeAsKey('platform')
                    ->defaultValue(ChatModelChoices::DEFAULT_MODELS)
                    ->validate()
                        ->always(static fn (array $models): array => array_replace(ChatModelChoices::DEFAULT_MODELS, $models))
                    ->end()
                    ->arrayPrototype()
                        ->performNoDeepMerging()
                        ->scalarPrototype()->cannotBeEmpty()->end()
                    ->end()
                ->end()
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
                        ->booleanNode('enabled')->defaultTrue()->end()
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
