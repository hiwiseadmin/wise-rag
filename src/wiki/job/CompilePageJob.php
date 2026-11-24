<?php
declare(strict_types=1);

namespace wise\wiki\job;

use think\facade\Db;
use wise\wiki\dto\CompileInput;
use wise\wiki\exception\WikiException;
use wise\wiki\service\CompileService;

/**
 * 单页重编译异步任务（think-queue）
 *
 * 负载：`['space_id'=>int, 'page_id'=>int, 'topic'=>string, 'slug'=>string, 'kind'=>string, 'trigger'=>string]`
 *
 * 与 wise-rag 的 IngestionJob 同款语义：
 *  - `$job` 用 object 声明，未安装 think-queue 时本类仍可加载 / 单测；
 *  - 失败按 `queue.max_retry` 重试，超限丢弃并记录；
 *  - 页面已被删除时任务直接作废（delete），不重试（重试也不会成功）。
 */
class CompilePageJob
{
    /**
     * @param object               $job
     * @param array<string, mixed> $data
     */
    public function fire(object $job, array $data): void
    {
        $pageId = (int) ($data['page_id'] ?? 0);

        try {
            $spaceId = (int) ($data['space_id'] ?? 0);
            if ($spaceId <= 0 || $pageId <= 0) {
                throw WikiException::invalidArgument('重编译任务缺少 space_id / page_id');
            }

            $page = Db::name('ai_wiki_pages')->where('id', $pageId)->field('id,title,slug,kind')->find();
            if (!is_array($page)) {
                $this->finish($job);

                return;
            }

            $result = app(CompileService::class)->compile(new CompileInput(
                $spaceId,
                (string) ($data['topic'] ?? $page['title']),
                (string) ($data['trigger'] ?? CompileInput::TRIGGER_DOC_CHANGED),
                $pageId,
                (string) ($data['slug'] ?? $page['slug']),
                (string) ($data['kind'] ?? $page['kind'])
            ));

            if (($result['ok'] ?? false) === false) {
                error_log(sprintf(
                    '[wise-llm-wiki] 重编译未通过 page=%d：%s',
                    $pageId,
                    implode('；', (array) ($result['errors'] ?? []))
                ));
            }

            $this->finish($job);
        } catch (\Throwable $e) {
            error_log(sprintf('[wise-llm-wiki] 重编译任务失败 page=%d：%s', $pageId, $e->getMessage()));

            $maxRetry = (int) config('wise-rag.wiki.queue.max_retry', 3);
            $attempts = method_exists($job, 'attempts') ? (int) $job->attempts() : 0;

            if ($attempts >= $maxRetry) {
                $this->finish($job);

                return;
            }

            if (method_exists($job, 'release')) {
                $job->release(10);
            }
        }
    }

    protected function finish(object $job): void
    {
        if (method_exists($job, 'delete')) {
            $job->delete();
        }
    }
}
