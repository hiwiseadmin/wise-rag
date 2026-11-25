<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. ScaleAdvisor 判定逻辑：阈值边界、驱动判定、关闭开关
 *   2. 提示文案含关键信息与可操作路径
 *   3. 集成：wise:rag:stats 与 wise:rag:prune 在超阈值时输出该提示，未超阈值时不输出
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p10.php
 * 退出码：0 全部通过；1 存在失败
 */

use think\console\Input;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;
use wise\rag\support\ScaleAdvisor;

require __DIR__ . '/../../../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

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

/** 就地修改 wise-rag 配置（Config::set 对整节为替换语义，故须回写完整数组） */
$setRag = static function (callable $mutate): void {
    $rag = (array) config('wise-rag');
    $mutate($rag);
    Config::set(['wise-rag' => $rag]);
};

/**
 * 以 Buffer 驱动调用命令的 execute()，返回其输出文本
 *
 * 注意：`think\console\Input` 仅在自动读取 `$_SERVER['argv']` 时才剥离命令名，
 * 显式传入数组时会原样作为 token 解析 —— 传 ['think'] 或命令名会被当作位置参数而报
 * "Too many arguments."，故此处传空数组（本用例均以默认参数运行，预览模式）。
 */
$runCommand = static function (object $command, array $argv = []): string {
    $input = new Input([]);
    $input->bind($command->getDefinition());

    $output = new Output('buffer');
    $method = new ReflectionMethod($command, 'execute');
    $method->setAccessible(true);
    $method->invoke($command, $input, $output);

    return (string) $output->fetch();
};

$kbKey = 'verify_p10_' . substr(md5((string) microtime(true)), 0, 8);
$kbId  = 0;

try {
    echo "== 1. ScaleAdvisor 判定逻辑 ==\n";

    check('默认阈值为 50000', ScaleAdvisor::DEFAULT_THRESHOLD === 50000);
    check('默认驱动为 database', ScaleAdvisor::driver() === ScaleAdvisor::BRUTE_DRIVER);

    $setRag(static function (array &$rag): void {
        $rag['vector']['default']             = 'database';
        $rag['vector']['brute_warn_threshold'] = 10;
    });

    check('超过阈值时建议切换', ScaleAdvisor::advises(11));
    check('等于阈值时不建议', !ScaleAdvisor::advises(10));
    check('低于阈值时不建议', !ScaleAdvisor::advises(9));

    $setRag(static function (array &$rag): void {
        $rag['vector']['default'] = 'pgvector';
    });
    check('已切到 ANN 驱动时不提示', !ScaleAdvisor::advises(999999));

    $setRag(static function (array &$rag): void {
        $rag['vector']['default']              = 'database';
        $rag['vector']['brute_warn_threshold'] = 0;
    });
    check('阈值 0 时关闭提示', !ScaleAdvisor::advises(999999));

    $setRag(static function (array &$rag): void {
        $rag['vector']['brute_warn_threshold'] = 10;
    });
    $hint = ScaleAdvisor::hint(123);
    check('文案含分块数与阈值', str_contains($hint, '123') && str_contains($hint, '10'), $hint);
    check(
        '文案给出可操作路径',
        str_contains($hint, 'vector.default') && str_contains($hint, 'wise:rag:index --force'),
        $hint
    );
    check('totalChunks 返回非负整数', ScaleAdvisor::totalChunks() >= 0);

    echo "== 2. wise:rag:stats 集成 ==\n";

    $kbId = (int) Db::name('ai_kb_knowledge_bases')->insertGetId([
        'kb_key' => $kbKey, 'name' => 'P10 规模验证库', 'owner_type' => 'admin', 'owner_id' => 0,
        'visibility' => 'private', 'embedding' => 'verify', 'embed_model' => 'verify', 'dimension' => 4,
        'vector_store' => 'database', 'collection' => '', 'chunk_config' => null, 'retrieval_config' => null,
        'doc_count' => 2, 'chunk_count' => 5, 'vector_count' => 5, 'status' => 'active', 'meta' => null,
        'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
    ]);

    $setRag(static function (array &$rag): void {
        $rag['vector']['brute_warn_threshold'] = 1;
    });

    $statsText = $runCommand(new \wise\rag\command\Stats(), ['think', 'wise:rag:stats']);
    check('stats 超阈值时输出提示', str_contains($statsText, '暴力检索阈值'), '输出中未找到提示');
    check('stats 提示含切换建议', str_contains($statsText, 'ANN'), '输出中未找到 ANN');

    $setRag(static function (array &$rag): void {
        $rag['vector']['brute_warn_threshold'] = 0;
    });
    $statsQuiet = $runCommand(new \wise\rag\command\Stats(), ['think', 'wise:rag:stats']);
    check('stats 关闭阈值后不再提示', !str_contains($statsQuiet, '暴力检索阈值'));

    echo "== 3. wise:rag:prune 集成 ==\n";

    $setRag(static function (array &$rag): void {
        $rag['vector']['brute_warn_threshold'] = 1;
    });
    $pruneText = $runCommand(new \wise\rag\command\Prune(), ['think', 'wise:rag:prune']);
    check('prune 超阈值时输出提示', str_contains($pruneText, '暴力检索阈值'), '输出中未找到提示');
    check('prune 仍保留原有分类统计', str_contains($pruneText, '损坏向量'));

    $setRag(static function (array &$rag): void {
        $rag['vector']['default'] = 'qdrant';
    });
    $pruneQuiet = $runCommand(new \wise\rag\command\Prune(), ['think', 'wise:rag:prune']);
    check('prune 切到 ANN 驱动后不再提示', !str_contains($pruneQuiet, '暴力检索阈值'));
} catch (\Throwable $e) {
    check('未捕获异常', false, $e::class . ': ' . $e->getMessage());
} finally {
    if ($kbId > 0) {
        Db::name('ai_kb_knowledge_bases')->where('id', $kbId)->delete();
    }
    $setRag(static function (array &$rag): void {
        $rag['vector']['default']              = 'database';
        $rag['vector']['brute_warn_threshold'] = 50000;
    });
}

echo "\n";
echo $failures === []
    ? "P10 全部通过（共 {$checks} 项）\n"
    : sprintf("P10 共 %d 项，失败 %d 项：\n", $checks, count($failures));

foreach ($failures as $item) {
    echo "  - {$item}\n";
}

exit($failures === [] ? 0 : 1);
