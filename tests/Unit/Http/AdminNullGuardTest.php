<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\CouponController;
use App\Http\Controllers\V2\Admin\KnowledgeController;
use App\Http\Controllers\V2\Admin\NoticeController;
use App\Http\Requests\Admin\CouponGenerate;
use App\Http\Requests\Admin\NoticeSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 更新类接口拿不到目标记录时必须返回业务错误，而不是 ->find()->update() 的空指针 500。
 */
class AdminNullGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_coupon_generate_unknown_id_returns_404xx_not_500(): void
    {
        $request = $this->formRequest(CouponGenerate::class, [
            'id' => 999999,
            'name' => 'ghost',
            'type' => 1,
            'value' => 100,
            'started_at' => time(),
            'ended_at' => time() + 3600,
        ]);

        $response = (new CouponController())->generate($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fail', json_decode($response->getContent(), true)['status']);
    }

    public function test_notice_save_unknown_id_returns_404xx_not_500(): void
    {
        $request = $this->formRequest(NoticeSave::class, [
            'id' => 999999,
            'title' => 'ghost',
            'content' => 'ghost',
        ]);

        $response = (new NoticeController())->save($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fail', json_decode($response->getContent(), true)['status']);
    }

    /**
     * FormRequest 平时由路由注入容器、redirector 和已解析的 validator，单测里要手动补。
     */
    private function formRequest(string $class, array $payload): Request
    {
        $request = $class::create('/', 'POST', $payload);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(\Illuminate\Routing\Redirector::class));
        $request->setValidator(
            $this->app->make(\Illuminate\Contracts\Validation\Factory::class)
                ->make($payload, $request->rules(), $request->messages())
        );

        return $request;
    }

    public function test_knowledge_fetch_unknown_id_returns_404xx_not_500(): void
    {
        $response = (new KnowledgeController())->fetch(Request::create('/', 'GET', ['id' => 999999]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fail', json_decode($response->getContent(), true)['status']);
    }
}