<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Lab;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use RuntimeException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool as PlatformTool;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

class McpChatLab
{
    protected const MAX_TOOL_ROUNDS = 6;
    protected const CMS_TOOL_PREFIX = 'sfs_cms_';

    public function __construct(
        protected RegistryInterface $registry,
        protected Server $server,
        protected ServiceLocator $platforms,
        protected ServiceLocator $mcpToolServices,
        protected RequestStack $requestStack,
    ) {
    }

    public function getPlatforms(): array
    {
        $platforms = array_keys($this->platforms->getProvidedServices());

        return array_combine($platforms, $platforms);
    }

    public function getModels(?string $platformName): array
    {
        if (!$platformName) {
            return [];
        }

        $platform = $this->getPlatform($platformName);
        $models = array_keys($platform->getModelCatalog()->getModels());

        sort($models);

        return array_combine($models, $models);
    }

    public function getAvailableTools(): array
    {
        $tools = [];

        foreach ($this->registry->getTools()->references as $tool) {
            if (!str_starts_with($tool->name, self::CMS_TOOL_PREFIX)) {
                continue;
            }

            $tools[$tool->name] = [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->inputSchema,
            ];
        }

        ksort($tools);

        return $tools;
    }

    public function ask(string $question, ?string $model, ?string $platformName, array $history = []): array
    {
        if (!$model) {
            throw new RuntimeException('A model is required to test the MCP chatbot.');
        }

        $locale = $this->requestStack->getCurrentRequest()?->attributes->get('_locale')
            ?: $this->requestStack->getCurrentRequest()?->getLocale()
            ?: 'es';
        $platform = $this->getPlatform($platformName);
        $tools = $this->createPlatformTools();

        if ([] === $tools) {
            throw new RuntimeException('No CMS MCP tools are registered.');
        }

        $messages = new MessageBag();
        $messages->add(Message::forSystem(<<<PROMPT
You are a CMS assistant used to test MCP tools.
Current admin locale: {$locale}
Answer questions about the existing CMS configuration, content, sites, layouts, modules, blocks, menus, internal links and site analytics.
Use the available read-only MCP tools when the answer needs CMS data.
Registered CMS tool names use the "sfs_cms_" prefix and are grouped by domain, for example "sfs_cms_sites_get_context", "sfs_cms_sites_list", "sfs_cms_routes_find_internal_links" and "sfs_cms_analytics_query_pages". Use those exact tool names and never call old "cms_" or legacy ungrouped tool names.
Do not invent CMS data. If the tools return an error or no data, say so clearly.
When the user asks for site traffic, visits, pageviews, bounce rate, time on page or aggregate statistics, use the CMS site analytics metrics tool.
When the user asks for page metrics, most or least read articles, top visited pages, popular content, content rankings or path patterns, use the CMS analytics page query tool.
When the user asks what the CMS project can do or how it is configured, use the CMS configuration context tool.
When the user asks for an admin/edit/details/preview link for content, use the current admin locale "{$locale}" as the locale argument when calling CMS content tools.
Return only one Markdown clickable link for that locale. Use adminUrls.content for edit/admin edition links, adminUrls.details for details, and adminUrls.preview for preview.
Do not list links for every available content locale. Do not invent admin routes or transform public URLs manually.
When a media image search result includes previewMarkdown, include that Markdown in the final answer to show the thumbnail preview and link to the media admin page.
Do not display raw media ids, raw URLs, sha1 hashes, or full media version lists unless the user asks for technical details.
For editorial review tasks, gather the site context and the relevant published content, then answer with: fit assessment, mismatches, and suggested replacement copy.
Prefer a useful final answer over repeatedly requesting more tools. If enough evidence is available, stop calling tools and answer.
Keep the final answer concise and useful for an editor.
PROMPT));

        foreach ($history as $message) {
            if (!is_array($message) || !is_string($message['content'] ?? null)) {
                continue;
            }

            if ('user' === ($message['role'] ?? null)) {
                $messages->add(Message::ofUser($message['content']));
                continue;
            }

            if ('assistant' === ($message['role'] ?? null)) {
                $messages->add(Message::ofAssistant($message['content']));
            }
        }

        $messages->add(Message::ofUser($question));

        $toolCalls = [];
        $result = null;
        $answer = 'The model did not return a response.';
        $startedAt = microtime(true);
        $durationMs = 0;
        $tokenUsage = null;

        try {
            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; ++$round) {
                $result = $platform->invoke($model, $messages, ['tools' => array_values($tools)])->getResult();
                $tokenUsage = $this->mergeTokenUsage($tokenUsage, $this->extractTokenUsage($result));

                if (!$result instanceof ToolCallResult) {
                    break;
                }

                $calls = $result->getContent();
                $messages->add(Message::ofAssistant($result));

                foreach ($calls as $call) {
                    $toolResult = $this->executeToolCall($call);
                    $toolCalls[] = $toolResult;
                    $messages->add(Message::ofToolCall($call, $toolResult['content']));
                }
            }

            if ($result instanceof ToolCallResult) {
                $messages->add(Message::ofUser(<<<PROMPT
Stop calling tools now. Use only the tool results already provided in this conversation.
Write the best final answer you can. If the available data is incomplete, say exactly what is missing and still provide the most useful editorial recommendation possible.
PROMPT));

                $result = $platform->invoke($model, $messages)->getResult();
                $tokenUsage = $this->mergeTokenUsage($tokenUsage, $this->extractTokenUsage($result));
            }

            $answer = $result ? $this->resultToText($result) : $answer;
        } finally {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->resetTraceablePlatform($platform);
        }

