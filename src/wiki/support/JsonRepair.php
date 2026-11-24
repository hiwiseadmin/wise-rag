<?php
declare(strict_types=1);

namespace wise\wiki\support;

/**
 * LLM JSON 输出的容错解析
 *
 * 为什么需要：wise-agent 的客户端不提供 json_schema 强约束，且 DeepSeek 等 provider
 * 不支持 response_format（`Feature::JSON_MODE` 为 false），模型常把 JSON 包在
 * ```json 围栏里、带前后解释文字、或留尾逗号。
 *
 * 纪律：**只做格式修复，不做语义补救**。修完仍必须过 OutputValidator；
 * 无法修复时返回 null，由调用方决定重试，绝不用「猜」的字段继续往下走。
 */
final class JsonRepair
{
    /**
     * @return array<string, mixed>|null
     */
    public static function parse(string $raw): ?array
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;

        foreach (self::candidates($text) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            $fixed = self::tidy($candidate);
            if ($fixed !== $candidate) {
                $decoded = json_decode($fixed, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * 候选文本：原文 → 去围栏 → 截取首个 { 到最后一个 }
     *
     * @return string[]
     */
    private static function candidates(string $text): array
    {
        $list = [$text];

        if (preg_match('/```(?:json|JSON)?\s*(.+?)\s*```/s', $text, $m) === 1) {
            $list[] = trim($m[1]);
        }

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $list[] = substr($text, $start, $end - $start + 1);
        }

        return array_values(array_unique($list));
    }

    /**
     * 常见格式修复：尾逗号、全角引号、裸换行（仅限已定位到对象文本的情形）
     */
    private static function tidy(string $json): string
    {
        $json = str_replace(['“', '”'], '"', $json);

        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;

        return $json;
    }
}
