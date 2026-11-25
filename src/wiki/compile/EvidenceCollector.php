<?php
declare(strict_types=1);

namespace wise\wiki\compile;

use wise\wiki\bridge\RagBridge;
use wise\wiki\dto\Evidence;
use wise\wiki\exception\WikiException;
use wise\wiki\model\WikiSpace;

/**
 * 取料：从**源知识库**检索证据
 *
 * ── 自循环隔离（本包最重要的一行约束）──────────────────────────────────
 * `kbIds` 必须是 `[$space->source_kb_id]` 的**字面量**，不接受任何外部传入。
 * 影子知识库（页面索引）因此不可能进入编译输入 —— 结论永远不会被当作事实喂回模型。
 * 改动此处的任何「可配置化」都会让 R-01 失效，评审必查。
 *
 * ── 失败语义 ──────────────────────────────────────────────────────────
 * 单个子查询失败只记日志（部分证据仍可编译）；全部子查询都失败才抛异常，
 * 避免「网络抖一下就把整页判死」。
 */
class EvidenceCollector
{
    public function __construct(protected RagBridge $rag)
    {
    }

    /**
     * @return Evidence[]
     * @throws WikiException 取料完全失败
     */
    public function collect(WikiSpace $space, string $topic, ?int $topK = null): array
    {
        $sourceKbId = (int) $space->source_kb_id;
        if ($sourceKbId <= 0) {
            throw WikiException::sourceKbMissing($sourceKbId);
        }

        $topK     = $topK ?? max(1, (int) config('wise-rag.wiki.compile.evidence_top_k', 12));
        $maxChars = max(1000, (int) config('wise-rag.wiki.compile.max_evidence_chars', 12000));

        $queries = $this->subQueries($topic);
        $seen    = [];
        $picked  = [];
        $used    = 0;
        $errors  = 0;

        foreach ($queries as $query) {
            try {
                $hits = $this->rag->retrieve($query, [$sourceKbId], $topK);
            } catch (WikiException $e) {
                $errors++;
                error_log('[wise-llm-wiki] 取料子查询失败（' . $query . '）：' . $e->getMessage());
                continue;
            }

            foreach ($hits as $hit) {
                $key = (int) $hit->docId . ':' . (int) $hit->chunkId;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $content = trim((string) $hit->content);
                if ($content === '') {
                    continue;
                }

                if (mb_strlen($content) > 2000) {
                    $content = mb_substr($content, 0, 2000) . '…';
                }

                if ($used + mb_strlen($content) > $maxChars) {
                    continue;
                }

                $used += mb_strlen($content);
                $picked[] = ['hit' => $hit, 'content' => $content];
            }
        }

        if ($picked === []) {
            if ($errors > 0) {
                throw WikiException::invalidArgument('取料失败：源知识库检索全部子查询均报错，请检查 wise-rag 与 embedding 配置。');
            }

            return [];
        }

        $docIds = array_values(array_unique(array_map(static fn (array $p): int => (int) $p['hit']->docId, $picked)));
        $hashes = [];
        try {
            $hashes = $this->rag->docHashes($docIds);
        } catch (WikiException $e) {
            error_log('[wise-llm-wiki] 补齐 doc_hash 失败（退化为下次必重编译）：' . $e->getMessage());
        }

        $evidence = [];
        foreach ($picked as $index => $item) {
            $hit  = $item['hit'];
            $cite = (array) ($hit->meta['cite'] ?? []);

            $evidence[] = new Evidence(
                $index + 1,
                $item['content'],
                (int) $hit->docId,
                (int) $hit->chunkId,
                (string) ($hashes[(int) $hit->docId] ?? ''),
                (string) ($cite['title'] ?? ''),
                (string) ($cite['url'] ?? ''),
                (string) ($cite['heading'] ?? ($hit->meta['heading'] ?? '')),
                (float) $hit->score
            );
        }

        return $evidence;
    }

    /**
     * 子查询拆分：主题本身 + 关键词组合
     *
     * 不调 LLM 做查询改写＼�：纯本地切分，零成本、可预测。
     * 中文没有空格分词，这里按标点与常见连接词切，再取长度 ≥ 2 的片段。
     *
     * @return string[]
     */
    public function subQueries(string $topic): array
    {
        $limit  = max(1, (int) config('wise-rag.wiki.compile.sub_queries', 3));
        $topic  = trim($topic);
        $queries = [$topic];

        if ($limit <= 1) {
            return $queries;
        }

        $parts = preg_split('/[\s,，。、;；:：\/\|（）()\[\]【】]+/u', $topic, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => mb_strlen($p) >= 2));

        if (count($parts) >= 2) {
            $queries[] = $parts[0] . ' ' . $parts[1];
        }
        foreach ($parts as $part) {
            if (count($queries) >= $limit) {
                break;
            }
            if ($part !== $topic) {
                $queries[] = $part;
            }
        }

        return array_values(array_unique(array_slice($queries, 0, $limit)));
    }
}
