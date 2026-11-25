<?php
declare(strict_types=1);

namespace wise\rag\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use wise\rag\eval\Evaluator;

/**
 * 检索评测（回归）
 *
 *   php think wise:rag:eval                          # 默认 runtime/wise-rag/eval.json
 *   php think wise:rag:eval ./eval/cases.json --top-k=5
 *   php think wise:rag:eval --json                    # 输出 JSON（便于 CI 比对）
 */
class Evaluate extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:rag:eval')
            ->setDescription('按评测集跑检索回归，输出 hit_rate / recall / MRR')
            ->addArgument('file', Argument::OPTIONAL, '评测集 JSON 路径')
            ->addOption('top-k', 'k', Option::VALUE_REQUIRED, '默认返回条数', '5')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出结果');
    }

    protected function execute(Input $input, Output $output): int
    {
        $file = (string) ($input->getArgument('file') ?: app()->getRuntimePath() . 'wise-rag' . DIRECTORY_SEPARATOR . 'eval.json');

        try {
            $cases = Evaluator::loadCases($file);
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln('评测集示例：[{"query":"A100 功率是多少","expect":["250W"],"kb":["product_docs"]}]');

            return 1;
        }

        if ($cases === []) {
            $output->writeln('<comment>评测集为空。</comment>');

            return 0;
        }

        $result = app(Evaluator::class)->run($cases, max(1, (int) $input->getOption('top-k')));

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return 0;
        }

        $output->writeln('<info>检索评测结果</info>');
        $output->writeln('');
        $output->writeln('命中  召回    MRR    耗时   查询');
        $output->writeln(str_repeat('-', 96));

        foreach ($result['cases'] as $row) {
            $output->writeln(sprintf(
                '%-5s %-6s %-6s %-6s %s',
                $row['hit'] ? '✓' : '✗',
                number_format((float) $row['recall'], 2),
                number_format((float) $row['mrr'], 2),
                $row['latency_ms'] . 'ms',
                mb_substr((string) $row['query'], 0, 44)
            ));

            if ((string) $row['error'] !== '') {
                $output->writeln('      <error>' . $row['error'] . '</error>');
            }
        }

        $s = $result['summary'];
        $output->writeln(str_repeat('-', 96));
        $output->writeln(sprintf(
            '用例 %d ｜ 命中率 %s ｜ 平均召回 %s ｜ MRR %s ｜ 平均耗时 %dms',
            (int) $s['cases'],
            number_format((float) $s['hit_rate'], 2),
            number_format((float) $s['avg_recall'], 2),
            number_format((float) $s['mrr'], 2),
            (int) $s['avg_latency_ms']
        ));

        return 0;
    }
}
