<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ManageUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(array $extra = []): Server
    {
        $server = new Server();
        $server->fill(array_merge([
            'name' => 'update-test',
            'type' => 'hysteria',
            'group_ids' => ['1'],
            'host' => '127.0.0.1',
            'port' => 36712,
            'server_port' => 36712,
            'rate' => 1,
            'show' => 1,
            'enabled' => 1,
            'protocol_settings' => [
                'version' => 2,
                'bandwidth' => ['up' => 100, 'down' => 100],
                'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'obfspass'],
                'tls' => ['server_name' => 'test.local'],
            ],
            'created_at' => time(),
            'updated_at' => time(),
        ], $extra));
        $server->save();

        return $server;
    }

    private function callUpdate(int $serverId, array $payload)
    {
        return (new ManageController())->update(
            Request::create('/', 'POST', array_merge(['id' => $serverId], $payload))
        );
    }

    public function test_update_rejects_fields_outside_whitelist(): void
    {
        $server = $this->makeServer();

        $response = $this->callUpdate($server->id, ['server_port' => 36713]);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('fail', $body['status']);
        $this->assertStringContainsString('server_port', $body['message']);

        // 数据库不得有任何变化
        $this->assertSame(36712, $server->fresh()->server_port);
    }

    public function test_update_accepts_whitelisted_fields(): void
    {
        $server = $this->makeServer();

        $response = $this->callUpdate($server->id, ['show' => 0, 'enabled' => false]);

        $this->assertSame(200, $response->getStatusCode());
        $fresh = $server->fresh();
        $this->assertSame(0, (int) $fresh->show);
        $this->assertFalse((bool) $fresh->enabled);
    }

    public function test_batch_update_rejects_fields_outside_whitelist(): void
    {
        $server = $this->makeServer();

        $controller = new ManageController();
        $response = $controller->batchUpdate(
            Request::create('/', 'POST', ['ids' => [$server->id], 'server_port' => 36713])
        );

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString('server_port', $body['message']);
        $this->assertSame(36712, $server->fresh()->server_port);
    }
}
