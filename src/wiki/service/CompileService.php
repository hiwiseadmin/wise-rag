<?php
declare(strict_types=1);

namespace wise\wiki\service;

use think\facade\Db;
use wise\wiki\compile\EvidenceCollector;
use wise\wiki\compile\OutputValidator;
use wise\wiki\compile\PageWriter;
use wise\wiki\compile\ShadowPublisher;
use wise\wiki\compile\WikiCompiler;
use wise\wiki\dto\CompileInput;
use wise\wiki\dto\ValidationResult;
use wise\wiki\exception\WikiException;
use wise\wiki\model\WikiCompileJob;
use wise\wiki\model\WikiPage;
use wise\wiki\model\WikiSpace;
use wise\wiki\support\WikiCounter;

/**
 * 编译编排（唯一入口）
 *
 * 顺序固定，每一步都有明确理由：
 *   预算闸 → 建 job → 抢页锁 → 取料 → LLM → 校验 → 落库（事务）→ 发布影子库（事务外）
 *
 * 不变式：
 *  - **取料只读源库**（EvidenceCollector 内部硬编码 kbIds），影子库不可能污染输入；
 *  - **发布在事务外**（embedding 是网络调用，R-10）；
 *  - **失败必落 job.error**（否则「页面为什么不更新」无法回答）；
 *  - 校验不通过不写正文（宁可不可见，不可误导）。
 */
