<?php
declare(strict_types=1);

/**
 * wise-rag 契约自检（配置声明 → 代码实现对齐）
 *
 * 动机：本包历史缺陷的共同根因是「配置 / README 声明了某项能力，代码却从未消费」——
 * `ingestion.async`、`retrieval.dedup`、`visibility`、`documents.error` 均属此类。
 * 本脚本把这些契约固化为断言：新增配置键若未被消费、或未实现的键没有显式标注，立即失败。
 *
 * 覆盖：
 *   1. 「已声明且应被消费」的配置键：断言在指定源码位置确实被读取
 *   2. 「已声明但未实现」的配置键：断言注释中明确标注 预留 / 未实现（文档不撒谎）
 *   3. 语义契约：visibility / documents.error / vector_count 在源码中被使用
 *   4. 包默认配置与宿主配置同步：对「列表型（覆盖语义）」与新增键做一致性检查
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-contracts.php
 * 退出码：0 全部通过；1 存在失败
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

$packageRoot   = dirname(__DIR__);
$packageConfig = $packageRoot . '/config/wise-rag.php';
$hostConfig    = dirname($packageRoot, 3) . '/config/wise-rag.php';

$failures = [];
$checks   = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  [ok]   {$label}\n";
        return;
    }
    $failures[] = $label . ($detail !== '' ? " ({$detail})" : '');
    echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** 读取源码并折叠空白，使断言不受排版影响 */
function flat(string $path): string
{
    $raw = @file_get_contents($path);

    return $raw === false ? '' : (string) preg_replace('/\s+/', ' ', $raw);
}

function sourceHas(string $path, string $needle): bool
{
    return $needle !== '' && str_contains(flat($path), (string) preg_replace('/\s+/', ' ', $needle));
}

/** @return array{0:bool,1:mixed} */
function arrayPath(array $data, string $path): array
{
    $value = $data;
    foreach (explode('.', $path) as $seg) {
        if (!is_array($value) || !array_key_exists($seg, $value)) {
            return [false, null];
        }
        $value = $value[$seg];
    }

    return [true, $value];
}

/** 配置文件中该键所在行（或相邻行）是否标注了 预留 / 未实现 */
function markedUnimplemented(string $configPath, string $key): bool
{
    $raw = @file_get_contents($configPath);
    if ($raw === false) {
        return false;
    }

    $lines = preg_split('/\R/', $raw) ?: [];
    foreach ($lines as $i => $line) {
        if (!str_contains($line, "'" . $key . "'")) {
            continue;
        }

        $window = ($lines[$i - 1] ?? '') . $line . ($lines[$i + 1] ?? '');
        if (str_contains($window, '未实现') || str_contains($window, '预留')) {
            return true;
        }
    }

    return false;
}

echo "== 1. 已声明且应被消费的配置键 ==\n";

