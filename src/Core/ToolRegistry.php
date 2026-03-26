<?php
declare(strict_types=1);
namespace ChimeraPHP\Core;

final class ToolRegistry
{
    /** @var array<string, ToolDefinition> */
    private array $tools = [];

    public function register(ToolDefinition $tool): void { $this->tools[$tool->name] = $tool; }
    public function registerAll(array $tools): void { foreach ($tools as $t) $this->register($t); }
    public function get(string $name): ?ToolDefinition { return $this->tools[$name] ?? null; }
    public function has(string $name): bool { return isset($this->tools[$name]); }
    public function count(): int { return count($this->tools); }

    /** @return ToolDefinition[] */
    public function getEnabled(): array { return array_filter($this->tools, fn($t) => $t->enabled); }

    /** Get OpenAI tool definitions for LLM. */
    public function toOpenAI(): array { return array_values(array_map(fn($t) => $t->toOpenAI(), $this->getEnabled())); }

    public function setEnabled(string $name, bool $enabled): void
    {
        if (isset($this->tools[$name])) $this->tools[$name]->enabled = $enabled;
    }

    public function disableAll(): void { foreach ($this->tools as $t) $t->enabled = false; }
    public function enableAll(): void { foreach ($this->tools as $t) $t->enabled = true; }

    /** Execute a single tool call. */
    public function execute(string $name, array $args): string
    {
        $tool = $this->tools[$name] ?? null;
        if (!$tool) return json_encode(['error' => "Unknown tool: {$name}"]);
        if (!$tool->enabled) return json_encode(['error' => "Tool disabled: {$name}"]);

        try {
            return $tool->execute($args);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Execute multiple tool calls. Safe tools run in parallel (conceptually),
     * unsafe tools run sequentially.
     * @return array<string, string> callId → result
     */
    public function executeAll(array $toolCalls): array
    {
        $results = [];

        // Separate safe vs unsafe
        $safe = [];
        $unsafe = [];
        foreach ($toolCalls as $tc) {
            $tool = $this->tools[$tc->name] ?? null;
            if ($tool && $tool->safe) {
                $safe[] = $tc;
            } else {
                $unsafe[] = $tc;
            }
        }

        // Execute safe tools (could be parallel with pcntl, but sequential is fine for PHP)
        foreach ($safe as $tc) {
            $results[$tc->id] = $this->execute($tc->name, $tc->arguments);
        }

        // Execute unsafe tools sequentially
        foreach ($unsafe as $tc) {
            $results[$tc->id] = $this->execute($tc->name, $tc->arguments);
        }

        return $results;
    }

    /** @return string[] */
    public function names(): array { return array_keys($this->tools); }

    /** @return array<string, ToolDefinition[]> */
    public function byCategory(): array
    {
        $cats = [];
        foreach ($this->tools as $t) $cats[$t->category][] = $t;
        return $cats;
    }
}
