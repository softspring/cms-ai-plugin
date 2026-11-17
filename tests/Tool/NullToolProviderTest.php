<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Tests\Tool;

use LogicException;
use PHPUnit\Framework\TestCase;
use Softspring\CmsAiPlugin\Tool\NullToolProvider;

class NullToolProviderTest extends TestCase
{
    public function testAiCanRunWithoutAnyMcpTools(): void
    {
        $provider = new NullToolProvider();
        self::assertSame([], $provider->getDefinitions());
        self::assertSame([], $provider->getTools());
        $this->expectException(LogicException::class);
        $provider->execute('sfs_cms_sites_list', []);
    }
}
