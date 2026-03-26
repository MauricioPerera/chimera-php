<?php
declare(strict_types=1);
namespace ChimeraPHP\Memory;

use ChimeraPHP\LLM\Message;
use ChimeraPHP\LLM\ProviderInterface;

/**
 * Post-conversation self-improvement: extract knowledge and save to memory.
 */
final class LearningLoop
{
    public function __construct(
        private readonly ProviderInterface $llm,
        private readonly ?object $agentMemory = null,
        private readonly ?callable $embedFn = null,
    ) {}

    /**
     * @param Message[] $messages Conversation history
     * @return array{sessionSaved: bool, memoriesExtracted: int, skillsExtracted: int}
     */
    public function learn(array $messages, bool $usedTools): array
    {
        $result = ['sessionSaved' => false, 'memoriesExtracted' => 0, 'skillsExtracted' => 0];

        if (!$this->agentMemory || !$this->embedFn || !$usedTools) {
            return $result;
        }

        // Build conversation transcript
        $transcript = '';
        foreach ($messages as $msg) {
            if ($msg->role === 'system') continue;
            $content = $msg->content ?? '';
            if ($content !== '') $transcript .= "[{$msg->role}]: {$content}\n";
        }

        if (strlen($transcript) < 100) return $result;

        // Ask LLM to extract knowledge
        $extractionPrompt = <<<PROMPT
Analyze this conversation and extract useful knowledge. Return JSON only:
{
  "memories": [{"content": "...", "tags": ["..."], "category": "fact|decision|issue|task|correction"}],
  "skills": [{"content": "...", "tags": ["..."], "category": "procedure|configuration|troubleshooting|workflow"}]
}
Rules:
- Max 3 memories, max 2 skills
- Only extract genuinely useful, reusable information
- Skip trivial or conversation-specific details
- If nothing worth saving, return empty arrays

Conversation:
{$transcript}
PROMPT;

        try {
            $response = $this->llm->chat([
                Message::system('You extract knowledge from conversations. Return valid JSON only.'),
                Message::user($extractionPrompt),
            ]);

            $json = $response->content ?? '';
            if (preg_match('/```(?:json)?\s*(.+?)```/s', $json, $m)) $json = $m[1];
            $data = json_decode(trim($json), true);

            if (!is_array($data)) return $result;

            $embedFn = $this->embedFn;

            // Save memories
            foreach (($data['memories'] ?? []) as $mem) {
                if (empty($mem['content'])) continue;
                $vec = $embedFn($mem['content']);
                $this->agentMemory->memories->saveOrUpdate('chimera', 'user', [
                    'content' => $mem['content'],
                    'tags' => $mem['tags'] ?? [],
                    'category' => $mem['category'] ?? 'fact',
                ], $vec);
                $result['memoriesExtracted']++;
            }

            // Save skills
            foreach (($data['skills'] ?? []) as $skill) {
                if (empty($skill['content'])) continue;
                $vec = $embedFn($skill['content']);
                $this->agentMemory->skills->saveOrUpdate('chimera', null, [
                    'content' => $skill['content'],
                    'tags' => $skill['tags'] ?? [],
                    'category' => $skill['category'] ?? 'procedure',
                ], $vec);
                $result['skillsExtracted']++;
            }

            if ($result['memoriesExtracted'] > 0 || $result['skillsExtracted'] > 0) {
                $this->agentMemory->flush();
                $result['sessionSaved'] = true;
            }
        } catch (\Throwable) {
            // Learning is best-effort
        }

        return $result;
    }
}
