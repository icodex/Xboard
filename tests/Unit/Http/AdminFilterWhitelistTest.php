<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\CouponController;
use App\Http\Controllers\V2\Admin\OrderController;
use App\Http\Controllers\V2\Admin\TicketController;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * filter[].id / sort[].id 是拼进 SQL 列位的，必须走白名单。
 * 未加白名单时该字段会作为列名执行，未知列直接抛 QueryException（即 500）。
 */
class AdminFilterWhitelistTest extends TestCase
{
    use RefreshDatabase;

    public function test_coupon_filter_ignores_non_column_fields(): void
    {
        $this->makeCoupon('a');
        $this->makeCoupon('b');

        $response = (new CouponController())->fetch($this->filtered('(select 1)', 'x'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $this->payload($response)['total']);
    }

    public function test_coupon_filter_applies_for_whitelisted_column(): void
    {
        $this->makeCoupon('alpha');
        $this->makeCoupon('beta');

        $response = (new CouponController())->fetch($this->filtered('name', 'alp'));

        $this->assertSame(1, $this->payload($response)['total']);
    }

    public function test_coupon_sort_ignores_non_column_fields(): void
    {
        $this->makeCoupon('a');
        $this->makeCoupon('b');

        $request = Request::create('/', 'GET', [
            'sort' => [
                ['id' => '(select 1)', 'desc' => true],
                ['id' => 'name', 'desc' => true],
            ],
        ]);

        $response = (new CouponController())->fetch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('b', $this->payload($response)['data'][0]['name']);
    }

    public function test_coupon_update_returns_success_payload(): void
    {
        $coupon = $this->makeCoupon('update-me');

        $response = (new CouponController())->update(Request::create('/', 'POST', [
            'id' => $coupon->id,
            'show' => 1,
        ]));

        $this->assertSame('success', $this->payload($response)['status']);
        $this->assertTrue((bool) Coupon::find($coupon->id)->show);
    }

    public function test_coupon_update_unknown_id_is_not_a_500(): void
    {
        $response = (new CouponController())->update(Request::create('/', 'POST', [
            'id' => 999999,
            'show' => 1,
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fail', $this->payload($response)['status']);
    }

    public function test_ticket_filter_ignores_non_column_fields(): void
    {
        $request = $this->filtered('(select 1)', 'x');
        $response = (new TicketController())->fetch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->payload($response)['total']);
    }

    public function test_order_filter_ignores_non_column_fields(): void
    {
        $request = $this->filtered('(select 1)', 'x');
        $response = (new OrderController())->fetch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->payload($response)['total']);
    }

    public function test_order_null_operator_is_handled(): void
    {
        $request = $this->filtered('paid_at', 'null:');
        $response = (new OrderController())->fetch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->payload($response)['total']);
    }

    public function test_user_bulk_sort_ignores_non_column_fields(): void
    {
        $user = User::create([
            'email' => 'bulk-sort@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000f1',
            'token' => md5('bulk-sort'),
            'group_id' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $request = Request::create('/', 'POST', [
            'scope' => 'filtered',
            'filter' => [['id' => 'email', 'value' => 'bulk-sort']],
            'sort' => '(select 1)',
            'sort_type' => 'DESC',
        ]);

        $response = (new \App\Http\Controllers\V2\Admin\UserController())->ban($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue((bool) $user->fresh()->banned);
    }

    public function test_order_page_size_is_capped(): void
    {
        $response = (new OrderController())->fetch(
            Request::create('/', 'GET', ['pageSize' => 100000])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(200, $this->payload($response)['per_page']);
    }

    private function filtered(string $field, mixed $value): Request
    {
        return Request::create('/', 'GET', [
            'filter' => [
                ['id' => $field, 'value' => $value],
            ],
        ]);
    }

    private function payload($response): array
    {
        return json_decode($response->getContent(), true) ?? [];
    }

    private function makeCoupon(string $name): Coupon
    {
        return Coupon::create([
            'code' => md5($name),
            'name' => $name,
            'type' => 1,
            'value' => 100,
            'show' => 1,
            'started_at' => time(),
            'ended_at' => time() + 3600,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}