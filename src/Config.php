<?php
declare(strict_types=1);
namespace ChimeraPHP;

final class Config
{
    public readonly string $provider;
    public readonly string $model;
    public readonly string $cfAccountId;
    public readonly string $cfApiToken;
    public readonly string $openrouterKey;
    public readonly int $maxIterations;
    public readonly string $dataDir;
    public readonly string $logLevel;
    public readonly ?string $telegramToken;
    public readonly array $telegramAllowed;
    public readonly ?string $systemPromptFile;

    public function __construct()
    {
        // Load .env if exists
        $envFile = getcwd() . '/.env';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (str_contains($line, '=')) putenv($line);
            }
        }

        $this->provider = getenv('CHIMERA_LLM_PROVIDER') ?: 'workers-ai';
        $this->model = getenv('CHIMERA_LLM_MODEL') ?: '@cf/ibm-granite/granite-4.0-h-micro';
        $this->cfAccountId = getenv('CF_ACCOUNT_ID') ?: '';
        $this->cfApiToken = getenv('CF_API_TOKEN') ?: '';
        $this->openrouterKey = getenv('OPENROUTER_API_KEY') ?: '';
        $this->maxIterations = (int)(getenv('CHIMERA_MAX_ITERATIONS') ?: 25);
        $this->dataDir = getenv('CHIMERA_DATA_DIR') ?: './data';
        $this->logLevel = getenv('CHIMERA_LOG_LEVEL') ?: 'info';
        $this->telegramToken = getenv('TELEGRAM_BOT_TOKEN') ?: null;
        $this->telegramAllowed = array_filter(explode(',', getenv('TELEGRAM_ALLOWED_USERS') ?: ''));
        $this->systemPromptFile = getenv('CHIMERA_SYSTEM_PROMPT') ?: null;
    }

    public function createProvider(): LLM\ProviderInterface
    {
        return match ($this->provider) {
            'openrouter' => new LLM\OpenRouterProvider($this->openrouterKey, $this->model),
            default => new LLM\WorkersAIProvider($this->cfAccountId, $this->cfApiToken, $this->model),
        };
    }

    public function systemPrompt(): string
    {
        if ($this->systemPromptFile && file_exists($this->systemPromptFile)) {
            return file_get_contents($this->systemPromptFile);
        }
        return '';
    }
}
