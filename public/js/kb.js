/**
 * wise-rag 知识库管理界面交互
 *
 * 由包内视图 view/admin/kb/index.html 以经典 script 引入（经 wise:vendor:publish
 * 发布到 /statics/plugins/wise-rag/js/kb.js）。
 *
 * 请求优先走框架壳提供的 Wise.util.http.post（自带 CSRF / 统一响应拦截），
 * 不可用时退化为原生 fetch（同样带上 csrf-token 头）。
 */
(function () {
    'use strict';

    var BASE = '/admin/kb';

    // ── 请求封装 ────────────────────────────────────────────────
    function request(url, data) {
        data = data || {};

        if (window.Wise && window.Wise.util && window.Wise.util.http && window.Wise.util.http.post) {
            return window.Wise.util.http.post(url, data, { interceptor: false });
        }

        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token || ''
            },
            body: JSON.stringify(data)
        }).then(function (r) { return r.json(); });
    }

    function toast(msg, type) {
        if (window.Wise && window.Wise.ui && typeof window.Wise.ui.toast === 'function') {
            window.Wise.ui.toast(msg, type || 'info');
            return;
        }
        // 退化：顶部飘一条提示
        var box = document.createElement('div');
        box.className = 'alert alert-' + (type === 'danger' ? 'danger' : 'info') + ' position-fixed top-0 start-50 translate-middle-x mt-3';
        box.style.zIndex = 20000;
        box.textContent = msg;
        document.body.appendChild(box);
        setTimeout(function () { box.remove(); }, 2500);
    }

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * 二次确认（唯一入口 UIS v1.1 §4：内核 Wise.ui.dialog.confirm）
     * @param {string} msg
     * @returns {Promise<boolean>}
     */
    function confirmBox(msg) {
        if (window.Wise && window.Wise.ui && window.Wise.ui.dialog) {
            return window.Wise.ui.dialog.confirm(msg, { title: '操作确认' });
        }
        return Promise.resolve(window.confirm(msg));
    }

    // ── 状态 ────────────────────────────────────────────────────
    var bases = [];
    var docPage = 1;

    // ── 统计 ────────────────────────────────────────────────────
    function loadStats() {
        request(BASE + '/stats').then(function (res) {
            if (res.code !== 0) { return; }
            var d = res.data || {};
            var cards = [
                ['知识库', d.bases], ['知识源', d.sources], ['文档', d.docs],
                ['分块', d.chunks], ['已向量化', d.vectors], ['失败文档', d.failed]
            ];
            document.getElementById('kb-stats').innerHTML = cards.map(function (c) {
                return '<div class="col-6 col-md-2"><div class="card"><div class="card-body py-2">'
                    + '<div class="text-muted small">' + c[0] + '</div>'
                    + '<div class="fs-5">' + (c[1] === undefined ? '—' : c[1]) + '</div>'
                    + '</div></div></div>';
            }).join('');
        });
    }

    // ── 知识库 ──────────────────────────────────────────────────
    function loadBases() {
        return request(BASE + '/bases').then(function (res) {
            if (res.code !== 0) { toast(res.msg || '加载失败', 'danger'); return; }
            bases = (res.data && res.data.list) || [];

            var body = document.getElementById('kb-body');
            if (!bases.length) {
                body.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">暂无知识库，先在上方新建。</td></tr>';
            } else {
                body.innerHTML = bases.map(function (kb) {
                    return '<tr>'
                        + '<td>' + kb.id + '</td>'
                        + '<td><div class="fw-semibold">' + esc(kb.name) + '</div><div class="text-muted small">' + esc(kb.kb_key) + '</div></td>'
                        + '<td>' + kb.dimension + '</td>'
                        + '<td>' + esc(kb.embedding) + '</td>'
                        + '<td>' + esc(kb.vector_store) + '</td>'
                        + '<td>' + kb.source_count + '</td>'
                        + '<td>' + kb.doc_count + '</td>'
                        + '<td>' + kb.chunk_count + '</td>'
                        + '<td>'
                        + '<button class="btn btn-sm btn-outline-primary me-1" data-act="reindex" data-id="' + kb.id + '">重建</button>'
                        + '<button class="btn btn-sm btn-outline-danger" data-act="delete" data-id="' + kb.id + '" data-name="' + esc(kb.name) + '">删除</button>'
                        + '</td></tr>';
                }).join('');
            }

            fillKbSelect('doc-kb', true);
            fillKbSelect('dbg-kb', true);
        });
    }

    function fillKbSelect(id, withAll) {
        var sel = document.getElementById(id);
        if (!sel) { return; }
        var html = withAll ? '<option value="0">全部知识库</option>' : '';
        html += bases.map(function (kb) {
            return '<option value="' + kb.id + '">' + esc(kb.name) + '（' + esc(kb.kb_key) + '）</option>';
        }).join('');
        sel.innerHTML = html;
    }

    function createBase() {
        var name = document.getElementById('kb-name').value.trim();
        if (!name) { toast('请填写名称', 'danger'); return; }

        request(BASE + '/base/save', {
            kb_key: document.getElementById('kb-key').value.trim(),
            name: name,
            embedding: document.getElementById('kb-embedding').value.trim(),
            visibility: document.getElementById('kb-visibility').value
        }).then(function (res) {
            toast(res.msg || 'ok', res.code === 0 ? 'info' : 'danger');
            if (res.code === 0) {
                document.getElementById('kb-name').value = '';
                document.getElementById('kb-key').value = '';
                loadBases().then(loadStats);
            }
        });
    }

    function baseAction(act, id, name) {
        if (act === 'reindex') {
            toast('开始重建索引…', 'info');
            request(BASE + '/base/reindex', { id: id }).then(function (res) {
                toast(res.msg || 'ok', res.code === 0 ? 'info' : 'danger');
                loadBases().then(loadStats);
            });
            return;
        }

        confirmBox('确认删除知识库「' + name + '」及其索引？').then(function (ok) {
            if (!ok) { return; }
            request(BASE + '/base/delete', { id: id }).then(function (res) {
                toast(res.msg || 'ok', res.code === 0 ? 'info' : 'danger');
                loadBases().then(loadStats);
            });
        });
    }

    // ── 文档 ────────────────────────────────────────────────────
    function loadDocs(page) {
        docPage = page || 1;
        var kbId = document.getElementById('doc-kb').value;

        request(BASE + '/documents', {
            kb_id: kbId,
            status: document.getElementById('doc-status').value,
            keyword: document.getElementById('doc-keyword').value.trim(),
            page: docPage,
            limit: 15
        }).then(function (res) {
            if (res.code !== 0) { toast(res.msg || '加载失败', 'danger'); return; }
            var list = (res.data && res.data.list) || [];
            var total = (res.data && res.data.total) || 0;

            var body = document.getElementById('doc-body');
            if (!list.length) {
                body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">暂无文档</td></tr>';
            } else {
                body.innerHTML = list.map(function (doc) {
                    return '<tr>'
                        + '<td>' + doc.id + '</td>'
                        + '<td>' + esc(doc.title) + '</td>'
                        + '<td>' + esc(doc.status) + '</td>'
                        + '<td>' + doc.chunk_count + '</td>'
                        + '<td>' + doc.char_count + '</td>'
                        + '<td class="text-muted small">' + esc(doc.create_time) + '</td>'
                        + '<td><button class="btn btn-sm btn-outline-danger" data-act="doc-del" data-id="' + doc.id + '">删除</button></td>'
                        + '</tr>';
                }).join('');
            }

            var pages = Math.max(1, Math.ceil(total / 15));
            document.getElementById('doc-pager').innerHTML =
                '<span class="text-muted small">共 ' + total + ' 条</span>'
                + '<span>'
                + '<button class="btn btn-sm btn-outline-secondary me-1" data-act="doc-prev"' + (docPage <= 1 ? ' disabled' : '') + '>上一页</button>'
                + '<span class="mx-2 small">' + docPage + ' / ' + pages + '</span>'
                + '<button class="btn btn-sm btn-outline-secondary" data-act="doc-next"' + (docPage >= pages ? ' disabled' : '') + '>下一页</button>'
                + '</span>';
        });
    }

    function uploadDoc() {
        var kbId = document.getElementById('doc-kb').value;
        var input = document.getElementById('doc-file');
        if (!kbId || Number(kbId) <= 0) { toast('请先选择具体知识库', 'danger'); return; }
        if (!input.files || !input.files.length) { toast('请选择文件', 'danger'); return; }

        var form = new FormData();
        form.append('kb_id', kbId);
        form.append('file', input.files[0]);

        var meta = document.querySelector('meta[name="csrf-token"]');
        var headers = {};
        if (meta && meta.getAttribute('content')) { headers['X-CSRF-TOKEN'] = meta.getAttribute('content'); }

        toast('上传中…', 'info');
        fetch(BASE + '/document/upload', { method: 'POST', credentials: 'same-origin', headers: headers, body: form })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                toast(res.msg || 'ok', res.code === 0 ? 'info' : 'danger');
                if (res.code === 0) {
                    input.value = '';
                    loadDocs(1);
                    loadStats();
                }
            })
            .catch(function (e) { toast('上传失败：' + e.message, 'danger'); });
    }

    function ingestDoc() {
        var kbId = document.getElementById('doc-kb').value;
        var text = document.getElementById('doc-text').value;
        if (!kbId || Number(kbId) <= 0) { toast('请先选择具体知识库', 'danger'); return; }
        if (!text.trim()) { toast('内容不能为空', 'danger'); return; }

        request(BASE + '/document/ingest', {
            kb_id: kbId,
            title: document.getElementById('doc-title').value.trim(),
            content: text
        }).then(function (res) {
            toast(res.msg || 'ok', res.code === 0 ? 'info' : 'danger');
            if (res.code === 0) {
                document.getElementById('doc-text').value = '';
                loadDocs(1);
                loadStats();
            }
        });
    }

    function deleteDoc(id) {
        confirmBox('确认删除该文档及其分块？').then(function (ok) {
            if (!ok) { return; }
            request(BASE + '/document/delete', { id: id }).then(function (res) {
                toast(res.msg || 'ok', res.code === 0 ? 'info' : 'danger');
                loadDocs(docPage);
                loadStats();
            });
        });
    }

    // ── 知识源 ──────────────────────────────────────────────────
    function loadSources() {
        var kbId = document.getElementById('doc-kb') ? document.getElementById('doc-kb').value : 0;

        request(BASE + '/source/list', { kb_id: kbId }).then(function (res) {
            if (res.code !== 0) { toast(res.msg || '加载失败', 'danger'); return; }
            var list = (res.data && res.data.list) || [];

            var body = document.getElementById('src-body');
            if (!list.length) {
                body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">暂无知识源</td></tr>';
                return;
            }
            body.innerHTML = list.map(function (s) {
                return '<tr>'
                    + '<td>' + s.id + '</td>'
                    + '<td class="font-monospace small">' + esc(s.source_key) + '</td>'
                    + '<td>' + esc(s.kb_key) + '</td>'
                    + '<td>' + esc(s.provider) + '</td>'
                    + '<td>' + esc(s.origin) + '</td>'
                    + '<td>' + esc(s.sync_mode) + '</td>'
                    + '<td class="text-muted small">' + esc(s.last_sync_label) + '</td>'
                    + '<td><button class="btn btn-sm btn-outline-primary" data-act="src-sync" data-id="' + s.id + '">同步</button></td>'
                    + '</tr>';
            }).join('');
        });
    }

    function syncSource(id) {
        toast('同步中…', 'info');
        request(BASE + '/source/sync', { id: id }).then(function (res) {
            toast(res.msg || 'ok', res.code === 0 ? 'info' : 'danger');
            loadSources();
            loadStats();
        });
    }

    // ── 检索调试 ────────────────────────────────────────────────
    function testRetrieval() {
        var query = document.getElementById('dbg-query').value.trim();
        if (!query) { toast('请输入查询内容', 'danger'); return; }

        var box = document.getElementById('dbg-result');
        box.innerHTML = '<div class="text-muted">检索中…</div>';

        request(BASE + '/test-retrieval', {
            query: query,
            kb_id: document.getElementById('dbg-kb').value,
            top_k: document.getElementById('dbg-topk').value,
            hybrid: document.getElementById('dbg-hybrid').checked ? 1 : 0
        }).then(function (res) {
            if (res.code !== 0) {
                box.innerHTML = '<div class="text-danger">' + esc(res.msg || '检索失败') + '</div>';
                return;
            }
            var list = (res.data && res.data.list) || [];
            if (!list.length) {
                box.innerHTML = '<div class="text-muted">无命中（可调低 min_score 或先建索引）。</div>';
                return;
            }
            box.innerHTML = list.map(function (hit, i) {
                var cite = hit.cite || {};
                return '<div class="card mb-2"><div class="card-body py-2">'
                    + '<div class="d-flex justify-content-between mb-1">'
                    + '<span class="badge text-bg-secondary">#' + (i + 1) + '</span>'
                    + '<span class="text-muted small">score ' + hit.score
                    + '（向量 ' + hit.vector_score + ' / 关键词 ' + hit.keyword_score + '）</span>'
                    + '</div>'
                    + '<div class="mb-1">' + esc(hit.content) + '</div>'
                    + '<div class="text-muted small">来源：' + esc(cite.title || '(未命名)')
                    + (cite.heading ? ' · ' + esc(cite.heading) : '')
                    + (cite.page ? ' · 第 ' + cite.page + ' 页' : '')
                    + (cite.kb_key ? ' · ' + esc(cite.kb_key) : '')
                    + '</div>'
                    + '</div></div>';
            }).join('');
        });
    }

    // ── 事件绑定 ────────────────────────────────────────────────
    function bind() {
        var tabs = document.querySelectorAll('#kb-tabs [data-tab]');
        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                tabs.forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                document.querySelectorAll('.kb-pane').forEach(function (p) {
                    p.classList.toggle('d-none', p.getAttribute('data-pane') !== btn.getAttribute('data-tab'));
                });
                var tab = btn.getAttribute('data-tab');
                if (tab === 'docs') { loadDocs(1); }
                if (tab === 'sources') { loadSources(); }
            });
        });

        document.getElementById('btn-kb-create').addEventListener('click', createBase);
        document.getElementById('btn-doc-refresh').addEventListener('click', function () { loadDocs(1); });
        document.getElementById('btn-doc-upload').addEventListener('click', uploadDoc);
        document.getElementById('btn-doc-ingest').addEventListener('click', ingestDoc);
        document.getElementById('btn-src-refresh').addEventListener('click', loadSources);
        document.getElementById('btn-dbg-search').addEventListener('click', testRetrieval);
        document.getElementById('doc-kb').addEventListener('change', function () { loadDocs(1); });

        document.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest('[data-act]') : null;
            if (!el) { return; }
            var act = el.getAttribute('data-act');
            var id = el.getAttribute('data-id');

            if (act === 'reindex' || act === 'delete') {
                baseAction(act, id, el.getAttribute('data-name') || id);
            } else if (act === 'doc-del') {
                deleteDoc(id);
            } else if (act === 'doc-prev') {
                loadDocs(Math.max(1, docPage - 1));
            } else if (act === 'doc-next') {
                loadDocs(docPage + 1);
            } else if (act === 'src-sync') {
                syncSource(id);
            }
        });
    }

    function init() {
        bind();
        loadBases().then(function () {
            loadStats();
            loadSources();
        });
    }

    window.WiseRagKb = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
