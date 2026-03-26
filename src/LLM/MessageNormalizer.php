<?php

declare(strict_types=1);

namespace ChimeraPHP\LLM;

/**
 * Normalizes LLM responses from 3 different formats into unified ToolCall[].
 *
 * 1. OpenAI format: choices[0].message.tool_calls[]
 * 2. Workers AI flat: tool_calls at root level
 * 3. Text-embedded: <tool_call>{"name":"...","arguments":{...}}</tool_call>
 */
final class MessageNormalizer
{
    /**
     * Parse raw API response into LLMResponse.
     */
    public static function normalize(array $raw): LLMResponse
    {
        // Format 1: OpenAI chat completions (also used by OpenRouter)
        if (isset($raw['choices'][0]['message'])) {
            return self::parseOpenAI($raw);
        }

        // Format 1b: Workers AI wrapping OpenAI format in result.choices
        if (isset($raw['result']['choices'][0]['message'])) {
            return self::parseOpenAI($raw['result']);
        }

        // Format 2: Workers AI flat format
        if (isset($raw['result']['response']) || isset($raw['result']['tool_calls'])) {
            return self::parseWorkersAI($raw);
        }

        // Fallback: try to extract from any 'response' field
        if (isset($raw['response'])) {
            return self::parseTextResponse($raw['response']);
        }

        return new LLMResponse(content: json_encode($raw), toolCalls: null, finishReason: 'error');
    }

    private static function parseOpenAI(array $raw): LLMResponse
    {
        $choice = $raw['choices'][0] ?? [];
        $msg = $choice['message'] ?? [];
        $content = $msg['content'] ?? null;
        $finishReason = $choice['finish_reason'] ?? 'stop';
        $usage = $raw['usage'] ?? [];

        $toolCalls = null;
        if (!empty($msg['tool_calls'])) {
            $toolCalls = [];
            foreach ($msg['tool_calls'] as $tc) {
                $fn = $tc['function'] ?? [];
                $args = is_string($fn['arguments'] ?? null)
                    ? (json_decode($fn['arguments'], true) ?? [])
                    : ($fn['arguments'] ?? []);
                $toolCalls[] = new ToolCall(
                    id: $tc['id'] ?? 'call_' . bin2hex(random_bytes(4)),
                    name: $fn['name'] ?? '',
                    arguments: $args,
                );
            }
        }

        // Check for text-embedded tool calls if no structured ones found
        if (empty($toolCalls) && $content !== null) {
            $embedded = self::extractEmbeddedToolCalls($content);
            if (!empty($embedded)) {
                $toolCalls = $embedded;
                $content = self::stripToolCallTags($content);
                $finishReason = 'tool_calls';
            }
        }

        return new LLMResponse(
            content: $content,
            toolCalls: $toolCalls ?: null,
            finishReason: $finishReason === 'tool_calls' ? 'tool_calls' : ($toolCalls ? 'tool_calls' : $finishReason),
            promptTokens: $usage['prompt_tokens'] ?? 0,
            completionTokens: $usage['completion_tokens'] ?? 0,
        );
    }

    private static function parseWorkersAI(array $raw): LLMResponse
    {
        $result = $raw['result'] ?? [];
        $content = $result['response'] ?? ($result['choices'][0]['message']['content'] ?? null);
        $usage = $result['usage'] ?? [];

        $toolCalls = null;
        $rawCalls = $result['tool_calls'] ?? ($result['choices'][0]['message']['tool_calls'] ?? []);
        if (!empty($rawCalls)) {
            $toolCalls = [];
            foreach ($rawCalls as $tc) {
                $fn = $tc['function'] ?? $tc;
                $args = is_string($fn['arguments'] ?? null)
                    ? (json_decode($fn['arguments'], true) ?? [])
                    : ($fn['arguments'] ?? []);
                $toolCalls[] = new ToolCall(
                    id: $tc['id'] ?? 'call_' . bin2hex(random_bytes(4)),
                    name: $fn['name'] ?? $tc['name'] ?? '',
                    arguments: $args,
                );
            }
        }

        // Check text-embedded
        if (empty($toolCalls) && $content !== null) {
            $embedded = self::extractEmbeddedToolCalls($content);
            if (!empty($embedded)) {
                $toolCalls = $embedded;
                $content = self::stripToolCallTags($content);
            }
        }

        return new LLMResponse(
            content: $content,
            toolCalls: $toolCalls ?: null,
            finishReason: $toolCalls ? 'tool_calls' : 'stop',
            promptTokens: $usage['prompt_tokens'] ?? 0,
            completionTokens: $usage['completion_tokens'] ?? 0,
        );
    }

    private static function parseTextResponse(string $text): LLMResponse
    {
        $embedded = self::extractEmbeddedToolCalls($text);
        if (!empty($embedded)) {
            return new LLMResponse(
                content: self::stripToolCallTags($text),
                toolCalls: $embedded,
                finishReason: 'tool_calls',
            );
        }
        return new LLMResponse(content: $text, toolCalls: null, finishReason: 'stop');
    }

    /**
     * Extract <tool_call>...</tool_call> tags from text (Granite/Hermes style).
     * @return ToolCall[]
     */
    private static function extractEmbeddedToolCalls(string $text): array
    {
        $calls = [];
        if (preg_match_all('/<tool_call>\s*(\{.+?\})\s*<\/tool_call>/s', $text, $matches)) {
            foreach ($matches[1] as $json) {
                $parsed = json_decode($json, true);
                if ($parsed && isset($parsed['name'])) {
                    $calls[] = new ToolCall(
                        id: 'call_' . bin2hex(random_bytes(4)),
                        name: $parsed['name'],
                        arguments: $parsed['arguments'] ?? $parsed['parameters'] ?? [],
                    );
                }
            }
        }
        return $calls;
    }

    private static function stripToolCallTags(string $text): string
    {
        $cleaned = preg_replace('/<tool_call>\s*\{.+?\}\s*<\/tool_call>/s', '', $text);
        return trim($cleaned ?? $text);
    }
}
