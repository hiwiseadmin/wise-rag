<?php
declare(strict_types=1);

namespace wise\rag\parser;

use wise\rag\contract\DocumentParserInterface;
use wise\rag\dto\ParsedDocument;
use wise\rag\exception\RagException;

/**
 * 解析器注册表
 *
 * 依据配置 `wise-rag.parser.mapping`（mime => 类名）解析文件；
 * mime 未命中时回退到各解析器的 supports() 探测（按扩展名）。
 */
class ParserRegistry
{
    /** @var array<string, string> mime => 解析器类名 */
    protected array $mapping;

    /** @var array<string, DocumentParserInterface> 已实例化的解析器（按类名缓存） */
    protected array $instances = [];

    /**
     * @param array<string, string> $mapping mime => 解析器类名
     */
    public function __construct(array $mapping = [])
    {
        $this->mapping = $mapping;
    }

    /**
     * 解析文件
     *
     * @param string      $filePath 文件绝对路径
     * @param string      $mime     MIME（可为空，按扩展名推断）
     * @param string|null $ext      扩展名（可为空，按文件路径推断）
     */
    public function parse(string $filePath, string $mime = '', ?string $ext = null): ParsedDocument
    {
        $ext  = strtolower($ext ?? (string) pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = strtolower($mime);

        $parser = $this->forMime($mime, $ext);
        if ($parser === null) {
            throw new RagException("没有可用于 mime={$mime} ext={$ext} 的解析器");
        }

        return $parser->parse($filePath);
    }

    /**
     * 查找可处理给定类型的解析器
     */
    public function forMime(string $mime, string $ext): ?DocumentParserInterface
    {
        if ($mime !== '' && isset($this->mapping[$mime])) {
            $parser = $this->make($this->mapping[$mime]);
            if ($parser !== null && $parser->supports($mime, $ext)) {
                return $parser;
            }
        }

        foreach ($this->mapping as $class) {
            $parser = $this->make($class);
            if ($parser !== null && $parser->supports($mime, $ext)) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * 全部可用解析器名称（调试 / 后台展示）
     *
     * @return string[]
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->mapping as $class) {
            $parser = $this->make($class);
            if ($parser !== null) {
                $names[] = $parser->getName();
            }
        }

        return array_values(array_unique($names));
    }

    protected function make(string $class): ?DocumentParserInterface
    {
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }

        if (!class_exists($class)) {
            return null;
        }

        $instance = new $class();
        if (!$instance instanceof DocumentParserInterface) {
            return null;
        }

        return $this->instances[$class] = $instance;
    }
}
