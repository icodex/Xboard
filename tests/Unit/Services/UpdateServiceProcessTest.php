<?php

namespace Tests\Unit\Services;

use App\Services\UpdateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Process::run() 不会因为非零退出码抛异常；更新流程必须自己看成功标志。
 * 另外 executeUpdate() 在“检查失败回退缓存”的路径上不能因缺 is_local_newer 崩掉。
 */
class UpdateServiceProcessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(UpdateService::CACHE_UPDATE_INFO);
        Cache::forget(UpdateService::CACHE_UPDATE_LOCK);
    }

    public function test_backup_failure_is_reported(): void
    {
        Process::fake([
            'php artisan backup:database' => Process::result('', 'disk full', 1),
            '*' => Process::result(),
        ]);

        try {
            $this->service()->callBackup();
            $this->fail('backup failure must throw');
        } catch (\Exception $e) {
            $this->assertStringContainsString('disk full', $e->getMessage());
        }
    }

    public function test_pull_failure_is_reported(): void
    {
        Process::fake([
            'git fetch origin master' => Process::result('', 'no network', 1),
            '*' => Process::result(),
        ]);

        $result = $this->service()->callPull();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no network', $result['message']);
    }

    public function test_pull_success_still_succeeds(): void
    {
        Process::fake([
            'git log*' => Process::result('20261118:abcdef1234'),
            '*' => Process::result(),
        ]);

        $result = $this->service()->callPull();

        $this->assertTrue($result['success']);
    }

    public function test_migration_failure_is_reported(): void
    {
        Process::fake([
            'php artisan migrate --force' => Process::result('', 'bad migration', 1),
            '*' => Process::result(),
        ]);

        try {
            $this->service()->callMigrations();
            $this->fail('migration failure must throw');
        } catch (\Exception $e) {
            $this->assertStringContainsString('bad migration', $e->getMessage());
        }
    }

    public function test_cached_update_info_always_has_the_local_newer_key(): void
    {
        Process::fake(['*' => Process::result()]);

        $info = (new UpdateService())->getCachedUpdateInfo();

        $this->assertArrayHasKey('is_local_newer', $info);
        $this->assertFalse($info['is_local_newer']);
    }

    public function test_execute_update_on_stale_cache_returns_already_latest(): void
    {
        // 缓存里故意不放 is_local_newer：旧代码在这里 Undefined array key
        Cache::put(UpdateService::CACHE_UPDATE_INFO, [
            'has_update' => false,
            'latest_version' => 'abc',
            'current_version' => 'abc',
            'update_logs' => [],
            'download_url' => '',
            'published_at' => '',
            'author' => '',
        ], 3600);

        Process::fake([
            'git log*' => Process::result(''),
            '*' => Process::result(),
        ]);

        $result = (new UpdateService())->executeUpdate();

        $this->assertFalse($result['success']);
        $this->assertSame(__('update.already_latest'), $result['message']);
    }

    private function service(): UpdateService
    {
        return new class extends UpdateService {
            public function callBackup(): void
            {
                $this->backupDatabase();
            }

            public function callPull(): array
            {
                return $this->pullLatestCode();
            }

            public function callMigrations(): void
            {
                $this->runMigrations();
            }
        };
    }
}