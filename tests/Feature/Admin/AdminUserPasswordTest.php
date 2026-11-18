<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Requests\Admin\UserUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_password_change_revokes_existing_tokens(): void
    {
        $user = User::create([
            'email' => 'admin-reset@example.com',
            'password' => password_hash('old-password', PASSWORD_DEFAULT),
            'uuid' => '00000000-0000-0000-0000-0000000000f2',
            'token' => md5('admin-reset@example.com'),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->createToken('stale-session', ['*'], now()->addYear());

        $request = UserUpdate::create('/', 'POST', [
            'id' => $user->id,
            'password' => 'new-password',
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        $response = (new UserController())->update($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $user->tokens()->count());
    }
}