        return [
            'answer' => $answer,
            'tool_calls' => $toolCalls,
            'tools' => $this->getAvailableTools(),
            'metrics' => [
                'durationMs' => $durationMs,
                'tokens' => $tokenUsage,
            ],
        ];
    }

    protected function createPlatformTools(): array
    {
        $tools = [];

        foreach ($this->registry->getTools()->references as $tool) {
            if (!str_starts_with($tool->name, self::CMS_TOOL_PREFIX)) {
                continue;
            }

            $reference = $this->registry->getTool($tool->name);
            $handler = $reference->handler;

            if (!is_array($handler) || !is_string($handler[0]) || !is_string($handler[1])) {
                continue;
            }

            $tools[$tool->name] = new PlatformTool(
                new ExecutionReference($handler[0], $handler[1]),
                $tool->name,
                $tool->description ?? 'CMS MCP tool',
                $tool->inputSchema,
            );
        }

        return $tools;
    }

    protected function executeToolCall(ToolCall $toolCall): array
    {
        $toolName = $this->normalizeToolName($toolCall->getName());
        $handler = new ReferenceHandler($this->mcpToolServices);
        $arguments = $toolCall->getArguments();
        $arguments['_session'] = new Session(new InMemorySessionStore());

        try {
            $reference = $this->registry->getTool($toolName);
            $result = $handler->handle($reference, $arguments);
            $content = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return [
                'id' => $toolCall->getId(),
                'name' => $toolName,
                'arguments' => $toolCall->getArguments(),
                'content' => $content,
                'error' => null,
            ];
        } catch (Throwable $e) {
            $content = json_encode([
                'error' => $e->getMessage(),
                'type' => $e::class,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return [
                'id' => $toolCall->getId(),
                'name' => $toolName,
                'arguments' => $toolCall->getArguments(),
                'content' => $content,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function resultToText(object $result): string
    {
        return match (true) {
            $result instanceof TextResult => $result->getContent(),
            $result instanceof ObjectResult => json_encode($result->getContent(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            $result instanceof ThinkingResult => '',
            $result instanceof MultiPartResult => implode('', array_map(
                fn (ResultInterface $part): string => $part instanceof ToolCallResult ? '' : $this->resultToText($part),
                $result->getContent(),
            )),
            $result instanceof ToolCallResult => 'The model could not produce a final answer after using the available MCP tools.',
            default => sprintf('Unsupported AI result type "%s".', $result::class),
        };
    }

    protected function normalizeToolName(string $toolName): string
    {
        if (str_starts_with($toolName, 'cms_')) {
            $toolName = 'sfs_'.$toolName;
        }

        return $this->legacyToolNameMap()[$toolName] ?? $toolName;
    }

    /**
     * @return array<string, string>
     */
    protected function legacyToolNameMap(): array
    {
        return [
            'sfs_cms_get_site_context' => 'sfs_cms_sites_get_context',
            'sfs_cms_get_configuration_context' => 'sfs_cms_configuration_get_context',
            'sfs_cms_get_site_analytics' => 'sfs_cms_analytics_get_site_metrics',
            'sfs_cms_get_top_content' => 'sfs_cms_analytics_query_pages',
            'sfs_cms_search_published_content' => 'sfs_cms_contents_search_published',
            'sfs_cms_get_published_content' => 'sfs_cms_contents_get_published',
            'sfs_cms_find_internal_links' => 'sfs_cms_routes_find_internal_links',
            'sfs_cms_get_menu_context' => 'sfs_cms_menus_get_context',
            'sfs_cms_media_list_image_types' => 'sfs_cms_media_images_list_types',
            'sfs_cms_media_search_images' => 'sfs_cms_media_images_search',
            'sfs_cms_media_get_image_context' => 'sfs_cms_media_images_get_context',
            'sfs_cms_analytics_get_top_content' => 'sfs_cms_analytics_query_pages',
        ];
    }

    protected function extractTokenUsage(?ResultInterface $result): ?array
    {
        if (!$result) {
            return null;
        }

        $usage = $result->getMetadata()->get('token_usage');

        if (!$usage instanceof TokenUsageInterface) {
            return null;
        }

        $tokens = [
            'prompt' => $usage->getPromptTokens(),
            'completion' => $usage->getCompletionTokens(),
            'thinking' => $usage->getThinkingTokens(),
            'tool' => $usage->getToolTokens(),
            'cached' => $usage->getCachedTokens(),
            'cacheCreation' => $usage->getCacheCreationTokens(),
            'cacheRead' => $usage->getCacheReadTokens(),
            'remaining' => $usage->getRemainingTokens(),
            'remainingMinute' => $usage->getRemainingTokensMinute(),
            'remainingMonth' => $usage->getRemainingTokensMonth(),
            'total' => $usage->getTotalTokens(),
        ];

        if (null === $tokens['total']) {
            $summedTokens = array_sum(array_filter([
                $tokens['prompt'],
                $tokens['completion'],
                $tokens['thinking'],
                $tokens['tool'],
            ], static fn (?int $value): bool => null !== $value));

            $tokens['total'] = $summedTokens > 0 ? $summedTokens : null;
        }

        return $tokens;
    }

    protected function mergeTokenUsage(?array $total, ?array $usage): ?array
    {
        if (!$usage) {
            return $total;
        }

        if (!$total) {
            return $usage;
        }

        foreach ($usage as $key => $value) {
            if (null === $value) {
                continue;
            }

            if (str_starts_with($key, 'remaining')) {
                $total[$key] = null === ($total[$key] ?? null) ? $value : min((int) $total[$key], $value);
                continue;
            }

            $total[$key] = (int) ($total[$key] ?? 0) + $value;
        }

        return $total;
    }

    protected function getPlatform(?string $platformName): PlatformInterface
    {
        $platformName = $platformName ?: array_key_first($this->platforms->getProvidedServices());

        if (!$platformName || !$this->platforms->has($platformName)) {
            throw new RuntimeException('No AI platform is configured for the MCP chatbot.');
        }

        $platform = $this->platforms->get($platformName);

        if (!$platform instanceof PlatformInterface) {
            throw new RuntimeException(sprintf('Service "%s" is not a valid AI platform.', $platformName));
        }

        return $platform;
    }

    protected function resetTraceablePlatform(PlatformInterface $platform): void
    {
        if ($platform instanceof ResetInterface) {
            $platform->reset();
        }
    }
}
