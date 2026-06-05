<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in('src')
    ->in('tests')
;

$config = new Config();

return $config->setRules([
        '@Symfony' => true,
        'full_opening_tag' => false,
        'phpdoc_separation' => false,
        'global_namespace_import' => ['import_classes' => true],
    ])
    ->setFinder($finder)
;
