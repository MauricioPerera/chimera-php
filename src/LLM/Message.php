<?php
declare(strict_types=1);
namespace ChimeraPHP\LLM;

final class Message implements \JsonSerializable
{
    /** @param ToolCall[]|null $toolCalls */
    public function __construct(
        public readonly string $role,
        public readonly ?string $content = null,
        public readonly ?array $toolCalls = null,
        public readonly ?string $toolCallId = null,
        public readonly ?string $name = null,
    ) {}

    public static function system(string $content): self { return new self('system', $content); }
    public static function user(string $content): self { return new self('user', $content); }
    public static function assistant(?string $content, ?array $toolCalls = null): self { return new self('assistant', $content, $toolCalls); }
    public static function toolResult(string $callId, string $content, string $name): self { return new self('tool', $content, null, $callId, $name); }

    public function jsonSerialize(): array
    {
        $data = ['role' => $this->role];
        if ($this->content !== null) $data['content'] = $this->content;
        if ($this->toolCalls) $data['tool_calls'] = array_map(fn($tc) => $tc->jsonSerialize(), $this->toolCalls);
        if ($this->toolCallId) $data['tool_call_id'] = $this->toolCallId;
        if ($this->name) $data['name'] = $this->name;
        return $data;
    }
}
