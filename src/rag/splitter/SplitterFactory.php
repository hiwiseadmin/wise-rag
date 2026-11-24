<?php
declare(strict_types=1);

namespace wise\rag\splitter;

use wise\rag\contract\TextSplitterInterface;
use wise\rag\exception\RagException;

/**
 * 分块器工厂
 *
 * 依配置 `wise-rag.chunk.splitter`（structure | fixed | recursive）选择实现。
 * 纯内部策略：切换靠配置，不引入扩展点。
 */
class SplitterFactory
{
    /** @var array<string, string> 名称 => 实现类 */
    protected const MAP = [
        'fixed'     => FixedSplitter::class,
        'structure' => StructureSplitter::class,
        'recursive' => RecursiveSplitter::class,
    ];

    protected string $default;

    public function __construct(?string $default = null)
    {
        $this->default = $default ?: 'structure';
    }

    public function make(?string $name = null): TextSplitterInterface
    {
        $name  = $name !== null && $name !== '' ? $name : $this->default;
        $class = self::MAP[$name] ?? self::MAP[$this->default] ?? StructureSplitter::class;

        $instance = new $class();
        if (!$instance instanceof TextSplitterInterface) {
            throw new RagException("分块器未实现 TextSplitterInterface：{$class}");
        }

        return $instance;
    }
}
