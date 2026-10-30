<?php

// 面板测试数据初始化（幂等）：31 节点 + 分组 + 套餐 + 管理员 + server_token + secure_path。
// 容器内执行：php artisan tinker --execute="require '/www/init.php';"
use App\Models\ServerGroup;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Console\Commands\XboardInstall;
use Illuminate\Support\Facades\Cache;

Illuminate\Database\Eloquent\Model::unguard();

// group + 31 节点（seed-nodes.php 只读 group、不建，兜底 id=1）
$group = ServerGroup::firstOrCreate(
    ['name' => '全协议测试'],
    ServerGroup::count() ? [] : ['id' => 1]
);

require '/www/seed-nodes.php';

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
