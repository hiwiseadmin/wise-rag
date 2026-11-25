<?php
declare(strict_types=1);

namespace wise\rag\source;

use think\facade\Db;
use wise\rag\contract\KnowledgeSourceInterface;
use wise\rag\dto\DocEntry;
use wise\rag\dto\IngestionContext;
use wise\rag\support\Identifier;

/**
 * 附件知识源
 *
 * 从附件表（默认 `attachment`，wise-attachment）拉取文件并交由解析器处理。
 *
 * ⚠️ 限制：仅支持**本地可读**的附件文件。云存储（OSS/COS/七牛/FTP）的附件无法直接读取，
 * 会被跳过（不影响其它来源）；需要云附件时请自定义驱动或先同步到本地。
 *
 * 声明示例：
 * {
 *   "key": "manuals",
 *   "provider": "attachment",
 *   "config": {
 *     "mime": ["application/pdf", "text/markdown"],
 *     "storage_root": "",          // 本地附件根目录（相对路径会被拼到此目录下）
 *     "title_field": "object_name",
 *     "mime_field": "mime_type",
 *     "limit": 200
 *   }
 * }
 */
class AttachmentSource implements KnowledgeSourceInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected string $sourceKey,
        protected array $config = [],
        protected mixed $checkpoint = null
    ) {
    }

    public function getKey(): string
    {
        return $this->sourceKey;
    }

    /**
     * @return iterable<int, DocEntry>
     */
    public function fetch(IngestionContext $ctx): iterable
    {
        $table = $this->table();
        if ($table === '' || !$this->tableExists($table)) {
            return;
        }

        $idField    = (string) ($this->config['id_field'] ?? 'id');
        $titleField = (string) ($this->config['title_field'] ?? 'object_name');
        $mimeField  = (string) ($this->config['mime_field'] ?? 'mime_type');
        $urlField   = (string) ($this->config['url_field'] ?? 'url');
        $mimes      = array_values(array_filter(array_map('strval', (array) ($this->config['mime'] ?? []))));
        $limit      = max(1, (int) ($this->config['limit'] ?? $ctx->batchSize));
        $root       = rtrim((string) ($this->config['storage_root'] ?? ''), '/\\');

        $idField   = Identifier::isColumn($idField) ? $idField : 'id';
        $mimeField = Identifier::isColumn($mimeField) ? $mimeField : '';

        $query = Db::name($table);
        if ($mimes !== [] && $mimeField !== '') {
            $query->whereIn($mimeField, $mimes);
        }

        $rows = $query->order($idField, 'asc')->limit($limit)->select()->toArray();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowId = (string) ($row[$idField] ?? '');
            if ($rowId === '') {
                continue;
            }

            $filePath = $this->resolveLocalPath($row, $root);
            if ($filePath === null) {
                continue;
            }

            yield new DocEntry(
                $table . ':' . $rowId,
                (string) ($row[$titleField] ?? ''),
                null,
                $filePath,
                [
                    'source_key' => $this->sourceKey,
                    'mime'       => (string) ($row[$mimeField] ?? ''),
                    'url'        => (string) ($row[$urlField] ?? ''),
                ]
            );
        }
    }

    public function getTitle(): string
    {
        return (string) ($this->config['title'] ?? $this->sourceKey);
    }

    public function getCheckpoint(): mixed
    {
        return $this->checkpoint;
    }

    protected function table(): string
    {
        $table = (string) ($this->config['table'] ?? 'attachment');

        return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1 ? $table : '';
    }

    protected function tableExists(string $table): bool
    {
        try {
            Db::name($table)->limit(1)->count();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 解析为本地可读文件路径；失败返回 null
     *
     * @param array<string, mixed> $row
     */
    protected function resolveLocalPath(array $row, string $root): ?string
    {
        $candidates = [];
        foreach (['object_name', 'url'] as $field) {
            $value = (string) ($row[$field] ?? '');
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        foreach ($candidates as $candidate) {
            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate) === 1) {
                continue;
            }

            $path = $candidate;
            $isAbsolute = str_starts_with($candidate, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $candidate) === 1;
            if ($root !== '' && !$isAbsolute) {
                $path = $root . DIRECTORY_SEPARATOR . ltrim($candidate, '/\\');
            }

            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }
}
