<?php

declare(strict_types=1);

namespace ChimeraPHP\Core;

use ChimeraPHP\LLM\Message;
use ChimeraPHP\LLM\ProviderInterface;

/**
 * The heart of Chimera: iterative LLM + tool execution loop.
 *
 * 1. Call LLM with messages + enabled tools
 * 2. If tool calls → execute → add results → loop back
 * 3. If text → return response
 * 4. Anti-loop: if repeated tool calls → disable tools → force text
 * 5. Max iterations: prevent runaway
 */
final class AgentLoop
{
    private readonly AntiLoop $antiLoop;
    private int $totalTokens = 0;

    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly ToolRegistry $tools,
        private readonly EventEmitter $events,
        private readonly int $maxIterations = 25,
    ) {
        $this->antiLoop = new AntiLoop();
    }

    /**
     * Run the agent loop.
     *
     * @param Message[] $messages Full conversation history
     * @return array{content: string, iterations: int, totalTokens: int, toolsUsed: string[]}
     */
    public function run(array $messages): array
    {
        $this->antiLoop->reset();
        $this->totalTokens = 0;
        $toolsUsed = [];
        $toolsDisabled = false;

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $this->events->emit('iteration', ['number' => $i + 1, 'max' => $this->maxIterations]);

            // Get tool definitions (may be disabled by anti-loop)
            $toolDefs = $toolsDisabled ? [] : $this->tools->toOpenAI();

            // Call LLM
            $this->events->emit('thinking', ['model' => $this->provider->model()]);
            $response = $this->provider->chat($messages, $toolDefs);
            $this->totalTokens += $response->totalTokens();

            // Handle tool calls
            if ($response->hasToolCalls() && !$toolsDisabled) {
                // Anti-loop check
                if ($this->antiLoop->check($response->toolCalls)) {
                    $this->events->emit('anti_loop', ['signature' => implode(',', array_map(fn($tc) => $tc->name, $response->toolCalls))]);
                    $toolsDisabled = true;
                    // Don't execute tools, loop back with tools disabled
                    $messages[] = Message::assistant('Let me provide a direct answer instead.');
                    continue;
                }

                // Add assistant message with tool calls
                $messages[] = Message::assistant($response->content, $response->toolCalls);

                // Execute all tool calls
                foreach ($response->toolCalls as $tc) {
                    $this->events->emit('tool_call', ['name' => $tc->name, 'args' => $tc->arguments]);
                    $toolsUsed[] = $tc->name;
                }

                $results = $this->tools->executeAll($response->toolCalls);

                // Add tool results to messages
                foreach ($response->toolCalls as $tc) {
                    $result = $results[$tc->id] ?? '{"error": "no result"}';
                    $this->events->emit('tool_result', ['name' => $tc->name, 'result' => mb_substr($result, 0, 200)]);
                    $messages[] = Message::toolResult($tc->id, $result, $tc->name);
                }

                continue; // Loop back
            }

            // Text response — we're done
            $content = $response->content ?? '';
            $this->events->emit('response', ['content' => $content, 'iterations' => $i + 1]);

            return [
                'content' => $content,
                'iterations' => $i + 1,
                'totalTokens' => $this->totalTokens,
                'toolsUsed' => array_unique($toolsUsed),
                'messages' => $messages,
            ];
        }

        // Max iterations reached
        $this->events->emit('max_iterations', ['limit' => $this->maxIterations]);

        return [
            'content' => "[Agent reached maximum iterations ({$this->maxIterations}). Last partial response may be incomplete.]",
            'iterations' => $this->maxIterations,
            'totalTokens' => $this->totalTokens,
            'toolsUsed' => array_unique($toolsUsed),
            'messages' => $messages,
        ];
    }

    public function getTotalTokens(): int { return $this->totalTokens; }
}
