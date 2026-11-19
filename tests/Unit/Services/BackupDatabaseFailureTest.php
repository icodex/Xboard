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
        $originalDefault = config('database.default');
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
            // sqlite must already exist for the connector; it lives in the temp dir
            // so a MySQL run does not leave a stray file in the repo root
            touch($temporaryStorage . '/source.sqlite');
            config([
                'database.default' => 'sqlite',
                // dump a throwaway file: with DB_DATABASE pointing at a MySQL schema
                // name, the sqlite connection would otherwise create it in the repo root
                'database.connections.sqlite.database' => $temporaryStorage . '/source.sqlite',
            ]);
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
            // RefreshDatabase rolls back on the *default* connection during teardown:
            // leaving it on the deleted sqlite file breaks the next test in the class
            config(['database.default' => $originalDefault]);
            File::deleteDirectory($temporaryStorage);
        }
    }

    public function test_failed_upload_keeps_compressed_backup_and_returns_failure(): void
    {
        $originalStorage = storage_path();
        $originalDefault = config('database.default');
        $temporaryStorage = sys_get_temp_dir() . '/xboard-backup-test-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($temporaryStorage);
        app()->useStoragePath($temporaryStorage);

        try {
            touch($temporaryStorage . '/source.sqlite');
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $temporaryStorage . '/source.sqlite',
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
            // see the comment in the first test: teardown rolls back on the default connection
            config(['database.default' => $originalDefault]);
            File::deleteDirectory($temporaryStorage);
        }
    }
}
