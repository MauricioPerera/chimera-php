<?php
declare(strict_types=1);
namespace ChimeraPHP\Core;

final class EventEmitter
{
    /** @var array<string, callable[]> */
    private array $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, array $data = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($data);
        }
    }
}
