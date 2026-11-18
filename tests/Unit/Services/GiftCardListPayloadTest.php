<?php

namespace Tests\Unit\Services;

use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Http\Controllers\V2\Admin\GiftCardController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 两个列表接口此前构造了富字段映射却把结果丢掉（只返回原始分页、且每行两次 count 查询）。
 * 这里锁住返回体里必须带统计/关联字段。
 */
class GiftCardListPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_list_includes_code_counts(): void
    {
        $template = $this->makeTemplate('list-me');
        $this->makeCode($template, 'code-a');
        $this->makeCode($template, 'code-b');

        $response = (new GiftCardController())->templates(Request::create('/', 'GET', []));
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(1, $payload['total']);
        $row = $payload['data'][0];
        $this->assertSame(2, $row['codes_count']);
        $this->assertSame(0, $row['used_count']);
        $this->assertSame('list-me', $row['name']);
    }

    public function test_code_list_includes_relation_and_status_names(): void
    {
        $template = $this->makeTemplate('codes-me');
        $this->makeCode($template, 'code-c');

        $response = (new GiftCardController())->codes(Request::create('/', 'GET', [
            'template_id' => $template->id,
        ]));
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(1, $payload['total']);
        $row = $payload['data'][0];
        $this->assertSame('codes-me', $row['template_name']);
        $this->assertSame('未使用', $row['status_name']);
        $this->assertArrayHasKey('user_email', $row);
    }

    private function makeTemplate(string $name): GiftCardTemplate
    {
        return GiftCardTemplate::create([
            'name' => $name,
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => 1,
            'rewards' => ['balance' => 100],
            'theme_color' => '#1890ff',
            'sort' => 0,
            'admin_id' => 1,
        ]);
    }

    private function makeCode(GiftCardTemplate $template, string $code): GiftCardCode
    {
        return GiftCardCode::create([
            'template_id' => $template->id,
            'code' => $code,
            'status' => GiftCardCode::STATUS_UNUSED,
            'max_usage' => 1,
        ]);
    }
}
