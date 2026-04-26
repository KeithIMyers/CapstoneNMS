<?php

namespace App\Services\Ai\Tools;

/**
 * In-memory registry of every tool the agent runner can dispatch. The
 * service container builds one and binds it as a singleton; assistants
 * pull instances by key. Iterating the registry produces the prompt
 * fragment the runner prepends to the system message.
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(Tool $tool): void
    {
        $this->tools[$tool->key()] = $tool;
    }

    public function get(string $key): ?Tool
    {
        return $this->tools[$key] ?? null;
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        return $this->tools;
    }

    /** @return array<string, Tool> Tools whose keys appear in $allowed. */
    public function only(array $allowed): array
    {
        return array_intersect_key($this->tools, array_flip($allowed));
    }
}