class CompileService
{
    public function __construct(
        protected SpaceService $spaces,
        protected PageService $pages,
        protected EvidenceCollector $collector,
        protected WikiCompiler $compiler,
        protected OutputValidator $validator,
        protected PageWriter $writer,
        protected ShadowPublisher $publisher
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws WikiException
     */
    public function compile(CompileInput $input): array
    {
        $space = $this->spaces->get($input->spaceId);
        if ($space === null) {
            throw WikiException::spaceNotFound($input->spaceId);
        }

        if (!$space->isActive()) {
            throw WikiException::invalidArgument(
                sprintf('空间[%s]当前状态为 %s，不可编译。', (string) $space->space_key, (string) $space->status)
            );
        }

        $this->assertBudget($space);

        $startedAt = microtime(true);
        $jobId     = $this->openJob($space, $input);
        $pageId    = $input->pageId;
        $lockOwner = 'compile:' . getmypid() . ':' . substr(md5((string) microtime(true)), 0, 6);

        try {
            $page = null;
            if ($pageId > 0) {
                $page = $this->pages->get($pageId);
                if ($page === null) {
                    throw WikiException::pageNotFound($pageId);
                }
                if (!$this->pages->acquireLock($pageId, $lockOwner, (int) config('wise-rag.wiki.compile.lock_ttl', 300))) {
                    throw WikiException::locked((string) $page->slug);
                }
            }

            $topic = trim($input->topic);
            if ($topic === '') {
                $topic = $page !== null ? (string) $page->title : '';
            }
            if ($topic === '') {
                throw WikiException::invalidArgument('编译主题为空：请传 --topic 或指定 --page。');
            }

            $evidence = $this->collector->collect($space, $topic);

            if ($evidence === []) {
                $this->closeJob($jobId, WikiCompileJob::STATUS_SKIPPED, [
                    'error' => '源知识库未检索到可用证据（资料未覆盖）',
                    'evidence_count' => 0,
                ]);

                return [
                    'ok'      => true,
                    'skipped' => true,
                    'reason'  => 'no_evidence',
                    'topic'   => $topic,
                    'job_id'  => $jobId,
                ];
            }

            $skipAllowed = $page !== null
                && (bool) config('wise-rag.wiki.compile.skip_if_unchanged', true)
                && in_array($input->trigger, [CompileInput::TRIGGER_DOC_CHANGED, CompileInput::TRIGGER_SCHEDULE], true);

            if ($skipAllowed) {
                $fingerprint = $this->writer->fingerprint($evidence);

                if ($fingerprint !== '' && $fingerprint === (string) $page->source_fingerprint) {
                    $this->pages->clearDirty($pageId);
                    $this->closeJob($jobId, WikiCompileJob::STATUS_SKIPPED, [
                        'error'          => '证据指纹未变化（skip_if_unchanged）',
                        'evidence_count' => count($evidence),
                    ]);

                    return [
                        'ok'       => true,
                        'skipped'  => true,
                        'reason'   => 'unchanged',
                        'topic'    => $topic,
                        'page_id'  => $pageId,
                        'job_id'   => $jobId,
                        'evidence' => count($evidence),
                    ];
                }
            }

            $existing = $this->pages->directory((int) $space->id);
            $kind     = $this->validator->normalizeKind($input->kind !== '' ? $input->kind : ($page->kind ?? 'topic'));
            $output   = $this->compiler->compile($space, $topic, $kind, $evidence, $existing);

            $validation = $this->validator->validate($output, $evidence, $space);

            $duplicates = $this->validator->duplicatesOf($output->slug, $existing);
            if ($duplicates !== []) {
                $validation->warnings[] = '疑似重复页面：' . implode(',', $duplicates);
                $validation->status     = ValidationResult::STATUS_DRAFT;
            }

            if (!$validation->ok) {
                $message = '校验未通过：' . implode('；', $validation->errors);
                $this->closeJob($jobId, WikiCompileJob::STATUS_FAILED, [
                    'error'          => $message,
                    'evidence_count' => count($evidence),
                    'tokens_in'      => $output->tokensIn,
                    'tokens_out'     => $output->tokensOut,
                    'model_name'     => $output->model,
                    'prompt_version' => $output->promptVersion,
                    'latency_ms'     => (int) round((microtime(true) - $startedAt) * 1000),
                ]);

                $this->chargeBudget($space, $output->tokensIn + $output->tokensOut);

                return [
                    'ok'         => false,
                    'topic'      => $topic,
                    'slug'       => $output->slug,
                    'job_id'     => $jobId,
                    'errors'     => $validation->errors,
                    'warnings'   => $validation->warnings,
                    'evidence'   => count($evidence),
                ];
            }

            $written = $this->writer->write($space, $output, $evidence, [
                'status'         => $validation->status,
                'change_reason'  => $input->pageId > 0 ? 'rebuild' : 'compile',
                'quality_score'  => (int) round($validation->citationCoverage * 100),
                'prompt_version' => $output->promptVersion,
                'model'          => $output->model,
                'tokens_in'      => $output->tokensIn,
                'tokens_out'     => $output->tokensOut,
                'cost'           => $this->estimateCost($output->tokensIn, $output->tokensOut),
            ]);

            $publish = ['published' => false, 'doc_id' => 0, 'reason' => 'deferred'];
            if (!$written['deferred']) {
                try {
                    $publish = $this->publisher->publish($space, (int) $written['page_id']);
                } catch (WikiException $e) {
                    error_log('[wise-llm-wiki] 发布影子库失败（page=' . $written['page_id'] . '）：' . $e->getMessage());
                    $publish = ['published' => false, 'doc_id' => 0, 'reason' => 'publish_failed'];
                }
            }

            $this->closeJob($jobId, WikiCompileJob::STATUS_OK, [
                'page_id'        => (int) $written['page_id'],
                'evidence_count' => count($evidence),
                'tokens_in'      => $output->tokensIn,
                'tokens_out'     => $output->tokensOut,
                'cost'           => $this->estimateCost($output->tokensIn, $output->tokensOut),
                'model_name'     => $output->model,
                'prompt_version' => $output->promptVersion,
                'latency_ms'     => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            $this->chargeBudget($space, $output->tokensIn + $output->tokensOut);
            WikiCounter::syncSpace((int) $space->id);

            return [
                'ok'             => true,
                'skipped'        => false,
                'job_id'         => $jobId,
                'page_id'        => (int) $written['page_id'],
                'slug'           => $output->slug,
                'status'         => (string) $written['status'],
                'rev_no'         => (int) $written['rev_no'],
                'deferred'       => (bool) $written['deferred'],
                'candidate_rev'  => (int) $written['candidate_rev'],
                'coverage'       => $validation->citationCoverage,
                'warnings'       => $validation->warnings,
                'evidence'       => count($evidence),
                'published'      => (bool) $publish['published'],
                'shadow_doc_id'  => (int) $publish['doc_id'],
                'publish_reason' => (string) $publish['reason'],
                'tokens_in'      => $output->tokensIn,
                'tokens_out'     => $output->tokensOut,
                'latency_ms'     => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (WikiException $e) {
            $this->closeJob($jobId, WikiCompileJob::STATUS_FAILED, [
                'error'      => mb_substr($e->getMessage(), 0, 480),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $this->closeJob($jobId, WikiCompileJob::STATUS_FAILED, [
                'error'      => mb_substr($e->getMessage(), 0, 480),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw WikiException::invalidArgument('编译异常：' . $e->getMessage());
        } finally {
            if ($pageId > 0) {
                $this->pages->releaseLock($pageId);
            }
        }
    }

    /**
     * 按任务快照重试一次编译（后台「重试」按钮）
     *
     * 只允许重试 failed / skipped —— running 的任务可能仍在执行，重试会造成重复计费。
     *
     * @return array<string, mixed>
     * @throws WikiException
     */
    public function retryJob(int $jobId): array
    {
        $job = Db::name('ai_wiki_compile_jobs')->where('id', $jobId)->find();

        if (!is_array($job)) {
            throw WikiException::invalidArgument('任务不存在');
        }

        if (!in_array((string) $job['status'], [WikiCompileJob::STATUS_FAILED, WikiCompileJob::STATUS_SKIPPED], true)) {
            throw WikiException::invalidArgument('只有失败 / 跳过的任务可以重试');
        }

        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];

        $topic  = trim((string) ($payload['topic'] ?? ''));
        $pageId = (int) $job['page_id'];

        if ($topic === '' && $pageId <= 0) {
            throw WikiException::invalidArgument('任务缺少主题与页面，无法重试');
        }

        return $this->compile(new CompileInput(
            (int) $job['space_id'],
            $topic,
            CompileInput::TRIGGER_MANUAL,
            $pageId,
            (string) ($payload['slug'] ?? ''),
            (string) ($payload['kind'] ?? 'topic')
        ));
    }

    /**
     * 批量编译一段空间的待办主题
     *
     * @return array<int, array<string, mixed>>
     */
    public function compileSpace(WikiSpace $space, array $topics, string $trigger = CompileInput::TRIGGER_MANUAL, int $limit = 20): array
    {
        $limit   = max(1, $limit);
        $results = [];

        foreach (array_slice($topics, 0, $limit) as $topic) {
            $topic = trim((string) $topic);
            if ($topic === '') {
                continue;
            }

            try {
                $results[] = $this->compile(new CompileInput((int) $space->id, $topic, $trigger));
            } catch (WikiException $e) {
                $results[] = ['ok' => false, 'topic' => $topic, 'errors' => [$e->getMessage()]];
            }
        }

        return $results;
    }


    /**
     * @return int job_id
     */
    protected function openJob(WikiSpace $space, CompileInput $input): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Db::name('ai_wiki_compile_jobs')->insertGetId([
            'space_id'       => (int) $space->id,
            'page_id'        => $input->pageId,
            'trigger_type'   => $input->trigger,
            'status'         => WikiCompileJob::STATUS_RUNNING,
            'prompt_version' => (string) config('wise-rag.wiki.compile.prompt_version', 'v1'),
            'model_name'     => '',
            'evidence_count' => 0,
            'tokens_in'      => 0,
            'tokens_out'     => 0,
            'cost'           => 0,
            'latency_ms'     => 0,
            'attempt'        => 1,
            'error'          => '',
            'payload'        => json_encode([
                'topic' => $input->topic,
                'slug'  => $input->slug,
                'kind'  => $input->kind,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'create_time'    => $now,
            'update_time'    => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function closeJob(int $jobId, string $status, array $data = []): void
    {
        if ($jobId <= 0) {
            return;
        }

        $data['status']      = $status;
        $data['update_time'] = date('Y-m-d H:i:s');

        try {
            Db::name('ai_wiki_compile_jobs')->where('id', $jobId)->update($data);
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] 更新编译任务失败: ' . $e->getMessage());
        }
    }

    /**
     * 预算闸：用尽即按 on_exceed 处理（pause = 暂停空间，warn = 只记日志）
     */
    protected function assertBudget(WikiSpace $space): void
    {
        $limit = (int) ($space->budget_tokens_month > 0
            ? $space->budget_tokens_month
            : config('wise-rag.wiki.budget.tokens_per_month', 0));

        if ($limit <= 0) {
            return;
        }

        $used = (int) $space->tokens_used_month;
        if ($used < $limit) {
            return;
        }

        $message = sprintf(
            '空间[%s]本月 token 预算已用尽（%d / %d）。',
            (string) $space->space_key,
            $used,
            $limit
        );

        if ((string) config('wise-rag.wiki.budget.on_exceed', 'pause') === 'pause') {
            $this->spaces->pause($space, $message);
            throw WikiException::invalidArgument($message . ' 已自动暂停，请调整预算后恢复。');
        }

        error_log('[wise-llm-wiki] ' . $message . '（on_exceed=warn，继续执行）');
    }

    protected function chargeBudget(WikiSpace $space, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        try {
            Db::name('ai_wiki_spaces')
                ->where('id', (int) $space->id)
                ->exp('tokens_used_month', 'tokens_used_month + ' . $tokens)
                ->update(['update_time' => date('Y-m-d H:i:s')]);
        } catch (\Exception $e) {
            error_log('[wise-llm-wiki] 累计 token 用量失败: ' . $e->getMessage());
        }
    }

    protected function estimateCost(int $tokensIn, int $tokensOut): float
    {
        $priceIn  = (float) config('wise-rag.wiki.budget.price_per_1k_in', 0);
        $priceOut = (float) config('wise-rag.wiki.budget.price_per_1k_out', 0);

        return round($tokensIn / 1000 * $priceIn + $tokensOut / 1000 * $priceOut, 6);
    }
}
