<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Bridge\Mcp;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use OutOfBoundsException;
use Softspring\CmsAiPlugin\Tool\ToolProviderInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Contracts\Service\ServiceProviderInterface;

class McpToolProvider implements ToolProviderInterface
{
    public function __construct(
        protected readonly Server $server,
        private readonly RegistryInterface $registry,
        private readonly ServiceProviderInterface $toolServices,
    ) {
    }

    public function getDefinitions(): array
    {
        $definitions = [];
        foreach ($this->registry->getTools()->references as $tool) {
            if (str_starts_with($tool->name, 'sfs_cms_')) {
                $definitions[$tool->name] = [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'input_schema' => $tool->inputSchema,
                ];
            }
        }
        ksort($definitions);

        return $definitions;
    }

    public function getTools(): array
    {
        $tools = [];
        foreach ($this->getDefinitions() as $name => $definition) {
            $handler = $this->registry->getTool($name)->handler;
            if (!is_array($handler)) {
                continue;
            }
            $parameters = $definition['input_schema'];
            $parameters['additionalProperties'] = false;
            $tools[$name] = new Tool(
                new ExecutionReference($handler[0], $handler[1]),
                $name,
                $definition['description'] ?? 'CMS tool',
                $parameters,
            );
        }

        return $tools;
    }

    public function execute(string $name, array $arguments): mixed
    {
        if (!isset($this->getDefinitions()[$name])) {
            throw new OutOfBoundsException(sprintf('CMS tool "%s" was not found.', $name));
        }
        $arguments['_session'] = new Session(new InMemorySessionStore());

        return new ReferenceHandler($this->toolServices)->handle($this->registry->getTool($name), $arguments);
    }
}
