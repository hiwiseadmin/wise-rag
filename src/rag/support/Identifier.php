<?php
declare(strict_types=1);

namespace wise\rag\support;

/**
 * 标识符校验（防列名 / 表名注入）
 *
 * 表名与列名来自配置（知识源 config）或调用方入参（RetrievalQuery::$filter）时，
 * 会被放进 Query Builder 的**非绑定位置**（where 的字段名、order 的字段名），
 * ThinkPHP 对这部分不做参数绑定，因此必须白名单校验后才能拼接。
 */
final class Identifier
{
    /**
     * 是否为安全的 SQL 列名
     *
     * 仅允许 [A-Za-z_][A-Za-z0-9_]*，排除 `id=1 OR 1=1 -- ` 这类伪列名。
     */
    public static function isColumn(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    /**
     * 是否为安全的表名（允许以数字开头，兼容前缀表名）
     */
    public static function isTable(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
    }
}
