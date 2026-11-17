<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Instructions;

use RuntimeException;
use Softspring\Bundle\ConfigBundle\Attribute\SfsConfig;
use Softspring\Bundle\ConfigBundle\Config\ConfigValueInterface;
use UnexpectedValueException;

final class AiInstructions
{
    public const KEY = 'cms_ai.instructions';
    public const MANAGE_PERMISSION = 'PERMISSION_SFS_CMS_ADMIN_AI_CONFIG';

    public function __construct(
        #[SfsConfig(self::KEY)]
        private readonly ConfigValueInterface $instructions,
        private readonly string $applicationInstructionsFile = __DIR__.'/../../config/prompts/application.txt',
    ) {
    }

    public function buildSystemPrompt(string $workflowInstructions): string
    {
        if (!is_file($this->applicationInstructionsFile) || !is_readable($this->applicationInstructionsFile)) {
            throw new RuntimeException('The CMS AI application instructions file is missing or unreadable.');
        }
        $application = file_get_contents($this->applicationInstructionsFile);
        if (false === $application || '' === trim($application)) {
            throw new RuntimeException('The CMS AI application instructions file is empty or unreadable.');
        }
        $preferences = $this->instructions->get();
        if (!is_string($preferences)) {
            throw new UnexpectedValueException('CMS AI instructions must be a string.');
        }
        $sections = [
            "Application instructions (highest priority):\n".trim($application),
            "Workflow requirements (application priority):\n".trim($workflowInstructions),
        ];
        if ('' !== trim($preferences)) {
            $sections[] = "Configured project preferences (lower priority):\n".trim($preferences);
        }

        return implode("\n\n", $sections);
    }

    public function buildImagePrompt(string $workflowInstructions, string $userPrompt): string
    {
        return $this->buildSystemPrompt($workflowInstructions)."\n\nUser request:\n".$userPrompt;
    }
}
