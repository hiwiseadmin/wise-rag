<?php
declare(strict_types=1);

namespace wise\rag\source;

use think\facade\Db;
use wise\rag\contract\KnowledgeSourceInterface;
use wise\rag\dto\DocEntry;
use wise\rag\dto\IngestionContext;
use wise\rag\support\Identifier;

/**
 * 数据表知识源
 *
 * 把业务表的若干字段拼成文本行作为知识（如商品说明书、FAQ），供插件「零代码」接入。
 *
 * 声明示例（插件 manifest.json → ai_schema.knowledge_sources[]）：
 * {
 *   "key": "product_docs",
 *   "title": "商品说明书",
 *   "provider": "table",
 *   "config": {
 *     "table": "smartstock_product",
 *     "id_field": "id",
 *     "title_field": "name",
 *     "content_fields": ["name", "description", "spec"],
 *     "updated_field": "update_time",     // 增量水位（可选）
 *     "where": {"status": 1},             // 等值过滤（可选）
 *     "limit": 500                        // 单批上限（可选）
 *   }
 * }
 */
class TableSource implements KnowledgeSourceInterface
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
        if ($table === '') {
            return;
        }

        $idField      = (string) ($this->config['id_field'] ?? 'id');
        $titleField   = (string) ($this->config['title_field'] ?? '');
        $updatedField = (string) ($this->config['updated_field'] ?? '');
        $contentFields = $this->stringList('content_fields');
        $limit        = max(1, (int) ($this->config['limit'] ?? $ctx->batchSize));

        $query = Db::name($table);

        foreach ((array) ($this->config['where'] ?? []) as $field => $value) {
            if (is_string($field) && Identifier::isColumn($field) && $value !== null) {
                $query->where($field, $value);
            }
        }

        $hasUpdatedField = $updatedField !== '' && Identifier::isColumn($updatedField);
        if ($hasUpdatedField && $ctx->since !== null && $ctx->since !== '') {
            $query->where($updatedField, '>', $ctx->since);
        }
        if ($hasUpdatedField) {
            $query->order($updatedField, 'asc');
        }

        $rows   = $query->limit($limit)->select()->toArray();
        $latest = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowId = (string) ($row[$idField] ?? '');
            if ($rowId === '') {
                continue;
            }

            $text = $this->composeText($row, $contentFields);
            if ($text === '') {
                continue;
            }

            if ($updatedField !== '' && isset($row[$updatedField])) {
                $latest = $row[$updatedField];
            }

            yield new DocEntry(
                $table . ':' . $rowId,
                $titleField !== '' ? (string) ($row[$titleField] ?? '') : '',
                $text,
                null,
                [
                    'source_key' => $this->sourceKey,
                    'table'      => $table,
                    'row_id'     => $rowId,
                    'url'        => $this->buildUrl($rowId),
                ]
            );
        }

        if ($latest !== null) {
            $this->checkpoint = $latest;
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

    /**
     * 表名（仅允许安全标识符，防注入）
     */
    protected function table(): string
    {
        $table = (string) ($this->config['table'] ?? '');

        return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1 ? $table : '';
    }

    /**
     * @return string[]
     */
    protected function stringList(string $key): array
    {
        $out = [];
        foreach ((array) ($this->config[$key] ?? []) as $item) {
            $item = (string) $item;
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * 行 → 文本（`字段：值` 逐行），未声明 content_fields 时取全部标量字段
     *
     * @param array<string, mixed> $row
     * @param string[]             $contentFields
     */
    protected function composeText(array $row, array $contentFields): string
    {
        $fields = $contentFields !== [] ? $contentFields : array_keys($row);

        $skip = ['id', 'create_time', 'update_time', 'delete_time'];
        $lines = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row) || in_array($field, $skip, true)) {
                continue;
            }

            $value = $row[$field];
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $lines[] = $field . '：' . $value;
        }

        return implode("\n", $lines);
    }

    protected function buildUrl(string $rowId): string
    {
        $template = (string) ($this->config['url_template'] ?? '');

        return $template === '' ? '' : str_replace('{id}', $rowId, $template);
    }
}
