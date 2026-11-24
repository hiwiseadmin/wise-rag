<?php
declare(strict_types=1);

namespace wise\wiki\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\wiki\service\InsightService;

/**
 * 观测报表
 *
 *   php think wiki:stats
 *   php think wiki:stats --space=product_docs
 *   php think wiki:stats --json
 *
 * 回答三个日常问题：编译是否正常、花了多少、页面还新不新鲜。
 */
class Stats extends Command
{
    protected function configure(): void
    {
        $this->setName('wiki:stats')
            ->setDescription('Wiki 观测：空间 / 页面 / 任务成功率 / token 与成本 / 预算占比 / 新鲜度分布')
            ->addOption('space', null, Option::VALUE_REQUIRED, '只统计指定空间（space_key 或 ID）')
            ->addOption('json', null, Option::VALUE_NONE, 'JSON 输出');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service = app(InsightService::class);
        $spaceId = null;

        $spaceKey = trim((string) $input->getOption('space'));
        if ($spaceKey !== '') {
            $space = app(\wise\wiki\service\SpaceService::class)->get($spaceKey);
            if ($space === null) {
                $output->writeln('<error>空间不存在：' . $spaceKey . '</error>');

                return 1;
            }
            $spaceId = (int) $space->id;
        }

        $data = $service->overview($spaceId);

        if ((bool) $input->getOption('json')) {
            $data['by_space'] = $spaceId === null ? $service->bySpace() : [];
            $output->writeln((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return 0;
        }

        $output->writeln('<info>Wiki 观测</info>' . ($spaceId !== null ? '（空间 #' . $spaceId . '）' : ''));

        $s = $data['spaces'];
        $output->writeln(sprintf('  空间      %d（active %d / paused %d）', $s['total'], $s['active'], $s['paused']));

        $p = $data['pages'];
        $output->writeln(sprintf(
            '  页面      %d（published %d / review %d / draft %d / archived %d）  待重编译 %d',
            $p['total'], $p['published'], $p['review'], $p['draft'], $p['archived'], $p['dirty']
        ));

        if ($p['unpublished_shadow'] > 0) {
            $output->writeln(sprintf(
                '            <comment>已发布但未进影子库 %d 页 → 执行 wiki:doctor --apply</comment>',
                $p['unpublished_shadow']
            ));
        }

        $j = $data['jobs'];
        $output->writeln(sprintf(
            '  任务      %d（ok %d / failed %d / skipped %d）成功率 %.2f%%  最近 %s',
            $j['total'], $j['ok'], $j['failed'], $j['skipped'], $j['success_rate'] * 100, $j['last_at'] !== '' ? $j['last_at'] : '—'
        ));

        $t = $data['tokens'];
        $output->writeln(sprintf(
            '  token     %s / %s（%.2f%%）%s',
            number_format((int) $t['used']),
            (int) $t['budget'] > 0 ? number_format((int) $t['budget']) : '未设上限',
            (float) $t['ratio'] * 100,
            $t['exhausted'] ? '  <error>预算已用尽</error>' : ''
        ));
        $output->writeln(sprintf('  成本      %s（按 jobs.cost 汇总）', number_format((float) $data['cost'], 6)));
        $output->writeln(sprintf('  依赖/链接 %d / %d', (int) $data['deps'], (int) $data['links']));

        $st = $data['staleness'];
        $output->writeln(sprintf(
            '  新鲜度    avg %.1f / max %d，超阈值（已发布）%d 页',
            (float) $st['avg'],
            (int) $st['max'],
            (int) $st['over_threshold']
        ));
        $output->writeln('  分布      ' . implode('  ', array_map(
            static fn (string $k, int $v): string => $k . ':' . $v,
            array_keys((array) $st['buckets']),
            array_values((array) $st['buckets'])
        )));

        if ($spaceId === null) {
            $rows = $service->bySpace();
            if ($rows !== []) {
                $output->writeln('');
                $output->writeln('<comment>按空间</comment>');
                $output->writeln(sprintf('  %-24s %6s %6s %10s %10s %8s %7s %8s', 'space_key', 'pages', 'dirty', 'tokens', 'budget', 'ratio', 'failed', 'cost'));

                foreach ($rows as $row) {
                    $output->writeln(sprintf(
                        '  %-24s %6d %6d %10s %10s %7.1f%% %7d %8.4f',
                        (string) $row['space_key'],
                        (int) $row['pages'],
                        (int) $row['dirty'],
                        number_format((int) $row['tokens']),
                        (int) $row['budget'] > 0 ? number_format((int) $row['budget']) : '-',
                        (float) $row['ratio'] * 100,
                        (int) $row['failed'],
                        (float) $row['cost']
                    ));
                }
            }
        }

        return 0;
    }
}
