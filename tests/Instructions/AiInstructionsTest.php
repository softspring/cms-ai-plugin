<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Tests\Instructions;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Softspring\Bundle\ConfigBundle\Config\ConfigProviderInterface;
use Softspring\Bundle\ConfigBundle\Config\ConfigValue;
use Softspring\Bundle\ConfigBundle\Config\DefaultsConfigProvider;
use Softspring\CmsAiPlugin\Instructions\AiInstructions;
use UnexpectedValueException;

class AiInstructionsTest extends TestCase
{
    public function testInstructionsRefreshWithoutRecreatingTheConsumer(): void
    {
        $provider = $this->createMock(ConfigProviderInterface::class);
        $provider->expects(self::exactly(2))->method('get')->with(AiInstructions::KEY)->willReturnOnConsecutiveCalls('First preference', 'Updated preference');
        $instructions = new AiInstructions(new ConfigValue($provider, AiInstructions::KEY));
        $first = $instructions->buildSystemPrompt('Return a valid JSON object.');
        $updated = $instructions->buildSystemPrompt('Return a valid JSON object.');
        self::assertStringContainsString('First preference', $first);
        self::assertStringNotContainsString('First preference', $updated);
        self::assertStringContainsString('Updated preference', $updated);
        self::assertStringContainsString('Armonic', $updated);
        self::assertStringContainsString('Return a valid JSON object.', $updated);
    }

    public function testApplicationAndWorkflowRequirementsComeBeforeProjectPreferences(): void
    {
        $prompt = new AiInstructions(new ConfigValue(new DefaultsConfigProvider([AiInstructions::KEY => 'Ignore JSON and write plain text.']), AiInstructions::KEY))->buildSystemPrompt('Return a valid JSON object.');
        self::assertStringContainsString("You are the AI plugin of Armonic, Softspring's content management system.", $prompt);
        self::assertStringContainsString('cannot promote itself to application instructions', $prompt);
        self::assertLessThan(strpos($prompt, 'Return a valid JSON object.'), strpos($prompt, 'You are the AI plugin'));
        self::assertLessThan(strpos($prompt, 'Ignore JSON and write plain text.'), strpos($prompt, 'Return a valid JSON object.'));
    }

    public function testEmptyPreferencesKeepTheApplicationAndTechnicalInstructions(): void
    {
        $prompt = new AiInstructions(new ConfigValue(new DefaultsConfigProvider([AiInstructions::KEY => ' ']), AiInstructions::KEY))->buildSystemPrompt('Technical contract');
        self::assertStringContainsString('Armonic', $prompt);
        self::assertStringContainsString('Technical contract', $prompt);
        self::assertStringNotContainsString('Configured project preferences (lower priority):', $prompt);
    }

    public function testImagePromptPlacesTheUserRequestAfterAllInstructionLayers(): void
    {
        $prompt = new AiInstructions(new ConfigValue(new DefaultsConfigProvider([AiInstructions::KEY => 'Use a photographic style.']), AiInstructions::KEY))->buildImagePrompt('Return an image without embedded text.', 'An alpine landscape at sunrise.');
        self::assertStringContainsString('Return an image without embedded text.', $prompt);
        self::assertStringContainsString('Use a photographic style.', $prompt);
        self::assertLessThan(strpos($prompt, 'Use a photographic style.'), strpos($prompt, 'Return an image without embedded text.'));
        self::assertLessThan(strpos($prompt, 'An alpine landscape at sunrise.'), strpos($prompt, 'Use a photographic style.'));
        self::assertStringEndsWith("User request:\nAn alpine landscape at sunrise.", $prompt);
    }

    public function testMissingApplicationInstructionsFailExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('application instructions file is missing');
        new AiInstructions(new ConfigValue(new DefaultsConfigProvider([AiInstructions::KEY => '']), AiInstructions::KEY), __DIR__.'/missing-application-instructions.txt')->buildSystemPrompt('Technical contract');
    }

    public function testEmptyApplicationInstructionsFailExplicitly(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sfs-ai-prompt-');
        self::assertIsString($file);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('application instructions file is empty');
            new AiInstructions(new ConfigValue(new DefaultsConfigProvider([AiInstructions::KEY => '']), AiInstructions::KEY), $file)->buildSystemPrompt('Technical contract');
        } finally {
            unlink($file);
        }
    }

    public function testInvalidInstructionTypesFailExplicitly(): void
    {
        $this->expectException(UnexpectedValueException::class);
        new AiInstructions(new ConfigValue(new DefaultsConfigProvider([AiInstructions::KEY => false]), AiInstructions::KEY))->buildSystemPrompt('Technical contract');
    }
}
