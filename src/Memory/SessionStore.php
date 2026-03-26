<?php
declare(strict_types=1);
namespace ChimeraPHP\Memory;

/**
 * SQLite + FTS5 session storage for conversation history.
 */
final class SessionStore
{
    private \SQLite3 $db;
    private bool $ftsEnabled = false;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
        $this->db = new \SQLite3($dataDir . '/sessions.db');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sessions (id TEXT PRIMARY KEY, title TEXT, model TEXT, platform TEXT, created_at TEXT, message_count INTEGER DEFAULT 0)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT, role TEXT, content TEXT, tool_calls TEXT, created_at TEXT)');

        // FTS5 for full-text search (graceful degradation if unavailable)
        try {
            $this->db->exec('CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(content, session_id)');
            $this->ftsEnabled = true;
        } catch (\Throwable) {
            $this->ftsEnabled = false;
        }
    }

    public function createSession(string $model = '', string $platform = 'cli'): string
    {
        $id = bin2hex(random_bytes(8));
        $stmt = $this->db->prepare('INSERT INTO sessions (id, title, model, platform, created_at) VALUES (:id, :title, :model, :platform, :at)');
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':title', '');
        $stmt->bindValue(':model', $model);
        $stmt->bindValue(':platform', $platform);
        $stmt->bindValue(':at', date('c'));
        $stmt->execute();
        return $id;
    }

    public function saveMessages(string $sessionId, array $messages): void
    {
        $this->db->exec('BEGIN');
        $stmt = $this->db->prepare('INSERT INTO messages (session_id, role, content, tool_calls, created_at) VALUES (:sid, :role, :content, :tc, :at)');

        foreach ($messages as $msg) {
            $content = $msg['content'] ?? ($msg->content ?? '');
            $role = $msg['role'] ?? ($msg->role ?? 'user');
            $tc = isset($msg['tool_calls']) ? json_encode($msg['tool_calls']) : null;

            $stmt->bindValue(':sid', $sessionId);
            $stmt->bindValue(':role', $role);
            $stmt->bindValue(':content', $content);
            $stmt->bindValue(':tc', $tc);
            $stmt->bindValue(':at', date('c'));
            $stmt->execute();
            $stmt->reset();

            if ($this->ftsEnabled && $content && $role !== 'tool') {
                $fts = $this->db->prepare('INSERT INTO messages_fts (content, session_id) VALUES (:content, :sid)');
                $fts->bindValue(':content', $content);
                $fts->bindValue(':sid', $sessionId);
                @$fts->execute();
            }
        }

        // Fix #1: Prepared statement instead of string interpolation
        $upd = $this->db->prepare('UPDATE sessions SET message_count = (SELECT COUNT(*) FROM messages WHERE session_id = :sid), title = (SELECT content FROM messages WHERE session_id = :sid AND role = \'user\' LIMIT 1) WHERE id = :sid');
        $upd->bindValue(':sid', $sessionId);
        $upd->execute();

        $this->db->exec('COMMIT');
    }

    public function search(string $query, int $limit = 10): array
    {
        if (!$this->ftsEnabled || $query === '') return [];

        $results = [];
        $stmt = $this->db->prepare("SELECT session_id, snippet(messages_fts, 0, '>', '<', '...', 30) as snippet FROM messages_fts WHERE content MATCH :q LIMIT :lim");
        $stmt->bindValue(':q', $query);
        $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
        $res = @$stmt->execute();

        if ($res !== false) {
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) $results[] = $row;
        }
        return $results;
    }

    public function listSessions(int $limit = 20): array
    {
        $results = [];
        $stmt = $this->db->prepare('SELECT * FROM sessions ORDER BY created_at DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
        $res = $stmt->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) $results[] = $row;
        return $results;
    }
}
