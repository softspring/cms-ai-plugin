<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Tests\Lab;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Softspring\Bundle\ConfigBundle\Config\ConfigValue;
use Softspring\Bundle\ConfigBundle\Config\DefaultsConfigProvider;
use Softspring\CmsAiPlugin\Instructions\AiInstructions;
use Softspring\CmsAiPlugin\Lab\ChatModelChoices;
use Softspring\CmsAiPlugin\Lab\ContentEditorAgent;
use Softspring\CmsAiPlugin\Lab\ContentVersionPayloadContext;
use Softspring\CmsAiPlugin\Lab\McpChatLab;
use Softspring\CmsAiPlugin\Tool\ToolProviderInterface;
use Softspring\CmsApiPlugin\Application\Query\ElementInstructionsQuery;
use Softspring\CmsApiPlugin\Application\Query\SiteQuery;
use Softspring\CmsBundle\Config\CmsConfig;
use Softspring\MediaBundle\Type\MediaTypesCollection;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Role;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RequestStack;

class PromptMessageHierarchyTest extends TestCase
{
    public function testEditorReceivesConfiguredElementInstructionsBeforeInvokingTheProvider(): void
    {
        $model = new Model('editor-prompt-test', [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::TOOL_CALLING, Capability::OUTPUT_STRUCTURED]);
        $catalog = $this->createStub(ModelCatalogInterface::class);
        $catalog->method('getModels')->willReturn(['editor-prompt-test' => []]);
        $catalog->method('getModel')->willReturn($model);
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getModelCatalog')->willReturn($catalog);
        $platform->expects(self::once())->method('invoke')->willReturnCallback(static function ($name, $input): never {
            self::assertSame('editor-prompt-test', $name);
            $messages = $input->getMessages();
            self::assertStringContainsString('aiInstructions', (string) $messages[0]->getContent());
            self::assertStringContainsString('elementInstructions', $messages[1]->asText());
            self::assertStringContainsString('Prefer paragraph modules for ordinary text.', $messages[1]->asText());
            throw new RuntimeException('Editor instructions inspected; no provider request sent.');
        });
        $payload = $this->createStub(ContentVersionPayloadContext::class);
        $payload->method('getSchema')->willReturn(['type' => 'object', 'properties' => []]);
        $cms = $this->createStub(CmsConfig::class);
        $cms->method('getModules')->willReturn(['paragraph' => ['meta' => ['ai_instructions' => 'Prefer paragraph modules for ordinary text.']]]);
        $media = $this->createStub(MediaTypesCollection::class);
        $agent = new ContentEditorAgent($payload, $this->createStub(ToolProviderInterface::class), $this->createStub(SiteQuery::class),
            new ServiceLocator(['custom' => static fn (): MockObject => $platform]), new ChatModelChoices(['custom' => ['editor-prompt-test']]),
            new AiInstructions(new ConfigValue(new DefaultsConfigProvider([AiInstructions::KEY => '']), AiInstructions::KEY)),
            new ElementInstructionsQuery($cms, $media));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Editor instructions inspected; no provider request sent.');
        $agent->edit('Add an introduction.', 'editor-prompt-test', 'custom', ['contentType' => 'page', 'layout' => 'default']);
    }

    public function testApplicationAndProjectInstructionsPrecedeUserHistoryAndRequest(): void
    {
        $model = new Model('prompt-test', [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::TOOL_CALLING]);
        $catalog = $this->createStub(ModelCatalogInterface::class);
        $catalog->method('getModels')->willReturn(['prompt-test' => []]);
        $catalog->method('getModel')->willReturn($model);
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getModelCatalog')->willReturn($catalog);
        $platform->expects(self::once())->method('invoke')->willReturnCallback(static function ($name, $input): never {
            self::assertSame('prompt-test', $name);
            self::assertInstanceOf(MessageBag::class, $input);
            $messages = $input->getMessages();
            self::assertCount(4, $messages);
            self::assertInstanceOf(SystemMessage::class, $messages[0]);
            $system = (string) $messages[0]->getContent();
            self::assertStringContainsString("You are the AI plugin of Armonic, Softspring's content management system.", $system);
            self::assertStringContainsString('Current admin locale:', $system);
            self::assertStringContainsString('Prefer concise editorial summaries.', $system);
            self::assertLessThan(strpos($system, 'Prefer concise editorial summaries.'), strpos($system, 'Workflow requirements'));
            self::assertStringNotContainsString('Summarize the latest three articles.', $system);
            self::assertSame(Role::User, $messages[1]->getRole());
            self::assertSame(Role::Assistant, $messages[2]->getRole());
            self::assertInstanceOf(UserMessage::class, $messages[3]);
            self::assertSame('Summarize the latest three articles.', $messages[3]->asText());

            throw new RuntimeException('Prompt inspection complete; no provider request sent.');
        });
        $tools = $this->createMock(ToolProviderInterface::class);
        $tools->method('getTools')->willReturn(['sfs_cms_test' => new Tool(new ExecutionReference(self::class), 'sfs_cms_test', 'Synthetic tool for prompt inspection.')]);
        $tools->expects(self::never())->method('execute');
        $assistant = new McpChatLab(
            $tools, new ServiceLocator(['custom' => static fn (): MockObject => $platform]), new RequestStack(),
            new ChatModelChoices(['custom' => ['prompt-test']]),
            new AiInstructions(new ConfigValue(new DefaultsConfigProvider([AiInstructions::KEY => 'Prefer concise editorial summaries.']), AiInstructions::KEY)),
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt inspection complete; no provider request sent.');
        $assistant->ask('Summarize the latest three articles.', 'prompt-test', 'custom', [
            ['role' => 'user', 'content' => 'Previous question'],
            ['role' => 'assistant', 'content' => 'Previous answer'],
        ]);
    }
}
