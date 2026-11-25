# wiseadmin/wise-rag — 知识库与 RAG 检索包

> 适用：WiseAdmin 1.0+ / PHP 8.1+ / ThinkPHP 8
> 状态：稳定（P0–P5 已完成，并经过安全与健壮性加固）
> 可独立使用：是（`composer require` 后自动发现服务与迁移）；与 `wise-agent` / `wise-mcp` 组合时能力更完整

## 它解决什么问题

`wise-rag` 是 WiseAdmin 的“读”侧能力：让业务插件**不写一行检索代码**就能拥有知识库，并让 Agent 自动具备可引用的检索工具。

- 文档摄取：text / markdown / html / pdf，支持 structure / fixed / recursive 分块；
- 检索：向量召回 + 关键词召回（FULLTEXT/ngram，短查询回退 LIKE）+ RRF/加权融合，可选重排；
- 引用溯源：命中结果带 `source`（知识库、标题、URL、页码、标题层级）；
- 权限隔离：`visibility` + `owner_type/owner_id`，私有库只能被归属方召回；
- 生态外溢：同一份检索能力可注册为 `wise-agent` 工具与 MCP 工具/资源。

完整设计文档见仓库根 `docs/wise-rag-package-design.md`。

## 核心特性

| 能力 | 说明 |
|---|---|
| 多源摄取 | 手工文本 / 上传文件 / 业务表 / 附件，异步入队避免阻塞 Web 请求 |
| 混合检索 | 向量 + 关键词 + 可选 Reranker；检索日志可观测 |
| 插件零接入 | 插件 `manifest.json` 声明 `ai_schema.knowledge_sources` 即可自动建库、索引、清理 |
| Agent 工具 | `kb.search` 与 `kb.{kb_key}.search`，统一权限 `ai.tool.kb_search` |
| MCP 外溢 | `kb.search` 工具与 `kb://...` 资源，供 Claude Desktop 等外部客户端消费 |
| 运维闭环 | `stats` / `index` / `prune` / `discover` / `eval` 命令，含损坏向量修复 |
| 后台管理 | 包内视图 `/admin/kb/index`：知识库 / 文档 / 知识源 / 检索调试四个页签 |

## 安装

```bash
composer require wiseadmin/wise-rag
cd src && composer dump-autoload          # 重建服务发现
php think migrate:run                     # 创建 ai_kb_* / ai_wiki_* 表
php think menu:sync                       # 声明式后台菜单，幂等落库
```

## 5 分钟最小示例

```php
// 建库（embedding 默认走 config('wise-rag.embedding')）
$kbId = wise_rag_create('product_docs', ['name' => '商品文档']);

// 灌入文本（文件摄取走 IngestionService）
wise_rag_ingest($kbId, "# A100\n\n功率 250W。", ['external_id' => 'p100']);

// 检索（返回带 cite 引用的命中）
$hits = wise_rag_search('A100 功率多少', ['product_docs'], 5);
```

后台入口：`插件管理 → 知识库管理`（`/admin/kb/index`）。宿主可用 `app/admin/view/wise-rag/**` 同名覆盖包内视图。

## 配置项速查

所有配置都在 `config/wise-rag.php`，以下为常用项（均有默认值，可不改）：

