<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Tests\Media;

use PHPUnit\Framework\TestCase;
use Softspring\CmsAiPlugin\Media\MediaImageGenerationRequirements;

class MediaImageGenerationRequirementsTest extends TestCase
{
    public function testItResolvesGenerationSizeFromUploadRequirements(): void
    {
        $requirements = new MediaImageGenerationRequirements();

        self::assertSame('1024x1536', $requirements->resolveGenerationSize(['allowLandscape' => false]));
        self::assertSame('1536x1024', $requirements->resolveGenerationSize(['allowPortrait' => false]));
        self::assertSame('1536x1024', $requirements->resolveGenerationSize(['minWidth' => 1400, 'minHeight' => 900]));
        self::assertSame('1024x1536', $requirements->resolveGenerationSize(['minWidth' => 900, 'minHeight' => 1400]));
        self::assertSame('1024x1024', $requirements->resolveGenerationSize([]));
    }

    public function testItBuildsPromptRequirementsForImageGeneration(): void
    {
        $requirements = new MediaImageGenerationRequirements();

        self::assertSame([
            'Output size: 1024x1536.',
            'The image must be suitable for a media slot that requires at least anypx width and 1600px height.',
            'Do not create a landscape image.',
        ], $requirements->buildPromptRequirements([
            'minHeight' => 1600,
            'allowLandscape' => false,
        ]));
    }

    public function testItDetectsPngOutputSupport(): void
    {
        $requirements = new MediaImageGenerationRequirements();

        self::assertTrue($requirements->supportsPngOutput([]));
        self::assertTrue($requirements->supportsPngOutput(['mimeTypes' => ['image/png']]));
        self::assertFalse($requirements->supportsPngOutput(['mimeTypes' => ['image/jpeg']]));
    }
}
