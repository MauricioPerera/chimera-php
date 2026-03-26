<?php

declare(strict_types=1);

namespace ChimeraPHP\Gateway;

use ChimeraPHP\Chimera;

/**
 * Interactive CLI gateway with slash commands and event display.
 */
final class CliGateway implements GatewayInterface
{
    public function __construct(
        private readonly Chimera $agent,
    ) {
        // Wire events for live feedback
        $agent->events->on('thinking', fn($d) => $this->info("Thinking... ({$d['model']})"));
        $agent->events->on('tool_call', fn($d) => $this->tool("  → {$d['name']}(" . json_encode($d['args'], JSON_UNESCAPED_SLASHES) . ")"));
        $agent->events->on('tool_result', fn($d) => $this->dim("  ← {$d['name']}: {$d['result']}"));
        $agent->events->on('anti_loop', fn($d) => $this->warn("  ⚠ Anti-loop: disabling tools ({$d['signature']})"));
        $agent->events->on('iteration', fn($d) => null); // silent
    }

    public function start(): void
    {
        $this->banner();

        while (true) {
            $input = trim(readline("\n\033[1;36mYou:\033[0m ") ?: '');
            if ($input === '') continue;

            readline_add_history($input);

            // Slash commands
            if (str_starts_with($input, '/')) {
                if ($this->handleCommand($input)) continue;
            }

            // Chat
            $result = $this->agent->chat($input);

            echo "\n\033[1;32mChimera:\033[0m {$result['content']}\n";
            $this->dim("  [{$result['iterations']} iterations, {$result['totalTokens']} tokens, tools: " . implode(',', $result['toolsUsed'] ?: ['none']) . "]");

            if (!empty($result['learned']['memoriesExtracted']) || !empty($result['learned']['skillsExtracted'])) {
                $this->info("  📚 Learned: {$result['learned']['memoriesExtracted']} memories, {$result['learned']['skillsExtracted']} skills");
            }
        }
    }

    private function handleCommand(string $cmd): bool
    {
        $parts = explode(' ', $cmd, 2);
        $command = $parts[0];
        $arg = $parts[1] ?? '';

        return match ($command) {
            '/quit', '/exit', '/q' => $this->quit(),
            '/help' => $this->showHelp(),
            '/tools' => $this->showTools(),
            '/model' => $this->showModel($arg),
            '/sessions' => $this->showSessions(),
            '/search' => $this->searchSessions($arg),
            '/clear' => $this->clearHistory(),
            '/dream' => $this->dream(),
            default => (bool)$this->warn("Unknown command: {$command}. Type /help"),
        };
    }

    private function quit(): bool { echo "\nGoodbye!\n"; exit(0); }

    private function showHelp(): bool
    {
        echo "\n  /help      — Show this help";
        echo "\n  /tools     — List registered tools";
        echo "\n  /model     — Show/change model (/model @cf/zai-org/glm-4.7-flash)";
        echo "\n  /sessions  — List recent sessions";
        echo "\n  /search Q  — Search past conversations";
        echo "\n  /dream     — Run memory consolidation";
        echo "\n  /clear     — Clear conversation history";
        echo "\n  /quit      — Exit\n";
        return true;
    }

    private function showTools(): bool
    {
        $cats = $this->agent->tools->byCategory();
        foreach ($cats as $cat => $tools) {
            echo "\n  [{$cat}]";
            foreach ($tools as $t) {
                $status = $t->enabled ? '✓' : '✗';
                $safe = $t->safe ? ' (safe)' : '';
                echo "\n    {$status} {$t->name} — {$t->description}{$safe}";
            }
        }
        echo "\n  Total: {$this->agent->tools->count()} tools\n";
        return true;
    }

    private function showModel(string $newModel): bool
    {
        if ($newModel !== '') {
            $this->agent->getProvider()->setModel($newModel);
            $this->info("Model changed to: {$newModel}");
        } else {
            echo "\n  Current model: {$this->agent->getProvider()->model()}\n";
        }
        return true;
    }

    private function showSessions(): bool
    {
        $sessions = $this->agent->sessions->listSessions(10);
        if (empty($sessions)) { echo "\n  No sessions yet.\n"; return true; }
        foreach ($sessions as $s) {
            $title = mb_substr($s['title'] ?? 'Untitled', 0, 50);
            echo "\n  [{$s['id']}] {$title} ({$s['message_count']} msgs, {$s['created_at']})";
        }
        echo "\n";
        return true;
    }

    private function searchSessions(string $query): bool
    {
        if ($query === '') { $this->warn("Usage: /search <query>"); return true; }
        $results = $this->agent->sessions->search($query, 5);
        if (empty($results)) { echo "\n  No results.\n"; return true; }
        foreach ($results as $r) echo "\n  [{$r['session_id']}] {$r['snippet']}";
        echo "\n";
        return true;
    }

    private function clearHistory(): bool
    {
        $this->agent->clear();
        $this->info("History cleared.");
        return true;
    }

    private function dream(): bool
    {
        $this->info("Agent sleeping... consolidating memories...");
        try {
            // Access agentMemory through reflection or try direct
            $result = $this->agent->chat('Please consolidate and organize your memories by calling the recall tool to review what you know, then summarize what you remember.');
            echo "\n\033[1;32mChimera:\033[0m {$result['content']}\n";
        } catch (\Throwable $e) {
            $this->warn("Dream failed: " . $e->getMessage());
        }
        return true;
    }

    private function banner(): void
    {
        echo "\n\033[1;35m";
        echo "  ╔══════════════════════════════════════╗\n";
        echo "  ║   Chimera PHP — Autonomous Agent     ║\n";
        echo "  ║   Model: {$this->pad($this->agent->getProvider()->model(), 27)} ║\n";
        echo "  ║   Tools: {$this->pad((string)$this->agent->tools->count(), 27)} ║\n";
        echo "  ║   Type /help for commands             ║\n";
        echo "  ╚══════════════════════════════════════╝\n";
        echo "\033[0m\n";
    }

    private function pad(string $s, int $len): string { return str_pad(mb_substr($s, 0, $len), $len); }
    private function info(string $msg): void { echo "\n\033[0;36m{$msg}\033[0m"; }
    private function tool(string $msg): void { echo "\n\033[0;33m{$msg}\033[0m"; }
    private function dim(string $msg): void { echo "\n\033[0;90m{$msg}\033[0m"; }
    private function warn(string $msg): void { echo "\n\033[0;31m{$msg}\033[0m"; }
}
