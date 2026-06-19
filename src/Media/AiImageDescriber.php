<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Media;

use RuntimeException;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\File\File;

class AiImageDescriber
{
    public const SUPPORTED_MIME_TYPES = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        protected ServiceLocator $platforms,
        protected string $defaultPlatform = 'openai',
        protected string $defaultModel = 'gpt-5-mini',
    ) {
    }

    public function describe(File $image, ?string $platformName = null, ?string $model = null): array
    {
        $path = $image->getRealPath() ?: $image->getPathname();
        if (!is_readable($path)) {
            throw new RuntimeException('The uploaded image file is not readable.');
        }

        $mimeType = $image->getMimeType();
        if (!is_string($mimeType) || !in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
            throw new RuntimeException(sprintf('The image MIME type "%s" is not supported for AI description generation.', $mimeType ?: 'unknown'));
        }

        $platformName = $this->resolvePlatformName($platformName);
        $model = $model ?: $this->defaultModel;

        $messages = new MessageBag();
        $messages->add(Message::forSystem(<<<PROMPT
You describe CMS media images for editorial search and accessibility review.
Return only a concise factual image description in English.
Do not mention that you are an AI model.
Do not invent people, brands, locations, or text that are not visible.
PROMPT));
        $messages->add(Message::ofUser(
            'Describe this uploaded CMS image in one or two short sentences.',
            Image::fromFile($path),
        ));

        $result = $this->getPlatform($platformName)->invoke($model, $messages)->getResult();
        $description = trim($this->resultToText($result));

        if ('' === $description) {
            throw new RuntimeException('The AI platform returned an empty image description.');
        }

        return [
            'description' => $description,
            'platform' => $platformName,
            'model' => $model,
            'generated_at' => gmdate(DATE_ATOM),
        ];
    }

    protected function resolvePlatformName(?string $platformName): string
    {
        $platformName = $platformName ?: ($this->platforms->has($this->defaultPlatform) ? $this->defaultPlatform : null);
        $platformName = $platformName ?: array_key_first($this->platforms->getProvidedServices());

        if (!$platformName || !$this->platforms->has($platformName)) {
            throw new RuntimeException('No AI platform is configured for image description generation.');
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

    protected function resultToText(object $result): string
    {
        return match (true) {
            $result instanceof TextResult => $result->getContent(),
            $result instanceof ObjectResult => json_encode($result->getContent(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            default => throw new RuntimeException(sprintf('Unsupported AI result type "%s".', $result::class)),
        };
    }
}
