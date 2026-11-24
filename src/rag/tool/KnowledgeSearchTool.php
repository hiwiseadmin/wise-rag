<?php
declare(strict_types=1);

namespace wise\rag\tool;

use wise\agent\contract\ToolInterface;
use wise\rag\dto\RetrievalQuery;
use wise\rag\dto\ScoredChunk;
use wise\rag\security\KnowledgePermissionProvider;
use wise\rag\service\RetrievalService;
use wise\rag\support\ErrorPresenter;

/**
 * 知识库检索工具（wise-agent ToolInterface）
 *
 * 命名约定（重要）：ToolRegistry 以 `getName()` 作为 LM 暴露的函数名，
 * 且执行时按同名查找注册键，因此本工具以「全名」注册到默认 `builtin` 命名空间，
 * 保证 `getName() === 注册键`（详见 KnowledgeToolRegistrar 注释）。
 *
 * 本类仅在 wise-agent 存在时被实例化（KnowledgeToolRegistrar::available() 守卫）。
 */
class KnowledgeSearchTool implements ToolInterface
{
    /**
     * @param string           $toolName    全名，如 kb.product_docs.search / kb.search
     * @param array<int, int>  $kbIds       限定知识库 ID（空 = 全部启用中的知识库）
     * @param array<string, mixed> $options 运行参数（default_top_k / max_results）
     */
    public function __construct(
        protected string $toolName,
        protected string $description,
        protected RetrievalService $retrieval,
        protected array $kbIds = [],
        protected array $options = []
    ) {
    }

    public function getName(): string
    {
        return $this->toolName;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => '要检索的自然语言问题或关键词',
                ],
                'top_k' => [
                    'type'        => 'integer',
                    'description' => '返回条数（默认取配置值）',
                ],
            ],
            'required'   => ['query'],
        ];
    }

    public function execute(array $arguments): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return json_encode(['error' => 'query 不能为空'], JSON_UNESCAPED_UNICODE);
        }

        $defaultTopK = (int) ($this->options['default_top_k'] ?? 5);
        $maxResults  = (int) ($this->options['max_results'] ?? 20);

        $topK = (int) ($arguments['top_k'] ?? $defaultTopK);
        $topK = $topK > 0 ? min($topK, max(1, $maxResults)) : $defaultTopK;

        try {
            $hits = $this->retrieval->retrieve(new RetrievalQuery($query, null, $this->kbIds, $topK));
        } catch (\Exception $e) {
            return json_encode(['error' => ErrorPresenter::message($e)], JSON_UNESCAPED_UNICODE);
        }

        $results = array_map(static function (ScoredChunk $hit): array {
            $cite = (array) ($hit->meta['cite'] ?? []);

            return [
                'content' => $hit->content,
                'score'   => round($hit->score, 4),
                'source'  => [
                    'kb_key'  => (string) ($cite['kb_key'] ?? ''),
                    'title'   => (string) ($cite['title'] ?? ''),
                    'url'     => (string) ($cite['url'] ?? ''),
                    'page'    => $cite['page'] ?? null,
                    'heading' => (string) ($cite['heading'] ?? ''),
                ],
            ];
        }, $hits);

        return json_encode([
            'query'   => $query,
            'count'   => count($results),
            'results' => $results,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function getPermission(): string
    {
        return KnowledgePermissionProvider::PERMISSION;
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getAccess(): string
    {
        return 'read';
    }

    public function getRiskLevel(): string
    {
        return 'low';
    }
}
