<?php
declare(strict_types=1);
namespace ChimeraPHP\Memory;

use ChimeraPHP\LLM\Message;

/**
 * Builds context-augmented system prompts with memory recall and history trimming.
 */
final class ContextBuilder
{
    private mixed $embedFn;

    public function __construct(
        private readonly string $basePrompt = '',
        private readonly int $maxChars = 50000,
        private readonly int $maxMessages = 40,
        private readonly ?object $agentMemory = null,
        ?callable $embedFn = null,
    ) {
        $this->embedFn = $embedFn;
    }

    /**
     * Build messages array with memory-augmented system prompt.
     *
     * @param Message[] $history Existing conversation history
     * @param string $userMessage The new user message
     * @return Message[]
     */
    public function build(array $history, string $userMessage): array
    {
        $systemPrompt = $this->basePrompt ?: $this->defaultPrompt();

        // Inject memory recall if available
        if ($this->agentMemory && $this->embedFn) {
            $recall = $this->recall($userMessage);
            if ($recall !== '') {
                $systemPrompt .= "\n\n<MEMORY>\n{$recall}\n</MEMORY>";
            }
        }

        // Build messages: system + trimmed history + new user message
        $messages = [Message::system($systemPrompt)];

        // Trim history to budget
        $trimmed = $this->trimHistory($history);
        foreach ($trimmed as $msg) {
            $messages[] = $msg;
        }

        $messages[] = Message::user($userMessage);
        return $messages;
    }

    private function recall(string $query): string
    {
        try {
            $embedFn = $this->embedFn;
            $vector = $embedFn($query);
            $context = $this->agentMemory->recall('chimera', 'user', $query, $vector);
            return $context->formatted ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return Message[] */
    private function trimHistory(array $history): array
    {
        if (count($history) <= $this->maxMessages) return $history;

        // Keep first user message + last N messages
        $first = null;
        foreach ($history as $msg) {
            if ($msg->role === 'user') { $first = $msg; break; }
        }

        $tail = array_slice($history, -($this->maxMessages - 1));
        return $first ? array_merge([$first], $tail) : $tail;
    }

    private function defaultPrompt(): string
    {
        return <<<'PROMPT'
You are Chimera, an autonomous AI agent running in PHP. You have access to tools for file operations, shell commands, memory, and workflow execution.

Guidelines:
- Use tools to accomplish tasks. Search before executing.
- Save important information to memory for future recall.
- Be concise and direct in your responses.
- If you're unsure, search your memory first.
PROMPT;
    }
}
