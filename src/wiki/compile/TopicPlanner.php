<?php
declare(strict_types=1);

namespace wise\wiki\compile;

use wise\wiki\bridge\RagBridge;
use wise\wiki\contract\TopicPlannerInterface;
use wise\wiki\dto\TopicCandidate;
use wise\wiki\model\WikiSpace;

/**
 * 选题：决定「本轮该编译哪些主题」
 *
 * 三个来源按优先级叠加－两个）：
 *  1. `topics.extra`：人工固定清单（可控、可复现，验证与演示都用它）；
 *  2. `ai_kb_retrieval_logs` 高频检索词：真实需求驱动（需 wise-rag 开启 `retrieval.log_enabled`）；
 *  3. 页面链接图缺口（依赖 ai_wiki_links 的沉淀数据）。
 *
 * 不在此处调 LLM：选题要便宜、确定性、可解释。
 */
class TopicPlanner implements TopicPlannerInterface
{
    public function __construct(protected RagBridge $rag)
    {
    }

    /**
     * @return TopicCandidate[]
     */
    public function plan(WikiSpace $space, int $limit = 20): array
    {
        $limit = max(1, $limit);
        $out   = [];
        $seen  = [];

        $push = static function (TopicCandidate $candidate) use (&$out, &$seen): void {
            $key = mb_strtolower(trim($candidate->topic));
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[]      = $candidate;
        };

        foreach ((array) config('wise-rag.wiki.topics.extra', []) as $topic) {
            $topic = trim((string) $topic);
            if ($topic !== '') {
                $push(new TopicCandidate($topic, '人工主题清单'));
            }
        }

        if ((bool) config('wise-rag.wiki.topics.from_retrieval_logs', true)) {
            $rows = $this->rag->topQueries(
                (int) $space->source_kb_id,
                (int) config('wise-rag.wiki.topics.lookback_days', 30),
                (int) config('wise-rag.wiki.topics.min_query_hits', 5),
                $limit
            );

            foreach ($rows as $row) {
                $push(new TopicCandidate($row['query'], '近期高频检索', $row['hits']));
            }
        }

        return array_slice($out, 0, $limit);
    }
}
