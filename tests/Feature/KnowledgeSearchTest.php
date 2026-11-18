<?php

namespace Tests\Feature;

use App\Models\Knowledge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 知识库搜索把用户输入直接拼进 LIKE：不转义时关键词 "%" 会匹配全部文章。
 * 转义符必须是显式 ESCAPE（SQLite 没有默认转义符），这里直接锁住行为。
 */
class KnowledgeSearchTest extends TestCase
{
    use RefreshDatabase;

    private const WITH_PERCENT = 'CPU 100% 占用';

    private const WITH_UNDERSCORE = '配置项 max_conn 说明';

    private const PLAIN = '普通文章，没有任何符号';

    public function test_percent_wildcard_is_treated_literally(): void
    {
        $this->makeKnowledge(self::WITH_PERCENT);
        $this->makeKnowledge(self::PLAIN);

        $this->assertSame([self::WITH_PERCENT], $this->searchTitles('%'));
    }

    public function test_underscore_wildcard_is_treated_literally(): void
    {
        $this->makeKnowledge(self::WITH_UNDERSCORE);
        $this->makeKnowledge(self::PLAIN);

        $this->assertSame([self::WITH_UNDERSCORE], $this->searchTitles('_'));
    }

    public function test_plain_keyword_still_matches(): void
    {
        $this->makeKnowledge(self::WITH_PERCENT);
        $this->makeKnowledge(self::PLAIN);

        $this->assertSame([self::PLAIN], $this->searchTitles('普通'));
    }

    /**
     * @return string[] 命中的文章标题
     */
    private function searchTitles(string $keyword): array
    {
        $user = User::create([
            'email' => 'knowledge-search@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000b9',
            'token' => md5('knowledge-search@example.com'),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 107374182400,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson(
            '/api/v1/user/knowledge/fetch?language=zh-CN&keyword=' . urlencode($keyword)
        );

        $response->assertOk();

        $titles = [];
        foreach ($response->json('data') as $articles) {
            foreach ($articles as $article) {
                $titles[] = $article['title'];
            }
        }
        sort($titles);

        return $titles;
    }

    private function makeKnowledge(string $title): void
    {
        Knowledge::create([
            'language' => 'zh-CN',
            'category' => 'FAQ',
            'title' => $title,
            'body' => $title,
            'sort' => 1,
            'show' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
