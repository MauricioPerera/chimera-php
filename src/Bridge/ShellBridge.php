<?php
declare(strict_types=1);
namespace ChimeraPHP\Bridge;

use ChimeraPHP\Core\ToolDefinition;

/** Expose php-agent-shell as 2 tools (Agent Shell pattern). */
final class ShellBridge
{
    /** @return ToolDefinition[] */
    public static function tools(object $shell): array
    {
        return [
            new ToolDefinition('cli_help', 'Get the Agent Shell interaction protocol — call first to learn available commands', [
                'type' => 'object', 'properties' => [], 'required' => [],
            ], fn() => $shell->help(), safe: true, category: 'shell'),

            new ToolDefinition('cli_exec', 'Execute a shell command. Use "search <query>" to discover, "describe <cmd>" for details, then execute.', [
                'type' => 'object', 'properties' => ['command' => ['type' => 'string', 'description' => 'Command to execute (e.g. "search deploy", "file:list --path .")']], 'required' => ['command'],
            ], function (array $args) use ($shell) {
                return json_encode($shell->exec($args['command'] ?? ''));
            }, category: 'shell'),
        ];
    }

    /** Fallback tools if php-agent-shell not available. */
    public static function fallbackTools(): array
    {
        return [
            new ToolDefinition('shell_exec', 'Execute a shell command', [
                'type' => 'object', 'properties' => ['cmd' => ['type' => 'string', 'description' => 'Command']], 'required' => ['cmd'],
            ], function (array $args) {
                $output = []; $code = 0;
                exec($args['cmd'] ?? 'echo no command', $output, $code);
                return json_encode(['code' => $code, 'output' => implode("\n", $output)]);
            }, category: 'shell'),

            new ToolDefinition('read_file', 'Read file contents', [
                'type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path'],
            ], function (array $args) {
                $path = $args['path'] ?? '';
                return file_exists($path) ? file_get_contents($path) : json_encode(['error' => 'Not found']);
            }, safe: true, category: 'shell'),

            new ToolDefinition('list_dir', 'List directory contents', [
                'type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path'],
            ], function (array $args) {
                $path = $args['path'] ?? '.';
                $files = is_dir($path) ? scandir($path) : [];
                return json_encode(array_values(array_diff($files, ['.', '..'])));
            }, safe: true, category: 'shell'),
        ];
    }
}
