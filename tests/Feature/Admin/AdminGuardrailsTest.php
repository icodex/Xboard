<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Requests\Admin\ConfigSave;
use App\Http\Requests\Admin\UserUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Tests\TestCase;

/**
 * Bulk actions and admin forms must refuse the request instead of acting on
 * "everything" or on an empty value.
 */
class AdminGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_ban_without_selection_or_filter_does_nothing(): void
    {
        $user = $this->makeUser();

        $response = (new UserController())->ban(Request::create('/', 'POST', []));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, (int) $user->fresh()->banned);
    }

    public function test_bulk_ban_with_a_cleared_filter_does_nothing(): void
    {
        $user = $this->makeUser();

        $response = (new UserController())->ban(Request::create('/', 'POST', ['scope' => 'filtered']));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, (int) $user->fresh()->banned);
    }

    public function test_bulk_ban_of_every_user_requires_explicit_confirmation(): void
    {
        $user = $this->makeUser();

        $refused = (new UserController())->ban(Request::create('/', 'POST', ['scope' => 'all']));
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertSame(0, (int) $user->fresh()->banned);

        $accepted = (new UserController())->ban(Request::create('/', 'POST', ['scope' => 'all', 'confirm_all' => true]));
        $this->assertSame(200, $accepted->getStatusCode());
        $this->assertSame(1, (int) $user->fresh()->banned);
    }

    public function test_empty_secure_path_is_rejected(): void
    {
        $this->assertTrue($this->configFails(['secure_path' => '']));
        $this->assertTrue($this->configFails(['secure_path' => 'short']));
        $this->assertFalse($this->configFails(['secure_path' => 'adm-12345']));
    }

    public function test_empty_server_token_is_rejected(): void
    {
        $this->assertTrue($this->configFails(['server_token' => '']));
        $this->assertTrue($this->configFails(['server_token' => 'too-short']));
        $this->assertFalse($this->configFails(['server_token' => str_repeat('a', 24)]));
    }

    public function test_empty_email_is_rejected_on_admin_user_save(): void
    {
        $request = UserUpdate::create('/', 'POST', ['id' => 1, 'email' => '']);
        $this->assertTrue(
            ValidatorFactory::make($request->all(), $request->rules())->fails(),
            'an empty email must not clear the account address'
        );
    }

    private function configFails(array $payload): bool
    {
        $request = ConfigSave::create('/', 'POST', $payload);

        return ValidatorFactory::make($request->all(), $request->rules())->fails();
    }

    private function makeUser(): User
    {
        return User::create([
            'email' => 'guardrail@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000g1',
            'token' => md5('guardrail@example.com'),
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}