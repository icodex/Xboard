<?php

namespace Tests\Unit\Services;

use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 渲染端读的是 frontend_theme（routes/web.php）；ThemeService 曾只写 current_theme，
 * 结果是「切换成功但前台没变」，且删除守卫保护的是另一个主题目录。
 */
class ThemeSwitchKeyTest extends TestCase
{
    use RefreshDatabase;

    private const THEME = 'zz-theme-key-test';

    private ?string $previousFrontend = null;

    private ?string $previousCurrent = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFrontend = admin_setting('frontend_theme');
        $this->previousCurrent = admin_setting('current_theme');
        admin_setting(['frontend_theme' => null, 'current_theme' => null]);
        $this->removeTheme();
        $this->makeTheme();
    }

    protected function tearDown(): void
    {
        // 先复位激活主题，避免 cleanup 受守卫影响
        admin_setting(['frontend_theme' => null, 'current_theme' => null]);
        $this->removeTheme();
        admin_setting([
            'frontend_theme' => $this->previousFrontend,
            'current_theme' => $this->previousCurrent,
        ]);
        parent::tearDown();
    }

    public function test_switch_sets_the_key_the_frontend_renders_from(): void
    {
        $this->assertTrue(app(ThemeService::class)->switch(self::THEME));

        $this->assertSame(self::THEME, admin_setting('frontend_theme'));
        $this->assertDirectoryExists(public_path('theme/' . self::THEME));
    }

    public function test_active_theme_cannot_be_deleted_without_the_legacy_key(): void
    {
        admin_setting(['frontend_theme' => self::THEME, 'current_theme' => null]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Current theme cannot be deleted');

        app(ThemeService::class)->delete(self::THEME);
    }

    private function makeTheme(): void
    {
        $path = base_path('storage/theme/' . self::THEME);
        File::makeDirectory($path, 0755, true);
        File::put($path . '/config.json', json_encode([
            'name' => self::THEME,
            'version' => '1.0.0',
            'configs' => [],
        ]));
        File::put($path . '/dashboard.blade.php', '<html></html>');
    }

    private function removeTheme(): void
    {
        foreach ([
            base_path('storage/theme/' . self::THEME),
            public_path('theme/' . self::THEME),
        ] as $path) {
            if (File::exists($path)) {
                File::deleteDirectory($path);
            }
        }
    }
}
