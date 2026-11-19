<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * webhook 只认 Telegram 官方的 X-Telegram-Bot-Api-Secret-Token 头。
 *
 * 之前拿 URL 里的 md5(bot token) 当口令：查询串会进 nginx/Cloudflare/监控日志，
 * 读到日志的人就能带上任意 chat.id 去驱动 bot（冒充已绑定用户）。
 */
class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret-value';

    public function test_webhook_rejects_when_bot_token_unset(): void
    {
        admin_setting(['telegram_bot_token' => '']);

        $this->postJson('/api/v1/guest/telegram/webhook', [], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->assertStatus(403);
    }

    public function test_webhook_rejects_when_the_secret_is_not_configured(): void
    {
        admin_setting(['telegram_bot_token' => 'abc', 'telegram_webhook_secret' => '']);

        $this->postJson('/api/v1/guest/telegram/webhook', [], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->assertStatus(403);
    }

    public function test_webhook_accepts_the_configured_secret_header(): void
    {
        admin_setting(['telegram_bot_token' => 'abc', 'telegram_webhook_secret' => self::SECRET]);

        $response = $this->postJson('/api/v1/guest/telegram/webhook', [], [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ]);

        $this->assertNotContains($response->getStatusCode(), [401, 403]);
    }

    public function test_webhook_rejects_a_wrong_or_missing_secret(): void
    {
        admin_setting(['telegram_bot_token' => 'abc', 'telegram_webhook_secret' => self::SECRET]);

        $this->postJson('/api/v1/guest/telegram/webhook', [], ['X-Telegram-Bot-Api-Secret-Token' => 'nope'])
            ->assertStatus(401);

        $this->postJson('/api/v1/guest/telegram/webhook', [])->assertStatus(401);
    }

    public function test_the_old_query_token_no_longer_passes(): void
    {
        admin_setting(['telegram_bot_token' => 'abc', 'telegram_webhook_secret' => self::SECRET]);

        $this->postJson('/api/v1/guest/telegram/webhook?access_token=' . md5('abc'), [])
            ->assertStatus(401);
    }
}