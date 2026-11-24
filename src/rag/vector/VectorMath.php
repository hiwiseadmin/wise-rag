<?php
declare(strict_types=1);

namespace wise\rag\vector;

/**
 * 向量数学工具
 *
 * float32 序列化（小端 `g`）与余弦相似度；供 `database` 向量驱动暴力检索使用。
 */
final class VectorMath
{
    /**
     * float[] → 二进制（float32 小端）
     *
     * @param float[] $vector
     */
    public static function pack(array $vector): string
    {
        if ($vector === []) {
            return '';
        }

        return pack('g*', ...array_map('floatval', array_values($vector)));
    }

    /**
     * 二进制 → float[]
     *
     * @return float[]
     */
    public static function unpack(string $binary): array
    {
        if ($binary === '') {
            return [];
        }

        $values = @unpack('g*', $binary);
        if ($values === false) {
            return [];
        }

        return array_map('floatval', array_values($values));
    }

    /**
     * 余弦相似度（[-1, 1]；任一侧为零向量时返回 0）
     *
     * @param float[] $a
     * @param float[] $b
     */
    public static function cosine(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $n   = count($a);
        $dot = 0.0;
        $na  = 0.0;
        $nb  = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na  += $x * $x;
            $nb  += $y * $y;
        }

        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
