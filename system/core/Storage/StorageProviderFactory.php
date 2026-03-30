<?php

declare(strict_types=1);

namespace Core\Storage;

use Core\App\Config;
use Core\Storage\Contracts\StorageProviderInterface;

final class StorageProviderFactory
{
    public static function create(Config $config): StorageProviderInterface
    {
        $driver = strtolower(trim((string) $config->get('storage.driver', 'local')));

        return match ($driver) {
            'local' => LocalFilesystemStorageProvider::fromConfig($config),
            's3_compatible' => S3CompatibleObjectStorageProvider::fromConfig($config),
            default => throw new \RuntimeException(
                'storage.driver must be "local" or "s3_compatible". Got: ' . $driver
            ),
        };
    }
}
