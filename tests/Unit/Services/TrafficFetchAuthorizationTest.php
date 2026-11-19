<?php

namespace Tests\Unit\Services;

use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every node shares one token, so the panel must not trust the user ids a node
 * reports. Only users in the node's own groups may have their usage increased.
 */
class TrafficFetchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_traffic_for_users_outside_the_node_groups_is_dropped(): void
    {
        Queue::fake();

        $node = $this->makeNode([1]);
        $mine = $this->makeUser(1);
        $foreign = $this->makeUser(2);

        (new UserService())->trafficFetch($node, 'vless', [
            $mine->id => [100, 10],
            $foreign->id => [999999, 999999],
        ]);

        Queue::assertPushed(TrafficFetchJob::class, function (TrafficFetchJob $job) use ($mine, $foreign) {
            $payload = (fn () => $this->data)->call($job);

            return array_key_exists($mine->id, $payload)
                && !array_key_exists($foreign->id, $payload);
        });
    }

    public function test_a_node_without_groups_accepts_no_traffic(): void
    {
        Queue::fake();

        $node = $this->makeNode([]);
        $user = $this->makeUser(1);

        (new UserService())->trafficFetch($node, 'vless', [
            $user->id => [100, 10],
        ]);

        Queue::assertNotPushed(TrafficFetchJob::class);
    }

    private function makeNode(array $groupIds): Server
    {
        return Server::create([
            'name' => 'node-a',
            'type' => Server::TYPE_VLESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => $groupIds,
            'show' => true,
        ]);
    }

    private function makeUser(int $groupId): User
    {
        return User::create([
            'email' => 'traffic-auth-' . $groupId . '-' . uniqid() . '@example.com',
            'password' => 'password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => substr(md5(uniqid('', true)), 0, 32),
            'group_id' => $groupId,
            'transfer_enable' => 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => null,
        ]);
    }
}