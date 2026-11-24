<?php
declare(strict_types=1);

namespace wise\wiki\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\wiki\eval\Evaluator;
use wise\wiki\exception\WikiException;

/**
 * 检索评测
 *
 *   php think wiki:eval                          # 默认评测集（runtime/wise-llm-wiki/eval.json）
 *   php think wiki:eval --file=./tests/fixtures/eval-cases.json
 *   php think wiki:eval --json                   # CI 可比较的 JSON 输出
 *   php think wiki:eval --strategy=both_merged --top-k=5
 */
class Evaluate extends Command
{
    protected function configure(): void
    {
        $this->setName('wiki:eval')
            ->setDescription('检索评测：hit_rate / page_hit_rate / MRR / 引用覆盖率 / 平均耗时')
            ->addOption('file', 'f', Option::VALUE_REQUIRED, '评测集 JSON 路径（缺省用 eval.default_file 或 runtime 下的 eval.json）')
            ->addOption('json', null, Option::VALUE_NONE, 'JSON 输出（便于 CI 比对）')
            ->addOption('top-k', null, Option::VALUE_REQUIRED, '单条 case 默认返回条数')
            ->addOption('strategy', null, Option::VALUE_REQUIRED, 'page_first | both_merged')
            ->addOption('min-coverage', null, Option::VALUE_REQUIRED, '引用覆盖率门槛（低于该值的命中不算合格）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $file = (string) $input->getOption('file');
        $path = $file !== '' ? $file : Evaluator::defaultPath();

        $evaluator = app(Evaluator::class);

        try {
            $cases = $evaluator->loadFile($path);
        } catch (WikiException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln('');
            $output->writeln('可以先用示例评测集跑通：');
            $output->writeln('  php think wiki:eval --file=src/packages/wiseadmin/wise-llm-wiki/tests/fixtures/eval-cases.json');

            return 1;
        }

        if ($cases === []) {
            $output->writeln('<comment>评测集为空：' . $path . '</comment>');

            return 0;
        }

        $options = [];
        if ((string) $input->getOption('top-k') !== '') {
            $options['top_k'] = (int) $input->getOption('top-k');
        }
        if ((string) $input->getOption('strategy') !== '') {
            $options['strategy'] = (string) $input->getOption('strategy');
        }
        if ((string) $input->getOption('min-coverage') !== '') {
            $options['min_citation_coverage'] = (float) $input->getOption('min-coverage');
        }

        $result = $evaluator->run($cases, $options);

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return 0;
        }

        $output->writeln('<info>Wiki 检索评测</info>');
        $output->writeln(sprintf('  评测集      %s', $path));
        $output->writeln(sprintf('  case 数     %d', (int) $result['cases']));
        $output->writeln(sprintf('  策略        %s', (string) $result['strategy']));
        $output->writeln('');

        $output->writeln(sprintf('  hit_rate           %.4f', $result['hit_rate']));
        $output->writeln(sprintf('  page_hit_rate      %.4f', $result['page_hit_rate']));
        $output->writeln(sprintf('  mrr                %.4f', $result['mrr']));
        $output->writeln(sprintf('  citation_coverage  %.4f', $result['citation_coverage']));
        $output->writeln(sprintf('  avg_latency_ms     %d', $result['avg_latency_ms']));

        if ((float) $result['min_citation_coverage'] > 0) {
            $output->writeln(sprintf('  覆盖门槛           %.2f（低于不计合格命中）', $result['min_citation_coverage']));
        }

        $output->writeln('');
        $output->writeln('<comment>明细</comment>');

        foreach ((array) $result['details'] as $detail) {
            $flag = !empty($detail['hit']) ? '<info>✓</info>' : '<error>✗</error>';
            $desc = sprintf(
                '%s %s → %s%s（%dms）',
                $flag,
                (string) $detail['query'],
                (string) $detail['source'],
                !empty($detail['page_id']) ? '#' . $detail['page_id'] : '',
                (int) $detail['latency_ms']
            );

            if (!empty($detail['hit'])) {
                $desc .= sprintf(' rank=%d', (int) $detail['rank']);
                if ($detail['coverage'] !== null) {
                    $desc .= sprintf(' coverage=%.2f', (float) $detail['coverage']);
                }
            } elseif (!empty($detail['error'])) {
                $desc .= '：' . (string) $detail['error'];
            } else {
                $desc .= '（未命中期望：' . implode('/', (array) ($detail['expect'] ?? [])) . '）';
            }

            $output->writeln('  ' . $desc);
        }

        return 0;
    }
}
