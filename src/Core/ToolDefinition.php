<?php
declare(strict_types=1);
namespace ChimeraPHP\Core;

final class ToolDefinition
{
    /** @param callable(array): string $handler */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters, // JSON Schema
        private readonly mixed $handler,
        public readonly bool $safe = false, // safe=true → can run in parallel
        public readonly string $category = 'general',
        public bool $enabled = true,
    ) {}

    public function execute(array $args): string
    {
        return ($this->handler)($args);
    }

    /** Convert to OpenAI tool format for LLM. */
    public function toOpenAI(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters,
            ],
        ];
    }
}
