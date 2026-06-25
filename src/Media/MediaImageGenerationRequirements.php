<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Media;

class MediaImageGenerationRequirements
{
    public function resolveGenerationSize(array $uploadRequirements): string
    {
        $minWidth = (int) ($uploadRequirements['minWidth'] ?? 0);
        $minHeight = (int) ($uploadRequirements['minHeight'] ?? 0);
        $allowLandscape = $uploadRequirements['allowLandscape'] ?? true;
        $allowPortrait = $uploadRequirements['allowPortrait'] ?? true;

        if (false === $allowLandscape) {
            return '1024x1536';
        }

        if (false === $allowPortrait) {
            return '1536x1024';
        }

        if ($minWidth > $minHeight && $minWidth > 1024) {
            return '1536x1024';
        }

        if ($minHeight > $minWidth && $minHeight > 1024) {
            return '1024x1536';
        }

        return '1024x1024';
    }

    public function supportsPngOutput(array $uploadRequirements): bool
    {
        $mimeTypes = (array) ($uploadRequirements['mimeTypes'] ?? []);

        return [] === $mimeTypes || in_array('image/png', $mimeTypes, true);
    }

    /**
     * @return list<string>
     */
    public function buildPromptRequirements(array $uploadRequirements, ?string $size = null): array
    {
        $size ??= $this->resolveGenerationSize($uploadRequirements);
        $requirements = ["Output size: $size."];

        if (!empty($uploadRequirements['minWidth']) || !empty($uploadRequirements['minHeight'])) {
            $requirements[] = sprintf('The image must be suitable for a media slot that requires at least %spx width and %spx height.', $uploadRequirements['minWidth'] ?? 'any', $uploadRequirements['minHeight'] ?? 'any');
        }

        if (false === ($uploadRequirements['allowLandscape'] ?? true)) {
            $requirements[] = 'Do not create a landscape image.';
        }

        if (false === ($uploadRequirements['allowPortrait'] ?? true)) {
            $requirements[] = 'Do not create a portrait image.';
        }

        return $requirements;
    }
}
