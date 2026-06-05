<?php

namespace Softspring\CmsAiPlugin;

use Softspring\CmsBundle\Plugin\SfsCmsPlugin;

class SfsCmsAiPlugin extends SfsCmsPlugin
{
    public static function getAlias(): string
    {
        return 'sfs_cms_ai';
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
