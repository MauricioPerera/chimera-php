<?php

declare(strict_types=1);

namespace ChimeraPHP\LLM;

final class OpenRouterProvider implements ProviderInterface
{
    private string $currentModel;

    public function __construct(
        private readonly string $apiKey,
        string $model = 'nousresearch/hermes-4-scout',
        private readonly string $baseUrl = 'https://openrouter.ai/api/v1',
    ) {
        $this->currentModel = $model;
    }

    public function name(): string { return 'openrouter'; }
    public function model(): string { return $this->currentModel; }
    public function setModel(string $model): void { $this->currentModel = $model; }

    public function chat(array $messages, array $tools = []): LLMResponse
    {
        $payload = [
            'model' => $this->currentModel,
            'messages' => array_map(fn($m) => $m->jsonSerialize(), $messages),
            'max_tokens' => 2048,
            'temperature' => 0.3,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        $ch = curl_init("{$this->baseUrl}/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->apiKey}",
                'Content-Type: application/json',
                'HTTP-Referer: https://github.com/MauricioPerera/chimera-php',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            return new LLMResponse(content: "OpenRouter error (HTTP {$httpCode}): {$response}", toolCalls: null, finishReason: 'error');
        }

        $raw = json_decode($response, true) ?? [];
        return MessageNormalizer::normalize($raw);
    }
}
