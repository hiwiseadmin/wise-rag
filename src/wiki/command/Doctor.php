<?php
declare(strict_types=1);

namespace wise\wiki\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\wiki\service\DoctorService;

/**
 * 体检与修复
 *
 *   php think wiki:doctor                 # 只报告
 *   php think wiki:doctor --apply         # 执行修复（幂等）
 *   php think wiki:doctor --space=3 --apply
 *
 * 建议纳入日常运维（或由 wise-crontab 每日跑一次 `--apply`）：
 * 它能发现「页面已发布但检索不到」「源库被删导致 wiki 静默停摆」这类不体检就永远不知道的问题。
 */
class Doctor extends Command
{
    protected function configure(): void
    {
        $this->setName('wiki:doctor')
            ->setDescription('Wiki 体检：影子文档一致性 / 孤儿依赖 / 残留锁 / prompt 漂移 / 保留策略 / 过期页面（--staleness）')
            ->addOption('apply', null, Option::VALUE_NONE, '执行修复（默认只报告）')
            ->addOption('space', null, Option::VALUE_REQUIRED, '只体检指定空间 ID')
            ->addOption('staleness', null, Option::VALUE_NONE, '先重算页面腐烂度，并撤下超过阈值的已发布页面');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service = app(DoctorService::class);
        $spaceId = (int) $input->getOption('space');
        $apply   = (bool) $input->getOption('apply');

        if ((bool) $input->getOption('staleness')) {
            $stale = app(\wise\wiki\service\StalenessService::class);
            $scope = $spaceId > 0 ? $spaceId : null;
            $refreshed = $stale->refresh($scope);
            $withdrawn = $stale->withdrawStale($scope);

            $output->writeln(sprintf(
                '<info>腐烂度</info> 重算 %d 页（变更 %d），撤下过期页面 %d 页',
                (int) $refreshed['pages'],
                (int) $refreshed['updated'],
                (int) $withdrawn['withdrawn']
            ));
            $output->writeln('');
        }

        $report = $service->inspect($spaceId > 0 ? $spaceId : null);

        $rows = [
            '空间源库缺失'       => ['spaces_missing_source', 'count'],
            '已发布未进影子库'   => ['pages_unpublished', 'count'],
            '影子文档已丢失'     => ['pages_shadow_missing', 'count'],
            '影子文档与页面不一致' => ['pages_shadow_stale', 'count'],
            '孤儿依赖'           => ['orphan_deps', 'count'],
            '残留编译锁'         => ['stale_locks', 'count'],
            '超期任务日志'       => ['expired_jobs', 'value'],
            '超量版本'           => ['excess_revisions', 'value'],
            'prompt 版本漂移'    => ['prompt_drifts', 'count'],
            '过期已发布页面'     => ['stale_published', 'value'],
        ];

        $output->writeln('<info>Wiki 体检</info>' . ($spaceId > 0 ? '（空间 #' . $spaceId . '）' : ''));

        $problems = 0;
        foreach ($rows as $label => [$key, $mode]) {
            $value = $mode === 'count' ? count((array) ($report[$key] ?? [])) : (int) ($report[$key] ?? 0);
            if ($value > 0) {
                $problems++;
            }
            $output->writeln(sprintf(
                '  %-22s %s',
                $label,
                $value > 0 ? '<comment>' . $value . '</comment>' : '0'
            ));
        }

        if ($problems === 0) {
            $output->writeln('');
            $output->writeln('<info>一切正常：未发现不一致。</info>');

            return 0;
        }

        $this->dumpDetail($output, '源库缺失空间', $report['spaces_missing_source'] ?? [], 'space_key');
        $this->dumpDetail($output, '未进影子库页面', $report['pages_unpublished'] ?? [], 'slug');
        $this->dumpDetail($output, '影子文档丢失页面', $report['pages_shadow_missing'] ?? [], 'slug');
        $this->dumpDetail($output, '影子文档不一致页面', $report['pages_shadow_stale'] ?? [], 'slug');
        $this->dumpDetail($output, 'prompt 漂移空间', $report['prompt_drifts'] ?? [], 'space_key');

        if (!$apply) {
            $output->writeln('');
            $output->writeln('<comment>仅报告。加 --apply 执行修复（所有修复动作幂等）。</comment>');

            return 0;
        }

        $fixed = $service->fix($report);

        $output->writeln('');
        $output->writeln('<info>已修复</info>');
        foreach ($fixed as $key => $value) {
            if ($value > 0) {
                $output->writeln(sprintf('  %-20s %d', $key, $value));
            }
        }

        $after = $service->inspect($spaceId > 0 ? $spaceId : null);
        $output->writeln('');
        $output->writeln($service->isHealthy($after) ? '<info>复检通过 ✅</info>' : '<comment>复检仍有问题（可能是源库确实缺失，需人工处理）</comment>');

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function dumpDetail(Output $output, string $title, array $rows, string $field): void
    {
        if ($rows === []) {
            return;
        }

        $output->writeln('  <comment>' . $title . '</comment>');
        foreach (array_slice($rows, 0, 5) as $row) {
            $output->writeln('    - ' . (string) ($row[$field] ?? json_encode($row, JSON_UNESCAPED_UNICODE)));
        }
        if (count($rows) > 5) {
            $output->writeln('    … 共 ' . count($rows) . ' 条');
        }
    }
}
