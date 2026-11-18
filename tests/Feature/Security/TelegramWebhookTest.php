<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * With no bot token configured, md5('') is a well-known constant: the webhook
 * used to accept it, letting anyone drive the bot. Unconfigured must be closed.
 */
class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_when_bot_token_unset(): void
    {
        admin_setting(['telegram_bot_token' => '']);

        $response = $this->postJson('/api/v1/guest/telegram/webhook?access_token=' . md5(''), []);

        $response->assertStatus(403);
    }

    public function test_webhook_accepts_configured_token(): void
    {
        admin_setting(['telegram_bot_token' => 'abc']);

        $response = $this->postJson('/api/v1/guest/telegram/webhook?access_token=' . md5('abc'), []);

        $this->assertNotContains($response->getStatusCode(), [401, 403]);
    }

    public function test_webhook_rejects_wrong_token(): void
    {
        admin_setting(['telegram_bot_token' => 'abc']);

        $response = $this->postJson('/api/v1/guest/telegram/webhook?access_token=' . md5('nope'), []);

        $response->assertStatus(401);
    }
}
