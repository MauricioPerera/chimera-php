<?php
declare(strict_types=1);
namespace ChimeraPHP\LLM;

final readonly class ToolCall implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'type' => 'function', 'function' => ['name' => $this->name, 'arguments' => json_encode($this->arguments)]];
    }
}