| 键 | 默认 | 说明 |
|---|---|---|
| `agent.auto_tool` | `true` | 是否自动向 wise-agent 注册检索工具 |
| `agent.tool_cache_ttl` | `60` | 知识库清单缓存秒数，建库/删库自动失效 |
| `ingestion.async` | `true` | 索引默认入队，避免在 Web 请求内同步跑完整库 |
| `retrieval.hybrid` | `true` | 开启关键词 + 向量混合召回 |
| `retrieval.fusion` | `rrf` | `rrf` 或 `weighted` |
| `retrieval.min_score` | `0.25` | 归一化后的融合分下限 |
| `retrieval.log_enabled` | `false` | 写入 `ai_kb_retrieval_logs` |
| `retrieval.rerank.enabled` | `false` | 打开后调用重排 provider |
| `vector.default` | `database` | 默认零外部依赖；可切 pgvector / redis / qdrant |
| `vector.brute_warn_threshold` | `50000` | 暴力检索规模告警阈值（0 关闭） |
| `vector.stores.database.scan_page_size` | `1000` | 游标分页页大小，控制峰值内存 |
| `cache.enabled` | `false` | 向量语义缓存（文本 md5 + 模型为键） |
| `stats.cache_ttl` | `60` | 统计概览缓存，计数变更自动失效 |
| `mcp.expose_tools` / `expose_resources` | `true` | MCP 外溢开关 |
| `web.groups.admin.middleware` | `RbacMiddleware` | `/admin/kb/*` 默认鉴权，宿主可覆盖 |

私有知识库按 `visibility` 隔离：`private` 仅 `owner_type/owner_id` 匹配者可召回，`owner_id = 0` 视为全局库。

## CLI 与运维

| 命令 | 说明 |
|---|---|
| `php think wise:rag:stats` | 知识库 / 源 / 文档 / 分块 / 向量化 / 失败文档概览 |
| `php think wise:rag:index <kb> [--async] [--force]` | 重建索引；未指定 `--async` 时遵循 `ingestion.async` |
| `php think wise:rag:prune` | 预览孤儿数据与损坏向量 |
| `php think wise:rag:prune --apply` | 清理孤儿源/文档/分块、过期缓存、损坏向量 |
| `php think wise:rag:discover [--index] [--dump=plugin]` | 发现插件声明的知识源，可立即索引 |
| `php think wise:rag:eval [cases.json] [--top-k=5] [--json]` | 检索评测，输出 hit_rate / 召回 / MRR / 耗时 |

规模建议：分块数超过 `vector.brute_warn_threshold` 且仍使用 `database` 暴力驱动时，`stats` / `prune` 会提示切换到带 ANN 索引的驱动并 `--force` 重建。

## 接入 wise-agent

包启动时会向 `wise-agent` 的 `ToolRegistry` 注册只读工具，Agent 无需改动：

```bash
php think wise:agent:permissions    # 幂等写入权限 ai.tool.kb_search
# 后台 → 权限管理 → 角色管理 → 分配「AI 工具：知识库检索」
```

| 工具名 | 说明 |
|---|---|
| `kb.search` | 全库检索（覆盖全部启用中的知识库） |
| `kb.{kb_key}.search` | 单知识库检索，非法字符规范化为 `_` |

- 工具元数据：`access=read` / `risk=low` / `permission=ai.tool.kb_search` / 免人工确认。
- 返回：`{query, count, results:[{content, score, source:{kb_key,title,url,page,heading}}]}`。
- 命名约束：工具注册在默认命名空间且 `getName()` 返回全名，保证 `getName() === 注册键`，否则会 `Tool not found`。
- 未安装 wise-agent 时权限与工具注册均为零副作用（`class_exists` 守卫）。

## 插件零接入

插件只需在 `manifest.json` 声明，无需写代码：

```json
{
  "ai_schema": {
    "knowledge_kb_key": "smartstock_docs",
    "knowledge_sources": [
      {
        "key": "product_docs",
        "title": "商品说明书",
        "provider": "table",
        "config": {
          "table": "smartstock_product",
          "id_field": "id",
          "title_field": "name",
          "content_fields": ["name", "description", "spec"],
          "updated_field": "update_time"
        },
        "sync_mode": "manual",
        "chunk": { "size": 400, "overlap": 60, "splitter": "structure" }
      }
    ]
  }
}
```

支持的知识源：

| provider | 说明 |
|---|---|
| `table` | 业务表字段拼文本，支持 `updated_field` 增量水位、`where`、`url_template` |
| `attachment` | 附件文件（仅本地可读；云存储跳过，不影响其它来源） |
| `manual` | 人工上传，不自动拉取 |

