<?php
declare(strict_types=1);

namespace wise\rag\discovery;

use think\facade\Db;
use wise\rag\model\KnowledgeBase;
use wise\rag\service\KnowledgeBaseService;

/**
 * 插件知识源发现（零接入）
 *
 * 扫描插件 `manifest.json` 的 `ai_schema.knowledge_sources` 声明，
 * 幂等落库到 `ai_kb_sources`（origin=plugin），并保证对应知识库存在。
 *
 * 声明示例：
 * ```json
 * "ai_schema": {
 *   "knowledge_kb_key": "smartstock_docs",     // 可选，默认取插件标识
 *   "knowledge_sources": [
 *     { "key": "product_docs", "title": "商品说明书", "provider": "table",
 *       "config": { "table": "smartstock_product", "content_fields": ["name","description"] },
 *       "sync_mode": "on_change", "chunk": { "size": 400, "overlap": 60, "splitter": "structure" } }
 *   ]
 * }
 * ```
 */
class KnowledgeDiscovery
{
    public function __construct(protected KnowledgeBaseService $kbService)
    {
    }

    /**
     * 发现并落库（幂等）
     *
     * @param string|null $onlyIdent 仅处理指定插件
     * @return array<int, array<string, mixed>>
     */
    public function discover(?string $onlyIdent = null): array
    {
        $results = [];

        foreach ($this->manifests() as $ident => $manifest) {
            if ($onlyIdent !== null && $onlyIdent !== '' && $ident !== $onlyIdent) {
                continue;
            }

            $aiSchema = (array) ($manifest['ai_schema'] ?? []);
            $sources  = (array) ($aiSchema['knowledge_sources'] ?? []);
            if ($sources === []) {
                continue;
            }

            $kbKey     = (string) ($aiSchema['knowledge_kb_key'] ?? $ident);
            $kbName    = (string) ($manifest['title'] ?? $ident);
            $createdKb = false;

            $kb = $this->kbService->findByKey($kbKey);
            if ($kb === null) {
                $kb = $this->kbService->create([
                    'kb_key'      => $kbKey,
                    'name'        => $kbName,
                    'description' => "由插件 {$ident} 的 manifest 声明自动创建",
                ]);
                $createdKb = true;
            }

            $sourceKeys = [];
            foreach ($sources as $decl) {
                if (!is_array($decl)) {
                    continue;
                }

                $key = (string) ($decl['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                $sourceKey = $ident . ':' . $key;
                $config    = (array) ($decl['config'] ?? []);
                $config['title'] = (string) ($decl['title'] ?? $key);
                if (!empty($decl['chunk'])) {
                    $config['chunk'] = (array) $decl['chunk'];
                }

                $this->upsertSource(
                    (int) $kb->id,
                    $sourceKey,
                    (string) ($decl['provider'] ?? 'manual'),
                    $config,
                    (string) ($decl['sync_mode'] ?? 'manual')
                );

                $sourceKeys[] = $sourceKey;
            }

            $results[] = [
                'plugin'     => $ident,
                'kb_id'      => (int) $kb->id,
                'kb_key'     => $kbKey,
                'created_kb' => $createdKb,
                'sources'    => $sourceKeys,
            ];
        }

        return $results;
    }

    /**
     * 导出某插件的知识源声明（便于补全 manifest）
     *
     * @return array<string, mixed>
     */
    public function dump(string $ident): array
    {
        $manifest = $this->manifests()[$ident] ?? null;
        if ($manifest === null) {
            return ['error' => "插件不存在或缺少 manifest.json：{$ident}"];
        }

        return [
            'plugin'           => $ident,
            'knowledge_kb_key' => (string) (($manifest['ai_schema']['knowledge_kb_key'] ?? $ident)),
            'knowledge_sources' => (array) ($manifest['ai_schema']['knowledge_sources'] ?? []),
        ];
    }

    /**
     * 扫描插件 manifest（ident => manifest）
     *
     * @return array<string, array<string, mixed>>
     */
    public function manifests(): array
    {
        $out = [];

        foreach ($this->pluginDirs() as $base) {
            foreach (glob($base . '*' . DIRECTORY_SEPARATOR . 'manifest.json') ?: [] as $file) {
                $ident = basename(dirname($file));
                if (isset($out[$ident])) {
                    continue;
                }

                $raw     = file_get_contents($file);
                $decoded = $raw === false ? null : json_decode($raw, true);
                if (is_array($decoded)) {
                    $out[$ident] = $decoded;
                }
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * 插件根目录候选（`plugins/` 为主，兼容 `plugin/`）
     *
     * @return string[]
     */
    protected function pluginDirs(): array
    {
        $candidates = [];
        if (function_exists('plugin_path')) {
            $candidates[] = plugin_path();
        }
        if (function_exists('root_path')) {
            $candidates[] = root_path() . 'plugins' . DIRECTORY_SEPARATOR;
            $candidates[] = root_path() . 'plugin' . DIRECTORY_SEPARATOR;
        }

        $dirs = [];
        foreach ($candidates as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                $dirs[$dir] = true;
            }
        }

        return array_keys($dirs);
    }

    /**
     * 幂等落库一个知识源
     *
     * @param array<string, mixed> $config
     */
    protected function upsertSource(int $kbId, string $sourceKey, string $provider, array $config, string $syncMode): void
    {
        $now  = date('Y-m-d H:i:s');
        $data = [
            'kb_id'       => $kbId,
            'source_key'  => $sourceKey,
            'origin'      => 'plugin',
            'provider'    => $provider,
            'config'      => json_encode($config, JSON_UNESCAPED_UNICODE),
            'sync_mode'   => $syncMode,
            'status'      => 'active',
            'update_time' => $now,
        ];

        $existing = Db::name('ai_kb_sources')
            ->where('kb_id', $kbId)
            ->where('source_key', $sourceKey)
            ->find();

        if (is_array($existing)) {
            Db::name('ai_kb_sources')->where('id', (int) $existing['id'])->update($data);

            return;
        }

        $data['create_time'] = $now;
        Db::name('ai_kb_sources')->insert($data);
    }

    /**
     * 插件知识库状态（供命令展示）
     *
     * @return array<string, mixed>|null
     */
    public function knowledgeBaseOf(string $kbKey): ?array
    {
        $row = Db::name('ai_kb_knowledge_bases')->where('kb_key', $kbKey)->find();

        return is_array($row) ? $row : null;
    }

    /**
     * 该插件默认知识库键
     */
    public function kbKeyOf(string $ident): string
    {
        $manifest = $this->manifests()[$ident] ?? [];

        return (string) (($manifest['ai_schema']['knowledge_kb_key'] ?? $ident)) ?: $ident;
    }

    /**
     * 是否为启用中的知识库状态
     */
    public function isActive(KnowledgeBase $kb): bool
    {
        return $kb->status === KnowledgeBase::STATUS_ACTIVE;
    }
}
