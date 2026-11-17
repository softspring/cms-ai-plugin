<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Lab;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;

final class ChatModelChoices
{
    public const DEFAULT_MODELS = [
        'openai' => ['gpt-6.1-sol', 'gpt-6.1-terra', 'gpt-6-astra', 'gpt-6-luna'],
        'gemini' => ['gemini-4-hydrogen', 'gemini-4-argon', 'gemini-4-carbon'],
    ];

    /** @param array<string, list<string>> $modelsByPlatform */
    public function __construct(private readonly array $modelsByPlatform = self::DEFAULT_MODELS)
    {
    }

    /** @return array<string, string> */
    public function forPlatform(PlatformInterface $platform, string $platformName, bool $structuredOutput = false): array
    {
        $required = [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::TOOL_CALLING];
        if ($structuredOutput) {
            $required[] = Capability::OUTPUT_STRUCTURED;
        }
        $catalog = $platform->getModelCatalog();
        $available = $catalog->getModels();
        $choices = [];
        foreach ($this->modelsByPlatform[$platformName] ?? [] as $name) {
            if (!array_key_exists($name, $available)) {
                continue;
            }
            $model = $catalog->getModel($name);
            foreach ($required as $capability) {
                if (!$model->supports($capability)) {
                    continue 2;
                }
            }
            $choices[$name] = $name;
        }

        return $choices;
    }
}
