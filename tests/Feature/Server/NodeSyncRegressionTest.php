<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Controllers\V2\Admin\Server\GroupController;
use App\Http\Requests\Admin\PlanSave;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\NodeFullSyncJob;
use App\Models\Server;
use App\Models\Plan;
use App\Models\ServerMachine;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class NodeSyncRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        // RefreshDatabase reuses the migrated schema; committed rows must not leak into it.
        Schema::disableForeignKeyConstraints();
        foreach (Schema::getTables() as $table) {
            if ($table['name'] !== 'migrations') {
                DB::table($table['name'])->delete();
            }
        }
        Schema::enableForeignKeyConstraints();
        DB::disconnect();
        parent::tearDown();
    }

    public function test_users_push_is_skipped_until_the_version_changes(): void
    {
        $server = $this->server([1]);
        $this->user('skip@example.com');
        Cache::put(CacheKey::get("NODE_WS_ALIVE_{$server->id}"), true, 60);
        Cache::put(CacheKey::get('SERVER_USERS_VERSION'), 7);

        $events = [];
        Redis::shouldReceive('publish')->times(5)->andReturnUsing(function ($channel, $message) use (&$events) {
            $events[] = json_decode($message, true)['event'];

            return 1;
        });

        $job = new NodeFullSyncJob($server->id);
        $job->handle();
        $job->handle(); // 名单和版本都没变：只重推 config
        Cache::put(CacheKey::get('SERVER_USERS_VERSION'), 8);
        $job->handle();

        $this->assertSame(
            ['sync.config', 'sync.users', 'sync.config', 'sync.config', 'sync.users'],
            $events
        );
    }

    public function test_a_push_that_reached_no_subscriber_is_retried_next_round(): void
    {
        $server = $this->server([1]);
        $this->user('lost@example.com');
        Cache::put(CacheKey::get("NODE_WS_ALIVE_{$server->id}"), true, 60);
        Cache::put(CacheKey::get('SERVER_USERS_VERSION'), 9);

        $events = [];
        // ws-server 不在：publish 投递给 0 个订阅者，不能记成"已同步"
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $message) use (&$events) {
            $events[] = json_decode($message, true)['event'];

            return 0;
        });
        (new NodeFullSyncJob($server->id))->handle();

        $this->assertSame(['sync.config', 'sync.users'], $events);

        // 下一轮送达成功：因为上一轮没写标记，users 会再推一次
        $events = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $message) use (&$events) {
            $events[] = json_decode($message, true)['event'];

            return 1;
        });
        (new NodeFullSyncJob($server->id))->handle();

        $this->assertSame(['sync.config', 'sync.users'], $events);
    }

    private function user(string $email, array $extra = []): User
    {
        return User::create(array_merge([
            'email' => $email, 'password' => 'password', 'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => md5($email), 'group_id' => 1, 'transfer_enable' => 1000,
            'u' => 0, 'd' => 0, 'banned' => 0,
        ], $extra));
    }

    private function server(array $groups): Server
    {
        return Server::create([
            'name' => 'sync-node', 'type' => Server::TYPE_VMESS, 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'show' => 1,
            'enabled' => 1, 'group_ids' => $groups,
            'protocol_settings' => ['network' => 'tcp', 'tls' => 0],
        ]);
    }

    public function test_legacy_and_integer_groups_receive_updates_and_appear_in_subscriptions(): void
    {
        $user = $this->user('groups@example.com');
        $integer = $this->server([1]);
        $string = $this->server(['1']);
        foreach ([$integer, $string] as $server) {
            Cache::put(CacheKey::get("NODE_WS_ALIVE_{$server->id}"), true, 60);
        }
        $published = [];
        Redis::shouldReceive('publish')->twice()->andReturnUsing(function ($channel, $message) use (&$published) {
            $published[] = json_decode($message, true);
        });
        NodeSyncService::notifyUserChanged($user);
        $this->assertEqualsCanonicalizing([$integer->id, $string->id], array_column($published, 'node_id'));
        $this->assertEqualsCanonicalizing([$integer->id, $string->id], array_column(ServerService::getAvailableServers($user), 'id'));
    }

    public function test_group_counts_and_deletion_guard_accept_both_json_id_types(): void
    {
        $integerGroup = new ServerGroup();
        $integerGroup->name = 'integer-group';
        $integerGroup->save();
        $stringGroup = new ServerGroup();
        $stringGroup->name = 'string-group';
        $stringGroup->save();
        $integerNode = $this->server([$integerGroup->id]);
        $stringNode = $this->server([(string) $stringGroup->id]);

        $this->assertSame([$integerNode->id], $integerGroup->servers()->pluck('id')->all());
        $this->assertSame([$stringNode->id], $stringGroup->servers()->pluck('id')->all());
        $this->assertSame(1, $integerGroup->server_count);
        $this->assertSame(1, $stringGroup->server_count);

        $controller = new GroupController();
        foreach ([$integerGroup, $stringGroup] as $group) {
            $response = $controller->drop(Request::create('/', 'POST', ['id' => (string) $group->id]));
            $this->assertSame(400, $response->getStatusCode());
            $this->assertNotNull($group->fresh());
        }
    }

    public function test_group_change_removes_from_old_group_only_after_commit(): void
    {
        $user = $this->user('move@example.com');
        $old = $this->server([1]);
        $oldString = $this->server(['1']);
        $new = $this->server([2]);
        $newString = $this->server(['2']);
        foreach ([$old, $oldString, $new, $newString] as $server) {
            Cache::put(CacheKey::get("NODE_WS_ALIVE_{$server->id}"), true, 60);
        }
        $published = [];
        Redis::shouldReceive('publish')->times(4)->andReturnUsing(function ($channel, $message) use (&$published) {
            $published[] = json_decode($message, true);
        });
        DB::beginTransaction();
        $user->update(['group_id' => 2]);
        $this->assertSame([], $published);
        DB::commit();
        $this->assertEqualsCanonicalizing([$old->id, $oldString->id], array_column(array_filter($published, fn ($event) => $event['data']['action'] === 'remove'), 'node_id'));
        $this->assertEqualsCanonicalizing([$new->id, $newString->id], array_column(array_filter($published, fn ($event) => $event['data']['action'] === 'add'), 'node_id'));
    }

    public function test_rolled_back_user_change_does_not_publish_or_invalidate(): void
    {
        $user = $this->user('rollback@example.com');
        Cache::put(CacheKey::get('SERVER_USERS_VERSION'), 15);
        Bus::fake();
        DB::beginTransaction();
        $user->update(['group_id' => 2]);
        DB::rollBack();
        $this->assertSame(1, (int) $user->fresh()->group_id);
        $this->assertSame(15, (int) Cache::get(CacheKey::get('SERVER_USERS_VERSION')));
        Bus::assertNothingDispatched();
    }

    public function test_traffic_exceeded_notifies_both_json_group_encodings(): void
    {
        $user = $this->user('exceeded@example.com', ['u' => 1000]);
        $integer = $this->server([1]);
        $string = $this->server(['1']);
        foreach ([$integer, $string] as $server) {
            Cache::put(CacheKey::get("NODE_WS_ALIVE_{$server->id}"), true, 60);
        }
        $published = [];
        Redis::shouldReceive('smembers')->once()->andReturn([(string) $user->id]);
        Redis::shouldReceive('srem')->once();
        Redis::shouldReceive('publish')->twice()->andReturnUsing(function ($channel, $message) use (&$published) {
            $published[] = json_decode($message, true);
        });
        $this->artisan('check:traffic-exceeded')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$integer->id, $string->id], array_column($published, 'node_id'));
        $this->assertSame(['remove', 'remove'], array_column(array_column($published, 'data'), 'action'));
    }

    public function test_reconciliation_pushes_current_users_without_bumping_the_version(): void
    {
        $server = $this->server([1]);
        $this->user('expired@example.com', ['expired_at' => time() - 1]);
        $active = $this->user('active@example.com');
        Cache::put(CacheKey::get("NODE_WS_ALIVE_{$server->id}"), true, 60);
        $key = CacheKey::get('SERVER_USERS_VERSION');
        Cache::put($key, 10);
        Bus::fake();
        NodeSyncService::reconcileAll();
        // 版本号只由名单变化（Observer 或显式 bump）推进：无条件递增会让
        // NodeFullSyncJob 的"版本没变就跳过"永远不成立
        $this->assertSame(10, (int) Cache::get($key));
        Bus::assertDispatched(NodeFullSyncJob::class);
        $published = [];
        Redis::shouldReceive('publish')->twice()->andReturnUsing(function ($channel, $message) use (&$published) {
            $published[] = json_decode($message, true);

            return 1;
        });
        (new NodeFullSyncJob($server->id))->handle();
        $this->assertSame('vmess', collect($published)->firstWhere('event', 'sync.config')['data']['config']['protocol']);
        $users = collect($published)->firstWhere('event', 'sync.users')['data']['users'];
        $this->assertSame([$active->id], array_column($users, 'id'));
    }
    public function test_bulk_ban_invalidates_payload_and_syncs_online_node(): void
    {
        $user = $this->user('bulk-ban@example.com');
        $server = $this->server([1]);
        Cache::put(CacheKey::get("NODE_WS_ALIVE_{$server->id}"), true, 60);
        Cache::put(CacheKey::get('SERVER_USERS_VERSION'), 20);
        Bus::fake();
        $response = (new UserController())->ban(Request::create('/', 'POST', ['scope' => 'selected', 'user_ids' => [$user->id]]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($user->fresh()->banned);
        $this->assertSame(21, (int) Cache::get(CacheKey::get('SERVER_USERS_VERSION')));
        Bus::assertDispatched(NodeFullSyncJob::class);
        $this->assertCount(0, ServerService::getAvailableUsers($server));
    }

    public function test_force_plan_update_reconciles_old_and_new_groups(): void
    {
        $plan = Plan::create(['name' => 'sync-plan', 'group_id' => 1, 'transfer_enable' => 1, 'show' => 1, 'sell' => 1]);
        $user = $this->user('plan-sync@example.com', ['plan_id' => $plan->id]);
        $old = $this->server([1]);
        $new = $this->server([2]);
        foreach ([$old, $new] as $server) {
            Cache::put(CacheKey::get("NODE_WS_ALIVE_{$server->id}"), true, 60);
        }
        Cache::put(CacheKey::get('SERVER_USERS_VERSION'), 30);
        Bus::fake();
        $request = PlanSave::create('/', 'POST', [
            'id' => $plan->id, 'name' => 'sync-plan', 'group_id' => 2, 'transfer_enable' => 1,
            'speed_limit' => 0, 'device_limit' => 0, 'force_update' => 1,
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();
        $this->assertSame(200, (new PlanController())->save($request)->getStatusCode());
        $this->assertSame(2, (int) $user->fresh()->group_id);
        $this->assertSame(31, (int) Cache::get(CacheKey::get('SERVER_USERS_VERSION')));
        Bus::assertDispatchedTimes(NodeFullSyncJob::class, 2);
        $this->assertCount(0, ServerService::getAvailableUsers($old));
        $this->assertCount(1, ServerService::getAvailableUsers($new));
    }

    public function test_machine_change_notifies_old_and_new_after_commit(): void
    {
        $old = ServerMachine::create(['name' => 'old', 'token' => 'old-machine-token', 'is_active' => 1]);
        $new = ServerMachine::create(['name' => 'new', 'token' => 'new-machine-token', 'is_active' => 1]);
        $server = $this->server([1]);
        $server->update(['machine_id' => $old->id]);
        $published = [];
        Redis::shouldReceive('publish')->twice()->andReturnUsing(function ($channel, $message) use (&$published) {
            $published[] = json_decode($message, true);
        });
        DB::beginTransaction();
        $server->update(['machine_id' => $new->id]);
        $this->assertSame([], $published);
        DB::commit();
        $this->assertEqualsCanonicalizing([$old->id, $new->id], array_column($published, 'machine_id'));
        $byMachine = collect($published)->keyBy('machine_id');
        $this->assertSame([], $byMachine[$old->id]['data']['nodes']);
        $this->assertSame($server->id, $byMachine[$new->id]['data']['nodes'][0]['id']);
    }


    public function test_partial_admin_edit_preserves_inviter_and_invalid_explicit_inviter_is_rejected(): void
    {
        $inviter = $this->user('inviter@example.com');
        $user = $this->user('invitee@example.com', ['invite_user_id' => $inviter->id]);
        $controller = new UserController();
        $request = UserUpdate::create('/', 'POST', ['id' => $user->id, 'remarks' => 'updated']);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();
        $controller->update($request);
        $this->assertSame($inviter->id, $user->fresh()->invite_user_id);

        $request = UserUpdate::create('/', 'POST', ['id' => $user->id, 'invite_user_email' => 'missing@example.com']);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();
        $this->assertSame(422, $controller->update($request)->getStatusCode());
        $this->assertSame($inviter->id, $user->fresh()->invite_user_id);

        $request = UserUpdate::create('/', 'POST', ['id' => $user->id, 'invite_user_email' => $user->email]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();
        $this->assertSame(422, $controller->update($request)->getStatusCode());

        $request = UserUpdate::create('/', 'POST', ['id' => $user->id, 'invite_user_email' => '']);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();
        $this->assertSame(200, $controller->update($request)->getStatusCode());
        $this->assertNull($user->fresh()->invite_user_id);
    }
}
