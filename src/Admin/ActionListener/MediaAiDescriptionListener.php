<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Admin\ActionListener;

use Psr\Log\LoggerInterface;
use Softspring\CmsAiPlugin\Media\AiImageDescriber;
use Softspring\Component\CrudlController\Event\ApplyEvent;
use Softspring\MediaBundle\Model\MediaInterface;
use Softspring\MediaBundle\Model\MediaVersionInterface;
use Softspring\MediaBundle\SfsMediaEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

class MediaAiDescriptionListener implements EventSubscriberInterface
{
    protected const METADATA_FIELD = 'sfs_cms_ai';
    protected const IMAGE_DESCRIPTION_FIELD = 'image_description';
    protected const GENERATED_PROMPT_FIELD = 'aiImageGeneratedPrompt';

    public function __construct(
        protected AiImageDescriber $imageDescriber,
        protected LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SfsMediaEvents::ADMIN_MEDIAS_CREATE_APPLY => [
                ['onMediaApply', 0],
            ],
            SfsMediaEvents::ADMIN_MEDIAS_CREATE_AJAX_APPLY => [
                ['onMediaApply', 0],
            ],
            SfsMediaEvents::ADMIN_MEDIAS_UPDATE_APPLY => [
                ['onMediaApply', 0],
            ],
        ];
    }

    public function onMediaApply(ApplyEvent $event): void
    {
        $media = $event->getEntity();
        if (!$media instanceof MediaInterface || !$media->isImage()) {
            return;
        }

        $version = $this->findUploadedOriginalVersion($media);
        if (!$version instanceof MediaVersionInterface) {
            return;
        }

        $upload = $version->getUpload();
        if (!$upload instanceof File) {
            return;
        }

        $mimeType = $upload->getMimeType();
        if (!is_string($mimeType) || !in_array($mimeType, AiImageDescriber::SUPPORTED_MIME_TYPES, true)) {
            return;
        }

        $generatedPrompt = $this->getGeneratedPrompt($event);
        if ($generatedPrompt) {
            if (!$media->getName()) {
                $media->setName($this->buildTitleFromPrompt($generatedPrompt));
            }

            if (!$media->getDescription()) {
                $media->setDescription($generatedPrompt);
            }
        }

        try {
            $description = $this->imageDescriber->describe($upload);
            $dimensions = $this->safeDimensions($upload);
            $description += [
                'source_prompt' => $generatedPrompt,
                'source_version' => $version->getVersion(),
                'source_mime_type' => $mimeType,
                'source_sha1' => $this->safeSha1($upload),
                'source_width' => $version->getWidth() ?? $dimensions['width'],
                'source_height' => $version->getHeight() ?? $dimensions['height'],
            ];

            $aiMetadata = $media->getMetadataField(self::METADATA_FIELD, []);
            if (!is_array($aiMetadata)) {
                $aiMetadata = [];
            }

            $aiMetadata[self::IMAGE_DESCRIPTION_FIELD] = $description;
            $media->setMetadataField(self::METADATA_FIELD, $aiMetadata);

            if (!$generatedPrompt && !$media->getDescription()) {
                $media->setDescription($description['description']);
            }
        } catch (Throwable $exception) {
            $this->logger->warning('CMS AI image description generation failed.', [
                'exception' => $exception,
                'media_id' => $media->getId(),
                'media_type' => $media->getType(),
                'version' => $version->getVersion(),
            ]);
        }
    }

    protected function getGeneratedPrompt(ApplyEvent $event): ?string
    {
        $form = $event->getForm();
        if (!$form || !$form->has(self::GENERATED_PROMPT_FIELD)) {
            return null;
        }

        $prompt = trim((string) $form->get(self::GENERATED_PROMPT_FIELD)->getData());

        return '' !== $prompt ? $prompt : null;
    }

    protected function buildTitleFromPrompt(string $prompt): string
    {
        $title = preg_replace('/\s+/', ' ', trim($prompt)) ?: 'AI generated image';

        return mb_substr($title, 0, 120);
    }

    protected function findUploadedOriginalVersion(MediaInterface $media): ?MediaVersionInterface
    {
        $original = $media->getVersion('_original');
        if ($original instanceof MediaVersionInterface && $original->getUpload() instanceof File) {
            return $original;
        }

        foreach ($media->getVersions() as $version) {
            if ($version instanceof MediaVersionInterface && $version->getUpload() instanceof File) {
                return $version;
            }
        }

        return null;
    }

    protected function safeSha1(File $file): ?string
    {
        $path = $file->getRealPath() ?: $file->getPathname();

        return is_readable($path) ? sha1_file($path) ?: null : null;
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    protected function safeDimensions(File $file): array
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        if (!is_readable($path)) {
            return ['width' => null, 'height' => null];
        }

        $size = getimagesize($path);
        if (false === $size) {
            return ['width' => null, 'height' => null];
        }

        return [
            'width' => $size[0],
            'height' => $size[1],
        ];
    }
}
