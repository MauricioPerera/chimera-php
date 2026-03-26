<?php

declare(strict_types=1);

namespace ChimeraPHP\LLM;

final class WorkersAIProvider implements ProviderInterface
{
    private string $currentModel;

    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
        string $model = '@cf/ibm-granite/granite-4.0-h-micro',
    ) {
        $this->currentModel = $model;
    }

    public function name(): string { return 'workers-ai'; }
    public function model(): string { return $this->currentModel; }
    public function setModel(string $model): void { $this->currentModel = $model; }

    public function chat(array $messages, array $tools = []): LLMResponse
    {
        $payload = ['messages' => array_map(fn($m) => $m->jsonSerialize(), $messages)];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        $payload['max_tokens'] = 2048;
        $payload['temperature'] = 0.3;

        $url = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/ai/run/{$this->currentModel}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$this->apiToken}", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            return new LLMResponse(content: "API error (HTTP {$httpCode})", toolCalls: null, finishReason: 'error');
        }

        $raw = json_decode($response, true) ?? [];
        return MessageNormalizer::normalize($raw);
    }
}
