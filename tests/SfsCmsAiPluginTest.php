<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Softspring\CmsAiPlugin\SfsCmsAiPlugin;

class SfsCmsAiPluginTest extends TestCase
{
    public function testProvidesCmsPluginAlias(): void
    {
        self::assertSame('sfs_cms_ai', SfsCmsAiPlugin::getAlias());
    }

    public function testReturnsPackagePath(): void
    {
        self::assertDirectoryExists((new SfsCmsAiPlugin())->getPath());
    }
}
