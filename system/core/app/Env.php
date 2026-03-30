<?php

declare(strict_types=1);

namespace Core\App;

final class Env
{
    private static array $vars = [];

    /** @var list<string> Absolute paths, in load order (.env then .env.local). */
    private static array $loadedFiles = [];

    public static function load(string $basePath): void
    {
        self::$vars = [];
        self::$loadedFiles = [];

        $files = [
            $basePath . '/.env',
            $basePath . '/.env.local',
        ];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            self::$loadedFiles[] = $file;
            self::applyEnvFile($file);
        }
    }

    /**
     * @return list<string>
     */
    public static function loadedEnvFilePaths(): array
    {
        return self::$loadedFiles;
    }

    private static function applyEnvFile(string $file): void
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                self::$vars[$name] = $value;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$vars[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
