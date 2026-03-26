<?php
declare(strict_types=1);
namespace ChimeraPHP\LLM;

final readonly class LLMResponse
{
    /**
     * @param ToolCall[]|null $toolCalls
     */
    public function __construct(
        public ?string $content,
        public ?array $toolCalls,
        public string $finishReason = 'stop', // stop | tool_calls | length | error
        public int $promptTokens = 0,
        public int $completionTokens = 0,
    ) {}

    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
