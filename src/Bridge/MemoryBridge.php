<?php
declare(strict_types=1);
namespace ChimeraPHP\Bridge;

use ChimeraPHP\Core\ToolDefinition;

/** Expose php-agent-memory as 4 agent tools. */
final class MemoryBridge
{
    /** @return ToolDefinition[] */
    public static function tools(object $memory, callable $embedFn): array
    {
        return [
            new ToolDefinition('recall', 'Search your memory for relevant context (memories, skills, knowledge)', [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'What to recall']], 'required' => ['query'],
            ], function (array $args) use ($memory, $embedFn) {
                try {
                    $vec = $embedFn($args['query']);
                    if (empty($vec) || $vec === array_fill(0, count($vec), 0.0)) {
                        return 'No memories yet (memory system initializing).';
                    }
                    $ctx = $memory->recall('chimera', 'user', $args['query'], $vec);
                    return $ctx->formatted ?: 'No relevant memories found.';
                } catch (\Throwable $e) {
                    return 'No memories available: ' . $e->getMessage();
                }
            }, safe: true, category: 'memory'),

            new ToolDefinition('remember', 'Save an important fact, decision, or preference to persistent memory', [
                'type' => 'object', 'properties' => [
                    'content' => ['type' => 'string', 'description' => 'What to remember'],
                    'tags' => ['type' => 'string', 'description' => 'Comma-separated tags'],
                    'category' => ['type' => 'string', 'enum' => ['fact', 'decision', 'issue', 'task', 'correction']],
                ], 'required' => ['content'],
            ], function (array $args) use ($memory, $embedFn) {
                try {
                    $vec = $embedFn($args['content']);
                    $tags = !empty($args['tags']) ? explode(',', $args['tags']) : [];
                    $r = $memory->memories->saveOrUpdate('chimera', 'user', [
                        'content' => $args['content'], 'tags' => array_map('trim', $tags), 'category' => $args['category'] ?? 'fact',
                    ], $vec);
                    $memory->flush();
                    $d = $r['deduplicated'] ? ' (updated existing)' : ' (new)';
                    return "Saved{$d}: {$r['id']}";
                } catch (\Throwable $e) {
                    return 'Memory save failed: ' . $e->getMessage();
                }
            }, category: 'memory'),

            new ToolDefinition('learn_skill', 'Save a reusable procedure or troubleshooting step', [
                'type' => 'object', 'properties' => [
                    'content' => ['type' => 'string', 'description' => 'The procedure/skill'],
                    'tags' => ['type' => 'string', 'description' => 'Comma-separated tags'],
                    'category' => ['type' => 'string', 'enum' => ['procedure', 'configuration', 'troubleshooting', 'workflow']],
                ], 'required' => ['content'],
            ], function (array $args) use ($memory, $embedFn) {
                try {
                    $vec = $embedFn($args['content']);
                    $tags = !empty($args['tags']) ? explode(',', $args['tags']) : [];
                    $r = $memory->skills->saveOrUpdate('chimera', null, [
                        'content' => $args['content'], 'tags' => array_map('trim', $tags), 'category' => $args['category'] ?? 'procedure',
                    ], $vec);
                    $memory->flush();
                    return "Skill saved: {$r['id']}";
                } catch (\Throwable $e) {
                    return 'Skill save failed: ' . $e->getMessage();
                }
            }, category: 'memory'),

            new ToolDefinition('memory_stats', 'Show memory statistics', [
                'type' => 'object', 'properties' => [], 'required' => [],
            ], function () use ($memory) {
                return json_encode($memory->stats());
            }, safe: true, category: 'memory'),
        ];
    }
}
