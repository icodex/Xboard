<?php

// 面板测试数据初始化（幂等）：协议矩阵、分组、套餐、管理员、server_token 和 secure_path。
// 容器内执行：php artisan tinker --execute="require '/www/init.php';"
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Console\Commands\XboardInstall;
use Illuminate\Support\Facades\Cache;

Illuminate\Database\Eloquent\Model::unguard();

// 创建测试分组，再按名称更新协议矩阵。
$group = ServerGroup::firstOrCreate(
    ['name' => '全协议测试'],
    ServerGroup::count() ? [] : ['id' => 1]
);

require '/www/seed-nodes.php';

// 预检：种子必须能通过管理端保存规则，避免带着脏配置进节点侧测试。
// ServerSave::rules() 按请求里的 type 分支，因此每个节点单独构造请求。
$invalid = [];
$seededNames = $seededNames ?? [];
foreach (Server::whereIn('name', $seededNames)->where('enabled', 1)->get() as $server) {
    $attrs = [
        'type' => $server->type,
        'name' => $server->name,
        'host' => $server->host,
        'port' => $server->port,
        'server_port' => $server->server_port,
        'rate' => $server->rate,
        'protocol_settings' => $server->protocol_settings ?? [],
        'cert_config' => $server->cert_config,
    ];
    $request = new \App\Http\Requests\Admin\ServerSave();
    $request->merge($attrs);
    $validator = \Illuminate\Support\Facades\Validator::make($attrs, $request->rules());
    if ($validator->fails()) {
        $invalid[$server->name] = $validator->errors()->toArray();
    }
}
if ($invalid !== []) {
    throw new \RuntimeException('seed violates ServerSave rules: ' . json_encode($invalid, JSON_UNESCAPED_UNICODE));
}

// 种子按名称更新，矩阵改名后旧节点会残留并混进测试表。
// 只动 tags 含 dev-seed 的行（seed 自己写入的），因此手工/节点侧新建的节点不会被删。
// PRUNE_STALE=1 时删除并打印；否则只告警。
$stale = Server::whereJsonContains('group_ids', (string) $group->id)
    ->whereJsonContains('tags', 'dev-seed')
    ->whereNotIn('name', $seededNames);
if ($stale->exists()) {
    $names = $stale->pluck('name');
    if (getenv('PRUNE_STALE') === '1') {
        $deleted = $stale->get()->each->delete()->count();
        echo 'PRUNED stale test nodes: ' . $names->take(5)->implode(', ')
            . ($names->count() > 5 ? ' (+' . ($names->count() - 5) . ')' : '') . ' total=' . $deleted . PHP_EOL;
    } else {
        echo 'WARN stale test nodes not in seed: ' . $names->take(5)->implode(', ')
            . ($names->count() > 5 ? ' (+' . ($names->count() - 5) . ')' : '') . PHP_EOL;
    }
}

Plan::firstOrCreate(
    ['name' => '全协议测试'],
    ['group_id' => $group->id, 'show' => 1, 'sell' => 1, 'sort' => 0, 'prices' => ['month' => 0]]
);
Plan::where('name', '全协议测试')->update(['group_id' => $group->id]);

Setting::firstOrCreate(['name' => 'server_token'], ['value' => 'xboard-node-test-token']);
// secure_path：admin 后台路由前缀（routes/web.php 启动时求值，改后必须 restart 容器）
Setting::firstOrCreate(['name' => 'secure_path'], ['value' => 'ac821707']);

if (!User::where('email', 'admin@xboard.test')->exists()) {
    XboardInstall::registerAdmin('admin@xboard.test', 'xboard123');
}
$user = User::where('email', 'admin@xboard.test')->first();
// 每次跑都重置密码并确保 is_admin：库里只有 hash 读不回，强制与 init.sh 打印的 __PASSWD__ 一致
$user->password = password_hash('xboard123', PASSWORD_DEFAULT);
$user->is_admin = 1;
$user->group_id = $group->id;
$user->expired_at = 4102444800; // 2100-01-01
$user->transfer_enable = 107374182400; // 100GB
$user->save();

// 设置缓存在 redis store（键 admin_settings），直写表后必须单独清
Cache::store('redis')->forget('admin_settings');

echo 'users=' . User::count()
    . ' servers=' . \App\Models\Server::count()
    . ' plans=' . Plan::count()
    . ' groups=' . ServerGroup::count()
    . ' secure_path=' . admin_setting('secure_path');
