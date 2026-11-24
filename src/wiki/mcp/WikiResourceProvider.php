<?php
declare(strict_types=1);

namespace wise\wiki\mcp;

use think\facade\Db;
use wise\mcp\contract\ResourceProviderInterface;
use wise\mcp\dto\ResourceDefinition;
use wise\wiki\model\WikiPage;
use wise\wiki\security\WikiPermissionProvider;

/**
 * MCP 资源提供者：把 wiki 页面以 MCP Resource 暴露
 *
 * 暴露的 URI：
 *   - `wiki://index`               空间与页面清单（JSON，仅 published）
 *   - `wiki://{space}/page/{slug}` 单页全文（text/markdown，仅 published）
 *
 * 只暴露 `published`：review / draft 属于未审核内容，不应被外部客户端读到
 * （与「只有 published 进影子库」同一条闸门，决策 6）。
 */
class WikiResourceProvider implements ResourceProviderInterface
{
    public function getNamespace(): string
    {
        return 'wiki';
    }

    /**
     * @return ResourceDefinition[]
     */
    public function listResources(): array
    {
        if (!(bool) $this->cfg('wise-rag.wiki.mcp.expose_resources', true)) {
            return [];
        }

        $resources = [
            new ResourceDefinition(
                'wiki://index',
                'Wiki 页面清单',
                '列出全部已发布的 wiki 页面（空间、slug、标题、版本、新鲜度）',
                'application/json',
                fn (): string => $this->indexContent(),
                ['permission' => WikiPermissionProvider::PERMISSION_READ]
            ),
        ];

        $maxPages = max(0, (int) $this->cfg('wise-rag.wiki.mcp.max_page_resources', 100));
        if ($maxPages === 0) {
            return $resources;
        }

        $rows = Db::name('ai_wiki_pages')
            ->where('status', WikiPage::STATUS_PUBLISHED)
            ->order('id', 'asc')
            ->limit($maxPages)
            ->field('id,space_id,slug,title,summary')
            ->select()
            ->toArray();

        if ($rows === []) {
            return $resources;
        }

        $spaceKeys = $this->spaceKeys(array_map(static fn (array $r): int => (int) $r['space_id'], $rows));

        foreach ($rows as $row) {
            $spaceKey = $spaceKeys[(int) $row['space_id']] ?? '';
            if ($spaceKey === '') {
                continue;
            }

            $slug  = (string) $row['slug'];
            $title = (string) $row['title'];

            $resources[] = new ResourceDefinition(
                "wiki://{$spaceKey}/page/{$slug}",
                $title,
                'wiki 页面《' . $title . '》全文（Markdown）',
                'text/markdown',
                fn (): string => $this->pageContent((int) $row['id']),
                [
                    'permission' => WikiPermissionProvider::PERMISSION_READ,
                    'page_id'    => (int) $row['id'],
                ]
            );
        }

        return $resources;
    }

    protected function indexContent(): string
    {
        $rows = Db::name('ai_wiki_pages')
            ->where('status', WikiPage::STATUS_PUBLISHED)
            ->order('space_id', 'asc')
            ->order('id', 'asc')
            ->field('id,space_id,slug,title,summary,rev_no,staleness,quality_score,update_time')
            ->select()
            ->toArray();

        $spaceKeys = $this->spaceKeys(array_map(static fn (array $r): int => (int) $r['space_id'], $rows));

        $pages = array_map(static fn (array $r): array => [
            'space_key' => $spaceKeys[(int) $r['space_id']] ?? '',
            'slug'      => (string) $r['slug'],
            'title'     => (string) $r['title'],
            'summary'   => (string) $r['summary'],
            'rev_no'    => (int) $r['rev_no'],
            'staleness' => (int) $r['staleness'],
            'quality'   => (int) $r['quality_score'],
            'updated_at'=> (string) $r['update_time'],
            'uri'       => 'wiki://' . ($spaceKeys[(int) $r['space_id']] ?? '') . '/page/' . (string) $r['slug'],
        ], $rows);

        return (string) json_encode([
            'count' => count($pages),
            'pages' => $pages,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    protected function pageContent(int $pageId): string
    {
        $page = Db::name('ai_wiki_pages')
            ->where('id', $pageId)
            ->where('status', WikiPage::STATUS_PUBLISHED)
            ->find();

        if (!is_array($page)) {
            return '# 页面不存在或未发布';
        }

        $header = sprintf(
            "> %s（rev%d，更新于 %s）\n\n",
            (string) $page['title'],
            (int) $page['rev_no'],
            (string) ($page['update_time'] ?? '')
        );

        return $header . (string) $page['content_md'];
    }

    /**
     * @param int[] $spaceIds
     * @return array<int, string>
     */
    protected function spaceKeys(array $spaceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $spaceIds))));
        if ($ids === []) {
            return [];
        }

        $rows = Db::name('ai_wiki_spaces')->whereIn('id', $ids)->field('id,space_key')->select()->toArray();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) $row['space_key'];
        }

        return $out;
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return function_exists('config') ? config($key, $default) : $default;
    }
}
