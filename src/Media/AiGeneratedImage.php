<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Media;

final class AiGeneratedImage
{
    public function __construct(
        public readonly string $path,
        public readonly string $originalName,
        public readonly string $mimeType,
    ) {
    }
}
