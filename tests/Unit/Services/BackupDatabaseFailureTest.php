<?php

namespace Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupDatabaseFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_compression_keeps_the_uncompressed_backup(): void
    {
        $originalStorage = storage_path();
        $originalPath = getenv('PATH');
        $originalEnvPath = $_ENV['PATH'] ?? null;
        $originalServerPath = $_SERVER['PATH'] ?? null;
        $temporaryStorage = sys_get_temp_dir() . '/xboard-backup-test-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($temporaryStorage . '/bin');
        file_put_contents($temporaryStorage . '/bin/gzip', "#!/bin/sh\nexit 1\n");
        chmod($temporaryStorage . '/bin/gzip', 0755);
        app()->useStoragePath($temporaryStorage);
        putenv('PATH=' . $temporaryStorage . '/bin:' . $originalPath);
        $_ENV['PATH'] = $_SERVER['PATH'] = $temporaryStorage . '/bin:' . $originalPath;

        try {
            config(['database.default' => 'sqlite']);
            $status = Artisan::call('backup:database');

            $this->assertSame(1, $status);
            $this->assertCount(1, glob($temporaryStorage . '/backup/*.sql'));
            $this->assertCount(0, glob($temporaryStorage . '/backup/*.gz'));
        } finally {
            putenv('PATH=' . $originalPath);
            if ($originalEnvPath === null) {
                unset($_ENV['PATH']);
            } else {
                $_ENV['PATH'] = $originalEnvPath;
            }
            if ($originalServerPath === null) {
                unset($_SERVER['PATH']);
            } else {
                $_SERVER['PATH'] = $originalServerPath;
            }
            app()->useStoragePath($originalStorage);
            File::deleteDirectory($temporaryStorage);
        }
    }

    public function test_failed_upload_keeps_compressed_backup_and_returns_failure(): void
    {
        $originalStorage = storage_path();
        $temporaryStorage = sys_get_temp_dir() . '/xboard-backup-test-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($temporaryStorage);
        app()->useStoragePath($temporaryStorage);

        try {
            config([
                'database.default' => 'sqlite',
                'cloud_storage.google_cloud.key_file' => $temporaryStorage . '/missing-key.json',
                'cloud_storage.google_cloud.storage_bucket' => 'test-bucket',
            ]);

            $status = Artisan::call('backup:database', ['upload' => 'upload']);

            $this->assertSame(1, $status);
            $backups = glob($temporaryStorage . '/backup/*.sql.gz');
            $this->assertCount(1, $backups);
            $this->assertNotFalse(gzdecode(file_get_contents($backups[0])));
        } finally {
            app()->useStoragePath($originalStorage);
            File::deleteDirectory($temporaryStorage);
        }
    }
}
