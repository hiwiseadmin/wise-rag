<?php
declare(strict_types=1);

namespace wise\rag\contract;

use wise\rag\dto\ParsedDocument;

/**
 * 文档解析契约
 *
 * 把文件（pdf / docx / markdown / html ...）解析为规范化纯文本 + 元数据。
 * 实现由配置 `wise-rag.parser.mapping` 按 mime / 扩展名路由；V1 全部内部实现，
 * 不引入扩展点（第三方可后续按需注册）。
 */
interface DocumentParserInterface
{
    /**
     * 是否支持给定类型
     *
     * @param string $mime MIME 类型（可能为空字符串）
     * @param string $ext  文件扩展名（小写，不含点）
     */
    public function supports(string $mime, string $ext): bool;

    /**
     * 解析文件为纯文本（结构保留：标题规范化等）
     *
     * @param string $filePath 文件绝对路径
     * @param array  $options  解析选项（实现自定义）
     */
    public function parse(string $filePath, array $options = []): ParsedDocument;

    /**
     * 驱动名称（用于配置与调试）
     */
    public function getName(): string;
}
