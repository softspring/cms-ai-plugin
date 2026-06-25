<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Seo\Content\Check;

use JsonException;
use RuntimeException;
use Softspring\CmsBundle\Model\ContentVersionInterface;
use Softspring\CmsSeoPlugin\Content\Check\CheckInterface;
use Softspring\CmsSeoPlugin\Content\Check\SeoCheckContext;
use Softspring\CmsSeoPlugin\Seo\SeoCheck;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use function count;
use function in_array;
use function is_array;

class AiKeywordCoverageCheck implements CheckInterface
{
    private const MAX_TEXT_LENGTH = 12000;

    public function __construct(
        protected ServiceLocator $platforms,
        protected string $defaultPlatform = 'gemini',
        protected string $defaultModel = 'gemini-2.5-flash',
    ) {
    }

    public function getCode(): string
    {
        return 'ai_keyword_coverage';
    }

    public function getPriority(): int
    {
        return 80;
    }

    public function check(SeoCheckContext $context): SeoCheck
    {
        $keywords = $this->resolveKeywords($context);

        if ([] === $keywords) {
            return new SeoCheck($this->getCode(), 'info', 80, ['keywords' => 0], [
                'keywords' => [],
                'ai_summary' => 'No SEO keywords are configured for this published version and locale.',
            ]);
        }

        try {
            $platformName = $this->resolvePlatformName();
            $platform = $this->getPlatform($platformName);
            $model = $this->resolveModel($platform);
            $result = $this->analyze($platform, $model, $context, $keywords);
        } catch (Throwable $e) {
            return new SeoCheck($this->getCode(), 'warning', 60, [
                'keywords' => count($keywords),
            ], [
                'keywords' => $keywords,
                'ai_error' => $e->getMessage(),
            ]);
        } finally {
            if (isset($platform) && $platform instanceof ResetInterface) {
                $platform->reset();
            }
        }

        $score = max(0, min(100, (int) ($result['score'] ?? 0)));
        $severity = $this->severity($score, (string) ($result['severity'] ?? ''));

        return new SeoCheck($this->getCode(), $severity, $score, [
            'keywords' => count($keywords),
            'matched' => (int) ($result['matchedKeywords'] ?? 0),
            'platform' => $platformName ?? '',
            'model' => $model ?? '',
        ], [
            'keywords' => $keywords,
            'matched_keywords' => (string) ($result['matchedKeywords'] ?? 0),
            'ai_summary' => $this->stringValue($result['summary'] ?? ''),
            'ai_recommendations' => $this->stringList($result['recommendations'] ?? []),
            'ai_platform' => ($platformName ?? '').' / '.($model ?? ''),
        ]);
    }

    /**
     * @param string[] $keywords
     *
     * @return array<string, mixed>
     */
    protected function analyze(PlatformInterface $platform, string $model, SeoCheckContext $context, array $keywords): array
    {
        $messages = new MessageBag();
        $messages->add(Message::forSystem(<<<PROMPT
You are an SEO reviewer for public CMS pages.
Review whether the visible public page content covers the configured SEO keywords naturally.
Return only the requested JSON object.
Do not suggest keyword stuffing.
PROMPT));
        $messages->add(Message::ofUser(sprintf(<<<PROMPT
Public URL: %s
Locale: %s
Configured SEO keywords: %s

Extracted public page data:
%s
PROMPT,
            $context->pageUrl->url,
            $context->pageUrl->locale,
            implode(', ', $keywords),
            $this->encodeJson($this->pageData($context)),
        )));

        $result = $platform->invoke($model, $messages, [
            PlatformSubscriber::RESPONSE_FORMAT => $this->responseFormat(),
        ])->getResult();

        return $this->decodeJsonObject($this->resultToText($result));
    }

