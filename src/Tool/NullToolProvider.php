<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Tool;

use LogicException;

class NullToolProvider implements ToolProviderInterface
{
    public function getDefinitions(): array
    {
        return [];
    }

    public function getTools(): array
    {
        return [];
    }

    public function execute(string $name, array $arguments): mixed
    {
        throw new LogicException(sprintf('No AI tool provider is configured for "%s".', $name));
    }
}
