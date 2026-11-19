<?php

namespace Tests\Unit\Services;

use App\Models\GiftCardTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GiftCardFestivalBonusTest extends TestCase
{
    use RefreshDatabase;

    public function test_festival_bonus_scales_quantities_but_not_identifiers(): void
    {
        $template = GiftCardTemplate::create([
            'name' => 'Festival plan card',
            'type' => GiftCardTemplate::TYPE_PLAN,
            'status' => 1,
            'rewards' => [
                'plan_id' => 5,
                'plan_validity_days' => 30,
                'balance' => 100,
            ],
            'limits' => [],
            'conditions' => [],
            'admin_id' => 1,
            'special_config' => [
                'festival_bonus' => 2,
                'start_time' => time() - 60,
                'end_time' => time() + 60,
            ],
        ]);

        $rewards = $template->calculateActualRewards(new User());

        // plan_id identifies a plan: doubling it silently hands out another plan.
        $this->assertSame(5, $rewards['plan_id']);
        $this->assertSame(30, $rewards['plan_validity_days']);
        // a quantity is what the bonus is for
        $this->assertSame(200, $rewards['balance']);
    }
}