发现与索引：

```bash
php think wise:rag:discover                       # 扫描声明并幂等落库
php think wise:rag:discover --index               # 发现后立即索引
php think wise:rag:index --source=smartstock:product_docs --async
php think queue:work                              # 异步索引 worker
```

插件卸载时经 `PluginUninstall` 事件自动清理其知识源和空知识库。自定义源实现 `KnowledgeSourceInterface` 后经 `wise-rag.sources.providers.*` 登记即可。

## MCP 外溢

经 wise-support 既有扩展点 `MCP_TOOL_PROVIDER` / `MCP_RESOURCE_PROVIDER` 注册，由 wise-mcp 聚合；未安装 wise-mcp 时零副作用。

```bash
php think wise:mcp:discover   # 可见 wise-rag => RagToolProvider / RagResourceProvider
```

| 暴露物 | 形态 | 说明 |
|---|---|---|
| `kb.search` | Tool | 只读检索，参数 `query` / `kb[]` / `top_k` |
| `kb://index` | Resource | 知识库清单与统计 |
| `kb://{kb_key}/docs` | Resource | 文档清单 |
| `kb://{kb_key}/doc/{id}` | Resource | 单篇全文（分块拼接） |

`max_doc_resources = 0` 时不暴露文档级资源。

## 升级与兼容

### 旧向量列必须重建索引

历史版本的 `ai_kb_chunks.vector` 被建为定长 `BINARY(255)`，float32 序列化结果会被补零或截断（维度 > 63 直接丢数据）。该列已改为 `BLOB`，但旧数据无法自动还原：

```bash
php think wise:rag:prune --apply                 # ① 标记损坏向量与待重建文档
php think wise:rag:index <kb_key|id> --force     # ② 强制重建
```

顺序不可颠倒，否则幂等逻辑会因 `content_hash` 未变且 `status=indexed` 跳过重建。

### Swoole / 常驻进程

包内已收敛进程级状态，部署侧还需：

1. 开启 cURL 与 sleep 协程 hook（建议 `SWOOLE_HOOK_ALL`），否则 Embedding / Rerank 会阻塞 worker；
2. 配置数据库连接池，并确认事务在协程间隔离；
3. 进程内缓存有上限或 TTL：`cache.drivers.database.memo_limit=500`、`keyword.cache_ttl`、`collection → kb_id` 映射。

## 与其他 wise-* 包的关系

- `wise-support`：提供扩展点、鉴权、限流、Pipeline 等底座；
- `wise-agent`：消费 `kb.*` 工具，权限统一走 RBAC；
- `wise-mcp`：把 `kb.search` 与 `kb://...` 外溢给外部 MCP 客户端；
- `wise-operation-log`：后台知识库写操作可接入审计注解。

## 契约自检

```bash
php src/packages/wiseadmin/wise-rag/tests/verify-contracts.php
php src/packages/wiseadmin/wise-rag/tests/verify-p1.php
# 其余 verify-p2 … verify-p11 覆盖 Agent 集成、零接入、混合检索、MCP、事务、脱敏、分页、统计、规模化与常驻进程
```

## 目录结构

```text
wise-rag/
├── config/wise-rag.php
├── database/migrations/           # ai_kb_* 与 ai_wiki_* 表
├── public/js/kb.js                # 发布到 /statics/plugins/wise-rag/js/kb.js
├── view/admin/kb/index.html       # 知识库后台页
├── tests/verify-p*.php            # 契约与回归护栏
└── src/
    ├── rag/                       # wise\rag：client/vector/keyword/splitter/parser/rerank/source/service/command/mcp
    └── wiki/                      # wise\wiki：Wiki 知识编译，bridge/ 是唯一引用 wise\rag 的边界
```

## 许可证

Apache-2.0
