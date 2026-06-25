<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Lab;

use JsonException;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
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
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool as PlatformTool;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function is_array;
use function is_string;

class ContentEditorAgent
{
    public const SESSION_PLATFORM_KEY = 'sfs_cms_ai.content_editor.platform';
    public const SESSION_MODEL_KEY = 'sfs_cms_ai.content_editor.model';

    private const MAX_TOOL_ROUNDS = 5;
    private const MAX_HISTORY_MESSAGES = 20;
    private const CMS_TOOL_PREFIX = 'sfs_cms_';

    public function __construct(
        private readonly ContentVersionPayloadContext $payloadContext,
        private readonly RegistryInterface $registry,
        private readonly ServiceLocator $platforms,
        private readonly ServiceLocator $mcpToolServices,
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

    public function getModelsByPlatform(): array
    {
        $models = [];

        foreach (array_keys($this->getPlatforms()) as $platformName) {
            $models[$platformName] = $this->getModels($platformName);
        }

        return $models;
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

    public function buildConversationKey(string $contentType, string $contentId, ?string $baseVersionId, string $layout): string
    {
        return 'sfs_cms_ai.content_editor.history.'.sha1(implode('|', [
            $contentType,
            $contentId,
            $baseVersionId ?: 'new',
            $layout,
        ]));
    }

    public function normalizeHistory(mixed $history): array
    {
        if (!is_array($history)) {
            return [];
        }

        $messages = array_values(array_filter($history, static function ($message): bool {
            return is_array($message)
                && in_array($message['role'] ?? null, ['user', 'assistant'], true)
                && is_string($message['content'] ?? null)
                && '' !== trim($message['content']);
        }));

        return array_slice($messages, -self::MAX_HISTORY_MESSAGES);
    }

    public function edit(string $instruction, ?string $model, ?string $platformName, array $context, array $history = []): array
    {
        $instruction = trim($instruction);
        if ('' === $instruction) {
            throw new RuntimeException('An instruction is required.');
        }

        if (!$model) {
            throw new RuntimeException('A model is required for the content editor agent.');
        }

        $contentType = (string) ($context['contentType'] ?? '');
        $layout = (string) ($context['layout'] ?? '');
        $currentPayload = $this->normalizeVersionPayload($context['currentPayload'] ?? [], $layout);
        $schema = $this->payloadContext->getSchema($contentType, $layout);
        $siteContext = $this->getSelectedSiteContext($context['selectedSite'] ?? null);

        $platform = $this->getPlatform($platformName);
        $tools = $this->createPlatformTools();
        $instruction = $this->addSiteAiContextInstruction($instruction, $context, $siteContext);

        $messages = new MessageBag();
        $messages->add(Message::forSystem($this->getSystemPrompt()));

        foreach ($this->normalizeHistory($history) as $message) {
            if ('user' === $message['role']) {
                $messages->add(Message::ofUser($message['content']));
                continue;
            }

            $messages->add(Message::ofAssistant($message['content']));
        }

        $messages->add(Message::ofUser(sprintf(<<<PROMPT
Editor instruction:
%s

Current edit context:
%s

JSON schema for the version payload:
%s
PROMPT,
            $instruction,
            $this->encodeJson([
                'contentType' => $contentType,
                'contentId' => $context['contentId'] ?? null,
                'contentName' => $context['contentName'] ?? null,
                'layout' => $layout,
                'baseVersionId' => $context['baseVersionId'] ?? null,
                'baseVersionNumber' => $context['baseVersionNumber'] ?? null,
                'selectedLocale' => $context['selectedLocale'] ?? null,
                'selectedSite' => $context['selectedSite'] ?? null,
                'locales' => $context['locales'] ?? [],
                'sites' => $context['sites'] ?? [],
                'selectedSiteContext' => $siteContext,
                'currentPayload' => $currentPayload,
            ]),
            $this->encodeJson($schema),
        )));

        $toolCalls = [];
        $result = null;
        $pendingToolCalls = [];
        $responseFormat = $this->createAgentResponseFormat();

        try {
            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; ++$round) {
                $options = [
                    PlatformSubscriber::RESPONSE_FORMAT => $responseFormat,
                ];

                if ([] !== $tools) {
                    $options['tools'] = array_values($tools);
                }

                $result = $platform->invoke($model, $messages, $options)->getResult();
                $pendingToolCalls = $this->extractToolCalls($result);

                if ([] === $pendingToolCalls) {
                    break;
                }

                $messages->add(Message::ofAssistant($result));

                foreach ($pendingToolCalls as $call) {
                    $toolResult = $this->executeToolCall($call);
                    $toolCalls[] = $toolResult;
                    $messages->add(Message::ofToolCall($call, $toolResult['content']));
                }
            }

            if ([] !== $pendingToolCalls) {
                $messages->add(Message::ofUser(<<<PROMPT
Stop calling tools now. Return the required JSON object using only the tool results and edit context already provided.
PROMPT));

                $result = $platform->invoke($model, $messages, [
                    PlatformSubscriber::RESPONSE_FORMAT => $responseFormat,
                ])->getResult();
            }

            $rawResponse = $result ? $this->resultToText($result) : '';
        } finally {
            $this->resetTraceablePlatform($platform);
        }

        $agentResponse = $this->decodeJsonObject($rawResponse);
        $patchPayload = $agentResponse['payload'] ?? [];

        if (!is_array($patchPayload)) {
            throw new RuntimeException('The content editor agent returned an invalid payload.');
        }

        $patchPayload = $this->payloadContext->normalizePayload($patchPayload);
        unset($patchPayload['_token'], $patchPayload['_ok'], $patchPayload['goto'], $patchPayload['module_prototypes_collection']);
        $patchPayload['layout'] = $layout;

        $replaceCollections = (bool) ($agentResponse['replaceCollections'] ?? false);
        $mergedPayload = $this->mergePayload($currentPayload, $patchPayload, $replaceCollections);
        $mergedPayload['layout'] = $layout;

        $validation = [
            'valid' => null,
            'errors' => [],
        ];

        try {
            $validation = $this->payloadContext->validatePayload($contentType, $layout, $mergedPayload);
            unset($validation['form']);
        } catch (Throwable $e) {
            $validation = [
                'valid' => false,
                'errors' => [[
                    'path' => 'validation',
                    'message' => $e->getMessage(),
                    'code' => null,
                ]],
            ];
        }

        return [
            'answer' => trim((string) ($agentResponse['answer'] ?? 'Draft updated.')),
            'replaceCollections' => $replaceCollections,
            'payload' => $patchPayload,
            'mergedPayload' => $mergedPayload,
            'isValid' => $validation['valid'],
            'errors' => $validation['errors'] ?? [],
            'rawResponse' => $rawResponse,
            'toolCalls' => $toolCalls,
            'tools' => $this->getAvailableTools(),
        ];
    }

    private function normalizeVersionPayload(mixed $payload, string $layout): array
    {
        if (!is_array($payload)) {
            $payload = [];
        }

        unset($payload['_token'], $payload['_ok'], $payload['goto'], $payload['module_prototypes_collection']);

        $payload['layout'] = $layout;
        $payload['data'] = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        return $payload;
    }

    private function mergePayload(array $basePayload, array $patchPayload, bool $replaceCollections): array
    {
        return $this->mergeValue($basePayload, $patchPayload, $replaceCollections);
    }

    private function mergeValue(mixed $baseValue, mixed $patchValue, bool $replaceCollections): mixed
    {
        if (!is_array($patchValue)) {
            return $patchValue;
        }

        if (!is_array($baseValue)) {
            return $patchValue;
        }

        if (array_is_list($patchValue)) {
            if ($replaceCollections) {
                return $patchValue;
            }

            $merged = array_is_list($baseValue) ? $baseValue : [];
            foreach ($patchValue as $index => $item) {
                $merged[$index] = $this->mergeValue($merged[$index] ?? null, $item, $replaceCollections);
            }

            ksort($merged);

            return array_values($merged);
        }

        $merged = $baseValue;
        foreach ($patchValue as $key => $value) {
            $merged[$key] = $this->mergeValue($merged[$key] ?? null, $value, $replaceCollections);
        }

        return $merged;
    }

    private function createPlatformTools(): array
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

    private function createAgentResponseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'content_editor_agent_response',
                'strict' => false,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['answer', 'replaceCollections', 'payload'],
                    'properties' => [
                        'answer' => [
                            'type' => 'string',
                            'description' => 'Short plain text status message for the editor.',
                        ],
                        'replaceCollections' => [
                            'type' => 'boolean',
                            'description' => 'Whether listed collection arrays should replace the current browser collections.',
                        ],
                        'payload' => [
                            'type' => 'object',
                            'description' => 'Partial Symfony form payload patch. Nested shape is defined by the edit-context schema.',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function getSystemPrompt(): string
    {
        return <<<PROMPT
You are an AI content editor embedded in an Armonic CMS content version edit form.

Scope:
- Only help generate, rewrite, translate, summarize, adapt, or improve CMS content for the open draft page.
- Only return changes that can be applied to the current Symfony form payload.
- Do not answer unrelated questions, give general consulting advice, write code, change infrastructure, browse arbitrary topics, or perform tasks outside content editing.
- If the editor asks for something outside this scope, return the required JSON object with a short refusal in "answer", "replaceCollections": false, and an empty "payload": {}.

Capabilities and limits:
- You may use the read-only CMS MCP tools to inspect published content, site context, menus, internal links, and existing media context.
- Registered CMS tool names use the "sfs_cms_" prefix. Use those exact tool names and never call old "cms_" tool names.
- You cannot save, publish, delete, create CMS versions, change configuration, run commands, or modify anything outside the browser draft form.
- The browser applies your returned payload to the open draft form only. A CMS version is saved only when the editor manually clicks the normal Save button.

Output contract:
- Return exactly one valid JSON object and nothing else.
- Do not wrap the JSON in Markdown.
- Do not return a full HTML page, explanatory prose outside JSON, tool logs, or alternative formats.
- The JSON object must have this shape:
{
  "answer": "Short message for the editor.",
  "replaceCollections": false,
  "payload": {
    "data": {}
  }
}

Payload rules:
- "payload" must use the same structure as the Symfony form payload and must respect the provided JSON schema.
- Return a partial payload with only changed fields by default.
- Set "replaceCollections" to true only when the instruction asks you to generate, rebuild, reorder, or remove a module collection. When it is true, include the complete array for every collection you replace.
- Never include "_token", "_ok", "goto", or "module_prototypes_collection".
- Do not change the layout.
- For translatable fields, preserve existing "_default" and "_trans_id" values when they are present.
- For HTML fields, return valid, concise HTML fragments inside payload fields, not Markdown and not complete HTML documents.
- If you need existing site, menu, link, media, or published content context, use the CMS tools before writing the payload.
PROMPT;
    }

    private function executeToolCall(ToolCall $toolCall): array
    {
        $toolName = $this->normalizeToolName($toolCall->getName());
        $handler = new ReferenceHandler($this->mcpToolServices);
        $arguments = $toolCall->getArguments();
        $arguments['_session'] = new Session(new InMemorySessionStore());

        try {
            $reference = $this->registry->getTool($toolName);
            $result = $handler->handle($reference, $arguments);
            $content = $this->encodeJson($result);

            return [
                'id' => $toolCall->getId(),
                'name' => $toolName,
                'arguments' => $toolCall->getArguments(),
                'content' => $content,
                'error' => null,
            ];
        } catch (Throwable $e) {
            $content = $this->encodeJson([
                'error' => $e->getMessage(),
                'type' => $e::class,
            ]);

            return [
                'id' => $toolCall->getId(),
                'name' => $toolName,
                'arguments' => $toolCall->getArguments(),
                'content' => $content,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function resultToText(object $result): string
    {
        return match (true) {
            $result instanceof TextResult => $result->getContent(),
            $result instanceof ObjectResult => $this->encodeJson($result->getContent()),
            $result instanceof ThinkingResult => '',
            $result instanceof MultiPartResult => implode('', array_map(
                fn (ResultInterface $part): string => $part instanceof ToolCallResult ? '' : $this->resultToText($part),
                $result->getContent(),
            )),
            $result instanceof ToolCallResult => throw new RuntimeException('The content editor agent did not return a final JSON response.'),
            default => throw new RuntimeException(sprintf('Unsupported AI result type "%s".', $result::class)),
        };
    }

    /**
     * @return ToolCall[]
     */
    private function extractToolCalls(object $result): array
    {
        if ($result instanceof ToolCallResult) {
            return $result->getContent();
        }

        if (!$result instanceof MultiPartResult) {
            return [];
        }

        $toolCalls = [];
        foreach ($result->getContent() as $part) {
            if ($part instanceof ToolCallResult) {
                array_push($toolCalls, ...$part->getContent());
            }
        }

        return $toolCalls;
    }

    private function getSelectedSiteContext(mixed $selectedSite): ?array
    {
        if (!is_string($selectedSite) || '' === trim($selectedSite)) {
            return null;
        }

        try {
            $reference = $this->registry->getTool('sfs_cms_sites_get_context');
            $handler = new ReferenceHandler($this->mcpToolServices);

            $result = $handler->handle($reference, [
                'site' => trim($selectedSite),
                '_session' => new Session(new InMemorySessionStore()),
            ]);

            return is_array($result) ? $result : null;
        } catch (Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'type' => $e::class,
            ];
        }
    }

    private function normalizeToolName(string $toolName): string
    {
        if (str_starts_with($toolName, 'cms_')) {
            $toolName = 'sfs_'.$toolName;
        }

        return $this->legacyToolNameMap()[$toolName] ?? $toolName;
    }

    /**
     * @return array<string, string>
     */
    private function legacyToolNameMap(): array
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

    private function resetTraceablePlatform(PlatformInterface $platform): void
    {
        if ($platform instanceof ResetInterface) {
            $platform->reset();
        }
    }

    private function decodeJsonObject(string $content): array
    {
        $content = trim($content);

        if (preg_match('/^<(?:!doctype\s+html|html|head|body|h[1-6])\b/i', $content)) {
            throw new RuntimeException('The content editor agent returned HTML instead of the required JSON object. Try again or use a JSON-capable model.');
        }

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
            $content = trim($content);
        }

        $jsonObject = $this->extractFirstJsonObject($content);
        if (null !== $jsonObject) {
            $content = $jsonObject;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('The content editor agent response is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('The content editor agent response must decode to a JSON object.');
        }

        return $decoded;
    }

    private function extractFirstJsonObject(string $content): ?string
    {
        $start = strpos($content, '{');
        if (false === $start) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($content);

        for ($i = $start; $i < $length; ++$i) {
            $char = $content[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ('\\' === $char) {
                    $escaped = true;
                    continue;
                }

                if ('"' === $char) {
                    $inString = false;
                }

                continue;
            }

            if ('"' === $char) {
                $inString = true;
                continue;
            }

            if ('{' === $char) {
                ++$depth;
                continue;
            }

            if ('}' !== $char) {
                continue;
            }

            --$depth;
            if (0 === $depth) {
                return substr($content, $start, $i - $start + 1);
            }
        }

        return null;
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function getPlatform(?string $platformName): PlatformInterface
    {
        $platformName = $platformName ?: array_key_first($this->platforms->getProvidedServices());

        if (!$platformName || !$this->platforms->has($platformName)) {
            throw new RuntimeException('No AI platform is configured for the content editor agent.');
        }

        $platform = $this->platforms->get($platformName);

        if (!$platform instanceof PlatformInterface) {
            throw new RuntimeException(sprintf('Service "%s" is not a valid AI platform.', $platformName));
        }

        return $platform;
    }

    private function addSiteAiContextInstruction(string $instruction, array $context, ?array $siteContext): string
    {
        $selectedSite = is_string($context['selectedSite'] ?? null) ? trim($context['selectedSite']) : '';

        if ('' !== $selectedSite) {
            $siteContextStatus = null === $siteContext
                ? 'No selected site context could be loaded.'
                : 'Selected site context is included in Current edit context as selectedSiteContext.';

            return <<<PROMPT
Selected site: {$selectedSite}
{$siteContextStatus}
Use selectedSiteContext.metadata.sfs_cms_ai as editorial constraints when it is available.
Apply the site description, target audience, editorial tone, brand voice, content guidelines, SEO guidelines, keywords, forbidden topics, and extra instructions when changing copy, headings, metadata, CTAs, or module content.
If the site AI instructions conflict with the user request, preserve the user intent but adapt wording to the site configuration.
Do not refuse the edit just because site context is missing; produce the best valid content payload for the current form schema.

Editor request:
{$instruction}
PROMPT;
        }

        return <<<PROMPT
If the current edit context includes selectedSiteContext.metadata.sfs_cms_ai, apply it as editorial constraints: tone, brand voice, keywords, SEO guidance, content guidelines, forbidden topics, and extra instructions.
Do not refuse the edit just because site context is missing; produce the best valid content payload for the current form schema.

Editor request:
{$instruction}
PROMPT;
    }
}
