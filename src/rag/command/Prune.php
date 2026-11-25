<?php
declare(strict_types=1);

namespace wise\rag\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use wise\rag\discovery\KnowledgeDiscovery;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\service\VectorRepairService;
use wise\rag\support\ScaleAdvisor;
use wise\rag\tool\KnowledgeToolRegistrar;

/**
 * 清理孤儿数据与损坏向量（默认仅预览）
 *
 *   1. 孤儿知识源：origin=plugin 但插件已卸载，或所属知识库已删除
 *   2. 孤儿文档：所属知识库已删除
 *   3. 孤儿分块：所属文档已删除
 *   4. 过期向量缓存：超出 cache.keep_days 未使用
 *   5. 损坏向量：序列化长度与 dimension 不符（历史定长列类型导致）→ 清空并标记文档待重建
 *
 *   php think wise:rag:prune            # 预览
 *   php think wise:rag:prune --apply    # 执行删除
 */
class Prune extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:rag:prune')
            ->setDescription('清理孤儿知识源 / 文档 / 分块（默认仅预览）')
            ->addOption('apply', null, Option::VALUE_NONE, '实际执行删除');
    }

    protected function execute(Input $input, Output $output): int
    {
        $apply = (bool) $input->getOption('apply');

        $installed = array_keys(app(KnowledgeDiscovery::class)->manifests());

        $kbIds = array_map('intval', Db::name('ai_kb_knowledge_bases')->column('id'));

        $orphanSources = [];
        foreach (Db::name('ai_kb_sources')->field('id,source_key,origin,kb_id')->select()->toArray() as $row) {
            $ident      = explode(':', (string) $row['source_key'])[0];
            $pluginGone = (string) $row['origin'] === 'plugin' && $ident !== '' && !in_array($ident, $installed, true);
            $kbGone     = !in_array((int) $row['kb_id'], $kbIds, true);

            if ($pluginGone || $kbGone) {
                $orphanSources[] = (int) $row['id'];
            }
        }

        $orphanDocs = array_map('intval', Db::name('ai_kb_documents')
            ->whereNotIn('kb_id', $kbIds === [] ? [0] : $kbIds)
            ->column('id'));

        $docIds = array_map('intval', Db::name('ai_kb_documents')->column('id'));
        $orphanChunkQuery = fn () => Db::name('ai_kb_chunks')
            ->whereNotIn('doc_id', $docIds === [] ? [0] : $docIds);

        $orphanChunks = array_map('intval', $orphanChunkQuery()->column('id'));

        $orphanChunkKbIds = array_values(array_unique(array_map('intval', $orphanChunkQuery()
            ->where('kb_id', '>', 0)
            ->column('kb_id'))));

        $keepDays     = (int) (function_exists('config') ? config('wise-rag.cache.keep_days', 30) : 30);
        $expiredCache = 0;
        if ($keepDays > 0) {
            try {
                $expiredCache = (int) Db::name('ai_kb_embedding_cache')
                    ->where('update_time', '<', date('Y-m-d H:i:s', time() - $keepDays * 86400))
                    ->count();
            } catch (\Exception $e) {
                $expiredCache = 0;
            }
        }

        $repair  = app(VectorRepairService::class);
        $broken  = $repair->inspect();

        $output->writeln($apply ? '<info>执行清理</info>' : '<comment>预览模式（加 --apply 执行删除）</comment>');
        $output->writeln(sprintf('  孤儿知识源：%d', count($orphanSources)));
        $output->writeln(sprintf('  孤儿文档  ：%d', count($orphanDocs)));
        $output->writeln(sprintf('  孤儿分块  ：%d', count($orphanChunks)));
        $output->writeln(sprintf('  过期向量缓存：%d（保留 %d 天）', $expiredCache, $keepDays));
        $output->writeln(sprintf(
            '  损坏向量  ：%d（受影响文档 %d，缓存 %d）→ 清空后需重建索引',
            $broken['chunks'],
            $broken['documents'],
            $broken['cache']
        ));

        $totalChunks = ScaleAdvisor::totalChunks();
        if (ScaleAdvisor::advises($totalChunks)) {
            $output->writeln('');
            $output->writeln('<comment>' . ScaleAdvisor::hint($totalChunks) . '</comment>');
        }

        if (!$apply) {
            return 0;
        }

        foreach (array_chunk($orphanSources, 500) as $ids) {
            Db::name('ai_kb_sources')->whereIn('id', $ids)->delete();
        }
        foreach (array_chunk($orphanDocs, 500) as $ids) {
            Db::name('ai_kb_documents')->whereIn('id', $ids)->delete();
        }
        foreach (array_chunk($orphanChunks, 500) as $ids) {
            Db::name('ai_kb_chunks')->whereIn('id', $ids)->delete();
        }

        if ($expiredCache > 0) {
            try {
                Db::name('ai_kb_embedding_cache')
                    ->where('update_time', '<', date('Y-m-d H:i:s', time() - $keepDays * 86400))
                    ->delete();
            } catch (\Exception $e) {
            }
        }

        if ($broken['chunks'] > 0 || $broken['cache'] > 0) {
            $fixed = $repair->repair();
            $output->writeln(sprintf(
                '  已清空损坏向量 %d 个（标记文档 %d 篇为待重建，清理缓存 %d 条，刷新知识库 %d 个）',
                $fixed['chunks'],
                $fixed['documents'],
                $fixed['cache'],
                $fixed['kbs']
            ));
        }

        if ($orphanChunkKbIds !== []) {
            $kbService = app(KnowledgeBaseService::class);
            foreach ($orphanChunkKbIds as $affectedKbId) {
                $kbService->refreshStats($affectedKbId);
            }
            $output->writeln(sprintf('  已刷新 %d 个知识库的计数', count($orphanChunkKbIds)));
        }

        KnowledgeToolRegistrar::flushToolCache();

        $output->writeln('<info>清理完成。</info>');

        return 0;
    }
}