/** 配置键 => [消费点文件, 源码特征] */
$consumed = [
    'wise-rag.ingestion.async'                 => ['src/rag/controller/admin/KbController.php', 'wise-rag.ingestion.async'],
    'wise-rag.ingestion.batch_size'            => ['src/rag/service/IngestionService.php', 'wise-rag.ingestion.batch_size'],
    'wise-rag.ingestion.max_retry'             => ['src/rag/job/IngestionJob.php', 'wise-rag.ingestion.max_retry'],
    'wise-rag.ingestion.queue'                 => ['src/rag/service/IndexTaskService.php', 'wise-rag.ingestion.queue'],
    'wise-rag.retrieval.dedup'                 => ['src/rag/service/RetrievalService.php', "['dedup']"],
    'wise-rag.retrieval.hybrid'                => ['src/rag/service/RetrievalService.php', "['hybrid']"],
    'wise-rag.retrieval.fusion'                => ['src/rag/service/RetrievalService.php', "['fusion']"],
    'wise-rag.retrieval.min_score'             => ['src/rag/service/RetrievalService.php', "['min_score']"],
    'wise-rag.retrieval.rrf_k'                 => ['src/rag/service/RetrievalService.php', "['rrf_k']"],
    'wise-rag.retrieval.max_context_chars'     => ['src/rag/service/RetrievalService.php', "['max_context_chars']"],
    'wise-rag.retrieval.rerank'                => ['src/rag/service/RetrievalService.php', "['rerank']"],
    'wise-rag.retrieval.log_enabled'           => ['src/rag/service/KnowledgeBaseService.php', 'wise-rag.retrieval.log_enabled'],
    'wise-rag.keyword.boolean'                 => ['src/rag/keyword/FulltextDriver.php', "['boolean']"],
    'wise-rag.keyword.min_length'              => ['src/rag/keyword/FulltextDriver.php', "['min_length']"],
    'wise-rag.keyword.cache_ttl'               => ['src/rag/keyword/FulltextDriver.php', "['cache_ttl']"],
    'wise-rag.keyword.max_terms'               => ['src/rag/keyword/LikeDriver.php', "['max_terms']"],
    'wise-rag.chunk.splitter'                  => ['src/rag/service/IngestionService.php', "['splitter']"],
    'wise-rag.chunk.size'                      => ['src/rag/splitter/FixedSplitter.php', "['size']"],
    'wise-rag.chunk.overlap'                   => ['src/rag/splitter/FixedSplitter.php', "['overlap']"],
    'wise-rag.chunk.min_chars'                 => ['src/rag/splitter/FixedSplitter.php', "['min_chars']"],
    'wise-rag.parser.max_file_size'            => ['src/rag/controller/admin/KbController.php', 'wise-rag.parser.max_file_size'],
    'wise-rag.parser.mapping'                  => ['src/rag/RagServiceProvider.php', 'wise-rag.parser.mapping'],
    'wise-rag.vector.stores'                   => ['src/rag/RagServiceProvider.php', 'wise-rag.vector'],
    'wise-rag.vector.stores.database.scan_page_size' => ['src/rag/vector/DatabaseVectorStore.php', "['scan_page_size']"],
    'wise-rag.vector.brute_warn_threshold'    => ['src/rag/support/ScaleAdvisor.php', 'wise-rag.vector.brute_warn_threshold'],
    'wise-rag.stats.cache_ttl'                 => ['src/rag/service/KnowledgeBaseService.php', 'wise-rag.stats.cache_ttl'],
    'wise-rag.cache.keep_days'                 => ['src/rag/command/Prune.php', 'wise-rag.cache.keep_days'],
    'wise-rag.cache.drivers.database.memo_limit' => ['src/rag/cache/DatabaseEmbeddingCache.php', "['memo_limit']"],
    'wise-rag.agent.auto_tool'                 => ['src/rag/RagServiceProvider.php', 'wise-rag.agent.auto_tool'],
    'wise-rag.agent.global_tool'               => ['src/rag/tool/KnowledgeToolRegistrar.php', "['global_tool']"],
    'wise-rag.agent.tool_cache_ttl'            => ['src/rag/tool/KnowledgeToolRegistrar.php', "['tool_cache_ttl']"],
    'wise-rag.mcp.expose_tools'                => ['src/rag/mcp/RagToolProvider.php', 'wise-rag.mcp.expose_tools'],
    'wise-rag.mcp.expose_resources'            => ['src/rag/mcp/RagResourceProvider.php', 'wise-rag.mcp.expose_resources'],
    'wise-rag.mcp.max_doc_resources'           => ['src/rag/mcp/RagResourceProvider.php', 'wise-rag.mcp.max_doc_resources'],
    'wise-rag.web（configKey）'                 => ['src/rag/RouteRegistrar.php', "protected string \$configKey = 'wise-rag';"],
];

foreach ($consumed as $key => [$file, $needle]) {
    $path = $packageRoot . '/' . $file;
    check(
        "声明被消费：{$key}",
        is_file($path) && sourceHas($path, $needle),
        is_file($path) ? "{$file} 中未找到 {$needle}" : "文件不存在：{$file}"
    );
}

echo "== 2. 已声明但未实现的配置键（注释必须标注） ==\n";

foreach (['query_rewrite', 'inject_context', 'auto_index'] as $key) {
    check("未实现已标注：{$key}", markedUnimplemented($packageConfig, $key), '注释中缺少 预留 / 未实现');
}

echo "== 3. 语义契约（曾被「声明未落实」的字段） ==\n";

check(
    'visibility 参与检索隔离',
    sourceHas($packageRoot . '/src/rag/service/RetrievalService.php', 'KnowledgeBase::VISIBILITY_PRIVATE')
);
check(
    '文档失败原因写入 error 列',
    sourceHas($packageRoot . '/src/rag/service/IngestionService.php', "'status' => 'error'")
);
check(
    '知识库维护 vector_count 计数',
    sourceHas($packageRoot . '/src/rag/support/KbCounter.php', "'vector_count' =>")
        && sourceHas($packageRoot . '/src/rag/service/KnowledgeBaseService.php', 'KbCounter::syncAndFlush')
);
check(
    '统计概览不再扫描 vector 大列',
    !sourceHas($packageRoot . '/src/rag/controller/admin/KbController.php', "whereNotNull('vector')->count()")
);
check(
    'session 读取已收敛到 ActorContext（常驻进程安全）',
    !sourceHas($packageRoot . '/src/rag/service/RetrievalService.php', "session('admin_id')")
        && sourceHas($packageRoot . '/src/rag/support/ActorContext.php', 'function current(): array')
);

