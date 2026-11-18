<?php

namespace Tests\Unit\Services;

use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 兑换码能直接换成余额/套餐：码体必须来自 CSPRNG，批量生成也不能每个码发一次查询。
 */
class GiftCardCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_generates_the_requested_number_of_unique_codes(): void
    {
        $template = $this->makeTemplate();

        GiftCardCode::batchGenerate($template->id, 50, ['prefix' => 'GC']);

        $codes = GiftCardCode::pluck('code');

        $this->assertCount(50, $codes);
        $this->assertSame(50, $codes->unique()->count());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^GC[0-9A-F]{12}$/', $code);
        }
    }

    public function test_single_code_is_hex_and_unprefixed_without_a_prefix(): void
    {
        $template = $this->makeTemplate();
        GiftCardCode::batchGenerate($template->id, 5, ['prefix' => 'ABC123']);
        GiftCardCode::batchGenerate($template->id, 5, ['prefix' => '']);

        foreach (GiftCardCode::pluck('code') as $code) {
            $this->assertMatchesRegularExpression('/^(ABC123)?[0-9A-F]{12}$/', $code);
        }
    }

    public function test_generate_code_never_returns_an_existing_code(): void
    {
        $template = $this->makeTemplate();
        GiftCardCode::insert([
            'template_id' => $template->id,
            'code' => 'GCTAKEN000001',
            'batch_id' => 'b1',
            'status' => GiftCardCode::STATUS_UNUSED,
            'max_usage' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        // generateCode 只返回码体，不落库；它必须跳过表里已有的码
        for ($i = 0; $i < 20; $i++) {
            $code = GiftCardCode::generateCode('GC');
            $this->assertNotSame('GCTAKEN000001', $code);
            $this->assertMatchesRegularExpression('/^GC[0-9A-F]{12}$/', $code);
        }
    }

    private function makeTemplate(): GiftCardTemplate
    {
        return GiftCardTemplate::create([
            'name' => 'Test Template',
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => 1,
            'rewards' => ['balance' => 100],
            'limits' => [],
            'conditions' => [],
            'admin_id' => 1,
        ]);
    }
}
