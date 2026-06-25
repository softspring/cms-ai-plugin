<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Media;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

class AiImageModelChoices
{
    private const SUPPORTED_PLATFORMS = ['openai', 'gemini'];
    private const GEMINI_IMAGE_MODELS = [
        'gemini-3.1-flash-image' => 'gemini-3.1-flash-image',
        'gemini-3-pro-image' => 'gemini-3-pro-image',
        'gemini-2.5-flash-image' => 'gemini-2.5-flash-image',
    ];

    public function __construct(
        protected ServiceLocator $platforms,
        protected OpenAiImageModels $openAiImageModels,
    ) {
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getModelsByPlatform(): array
    {
        $models = [];

        foreach (array_keys($this->platforms->getProvidedServices()) as $name) {
            if (!in_array($name, self::SUPPORTED_PLATFORMS, true)) {
                continue;
            }

            $platformModels = $this->getImageModelChoices($name);
            if ([] !== $platformModels) {
                $models[$name] = $platformModels;
            }
        }

        return [] !== $models ? $models : ['openai' => ['gpt-image-1' => 'gpt-image-1']];
    }

    /**
     * @return array<string, string>
     */
    protected function getImageModelChoices(string $platformName): array
    {
        if (!$this->platforms->has($platformName)) {
            return [];
        }

        $platform = $this->platforms->get($platformName);
        if (!$platform instanceof PlatformInterface) {
            return [];
        }

        $models = [];
        foreach ($platform->getModelCatalog()->getModels() as $model => $definition) {
            foreach ($definition['capabilities'] ?? [] as $capability) {
                if ($capability instanceof Capability && Capability::OUTPUT_IMAGE === $capability) {
                    $models[$model] = $model;
                    break;
                }
            }
        }

        if ('openai' === $platformName) {
            $models = ['gpt-image-1' => 'gpt-image-1'] + $models;
            $models = array_merge($models, $this->openAiImageModels->getChoices());
        }

        if ('gemini' === $platformName) {
            $models = self::GEMINI_IMAGE_MODELS + $models;
        }

        ksort($models);

        return $models;
    }
}