echo "== 4. 包默认配置与宿主配置同步 ==\n";

$pkg  = (require $packageConfig) ?: [];
$host = (require $hostConfig) ?: [];

/** 递归找出 $base 有而 $override 缺失的键路径（列表型数组视为叶子，不做逐元素比较） */
function missingPaths(array $base, array $override, string $prefix = ''): array
{
    $missing = [];
    foreach ($base as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (!array_key_exists($key, $override)) {
            $missing[] = $path;
            continue;
        }

        if (
            is_array($value) && is_array($override[$key])
            && !array_is_list($value) && !array_is_list($override[$key])
        ) {
            $missing = array_merge($missing, missingPaths($value, $override[$key], $path));
        }
    }

    return $missing;
}

/** 这些键必须两侧存在且取值一致（列表型键为覆盖语义，包内改默认值对宿主无效） */
$mustMatch = [
    'web.enable',
    'web.groups.admin.middleware',
    'ingestion.async',
    'retrieval.dedup',
    'agent.auto_tool',
    'vector.stores.database.scan_page_size',
    'vector.brute_warn_threshold',
    'stats.cache_ttl',
    'cache.drivers.database.memo_limit',
];

foreach ($mustMatch as $path) {
    [$pkgHas, $pkgVal]   = arrayPath($pkg, $path);
    [$hostHas, $hostVal] = arrayPath($host, $path);

    check(
        "两侧一致：{$path}",
        $pkgHas && $hostHas && $pkgVal === $hostVal,
        sprintf(
            'package=%s host=%s',
            $pkgHas ? json_encode($pkgVal, JSON_UNESCAPED_UNICODE) : '(缺失)',
            $hostHas ? json_encode($hostVal, JSON_UNESCAPED_UNICODE) : '(缺失)'
        )
    );
}

[$mwOk, $mw] = arrayPath($host, 'web.groups.admin.middleware');
check('宿主 web 分组已挂载鉴权中间件', $mwOk && is_array($mw) && $mw !== [], json_encode($mw));

$missingKeys = missingPaths($pkg, $host);
check(
    '宿主配置覆盖包默认的全部键（列表型键除外）',
    $missingKeys === [],
    '缺失 ' . count($missingKeys) . ' 个：' . implode('、', array_slice($missingKeys, 0, 12))
);

$hostOnly = missingPaths($host, $pkg);
if ($hostOnly !== []) {
    echo '  [note] 宿主配置独有的键（允许的本地扩展）：' . implode('、', array_slice($hostOnly, 0, 12)) . "\n";
}

echo "== 5. catch (\\Throwable) 兜底白名单（TD-13） ==\n";

/**
 * 只允许下列位置捕获 \Throwable：要么会把异常重新抛出 / 记录日志（非静默吞掉），
 * 要么处于「必须跑完」的边界（boot 可选集成、队列记账、卸载清理、异常链内部）。
 * 其余位置一律应为 \Exception —— 否则 PHP Error（未定义常量 / 方法等编程错误）
 * 会被降级成静默分支，这正是整体复核查出 R2 缺陷的成因。
 */
$throwableAllowList = [
    'RagServiceProvider.php'      => 4,
    'EventBus.php'                => 1,
    'PluginUninstallCleanup.php'  => 1,
    'IngestionJob.php'            => 1,
    'PdfParser.php'               => 1,
    'IngestionService.php'        => 2,
];

$throwableHits = [];
$srcIterator   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageRoot . '/src'));

foreach ($srcIterator as $srcFile) {
    if (!$srcFile->isFile() || $srcFile->getExtension() !== 'php') {
        continue;
    }

    $hit = substr_count((string) file_get_contents($srcFile->getPathname()), 'catch (\Throwable');
    if ($hit > 0) {
        $throwableHits[$srcFile->getFilename()] = ($throwableHits[$srcFile->getFilename()] ?? 0) + $hit;
    }
}

ksort($throwableHits);
ksort($throwableAllowList);

check(
    'catch (\\Throwable) 仅出现在白名单内且数量一致',
    $throwableHits === $throwableAllowList,
    'actual=' . json_encode($throwableHits, JSON_UNESCAPED_UNICODE)
        . ' expected=' . json_encode($throwableAllowList, JSON_UNESCAPED_UNICODE)
);

echo "\n";
echo $failures === []
    ? "契约自检全部通过（共 {$checks} 项）\n"
    : sprintf("契约自检共 %d 项，失败 %d 项：\n", $checks, count($failures));

foreach ($failures as $item) {
    echo "  - {$item}\n";
}

exit($failures === [] ? 0 : 1);
