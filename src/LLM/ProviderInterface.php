<?php
declare(strict_types=1);
namespace ChimeraPHP\LLM;

interface ProviderInterface
{
    public function name(): string;
    public function model(): string;

    /**
     * @param Message[] $messages
     * @param array[] $tools Tool definitions in OpenAI format
     */
    public function chat(array $messages, array $tools = []): LLMResponse;

    public function setModel(string $model): void;
}
