<?php
declare(strict_types=1);
namespace ChimeraPHP\Bridge;

use ChimeraPHP\Core\ToolDefinition;

/** Expose php-a2e as 3 tools. */
final class A2EBridge
{
    /** @return ToolDefinition[] */
    public static function tools(object $a2e): array
    {
        return [
            new ToolDefinition('a2e_capabilities', 'List available A2E operations and APIs', [
                'type' => 'object', 'properties' => [], 'required' => [],
            ], fn() => json_encode($a2e->capabilities()), safe: true, category: 'a2e'),

            new ToolDefinition('a2e_validate', 'Validate a JSONL workflow before executing', [
                'type' => 'object', 'properties' => ['workflow' => ['type' => 'string', 'description' => 'JSONL workflow string']], 'required' => ['workflow'],
            ], fn(array $args) => json_encode($a2e->validate($args['workflow'] ?? '')), safe: true, category: 'a2e'),

            new ToolDefinition('a2e_execute', 'Execute a validated JSONL workflow', [
                'type' => 'object', 'properties' => ['workflow' => ['type' => 'string', 'description' => 'JSONL workflow string']], 'required' => ['workflow'],
            ], function (array $args) use ($a2e) {
                $result = $a2e->execute($args['workflow'] ?? '');
                return json_encode($result);
            }, category: 'a2e'),
        ];
    }
}
