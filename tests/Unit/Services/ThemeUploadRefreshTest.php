<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\ThemeController;
use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

/**
 * 升级“正在使用的主题”时必须把 public 资源补回去；
 * saveThemeConfig 的 config 必须是数组（否则 updateConfig(array) 直接 TypeError）。
 */
class ThemeUploadRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const THEME = 'zz-theme-upload-test';

    private ?string $previousFrontend = null;

    private ?string $previousCurrent = null;

    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFrontend = admin_setting('frontend_theme');
        $this->previousCurrent = admin_setting('current_theme');
        admin_setting(['frontend_theme' => null, 'current_theme' => null]);
        $this->removeTheme();

        $this->zipPath = sys_get_temp_dir() . '/' . self::THEME . '.zip';
    }

    protected function tearDown(): void
    {
        admin_setting(['frontend_theme' => null, 'current_theme' => null]);
        $this->removeTheme();
        admin_setting([
            'frontend_theme' => $this->previousFrontend,
            'current_theme' => $this->previousCurrent,
        ]);
        parent::tearDown();
    }

    public function test_upgrading_the_active_theme_restores_public_assets(): void
    {
        $this->makeInstalledTheme('1.0.0');
        admin_setting(['frontend_theme' => self::THEME]);
        File::makeDirectory(public_path('theme/' . self::THEME), 0755, true);
        File::put(public_path('theme/' . self::THEME . '/stale.txt'), 'old');

        $this->assertTrue(app(ThemeService::class)->upload($this->makeZip('2.0.0')));

        // cleanupThemeFiles 会先删掉 public 目录；修复后必须由 refreshCurrentTheme 补回
        $this->assertFileExists(public_path('theme/' . self::THEME . '/dashboard.blade.php'));
        $this->assertFileDoesNotExist(public_path('theme/' . self::THEME . '/stale.txt'));
        $this->assertFileExists(base_path('storage/theme/' . self::THEME . '/config.json'));
    }

    public function test_save_theme_config_rejects_non_array_config(): void
    {
        $this->expectException(ValidationException::class);

        (new ThemeController(app(ThemeService::class)))->saveThemeConfig(
            Request::create('/', 'POST', ['name' => self::THEME, 'config' => 'oops'])
        );
    }

    private function makeInstalledTheme(string $version): void
    {
        $path = base_path('storage/theme/' . self::THEME);
        File::makeDirectory($path, 0755, true);
        File::put($path . '/config.json', json_encode([
            'name' => self::THEME,
            'version' => $version,
            'configs' => [],
        ]));
        File::put($path . '/dashboard.blade.php', '<html>1</html>');
    }

    private function makeZip(string $version): UploadedFile
    {
        if (File::exists($this->zipPath)) {
            File::delete($this->zipPath);
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('config.json', json_encode([
            'name' => self::THEME,
            'version' => $version,
            'configs' => [],
        ]));
        $zip->addFromString('dashboard.blade.php', '<html>2</html>');
        $zip->close();

        return new UploadedFile($this->zipPath, 'theme.zip', 'application/zip', null, true);
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
        if (isset($this->zipPath) && File::exists($this->zipPath)) {
            File::delete($this->zipPath);
        }
    }
}