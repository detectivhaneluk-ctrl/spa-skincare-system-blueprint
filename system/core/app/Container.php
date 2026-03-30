<?php

declare(strict_types=1);

namespace Core\App;

final class Container
{
    private array $bindings = [];
    private array $instances = [];

    public function singleton(string $id, callable $factory): void
    {
        $this->bindings[$id] = ['factory' => $factory, 'shared' => true];
    }

    public function bind(string $id, callable $factory): void
    {
        $this->bindings[$id] = ['factory' => $factory, 'shared' => false];
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        $binding = $this->bindings[$id] ?? null;
        if (!$binding) {
            throw new \RuntimeException("No binding for: {$id}");
        }
        $instance = $binding['factory']($this);
        if ($binding['shared']) {
            $this->instances[$id] = $instance;
        }
        return $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }
}
