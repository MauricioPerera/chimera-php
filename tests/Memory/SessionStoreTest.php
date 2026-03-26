<?php
declare(strict_types=1);
namespace ChimeraPHP\Tests\Memory;

use PHPUnit\Framework\TestCase;
use ChimeraPHP\Memory\SessionStore;
use ChimeraPHP\Memory\ContextBuilder;
use ChimeraPHP\LLM\Message;

final class SessionStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/chimera-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $dbFile = $this->tmpDir . '/sessions.db';
        if (file_exists($dbFile)) unlink($dbFile);
        if (file_exists($dbFile . '-wal')) unlink($dbFile . '-wal');
        if (file_exists($dbFile . '-shm')) unlink($dbFile . '-shm');
        @rmdir($this->tmpDir);
    }

    public function testCreateAndListSessions(): void
    {
        if (!class_exists(\SQLite3::class)) { $this->markTestSkipped('SQLite3 ext not available'); }
        $store = new SessionStore($this->tmpDir);
        $id = $store->createSession('granite', 'cli');
        $this->assertNotEmpty($id);

        $sessions = $store->listSessions();
        $this->assertCount(1, $sessions);
        $this->assertSame($id, $sessions[0]['id']);
    }

    public function testSaveAndSearch(): void
    {
        if (!class_exists(\SQLite3::class)) { $this->markTestSkipped('SQLite3 ext not available'); }
        $store = new SessionStore($this->tmpDir);
        $sid = $store->createSession();
        $store->saveMessages($sid, [
            ['role' => 'user', 'content' => 'How do I deploy to production?'],
            ['role' => 'assistant', 'content' => 'Run git push origin main then deploy.sh'],
        ]);

        $results = $store->search('deploy production');
        $this->assertNotEmpty($results);
    }

    public function testContextBuilderTrimsHistory(): void
    {
        $builder = new ContextBuilder(basePrompt: 'You are a test agent.', maxMessages: 3);

        $history = [
            Message::user('First message'),
            Message::assistant('First response'),
            Message::user('Second message'),
            Message::assistant('Second response'),
            Message::user('Third message'),
            Message::assistant('Third response'),
        ];

        $messages = $builder->build($history, 'New question');

        // system + trimmed history + new user = should not exceed max
        $this->assertLessThanOrEqual(5, count($messages)); // system + 3 history + user
        $this->assertSame('system', $messages[0]->role);
        $this->assertSame('user', end($messages)->role);
        $this->assertStringContainsString('New question', end($messages)->content);
    }

    public function testContextBuilderInjectsPrompt(): void
    {
        $builder = new ContextBuilder(basePrompt: 'Custom system prompt here.');
        $messages = $builder->build([], 'Hello');

        $this->assertStringContainsString('Custom system prompt', $messages[0]->content);
    }
}
