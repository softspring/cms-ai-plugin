<?php

namespace Softspring\CmsAiPlugin\Tests\Lab;

use PHPUnit\Framework\TestCase;
use Softspring\CmsAiPlugin\DependencyInjection\Configuration;
use Softspring\CmsAiPlugin\Lab\ChatModelChoices;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Config\Definition\Processor;

class ChatModelChoicesTest extends TestCase
{
    public function testModelsAreFilteredByAssistantCapabilities(): void
    {
        $chat = [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::TOOL_CALLING];
        $models = [
            'chatgpt-image-latest' => new Model('chatgpt-image-latest', [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::OUTPUT_IMAGE]),
            'embedding' => new Model('embedding', [Capability::INPUT_TEXT, Capability::EMBEDDINGS]),
            'speech' => new Model('speech', [Capability::INPUT_TEXT, Capability::OUTPUT_AUDIO]),
            'chat-without-tools' => new Model('chat-without-tools', [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT]),
            'tool-chat' => new Model('tool-chat', $chat),
            'structured-chat' => new Model('structured-chat', [...$chat, Capability::OUTPUT_STRUCTURED]),
        ];
        $catalog = $this->createStub(ModelCatalogInterface::class);
        $catalog->method('getModels')->willReturn(array_fill_keys(array_keys($models), []));
        $catalog->method('getModel')->willReturnCallback(static fn (string $name): Model => $models[$name]);
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getModelCatalog')->willReturn($catalog);
        $platform->expects(self::never())->method('invoke');

        $choices = new ChatModelChoices(['custom' => ['tool-chat', 'structured-chat', 'chatgpt-image-latest', 'embedding', 'speech', 'chat-without-tools']]);

        self::assertSame(['tool-chat' => 'tool-chat', 'structured-chat' => 'structured-chat'], $choices->forPlatform($platform, 'custom'));
        self::assertSame(['structured-chat' => 'structured-chat'], $choices->forPlatform($platform, 'custom', true));
    }

    public function testOlderModelsRequireExplicitConfigurationAndConfiguredOrderIsPreserved(): void
    {
        $capabilities = [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::TOOL_CALLING, Capability::OUTPUT_STRUCTURED];
        $models = [];
        foreach (['gpt-4.1', 'gpt-5', 'gpt-6.1-sol', 'gpt-6-luna'] as $name) {
            $models[$name] = new Model($name, $capabilities);
        }
        $catalog = $this->createStub(ModelCatalogInterface::class);
        $catalog->method('getModels')->willReturn(array_fill_keys(array_keys($models), []));
        $catalog->method('getModel')->willReturnCallback(static fn (string $name): Model => $models[$name]);
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getModelCatalog')->willReturn($catalog);
        $platform->expects(self::never())->method('invoke');

        self::assertSame(['gpt-6.1-sol', 'gpt-6-luna'], array_keys(new ChatModelChoices()->forPlatform($platform, 'openai', true)));
        self::assertSame(['gpt-4.1', 'gpt-6.1-sol'], array_keys(new ChatModelChoices(['openai' => ['gpt-4.1', 'missing', 'gpt-6.1-sol']])->forPlatform($platform, 'openai', true)));
        self::assertSame([], new ChatModelChoices(['openai' => []])->forPlatform($platform, 'openai'));
        self::assertSame([], new ChatModelChoices()->forPlatform($platform, 'custom'));
    }

    public function testOnlyOldModelsInTheCatalogDoNotEnableAnAutomaticFallback(): void
    {
        $catalog = $this->createStub(ModelCatalogInterface::class);
        $catalog->method('getModels')->willReturn(['gpt-4.1' => []]);
        $platform = $this->createStub(PlatformInterface::class);
        $platform->method('getModelCatalog')->willReturn($catalog);
        self::assertSame([], new ChatModelChoices()->forPlatform($platform, 'openai'));
    }

    public function testConfigurationReplacesEachProviderListWithoutLosingOtherProviders(): void
    {
        $configuration = new Configuration();
        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [
            ['assistant_models' => ['openai' => ['gpt-4.1', 'gpt-6.1-sol']]],
            ['assistant_models' => ['openai' => ['gpt-6-luna'], 'custom' => ['my-model']]],
        ]);
        self::assertSame(['gpt-6-luna'], $config['assistant_models']['openai']);
        self::assertSame(ChatModelChoices::DEFAULT_MODELS['gemini'], $config['assistant_models']['gemini']);
        self::assertSame(['my-model'], $config['assistant_models']['custom']);
    }
}
