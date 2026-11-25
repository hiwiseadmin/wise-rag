<?php
declare(strict_types=1);

namespace wise\rag\mcp;

use wise\mcp\contract\ToolProviderInterface;
use wise\mcp\dto\ToolDefinition;
use wise\rag\dto\RetrievalQuery;
use wise\rag\dto\ScoredChunk;
use wise\rag\security\KnowledgePermissionProvider;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\RetrievalService;
use wise\rag\support\ErrorPresenter;

/**
 * MCP 工具提供者：把知识库检索以 MCP Tool 暴露给外部客户端
 *
 * 经 `ExtPoint::register(ToolProviderInterface::EXT_POINT, 'wise-rag', self::class)` 注册，
 * 由 wise-mcp 的 `CapabilityRegistry` 聚合（容器实例化，构造函数自动装配）。
 *
 * 暴露工具：`kb.search`（只读，权限标识复用 `ai.tool.kb_search`，免人工确认）。
 */
class RagToolProvider implements ToolProviderInterface
{
    public function __construct(
        protected RetrievalService $retrieval,
        protected KnowledgeBaseService $kbService
    ) {
    }

    public function getNamespace(): string
    {
        return 'rag';
    }

    /**
     * @return ToolDefinition[]
     */
    public function listTools(): array
    {
        if (!(bool) $this->cfg('wise-rag.mcp.expose_tools', true)) {
            return [];
        }

        return [$this->searchTool()];
    }

    protected function searchTool(): ToolDefinition
    {
        return new ToolDefinition(
            'kb.search',
            '检索 wise-rag 知识库（只读），返回带引用溯源（文档标题 / 标题路径 / 页码）的片段。'
                . '适用于内部文档、手册、FAQ、产品资料类问题。',
            [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => '自然语言问题或关键词',
                    ],
                    'kb' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => '限定知识库 kb_key 列表（可选，缺省检索全部启用中的知识库）',
                    ],
                    'top_k' => [
                        'type'        => 'integer',
                        'description' => '返回条数（默认 5，上限 20）',
                    ],
                ],
                'required'   => ['query'],
            ],
            function (array $arguments): array {
                $query = trim((string) ($arguments['query'] ?? ''));
                if ($query === '') {
                    return ToolDefinition::error('query 不能为空');
                }

                $topK  = (int) ($arguments['top_k'] ?? 5);
                $topK  = min(20, max(1, $topK > 0 ? $topK : 5));
                $kbIds = $this->resolveKbIds((array) ($arguments['kb'] ?? []));

                try {
                    $hits = $this->retrieval->retrieve(new RetrievalQuery($query, null, $kbIds, $topK));
                } catch (\Exception $e) {
                    return ToolDefinition::error('检索失败：' . ErrorPresenter::message($e));
                }

                return [
                    'query'   => $query,
                    'count'   => count($hits),
                    'results' => array_map(static function (ScoredChunk $hit): array {
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
                    }, $hits),
                ];
            },
            [
                'title'                  => '知识库检索',
                'permission'             => KnowledgePermissionProvider::PERMISSION,
                'requires_confirmation'  => false,
            ]
        );
    }

    /**
     * kb_key 列表 → 知识库 ID 列表
     *
     * @param string[] $kbKeys
     * @return int[]
     */
    protected function resolveKbIds(array $kbKeys): array
    {
        $ids = [];
        foreach ($kbKeys as $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $kb = $this->kbService->findByKey($key);
            if ($kb !== null) {
                $ids[] = (int) $kb->id;
            }
        }

        return $ids;
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return function_exists('config') ? config($key, $default) : $default;
    }
}
