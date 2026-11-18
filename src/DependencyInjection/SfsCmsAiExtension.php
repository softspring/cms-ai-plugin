<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\DependencyInjection;

use Softspring\CmsAiPlugin\Instructions\AiInstructions;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function dirname;
use function in_array;

class SfsCmsAiExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('sfs_cms_ai.assistant_models', $config['assistant_models']);
        $container->setParameter('sfs_cms_ai.http_client.timeout', $config['http_client']['timeout']);
        $container->setParameter('sfs_cms_ai.http_client.max_duration', $config['http_client']['max_duration']);
        $container->setParameter('sfs_cms_ai.content_editor.enabled', $config['content_editor']['enabled']);
        $container->setParameter('sfs_cms_ai.content_editor.max_execution_time', $config['content_editor']['max_execution_time']);
        $container->setParameter('sfs_cms_ai.seo_analysis.platform', $config['seo_analysis']['platform']);
        $container->setParameter('sfs_cms_ai.seo_analysis.model', $config['seo_analysis']['model']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config/services'));
        $loader->load('services.yaml');

        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];
        if (in_array('Symfony\\AI\\McpBundle\\McpBundle', $bundles, true)) {
            $loader->load('mcp.yaml');
        }

        if ($this->isCmsSeoPluginAvailable()) {
            $loader->load('seo.yaml');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependAiHttpClient($container);
        if ($container->hasExtension('sfs_config')) {
            $container->prependExtensionConfig('sfs_config', [
                'defaults' => ['cms_ai.instructions' => ''],
                'groups' => ['cms' => [
                    'label' => 'groups.cms.label', 'description' => 'groups.cms.description',
                    'translation_domain' => 'sfs_config', 'priority' => 100,
                ]],
                'definitions' => ['cms_ai.instructions' => [
                    'type' => 'text', 'label' => 'cms_ai.instructions.label',
                    'group' => 'cms', 'help' => 'cms_ai.instructions.help',
                    'permissions' => ['update' => AiInstructions::MANAGE_PERMISSION, 'delete' => AiInstructions::MANAGE_PERMISSION],
                ]],
            ]);
        }

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

    private function prependAiHttpClient(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('ai')) {
            return;
        }

        $platforms = [];
        foreach ($container->getExtensionConfig('ai') as $config) {
            foreach (array_keys($config['platform'] ?? []) as $platformName) {
                if (!in_array($platformName, $this->supportedAiHttpClientPlatforms(), true)) {
                    continue;
                }

                $platforms[$platformName] = [
                    'http_client' => 'sfs_cms_ai.http_client',
                ];
            }
        }

        if ([] === $platforms) {
            return;
        }

        $container->prependExtensionConfig('ai', [
            'platform' => $platforms,
        ]);
    }

    private function isCmsSeoPluginAvailable(): bool
    {
        return interface_exists('Softspring\\CmsSeoPlugin\\Content\\Check\\CheckInterface');
    }

    /**
     * @return string[]
     */
    private function supportedAiHttpClientPlatforms(): array
    {
        return [
            'albert',
            'anthropic',
            'azure',
            'cartesia',
            'cerebras',
            'cohere',
            'deepseek',
            'dockermodelrunner',
            'elevenlabs',
            'gemini',
            'generic',
            'huggingface',
            'lmstudio',
            'mistral',
            'ollama',
            'openai',
            'openresponses',
            'openrouter',
            'ovh',
            'perplexity',
            'scaleway',
            'vertexai',
            'voyage',
        ];
    }
}
