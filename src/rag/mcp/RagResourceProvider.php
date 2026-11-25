<?php
declare(strict_types=1);

namespace wise\rag\mcp;

use think\facade\Db;
use wise\mcp\contract\ResourceProviderInterface;
use wise\mcp\dto\ResourceDefinition;
use wise\rag\security\KnowledgePermissionProvider;
use wise\rag\service\KnowledgeBaseService;

/**
 * MCP 资源提供者：把知识库以 MCP Resource 暴露
 *
 * 暴露的 URI：
 *   - `kb://index`                     知识库清单（含统计）
 *   - `kb://{kb_key}/docs`             该知识库的文档清单
 *   - `kb://{kb_key}/doc/{doc_id}`     单篇文档全文（分块拼接）
 *
 * 资源读取器为**惰性闭包**（仅在 `resources/read` 时执行），列表阶段只做轻量查询；
 * 文档级资源数量由 `wise-rag.mcp.max_doc_resources` 限制（默认 100，0 = 不暴露文档级资源）。
 */
class RagResourceProvider implements ResourceProviderInterface
{
    public function __construct(protected KnowledgeBaseService $kbService)
    {
    }

    public function getNamespace(): string
    {
        return 'rag';
    }

    /**
     * @return ResourceDefinition[]
     */
    public function listResources(): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $resources = [
            new ResourceDefinition(
                'kb://index',
                '知识库清单',
                '列出全部启用中的知识库及其文档 / 分块统计（JSON）',
                'application/json',
                fn (): string => $this->indexContent(),
                ['permission' => KnowledgePermissionProvider::PERMISSION]
            ),
        ];

        $maxDocs = max(0, (int) $this->cfg('wise-rag.mcp.max_doc_resources', 100));
        if ($maxDocs === 0) {
            foreach ($this->kbService->all(true) as $kb) {
                $resources[] = $this->docsResource((string) $kb->kb_key, (string) $kb->name);
            }

            return $resources;
        }

        foreach ($this->kbService->all(true) as $kb) {
            $kbKey = (string) $kb->kb_key;
            $resources[] = $this->docsResource($kbKey, (string) $kb->name);

            $docs = Db::name('ai_kb_documents')
                ->where('kb_id', (int) $kb->id)
                ->where('status', 'indexed')
                ->order('id', 'asc')
                ->limit($maxDocs)
                ->field('id,title')
                ->select()
                ->toArray();

            foreach ($docs as $doc) {
                $docId = (int) $doc['id'];
                $title = (string) ($doc['title'] ?? ('#' . $docId));

                $resources[] = new ResourceDefinition(
                    "kb://{$kbKey}/doc/{$docId}",
                    $title,
                    "知识库「{$kb->name}」文档《{$title}》全文",
                    'text/markdown',
                    fn (): string => $this->docContent($docId, $kbKey),
                    ['permission' => KnowledgePermissionProvider::PERMISSION, 'doc_id' => $docId]
                );
            }
        }

        return $resources;
    }

    protected function docsResource(string $kbKey, string $kbName): ResourceDefinition
    {
        return new ResourceDefinition(
            "kb://{$kbKey}/docs",
            "知识库「{$kbName}」文档清单",
            "知识库「{$kbName}」（kb_key: {$kbKey}）下文档的清单（JSON）",
            'application/json',
            fn (): string => $this->docsContent($kbKey),
            ['permission' => KnowledgePermissionProvider::PERMISSION]
        );
    }

    /**
     * kb://index 内容
     */
    protected function indexContent(): string
    {
        $rows = [];
        foreach ($this->kbService->all(true) as $kb) {
            $rows[] = [
                'kb_key'      => (string) $kb->kb_key,
                'name'        => (string) $kb->name,
                'description' => (string) $kb->description,
                'doc_count'   => (int) $kb->doc_count,
                'chunk_count' => (int) $kb->chunk_count,
                'embedding'   => (string) $kb->embedding,
            ];
        }

        return (string) json_encode(['knowledge_bases' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * kb://{kb_key}/docs 内容
     */
    protected function docsContent(string $kbKey): string
    {
        $kb = $this->kbService->findByKey($kbKey);
        if ($kb === null) {
            return (string) json_encode(['error' => "知识库不存在：{$kbKey}"], JSON_UNESCAPED_UNICODE);
        }

        $docs = Db::name('ai_kb_documents')
            ->where('kb_id', (int) $kb->id)
            ->order('id', 'asc')
            ->field('id,title,status,chunk_count,char_count,update_time')
            ->select()
            ->toArray();

        return (string) json_encode([
            'kb_key' => $kbKey,
            'name'   => (string) $kb->name,
            'docs'   => $docs,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * kb://{kb_key}/doc/{doc_id} 内容（分块按序拼接）
     */
    protected function docContent(int $docId, string $kbKey = ''): string
    {
        $kb = $kbKey === '' ? null : $this->kbService->findByKey($kbKey);
        if ($kbKey !== '' && $kb === null) {
            return "知识库不存在：{$kbKey}";
        }

        $query = Db::name('ai_kb_documents')->where('id', $docId);
        if ($kb !== null) {
            $query->where('kb_id', (int) $kb->id);
        }

        $doc = $query->find();
        if (!is_array($doc)) {
            return "文档不存在：{$docId}";
        }

        $chunks = Db::name('ai_kb_chunks')
            ->where('doc_id', $docId)
            ->order('chunk_index', 'asc')
            ->column('content');

        $parts = [];
        foreach ($chunks as $chunk) {
            $parts[] = trim((string) $chunk);
        }

        return "# " . (string) ($doc['title'] ?? ('文档 #' . $docId)) . "\n\n" . implode("\n\n", $parts);
    }

    protected function enabled(): bool
    {
        return (bool) $this->cfg('wise-rag.mcp.expose_resources', true);
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return function_exists('config') ? config($key, $default) : $default;
    }
}
