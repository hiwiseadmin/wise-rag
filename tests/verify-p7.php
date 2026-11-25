<?php
declare(strict_types=1);

/**
 * 覆盖：
 *   1. ProviderException 向后兼容（单参构造 / context 读取）
 *   2. ErrorPresenter 出口：普通异常透传、ProviderException 详情不进入返回值
 *   3. HttpClient::redact() 对 sk- / Bearer / api_key= 的掩码
 *   4. HttpClient 失败路径不外泄响应体与主机细节（真实发起一次必然失败请求）
 *
 * 用法：php src/packages/wiseadmin/wise-rag/tests/verify-p7.php
 * 退出码：0 全部通过；1 存在失败
 */

use wise\rag\client\HttpClient;
use wise\rag\exception\ProviderException;
use wise\rag\exception\RagException;
use wise\rag\support\ErrorPresenter;

require __DIR__ . '/../../../../vendor/autoload.php';

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

echo "== 1. ProviderException 向后兼容 ==\n";

$legacy = new ProviderException('旧的单参调用');
check('单参构造 message 原样', $legacy->getMessage() === '旧的单参调用');
check('单参构造 context 为空', $legacy->getContext() === []);
check('仍是 RagException 子类', $legacy instanceof RagException);

$ctx = new ProviderException('脱敏文案', 0, null, ['status' => 401, 'body' => 'sk-***']);
check('多参构造可读取 context', ($ctx->getContext()['status'] ?? 0) === 401);

echo "== 2. ErrorPresenter 出口 ==\n";

check('普通异常透传 message', ErrorPresenter::message(new \RuntimeException('数据库连接失败')) === '数据库连接失败');
check(
    'ProviderException 返回脱敏 message',
    ErrorPresenter::message(new ProviderException('上游服务拒绝请求（HTTP 401），请检查 api_key', 0, null, ['body' => 'sk-live-secret']))
        === '上游服务拒绝请求（HTTP 401），请检查 api_key'
);
check(
    'context 内容不出现在返回值中',
    mb_strpos(
        ErrorPresenter::message(new ProviderException('文案', 0, null, ['body' => 'sk-live-secret'])),
        'sk-live-secret'
    ) === false
);

echo "== 3. HttpClient 脱敏 ==\n";

$http   = new HttpClient();
$redact = new ReflectionMethod(HttpClient::class, 'redact');
$redact->setAccessible(true);

$raw = 'invalid key: Bearer sk-live-abcdef123456 and {"api_key":"deadbeefcafe1234"}';
$out = (string) $redact->invoke($http, $raw);

check('sk- 密钥被掩码', mb_strpos($out, 'sk-live-abcdef123456') === false, $out);
check('Bearer token 被掩码', mb_strpos($out, 'Bearer sk-live') === false, $out);
check('api_key 值被掩码', mb_strpos($out, 'deadbeefcafe1234') === false, $out);
check('非敏感文本保留', mb_strpos($out, 'invalid key') !== false, $out);

$context = new ReflectionMethod(HttpClient::class, 'context');
$context->setAccessible(true);
$ctxArr = (array) $context->invoke($http, 'https://api.example.com/v1/embeddings', 401, $raw);

check('context 含状态码', ($ctxArr['status'] ?? 0) === 401);
check('context 内 body 已脱敏', mb_strpos((string) ($ctxArr['body'] ?? ''), 'sk-live-abcdef123456') === false);
check('context body 截断至 1000 字内', mb_strlen((string) ($ctxArr['body'] ?? '')) <= 1000);

echo "== 4. 失败路径不外泄内部细节 ==\n";

$client = new HttpClient([
    'max_retry'       => 0,
    'retry_delay_ms'  => 0,
    'timeout'         => 2,
    'connect_timeout' => 1,
]);

$caught = null;
try {
    $client->postJson('http://127.0.0.1:1/embeddings', ['input' => 'x']);
} catch (\Throwable $e) {
    $caught = $e;
}

check('抛出 ProviderException', $caught instanceof ProviderException, $caught === null ? 'no throw' : $caught::class);
check('message 给出可操作文案', $caught !== null && mb_strpos($caught->getMessage(), '上游服务暂时不可用') !== false, (string) ($caught?->getMessage()));
check('message 不含 URL / 主机', $caught !== null && mb_strpos($caught->getMessage(), '127.0.0.1') === false, (string) ($caught?->getMessage()));
check('context 记录 url 供排障', $caught instanceof ProviderException && ($caught->getContext()['url'] ?? '') !== '');

echo "\n";
echo $failures === []
    ? "P7 全部通过（共 {$checks} 项）\n"
    : sprintf("P7 共 %d 项，失败 %d 项：\n", $checks, count($failures));

foreach ($failures as $item) {
    echo "  - {$item}\n";
}

exit($failures === [] ? 0 : 1);
