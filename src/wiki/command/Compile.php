<?php
declare(strict_types=1);

namespace wise\wiki\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\wiki\compile\TopicPlanner;
use wise\wiki\dto\CompileInput;
use wise\wiki\exception\WikiException;
use wise\wiki\service\CompileService;
use wise\wiki\service\PageService;
use wise\wiki\service\SpaceService;

/**
 * 编译命令
 *
 *   php think wiki:compile --space=product_docs --topic="A100 功率"
 *   php think wiki:compile --space=product_docs --page=12
 *   php think wiki:compile --space=product_docs --limit=5          # 走选题
 *   php think wiki:compile --space=product_docs --limit=5 --dry-run # 只看选题
 *   php think wiki:compile --space=product_docs --dirty            # 只编译待重编译页面
 */
class Compile extends Command
{
    protected function configure(): void
    {
        $this->setName('wiki:compile')
            ->setDescription('编译 wiki 页面：源知识库取料 → LLM 编译 → 落库 → 发布到影子知识库')
            ->addOption('space', null, Option::VALUE_REQUIRED, '空间标识（space_key）或 ID')
            ->addOption('topic', null, Option::VALUE_REQUIRED, '单一主题（缺省时走选题）')
            ->addOption('page', null, Option::VALUE_REQUIRED, '重编译指定页面 ID')
            ->addOption('kind', null, Option::VALUE_OPTIONAL, '页面类型：topic/entity/decision/faq/glossary', 'topic')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '本轮最多编译页数', '5')
            ->addOption('dirty', null, Option::VALUE_NONE, '只编译 dirty_reason 非空的页面')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只展示将要编译的主题，不实际调用 LLM');
    }

    protected function execute(Input $input, Output $output): int
    {
        $spaceKey = trim((string) $input->getOption('space'));
        if ($spaceKey === '') {
            $output->writeln('<error>缺少 --space 参数（空间标识或 ID）</error>');

            return 1;
        }

        $space = app(SpaceService::class)->get($spaceKey);
        if ($space === null) {
            $output->writeln('<error>空间不存在：' . $spaceKey . '</error>');

            return 1;
        }

        if (!$space->isActive()) {
            $output->writeln('<error>空间状态为 ' . (string) $space->status . '，不可编译</error>');

            return 1;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $kind  = (string) $input->getOption('kind');
        $page  = (int) $input->getOption('page');

        $output->writeln(sprintf(
            '<info>空间</info> %s（#%d）  源库=%d  影子库=%d  prompt=%s',
            (string) $space->space_key,
            (int) $space->id,
            (int) $space->source_kb_id,
            (int) $space->shadow_kb_id,
            (string) $space->prompt_version
        ));

        if ($page > 0) {
            $model = app(PageService::class)->get($page);
            if ($model === null) {
                $output->writeln('<error>页面不存在：' . $page . '</error>');

                return 1;
            }

            return $this->runOne(
                $output,
                new CompileInput((int) $space->id, (string) $model->title, CompileInput::TRIGGER_MANUAL, $page, (string) $model->slug, (string) $model->kind)
            );
        }

        $topic = trim((string) $input->getOption('topic'));
        if ($topic !== '') {
            return $this->runOne($output, new CompileInput((int) $space->id, $topic, CompileInput::TRIGGER_MANUAL, 0, '', $kind));
        }

        $results  = [];
        $failures = 0;

        if ((bool) $input->getOption('dirty')) {
            $pages = app(PageService::class)->dirty((int) $space->id, $limit);
            $output->writeln(sprintf('<info>待重编译页面</info> %d 个', count($pages)));

            foreach ($pages as $model) {
                $input2 = new CompileInput(
                    (int) $space->id,
                    (string) $model->title,
                    CompileInput::TRIGGER_DOC_CHANGED,
                    (int) $model->id,
                    (string) $model->slug,
                    (string) $model->kind
                );

                if ((bool) $input->getOption('dry-run')) {
                    $output->writeln('  - ' . (string) $model->slug . '（' . (string) $model->dirty_reason . '）');
                    continue;
                }

                $results[] = $this->compileAndReport($output, $input2);
            }

            return $failures === 0 ? 0 : 1;
        }

        $candidates = app(TopicPlanner::class)->plan($space, $limit);
        if ($candidates === []) {
            $output->writeln('<comment>没有待编译主题：可先配置 wise-llm-wiki.topics.extra，'
                . '或让 wise-rag 打开 retrieval.log_enabled 以从高频检索词自动选题。</comment>');

            return 0;
        }

        $output->writeln(sprintf('<info>选题</info> %d 个', count($candidates)));
        foreach ($candidates as $candidate) {
            $output->writeln(sprintf(
                '  - %s（%s，命中 %d）',
                $candidate->topic,
                $candidate->reason,
                $candidate->hits
            ));
        }

        if ((bool) $input->getOption('dry-run')) {
            $output->writeln('<comment>dry-run：未调用 LLM</comment>');

            return 0;
        }

        foreach ($candidates as $candidate) {
            $results[] = $this->compileAndReport(
                $output,
                new CompileInput((int) $space->id, $candidate->topic, CompileInput::TRIGGER_MANUAL, 0, '', $kind)
            );
        }

        foreach ($results as $r) {
            if (($r['ok'] ?? false) === false) {
                $failures++;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('完成：%d 个主题，失败 %d 个', count($results), $failures));

        return $failures === 0 ? 0 : 1;
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function compileAndReport(Output $output, CompileInput $input): array
    {
        try {
            $result = app(CompileService::class)->compile($input);
        } catch (WikiException $e) {
            $output->writeln('  <error>✗</error> ' . $input->topic . ' — ' . $e->getMessage());

            return ['ok' => false, 'topic' => $input->topic, 'errors' => [$e->getMessage()]];
        }

        if (!empty($result['skipped'])) {
            $output->writeln('  <comment>·</comment> ' . $input->topic . ' — 跳过（无可用证据）');

            return $result;
        }

        if (($result['ok'] ?? false) === false) {
            $output->writeln('  <error>✗</error> ' . ($result['slug'] ?? $input->topic)
                . ' — 校验失败：' . implode('；', (array) ($result['errors'] ?? [])));

            return $result;
        }

        $flags = [];
        if (!empty($result['deferred'])) {
            $flags[] = '人工稿未覆盖';
        }
        if (empty($result['published'])) {
            $flags[] = '未发布(' . (string) ($result['publish_reason'] ?? '') . ')';
        }

        $output->writeln(sprintf(
            '  <info>✓</info> %s → %s（#%d rev%d, %s, 证据 %d, 覆盖率 %.2f, %dms）%s',
            $input->topic,
            (string) ($result['slug'] ?? ''),
            (int) ($result['page_id'] ?? 0),
            (int) ($result['rev_no'] ?? 0),
            (string) ($result['status'] ?? ''),
            (int) ($result['evidence'] ?? 0),
            (float) ($result['coverage'] ?? 0),
            (int) ($result['latency_ms'] ?? 0),
            $flags === [] ? '' : ' [' . implode(' / ', $flags) . ']'
        ));

        return $result;
    }

    protected function runOne(Output $output, CompileInput $input): int
    {
        $result = $this->compileAndReport($output, $input);

        return ($result['ok'] ?? false) === false ? 1 : 0;
    }
}
