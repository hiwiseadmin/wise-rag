<?php
declare(strict_types=1);

namespace wise\wiki\support;

/**
 * 页面 slug 规范化
 *
 * 规则：只允许 `[a-z0-9-]`，小写、折叠分隔符、去掉首尾 `-`，长度 ≤ 100。
 * 中文标题无法音译（不引入拼音依赖），退化为 `page-{hash}`，保证唯一且稳定（同标题同 slug）。
 */
final class Slugger
{
    public const PATTERN = '/^[a-z0-9][a-z0-9-]{0,99}$/';

    public static function isValid(string $slug): bool
    {
        return preg_match(self::PATTERN, $slug) === 1;
    }

    public static function make(string $text, string $fallback = 'page'): string
    {
        $raw = trim($text);

        $ascii = preg_replace('/[^A-Za-z0-9]+/', '-', $raw) ?? '';
        $slug  = strtolower(trim($ascii, '-'));

        $slug = preg_replace('/-{2,}/', '-', $slug) ?? $slug;
        $slug = substr($slug, 0, 100);
        $slug = rtrim($slug, '-');

        if ($slug === '' || self::isValid($slug) === false) {
            return $fallback . '-' . substr(md5($raw), 0, 8);
        }

        return $slug;
    }

    /**
     * 相似度（用于「重复建页」检测：同一主题不该有 3 个 slug）
     */
    public static function similar(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 4);
    }
}
