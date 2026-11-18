<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 打开发送时不存在的工单曾直接 500：先 first() 再 load()，
 * 空结果会在 load() 上崩，下面的 if (!$ticket) 永远走不到。
 */
class TicketFetchTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_ticket_returns_400_not_500(): void
    {
        $user = $this->makeUser('ticket-1@example.com', '00000000-0000-0000-0000-0000000000a1');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/ticket/fetch?id=999999');

        $response->assertStatus(400);
        $response->assertJson(['status' => 'fail']);
    }

    public function test_other_users_ticket_is_not_readable(): void
    {
        $owner = $this->makeUser('ticket-owner@example.com', '00000000-0000-0000-0000-0000000000a2');
        $intruder = $this->makeUser('ticket-intruder@example.com', '00000000-0000-0000-0000-0000000000a3');

        \App\Models\Ticket::create([
            'user_id' => $owner->id,
            'subject' => 'private',
            'level' => 1,
            'status' => 0,
            'reply_status' => 1,
            'last_reply_user_id' => $owner->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $ticketId = \App\Models\Ticket::where('user_id', $owner->id)->value('id');

        $response = $this->actingAs($intruder, 'sanctum')->getJson('/api/v1/user/ticket/fetch?id=' . $ticketId);

        $response->assertStatus(400);
        $response->assertJson(['status' => 'fail']);
    }

    public function test_existing_ticket_returns_its_messages(): void
    {
        $user = $this->makeUser('ticket-ok@example.com', '00000000-0000-0000-0000-0000000000a4');
        $ticket = \App\Models\Ticket::create([
            'user_id' => $user->id,
            'subject' => 'help me',
            'level' => 1,
            'status' => \App\Models\Ticket::STATUS_OPENING,
            'reply_status' => \App\Models\Ticket::REPLY_STATUS_WAITING,
            'last_reply_user_id' => $user->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        \App\Models\TicketMessage::create([
            'user_id' => $user->id,
            'ticket_id' => $ticket->id,
            'message' => 'first message',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/ticket/fetch?id=' . $ticket->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $ticket->id);
        $response->assertJsonPath('data.subject', 'help me');
        $response->assertJsonCount(1, 'data.message');
        $response->assertJsonPath('data.message.0.message', 'first message');
    }

    private function makeUser(string $email, string $uuid): User
    {
        return User::create([
            'email' => $email,
            'password' => 'password',
            'uuid' => $uuid,
            'token' => md5($email),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
