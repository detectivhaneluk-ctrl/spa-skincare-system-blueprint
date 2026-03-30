<?php

declare(strict_types=1);

namespace Core\App;

final class Config
{
    private array $cache = [];

    public function __construct(private string $configPath)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        if (!isset($this->cache[$file])) {
            $path = $this->configPath . '/' . $file . '.php';
            if (!is_file($path)) {
                return $default;
            }
            $this->cache[$file] = require $path;
        }
        $data = $this->cache[$file];
        foreach ($parts as $part) {
            $data = is_array($data) && array_key_exists($part, $data) ? $data[$part] : $default;
        }
        return $data;
    }
}
