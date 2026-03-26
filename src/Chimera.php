<?php

declare(strict_types=1);

namespace ChimeraPHP;

use ChimeraPHP\Bridge\A2EBridge;
use ChimeraPHP\Bridge\MemoryBridge;
use ChimeraPHP\Bridge\ShellBridge;
use ChimeraPHP\Core\AgentLoop;
use ChimeraPHP\Core\EventEmitter;
use ChimeraPHP\Core\ToolRegistry;
use ChimeraPHP\LLM\Message;
use ChimeraPHP\LLM\ProviderInterface;
use ChimeraPHP\Memory\ContextBuilder;
use ChimeraPHP\Memory\LearningLoop;
use ChimeraPHP\Memory\SessionStore;

/**
 * Chimera PHP — Self-improving autonomous AI agent.
 *
 * Integrates: LLM providers + tool execution + persistent memory + learning.
 */
final class Chimera
{
    public readonly ToolRegistry $tools;
    public readonly EventEmitter $events;
    public readonly SessionStore $sessions;

    private readonly ProviderInterface $provider;
    private readonly ContextBuilder $contextBuilder;
    private readonly LearningLoop $learner;
    private readonly Config $config;

    /** @var Message[] */
    private array $history = [];

    private ?object $agentMemory = null;
    private ?callable $embedFn = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->provider = $config->createProvider();
        $this->tools = new ToolRegistry();
        $this->events = new EventEmitter();
        $this->sessions = new SessionStore($config->dataDir);

        // Try loading optional packages
        $this->loadOptionalPackages();

        // Build context builder
        $this->contextBuilder = new ContextBuilder(
            basePrompt: $config->systemPrompt(),
            agentMemory: $this->agentMemory,
            embedFn: $this->embedFn,
        );

        // Learning loop
        $this->learner = new LearningLoop($this->provider, $this->agentMemory, $this->embedFn);
    }

    /**
     * Chat: process a user message through the full agent loop.
     *
     * @return array{content: string, iterations: int, totalTokens: int, toolsUsed: string[], learned: array}
     */
    public function chat(string $userMessage): array
    {
        // Build context-augmented messages
        $messages = $this->contextBuilder->build($this->history, $userMessage);

        // Run agent loop
        $loop = new AgentLoop($this->provider, $this->tools, $this->events, $this->config->maxIterations);
        $result = $loop->run($messages);

        // Update history
        $this->history[] = Message::user($userMessage);
        $this->history[] = Message::assistant($result['content']);

        // Learning loop (post-conversation, best-effort)
        $usedTools = !empty($result['toolsUsed']);
        $learned = $this->learner->learn($result['messages'] ?? [], $usedTools);
        $result['learned'] = $learned;

        return $result;
    }

    /**
     * Clear conversation history.
     */
    public function clear(): void
    {
        $this->history = [];
    }

    public function getProvider(): ProviderInterface { return $this->provider; }

    /**
     * Load optional PHP packages and register their tools.
     */
    private function loadOptionalPackages(): void
    {
        // php-agent-memory
        if (class_exists(\PHPAgentMemory\AgentMemory::class)) {
            try {
                $cfAccount = $this->config->cfAccountId;
                $cfToken = $this->config->cfApiToken;

                $this->embedFn = function (string $text) use ($cfAccount, $cfToken): array {
                    $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/{$cfAccount}/ai/run/@cf/google/embeddinggemma-300m");
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$cfToken}", 'Content-Type: application/json'],
                        CURLOPT_POSTFIELDS => json_encode(['text' => [$text]]),
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    $response = curl_exec($ch);
                    curl_close($ch);
                    if ($response === false) return array_fill(0, 768, 0.0);
                    $data = json_decode($response, true) ?? [];
                    return $data['result']['data'][0] ?? array_fill(0, 768, 0.0);
                };

                $memConfig = new \PHPAgentMemory\Config(
                    dataDir: $this->config->dataDir . '/memory',
                    dimensions: 768,
                    quantized: true,
                    embedFn: $this->embedFn,
                );
                $this->agentMemory = new \PHPAgentMemory\AgentMemory($memConfig);
                $this->tools->registerAll(MemoryBridge::tools($this->agentMemory, $this->embedFn));
            } catch (\Throwable) {}
        }

        // php-agent-shell
        if (class_exists(\PHPAgentShell\AgentShell::class) && $this->embedFn) {
            try {
                $shell = new \PHPAgentShell\AgentShell(embedFn: $this->embedFn, dimensions: 768);
                $shell->registerDefaults();
                $this->tools->registerAll(ShellBridge::tools($shell));
            } catch (\Throwable) {
                $this->tools->registerAll(ShellBridge::fallbackTools());
            }
        } else {
            $this->tools->registerAll(ShellBridge::fallbackTools());
        }

        // php-a2e
        if (class_exists(\PHPA2E\A2E::class)) {
            try {
                $a2e = new \PHPA2E\A2E(new \PHPA2E\Config(dataDir: $this->config->dataDir . '/a2e', masterKey: 'chimera'));
                $this->tools->registerAll(A2EBridge::tools($a2e));
            } catch (\Throwable) {}
        }
    }
}
