<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Softspring\CmsAiPlugin\Bridge\Mcp\McpToolProvider;
use Softspring\CmsAiPlugin\DependencyInjection\SfsCmsAiExtension;
use Softspring\CmsAiPlugin\Tool\NullToolProvider;
use Softspring\CmsAiPlugin\Tool\ToolProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class SfsCmsAiExtensionTest extends TestCase
{
    public function testMcpIsNotRequiredWhenTheBundleIsNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        (new SfsCmsAiExtension())->load([], $container);

        self::assertSame(NullToolProvider::class, (string) $container->getAlias(ToolProviderInterface::class));
        self::assertFalse($container->hasDefinition(McpToolProvider::class));
    }

    public function testRegisteredMcpBundleEnablesTheBridgeWithoutAnExtensionRegistry(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['McpBundle' => 'Symfony\AI\McpBundle\McpBundle']);
        (new SfsCmsAiExtension())->load([], $container);

        self::assertSame(McpToolProvider::class, (string) $container->getAlias(ToolProviderInterface::class));
        $arguments = $container->getDefinition(McpToolProvider::class)->getArguments();
        self::assertSame('mcp.server', (string) $arguments['$server']);
        self::assertSame('mcp.registry', (string) $arguments['$registry']);
    }
}
