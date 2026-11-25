<?php
declare(strict_types=1);

namespace wise\rag\listener;

use think\facade\Db;
use wise\rag\service\KnowledgeBaseService;
use wise\rag\tool\KnowledgeToolRegistrar;

/**
 * 插件卸载清理（PluginUninstall 事件）
 *
 * 1. 删除该插件声明的知识源（source_key 前缀 `{ident}:`、origin=plugin）；
 * 2. 若其自建知识库（kb_key = ident）已无文档，连同向量一并删除，做到「卸载无痕」；
 * 3. 失效检索工具缓存，使工具列表即时更新。
 */
class PluginUninstallCleanup
{
    public function handle(object $event): void
    {
        $ident = (string) ($event->pluginIdent ?? '');
        if ($ident === '' || preg_match('/^[A-Za-z0-9_-]+$/', $ident) !== 1) {
            return;
        }

        try {
            $deletedSources = (int) Db::name('ai_kb_sources')
                ->where('origin', 'plugin')
                ->where('source_key', 'like', $ident . ':%')
                ->delete();

            $removedKb = false;
            $kb = Db::name('ai_kb_knowledge_bases')->where('kb_key', $ident)->find();
            if (is_array($kb) && (int) ($kb['doc_count'] ?? 0) === 0) {
                app(KnowledgeBaseService::class)->delete((int) $kb['id'], true);
                $removedKb = true;
            }

            KnowledgeToolRegistrar::flushToolCache();

            error_log(sprintf(
                '[wise-rag] 插件 %s 卸载清理：删除知识源 %d 个%s',
                $ident,
                $deletedSources,
                $removedKb ? '，并移除空知识库' : ''
            ));
        } catch (\Throwable $e) {
            error_log('[wise-rag] 插件卸载清理失败: ' . $e->getMessage());
        }
    }
}
