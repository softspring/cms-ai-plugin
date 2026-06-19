<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Softspring\CmsAiPlugin\Media\MediaImageRequirementsDescriber;
use Softspring\MediaBundle\EntityManager\MediaManagerInterface;
use Softspring\MediaBundle\Model\MediaInterface;
use Softspring\MediaBundle\Model\MediaVersionInterface;
use Softspring\MediaBundle\Type\MediaTypesCollection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use const DATE_ATOM;

class MediaImageTools
{
    public function __construct(
        private readonly MediaTypesCollection $mediaTypesCollection,
        private readonly MediaManagerInterface $mediaManager,
        private readonly MediaImageRequirementsDescriber $requirementsDescriber,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[McpTool(
        name: 'cms_media_list_image_types',
        title: 'List CMS media image types',
        description: 'Return configured image media types, upload requirements, and compatible AI generation sizes.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function listImageTypes(
        #[Schema(type: 'boolean', description: 'Whether to include private media types.')]
        bool $includePrivate = false,
    ): array {
        try {
            $types = [];

            foreach ($this->mediaTypesCollection->getTypes() as $type => $typeConfig) {
                if ('image' !== ($typeConfig['type'] ?? null)) {
                    continue;
                }

                if (!$includePrivate && ($typeConfig['private'] ?? false)) {
                    continue;
                }

                $types[$type] = $this->requirementsDescriber->describe($type, $typeConfig);
            }

            ksort($types);

            return [
                'count' => count($types),
                'types' => array_values($types),
            ];
        } catch (Throwable $e) {
            return $this->toolError($e);
        }
    }

    #[McpTool(
        name: 'cms_media_search_images',
        title: 'Search CMS media images',
        description: 'Search existing CMS image media items by text and type, returning chat-ready thumbnail previews and admin links.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function searchImages(
        #[Schema(type: 'string', description: 'Optional text to search in image name and description.')]
        ?string $query = null,
        #[Schema(type: 'string', description: 'Optional media type key to restrict the search.')]
        ?string $type = null,
        #[Schema(type: 'boolean', description: 'Whether to include private media.')]
        bool $includePrivate = false,
        #[Schema(type: 'integer', description: 'Maximum number of image results to return.')]
        int $limit = 20,
    ): array {
        try {
            $limit = max(1, min($limit, 50));
            $query = trim((string) $query);
            $type = trim((string) $type);

            if ('' !== $type) {
                $typeConfig = $this->mediaTypesCollection->getType($type);
                if ('image' !== ($typeConfig['type'] ?? null)) {
                    return ['error' => sprintf('Media type "%s" is not an image type.', $type)];
                }
            }

            $qb = $this->mediaManager->getRepository()->createQueryBuilder('media')
                ->andWhere('media.mediaType = :imageMediaType')
                ->setParameter('imageMediaType', MediaInterface::MEDIA_TYPE_IMAGE)
                ->orderBy('media.createdAt', 'DESC')
                ->addOrderBy('media.name', 'ASC')
                ->setMaxResults($limit);

            if (!$includePrivate) {
                $qb->andWhere('media.private IS NULL OR media.private = :private')
                    ->setParameter('private', false);
            }

            if ('' !== $type) {
                $qb->andWhere('media.type = :type')
                    ->setParameter('type', $type);
            }

            if ('' !== $query) {
                $qb->andWhere('LOWER(media.name) LIKE :query OR LOWER(media.description) LIKE :query')
                    ->setParameter('query', '%'.mb_strtolower($query).'%');
            }

            $results = array_values(array_filter(
                $qb->getQuery()->getResult(),
                static fn (mixed $media): bool => $media instanceof MediaInterface,
            ));

            return [
                'query' => '' !== $query ? $query : null,
                'type' => '' !== $type ? $type : null,
                'includePrivate' => $includePrivate,
                'limit' => $limit,
                'count' => count($results),
                'images' => array_map(fn (MediaInterface $media): array => $this->summarizeImagePreview($media), $results),
            ];
        } catch (Throwable $e) {
            return $this->toolError($e);
        }
    }

    #[McpTool(
        name: 'cms_media_get_image_context',
        title: 'Get CMS media image context',
        description: 'Return metadata, upload requirements, and version information for one CMS image media item.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function getImageContext(
        #[Schema(type: 'string', description: 'Media id.')]
        string $mediaId,
    ): array {
        try {
            $media = $this->mediaManager->getRepository()->find($mediaId);

            if (!$media instanceof MediaInterface) {
                return ['error' => sprintf('Media "%s" was not found.', $mediaId)];
            }

            if (!$media->isImage()) {
                return ['error' => sprintf('Media "%s" is not an image.', $mediaId)];
            }

            $typeConfig = $this->mediaTypesCollection->getType((string) $media->getType());

            return [
                'id' => $media->getId(),
                'type' => $media->getType(),
                'adminUrl' => $this->generateMediaAdminUrl($media),
                'previewMarkdown' => $this->buildPreviewMarkdown($media),
                'name' => $media->getName(),
                'description' => $media->getDescription(),
                'altTexts' => $media->getAltTexts(),
                'metadata' => $media->getMetadata(),
                'aiMetadata' => $media->getMetadataField('sfs_cms_ai', []),
                'requirements' => $this->requirementsDescriber->describe((string) $media->getType(), $typeConfig),
                'thumbnail' => $this->summarizeThumbnail($media, true),
                'versions' => array_map(fn (MediaVersionInterface $version): array => $this->summarizeVersion($version, true), $media->getVersions()->toArray()),
            ];
        } catch (Throwable $e) {
            return $this->toolError($e);
        }
    }

