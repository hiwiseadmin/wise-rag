<?php
declare(strict_types=1);

namespace wise\rag\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use wise\rag\support\ScaleAdvisor;

/**
 * 知识库统计概览
 *
 *   php think wise:rag:stats
 */
class Stats extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:rag:stats')
            ->setDescription('知识库 / 文档 / 分块 / 知识源统计');
    }

    protected function execute(Input $input, Output $output): int
    {
        $bases = Db::name('ai_kb_knowledge_bases')->order('id', 'asc')->select()->toArray();

        if ($bases === []) {
            $output->writeln('<comment>暂无知识库。可执行：php think wise:rag:discover 或 wise_rag_create()</comment>');

            return 0;
        }

        $output->writeln('<info>wise-rag 知识库概览</info>');
        $output->writeln('');
        $output->writeln('ID   kb_key              名称                 维度  embedding       向量库    源  文档  分块');
        $output->writeln(str_repeat('-', 112));

        $totals = ['sources' => 0, 'docs' => 0, 'chunks' => 0];

        foreach ($bases as $kb) {
            $kbId    = (int) $kb['id'];
            $sources = (int) Db::name('ai_kb_sources')->where('kb_id', $kbId)->count();

            $totals['sources'] += $sources;
            $totals['docs']    += (int) $kb['doc_count'];
            $totals['chunks']  += (int) $kb['chunk_count'];

            $output->writeln(sprintf(
                '%-4d %-20s %-20s %-4d %-15s %-9s %-3d %-5d %-5d',
                $kbId,
                mb_substr((string) $kb['kb_key'], 0, 20),
                mb_substr((string) $kb['name'], 0, 20),
                (int) $kb['dimension'],
                mb_substr((string) $kb['embedding'], 0, 15),
                mb_substr((string) $kb['vector_store'], 0, 9),
                $sources,
                (int) $kb['doc_count'],
                (int) $kb['chunk_count']
            ));
        }

        $output->writeln(str_repeat('-', 112));
        $output->writeln(sprintf(
            '合计：知识库 %d 个，知识源 %d 个，文档 %d 篇，分块 %d 个',
            count($bases),
            $totals['sources'],
            $totals['docs'],
            $totals['chunks']
        ));

        $failed = (int) Db::name('ai_kb_documents')->where('status', 'error')->count();
        if ($failed > 0) {
            $output->writeln(sprintf('<comment>注意：有 %d 篇文档索引失败（status=error），可查看 ai_kb_documents.error</comment>', $failed));
        }

        $totalChunks = (int) $totals['chunks'];
        if (ScaleAdvisor::advises($totalChunks)) {
            $output->writeln('');
            $output->writeln('<comment>' . ScaleAdvisor::hint($totalChunks) . '</comment>');
        }

        return 0;
    }
}
