<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Tool;

use Symfony\AI\Platform\Tool\Tool;

interface ToolProviderInterface
{
    /** @return array<string, array{name: string, description: ?string, input_schema: array}> */
    public function getDefinitions(): array;

    /** @return array<string, Tool> */
    public function getTools(): array;

    public function execute(string $name, array $arguments): mixed;
}
