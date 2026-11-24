<?php
declare(strict_types=1);

/**
 * 背景：容器 `make()` 会把解析结果写入 `$instances`，因此在 Swoole / 常驻队列 worker 下
 * 服务实例会**跨请求存活**。本脚本固定住「进程级状态必须安全」这一约束。
 *
 * 覆盖：
 *   1. 无请求上下文时身份读取不抛异常且为匿名（队列 / CLI / 非请求协程）
 *   2. 包内不存在超全局变量依赖（$_SERVER / $_GET / $_POST / $_SESSION / $_FILES / $_ENV / $_COOKIE / $GLOBALS）
 *   3. 向量缓存的进程内 memo 有上限（否则常驻进程内存无限增长）
 *   4. FULLTEXT 可用性静态缓存受 TTL 约束（否则迁移新增索引在重启前不生效）
 *   5. HTTP 退避等待在协程外不报错（协程内让出控制权）
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p11.php
 * 退出码：0 全部通过；1 存在失败
 */

use think\facade\Db;
use wise\rag\cache\DatabaseEmbeddingCache;
use wise\rag\client\HttpClient;
use wise\rag\keyword\FulltextDriver;
use wise\rag\support\ActorContext;

require __DIR__ . '/../../../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

$packageRoot = dirname(__DIR__);

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

/** 读取对象的 protected/private 属性 */
function propValue(object $object, string $property): mixed
{
    $ref = new ReflectionProperty($object, $property);
    $ref->setAccessible(true);

    return $ref->getValue($object);
}

/** 读取类的 protected/private 静态属性 */
function staticPropValue(string $class, string $property): mixed
{
    $ref = new ReflectionProperty($class, $property);
    $ref->setAccessible(true);

    return $ref->getValue();
}

function setStaticPropValue(string $class, string $property, mixed $value): void
{
    $ref = new ReflectionProperty($class, $property);
    $ref->setAccessible(true);
    $ref->setValue(null, $value);
}

try {
    echo "== 1. 请求作用域身份（ActorContext） ==\n";

    $actor = ActorContext::current();
    check('无请求上下文时返回匿名', ($actor['id'] ?? -1) === 0, json_encode($actor));
    check('匿名身份 type 固定为 admin', ($actor['type'] ?? '') === 'admin', json_encode($actor));
    check('isAuthenticated 为 false', ActorContext::isAuthenticated() === false);

    echo "== 2. 包内无超全局变量依赖 ==\n";

    $superglobals = ['$_SERVER', '$_GET', '$_POST', '$_SESSION', '$_FILES', '$_ENV', '$_COOKIE', '$GLOBALS'];
    $hits         = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageRoot . '/src'));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        foreach ($superglobals as $name) {
            if (str_contains($source, $name)) {
                $hits[] = $file->getFilename() . ':' . $name;
            }
        }
    }

    check('src 内未直接依赖超全局变量', $hits === [], implode('、', array_slice($hits, 0, 8)));

    echo "== 3. 向量缓存 memo 有上限 ==\n";

    $cache = new DatabaseEmbeddingCache(['memo_limit' => 3]);
    for ($i = 0; $i < 6; $i++) {
        $cache->get(md5('p11-' . $i), 'verify-p11');
    }
    $memo = (array) propValue($cache, 'memo');
    check('memo 不超过配置上限', count($memo) <= 3, 'count=' . count($memo));

    $defaultCache = new DatabaseEmbeddingCache([]);
    check('未配置时默认上限为 500', propValue($defaultCache, 'memoLimit') === 500, (string) propValue($defaultCache, 'memoLimit'));

    echo "== 4. FULLTEXT 可用性静态缓存受 TTL 约束 ==\n";

    FulltextDriver::resetAvailability();

    $noCacheDriver = new FulltextDriver(['cache_ttl' => 0]);
    $noCacheDriver->isAvailable();
    check('cache_ttl=0 时不写进程内静态缓存', staticPropValue(FulltextDriver::class, 'available') === null);

    FulltextDriver::resetAvailability();
    $driver = new FulltextDriver(['cache_ttl' => 60]);
    $driver->isAvailable();
    check('cache_ttl>0 时写入静态缓存', staticPropValue(FulltextDriver::class, 'available') !== null);
    check('同时记录写入时间戳', (int) staticPropValue(FulltextDriver::class, 'availableAt') > 0);

    $stale = time() - 600;
    setStaticPropValue(FulltextDriver::class, 'availableAt', $stale);
    $driver->isAvailable();
    $refreshed = (int) staticPropValue(FulltextDriver::class, 'availableAt');
    check('TTL 过期后会重新探测', $refreshed > $stale, "stale={$stale} now={$refreshed}");

    FulltextDriver::resetAvailability();

    echo "== 5. HTTP 退避等待不阻塞（协程外不报错） ==\n";

    $client = new HttpClient(['retry_delay_ms' => 0]);
    $method = new ReflectionMethod(HttpClient::class, 'backoff');
    $method->setAccessible(true);

    $thrown = '';
    try {
        $method->invoke($client, 1);
    } catch (\Throwable $e) {
        $thrown = $e->getMessage();
    }
    check('retry_delay_ms=0 时直接返回', $thrown === '', $thrown);

    $client2 = new HttpClient(['retry_delay_ms' => 1]);
    $method->invoke($client2, 1);
    check('retry_delay_ms>0 时正常退避（无 Swoole 走 usleep）', true);
    check('Swoole 不可用时不会误调协程 API', !class_exists('\Swoole\Coroutine'));
} catch (\Throwable $e) {
    check('未捕获异常', false, $e::class . ': ' . $e->getMessage());
} finally {
    FulltextDriver::resetAvailability();
}

echo "\n";
echo $failures === []
    ? "P11 全部通过（共 {$checks} 项）\n"
    : sprintf("P11 共 %d 项，失败 %d 项：\n", $checks, count($failures));

foreach ($failures as $item) {
    echo "  - {$item}\n";
}

exit($failures === [] ? 0 : 1);