    private function summarizeImagePreview(MediaInterface $media): array
    {
        return [
            'title' => $media->getName() ?: sprintf('Media image %s', $media->getId()),
            'type' => $media->getType(),
            'private' => $media->getPrivate(),
            'description' => $media->getDescription(),
            'thumbnail' => $this->summarizeThumbnail($media),
            'adminUrl' => $this->generateMediaAdminUrl($media),
            'previewMarkdown' => $this->buildPreviewMarkdown($media),
        ];
    }

    private function summarizeThumbnail(MediaInterface $media, bool $includeInternalUrl = false): array
    {
        $thumbnail = $media->getVersion('_thumbnail');
        if ($thumbnail instanceof MediaVersionInterface) {
            return $this->summarizeVersion($thumbnail, $includeInternalUrl);
        }

        $summary = [
            'version' => '_thumbnail',
            'publicUrl' => null,
            'width' => null,
            'height' => null,
            'fileSize' => null,
            'mimeType' => null,
            'missing' => true,
        ];

        if ($includeInternalUrl) {
            $summary['url'] = null;
            $summary['uploadedAt'] = null;
            $summary['generatedAt'] = null;
        }

        return $summary;
    }

    private function summarizeVersion(MediaVersionInterface $version, bool $includeInternalUrl = false): array
    {
        $summary = [
            'version' => $version->getVersion(),
            'publicUrl' => $version->getPublicUrl(),
            'width' => $version->getWidth(),
            'height' => $version->getHeight(),
            'fileSize' => $version->getFileSize(),
            'mimeType' => $version->getFileMimeType(),
        ];

        if ($includeInternalUrl) {
            $summary['url'] = $version->getUrl();
            $summary['uploadedAt'] = $version->getUploadedAt()?->format(DATE_ATOM);
            $summary['generatedAt'] = $version->getGeneratedAt()?->format(DATE_ATOM);
        }

        return $summary;
    }

    private function generateMediaAdminUrl(MediaInterface $media): string
    {
        return $this->urlGenerator->generate('sfs_media_admin_medias_read', [
            '_locale' => $this->getLocale(),
            'media' => $media->getId(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function getLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->attributes->get('_locale') ?: $request?->getLocale() ?: 'es';
    }

    private function buildPreviewMarkdown(MediaInterface $media): string
    {
        $thumbnail = $this->summarizeThumbnail($media);
        $thumbnailUrl = $thumbnail['publicUrl'] ?? '';
        $title = $media->getName() ?: 'Media image';
        $adminUrl = $this->generateMediaAdminUrl($media);

        return sprintf(
            "[![%s](%s)](%s)\n[%s](%s)",
            $this->escapeMarkdownText($title),
            $thumbnailUrl,
            $adminUrl,
            $this->escapeMarkdownText($title),
            $adminUrl,
        );
    }

    private function escapeMarkdownText(string $text): string
    {
        return str_replace(['[', ']', '(', ')'], ['\\[', '\\]', '\\(', '\\)'], $text);
    }

    private function toolError(Throwable $e): array
    {
        return [
            'error' => $e->getMessage(),
            'type' => $e::class,
        ];
    }
}
