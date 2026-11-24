<?php
declare(strict_types=1);

namespace wise\rag\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\rag\discovery\KnowledgeDiscovery;
use wise\rag\service\IndexTaskService;

/**
 * 发现插件的 knowledge_sources 声明并落库（幂等）
 *
 *   php think wise:rag:discover
 *   php think wise:rag:discover --plugin=smartstock
 *   php think wise:rag:discover --dump=smartstock      # 仅导出声明
 *   php think wise:rag:discover --index                 # 发现后立即索引
 */
class Discover extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:rag:discover')
            ->setDescription('发现插件 manifest 的 ai_schema.knowledge_sources 并幂等落库')
            ->addOption('plugin', 'p', Option::VALUE_REQUIRED, '仅处理指定插件标识')
            ->addOption('dump', 'd', Option::VALUE_REQUIRED, '仅导出指定插件的知识源声明（不落库）')
            ->addOption('index', null, Option::VALUE_NONE, '发现后立即同步索引');
    }

    protected function execute(Input $input, Output $output): int
    {
        $discovery = app(KnowledgeDiscovery::class);

        $dump = (string) $input->getOption('dump');
        if ($dump !== '') {
            $output->writeln((string) json_encode(
                $discovery->dump($dump),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            ));

            return 0;
        }

        $results = $discovery->discover((string) $input->getOption('plugin'));

        if ($results === []) {
            $output->writeln('<comment>未发现任何插件的 knowledge_sources 声明。</comment>');
            $output->writeln('提示：在插件 manifest.json 的 ai_schema.knowledge_sources 中声明后重试。');

            return 0;
        }

        $output->writeln('<info>知识源发现结果</info>');

        foreach ($results as $row) {
            $output->writeln(sprintf(
                '  %-16s kb=%-20s(#%d)%s  源 %d 个：%s',
                (string) $row['plugin'],
                (string) $row['kb_key'],
                (int) $row['kb_id'],
                !empty($row['created_kb']) ? ' [新建知识库]' : '',
                count((array) $row['sources']),
                implode(', ', (array) $row['sources'])
            ));
        }

        if ((bool) $input->getOption('index')) {
            $tasks = app(IndexTaskService::class);
            $output->writeln('');
            $output->writeln('<info>开始索引…</info>');

            foreach ($results as $row) {
                $res  = $tasks->run((int) $row['kb_id'], null, false);
                $done = (array) ($res['result'] ?? []);
                $output->writeln(sprintf('  → kb=%s：%d 个知识源', (string) $row['kb_key'], (int) ($done['sources'] ?? 0)));
            }
        }

        $output->writeln('');
        $output->writeln('<info>完成。</info> 下一步：php think wise:rag:index <kb>（建索引）');

        return 0;
    }
}