    /**
     * @return array<string, mixed>
     */
    protected function pageData(SeoCheckContext $context): array
    {
        $body = $context->textContent('//body');

        return [
            'title' => $context->textContent('//title'),
            'metaDescription' => $context->attribute('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="description"]', 'content'),
            'h1' => $context->textList('//h1'),
            'h2' => array_slice($context->textList('//h2'), 0, 20),
            'bodyText' => mb_substr($body, 0, self::MAX_TEXT_LENGTH),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'cms_ai_seo_keyword_coverage',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['score', 'severity', 'matchedKeywords', 'summary', 'recommendations'],
                    'properties' => [
                        'score' => [
                            'type' => 'integer',
                            'minimum' => 0,
                            'maximum' => 100,
                        ],
                        'severity' => [
                            'type' => 'string',
                            'enum' => ['ok', 'info', 'warning', 'error'],
                        ],
                        'matchedKeywords' => [
                            'type' => 'integer',
                            'minimum' => 0,
                        ],
                        'summary' => [
                            'type' => 'string',
                        ],
                        'recommendations' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return string[]
     */
    protected function resolveKeywords(SeoCheckContext $context): array
    {
        $content = $context->pageUrl->routePath->getRoute()?->getContent();
        $version = $content?->getPublishedVersion();

        if (!$version instanceof ContentVersionInterface) {
            return [];
        }

        $seo = $version->getSeo();
        if (!is_array($seo)) {
            return [];
        }

        return $this->normalizeKeywords($this->localizedSeoValue($seo['metaKeywords'] ?? null, $context->pageUrl->locale));
    }

    protected function localizedSeoValue(mixed $value, string $locale): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return $value;
        }

        foreach ([$locale, str_replace('-', '_', $locale), str_replace('_', '-', $locale), '_default', 'default'] as $key) {
            if (array_key_exists($key, $value)) {
                return $value[$key];
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    protected function normalizeKeywords(mixed $value): array
    {
        if (is_array($value)) {
            $keywords = [];
            foreach ($value as $item) {
                array_push($keywords, ...$this->normalizeKeywords($item));
            }

            return array_values(array_unique($keywords));
        }

        $value = trim((string) $value);
        if ('' === $value) {
            return [];
        }

        $keywords = preg_split('/[,;\n\r]+/', $value) ?: [];
        $keywords = array_map(static fn (string $keyword): string => trim($keyword), $keywords);
        $keywords = array_filter($keywords, static fn (string $keyword): bool => '' !== $keyword);

        return array_values(array_unique($keywords));
    }

    protected function resolvePlatformName(): string
    {
        $platformName = $this->platforms->has($this->defaultPlatform) ? $this->defaultPlatform : array_key_first($this->platforms->getProvidedServices());

        if (!$platformName || !$this->platforms->has($platformName)) {
            throw new RuntimeException('No AI platform is configured for SEO keyword analysis.');
        }

        return $platformName;
    }

    protected function getPlatform(string $platformName): PlatformInterface
    {
        $platform = $this->platforms->get($platformName);

        if (!$platform instanceof PlatformInterface) {
            throw new RuntimeException(sprintf('Service "%s" is not a valid AI platform.', $platformName));
        }

        return $platform;
    }

    protected function resolveModel(PlatformInterface $platform): string
    {
        $models = array_keys($platform->getModelCatalog()->getModels());

        if ([] === $models || in_array($this->defaultModel, $models, true)) {
            return $this->defaultModel;
        }

        foreach (['gemini-2.5-flash', 'gemini-2.5-pro', 'gpt-5-mini', 'gpt-4.1-mini', 'gpt-4o-mini', 'claude-sonnet-4-5'] as $candidate) {
            if (in_array($candidate, $models, true)) {
                return $candidate;
            }
        }

        sort($models);

        return $models[0];
    }

    protected function severity(int $score, string $suggestedSeverity): string
    {
        if (in_array($suggestedSeverity, ['ok', 'info', 'warning', 'error'], true)) {
            return $suggestedSeverity;
        }

        return match (true) {
            $score < 40 => 'error',
            $score < 75 => 'warning',
            $score < 90 => 'info',
            default => 'ok',
        };
    }

    protected function resultToText(object $result): string
    {
        return match (true) {
            $result instanceof TextResult => $result->getContent(),
            $result instanceof ObjectResult => $this->encodeJson($result->getContent()),
            $result instanceof ThinkingResult => '',
            $result instanceof MultiPartResult => implode('', array_map(
                fn (ResultInterface $part): string => $part instanceof ToolCallResult ? '' : $this->resultToText($part),
                $result->getContent(),
            )),
            default => throw new RuntimeException(sprintf('Unsupported AI result type "%s".', $result::class)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJsonObject(string $content): array
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
            $content = trim($content);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('The AI SEO response is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('The AI SEO response must decode to a JSON object.');
        }

        return $decoded;
    }

    protected function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    protected function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * @return string[]
     */
    protected function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return '' === trim((string) $value) ? [] : [trim((string) $value)];
        }

        $items = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ('' !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
