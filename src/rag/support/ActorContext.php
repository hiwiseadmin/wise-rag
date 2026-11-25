<?php
declare(strict_types=1);

namespace wise\rag\support;

/**
 * 当前操作者（请求作用域）
 *
 * 为什么需要统一收敛：
 *   - `session()` 只在存在**当前 HTTP 请求**时才有意义。Swoole 常驻进程下容器会长期
 *     持有服务实例，若在非请求上下文（队列 worker / CLI）读取 session，可能拿到
 *     「上一个请求」的残留身份，从而误判知识库可见性与检索日志归属；
 *   - 因此这里集中处理：读取失败一律视为**匿名**（id=0），保证检索链路不因环境差异中断，
 *     且匿名身份在 `RetrievalService` 中只会命中「全局知识库」（owner_id ≤ 0）。
 *
 * 调用约定：本类只读，不写 session；返回结构固定为 ['type' => 'admin'|'member', 'id' => int]。
 */
final class ActorContext
{
    /**
     * 读取当前操作者
     *
     * @return array{type:string, id:int}
     */
    public static function current(): array
    {
        $anonymous = ['type' => 'admin', 'id' => 0];

        if (!function_exists('session')) {
            return $anonymous;
        }

        try {
            $adminId  = (int) (session('admin_id') ?: 0);
            $memberId = (int) (session('member_id') ?: 0);
        } catch (\Exception $e) {
            return $anonymous;
        }

        if ($adminId > 0) {
            return ['type' => 'admin', 'id' => $adminId];
        }

        if ($memberId > 0) {
            return ['type' => 'member', 'id' => $memberId];
        }

        return $anonymous;
    }

    /**
     * 是否处于已登录的请求上下文
     */
    public static function isAuthenticated(): bool
    {
        return self::current()['id'] > 0;
    }
}
