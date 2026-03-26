<?php
declare(strict_types=1);
namespace ChimeraPHP\Tests\Core;

use PHPUnit\Framework\TestCase;
use ChimeraPHP\Core\AgentLoop;
use ChimeraPHP\Core\AntiLoop;
use ChimeraPHP\Core\EventEmitter;
use ChimeraPHP\Core\ToolDefinition;
use ChimeraPHP\Core\ToolRegistry;
use ChimeraPHP\LLM\LLMResponse;
use ChimeraPHP\LLM\Message;
use ChimeraPHP\LLM\MessageNormalizer;
use ChimeraPHP\LLM\ProviderInterface;
use ChimeraPHP\LLM\ToolCall;

/** Mock LLM provider for testing. */
class MockProvider implements ProviderInterface
{
    private array $responses = [];
    private int $callIndex = 0;
    private string $currentModel = 'mock';

    public function name(): string { return 'mock'; }
    public function model(): string { return $this->currentModel; }
    public function setModel(string $model): void { $this->currentModel = $model; }

    public function addResponse(LLMResponse $r): void { $this->responses[] = $r; }

    public function chat(array $messages, array $tools = []): LLMResponse
    {
        return $this->responses[$this->callIndex++] ?? new LLMResponse('No more responses', null);
    }
}

final class AgentLoopTest extends TestCase
{
    public function testSimpleTextResponse(): void
    {
        $provider = new MockProvider();
        $provider->addResponse(new LLMResponse('Hello!', null, 'stop', 10, 5));

        $tools = new ToolRegistry();
        $events = new EventEmitter();
        $loop = new AgentLoop($provider, $tools, $events, 5);

        $result = $loop->run([Message::user('Hi')]);
        $this->assertSame('Hello!', $result['content']);
        $this->assertSame(1, $result['iterations']);
    }

    public function testToolCallAndResponse(): void
    {
        $provider = new MockProvider();
        // First: tool call
        $provider->addResponse(new LLMResponse(null, [
            new ToolCall('call_1', 'get_time', []),
        ], 'tool_calls'));
        // Second: text response after tool result
        $provider->addResponse(new LLMResponse('The time is 12:00', null, 'stop'));

        $tools = new ToolRegistry();
        $tools->register(new ToolDefinition('get_time', 'Get current time', [
            'type' => 'object', 'properties' => [], 'required' => [],
        ], fn() => json_encode(['time' => '12:00']), safe: true));

        $events = new EventEmitter();
        $loop = new AgentLoop($provider, $tools, $events, 10);

        $result = $loop->run([Message::user('What time is it?')]);
        $this->assertSame('The time is 12:00', $result['content']);
        $this->assertSame(2, $result['iterations']);
        $this->assertContains('get_time', $result['toolsUsed']);
    }

    public function testAntiLoopDetection(): void
    {
        $antiLoop = new AntiLoop(2);

        $calls = [new ToolCall('1', 'search', []), new ToolCall('2', 'search', [])];
        $this->assertFalse($antiLoop->check($calls)); // 1st time
        $this->assertTrue($antiLoop->check($calls));  // 2nd time → trigger
    }

    public function testAntiLoopReset(): void
    {
        $antiLoop = new AntiLoop(2);
        $calls = [new ToolCall('1', 'foo', [])];
        $antiLoop->check($calls);
        $antiLoop->check($calls); // would trigger

        $antiLoop->reset();
        $this->assertFalse($antiLoop->check($calls)); // reset, 1st again
    }

    public function testMaxIterations(): void
    {
        $provider = new MockProvider();
        // Use different tool names each time to avoid anti-loop
        for ($i = 0; $i < 5; $i++) {
            $provider->addResponse(new LLMResponse(null, [
                new ToolCall("call_{$i}", "tool_{$i}", []),
            ], 'tool_calls'));
        }

        $tools = new ToolRegistry();
        for ($i = 0; $i < 5; $i++) {
            $tools->register(new ToolDefinition("tool_{$i}", "Tool {$i}", [
                'type' => 'object', 'properties' => [], 'required' => [],
            ], fn() => '{"ok":true}'));
        }

        $events = new EventEmitter();
        $loop = new AgentLoop($provider, $tools, $events, 3);

        $result = $loop->run([Message::user('Loop forever')]);
        $this->assertSame(3, $result['iterations']);
        $this->assertStringContainsString('maximum iterations', $result['content']);
    }

    public function testToolRegistryExecuteAll(): void
    {
        $tools = new ToolRegistry();
        $tools->register(new ToolDefinition('safe1', 'Safe', ['type' => 'object', 'properties' => []], fn() => '"a"', safe: true));
        $tools->register(new ToolDefinition('unsafe1', 'Unsafe', ['type' => 'object', 'properties' => []], fn() => '"b"', safe: false));

        $calls = [
            new ToolCall('c1', 'safe1', []),
            new ToolCall('c2', 'unsafe1', []),
        ];

        $results = $tools->executeAll($calls);
        $this->assertSame('"a"', $results['c1']);
        $this->assertSame('"b"', $results['c2']);
    }

    public function testEventEmitter(): void
    {
        $emitter = new EventEmitter();
        $received = [];
        $emitter->on('test', function ($data) use (&$received) { $received[] = $data; });
        $emitter->emit('test', ['foo' => 'bar']);
        $this->assertSame([['foo' => 'bar']], $received);
    }

    public function testMessageNormalizerOpenAI(): void
    {
        $raw = [
            'choices' => [['message' => ['content' => 'Hello', 'role' => 'assistant'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ];
        $response = MessageNormalizer::normalize($raw);
        $this->assertSame('Hello', $response->content);
        $this->assertNull($response->toolCalls);
        $this->assertSame(15, $response->totalTokens());
    }

    public function testMessageNormalizerToolCalls(): void
    {
        $raw = [
            'choices' => [['message' => [
                'content' => null,
                'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'test', 'arguments' => '{"a":1}']]],
            ], 'finish_reason' => 'tool_calls']],
        ];
        $response = MessageNormalizer::normalize($raw);
        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('test', $response->toolCalls[0]->name);
        $this->assertSame(['a' => 1], $response->toolCalls[0]->arguments);
    }

    public function testMessageNormalizerEmbeddedTags(): void
    {
        $raw = ['choices' => [['message' => [
            'content' => 'I will search <tool_call>{"name":"search","arguments":{"q":"test"}}</tool_call>',
        ], 'finish_reason' => 'stop']]];
        $response = MessageNormalizer::normalize($raw);
        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('search', $response->toolCalls[0]->name);
    }

    public function testMessageNormalizerWorkersAI(): void
    {
        $raw = ['result' => ['response' => 'Hello from Workers AI'], 'success' => true];
        $response = MessageNormalizer::normalize($raw);
        $this->assertSame('Hello from Workers AI', $response->content);
    }

    public function testToolRegistryDisableEnable(): void
    {
        $tools = new ToolRegistry();
        $tools->register(new ToolDefinition('t1', 'Test', ['type' => 'object', 'properties' => []], fn() => 'ok'));
        $this->assertCount(1, $tools->getEnabled());

        $tools->setEnabled('t1', false);
        $this->assertCount(0, $tools->getEnabled());

        $tools->enableAll();
        $this->assertCount(1, $tools->getEnabled());
    }
}
