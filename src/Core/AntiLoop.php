<?php
declare(strict_types=1);
namespace ChimeraPHP\Core;

use ChimeraPHP\LLM\ToolCall;

/**
 * Detects when the agent is stuck calling the same tools repeatedly.
 * If the same tool signature appears 2x consecutively, forces text response.
 */
final class AntiLoop
{
    private ?string $lastSignature = null;
    private int $repeatCount = 0;
    private readonly int $maxRepeats;

    public function __construct(int $maxRepeats = 2)
    {
        $this->maxRepeats = $maxRepeats;
    }

    /**
     * Check tool calls and return true if tools should be disabled.
     * @param ToolCall[] $toolCalls
     */
    public function check(array $toolCalls): bool
    {
        $names = array_map(fn($tc) => $tc->name, $toolCalls);
        sort($names);
        $signature = implode(',', $names);

        if ($signature === $this->lastSignature) {
            $this->repeatCount++;
        } else {
            $this->repeatCount = 1;
            $this->lastSignature = $signature;
        }

        return $this->repeatCount >= $this->maxRepeats;
    }

    public function reset(): void
    {
        $this->lastSignature = null;
        $this->repeatCount = 0;
    }
}
