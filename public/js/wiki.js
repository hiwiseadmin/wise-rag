/**
 * wise-llm-wiki 后台交互（四页签：空间 / 页面 / 编译台 / 检索调试）
 *
 * 由包内视图 view/admin/wiki/index.html 以 defer 脚本引入
 * （经 wise:vendor:publish 发布到 /statics/plugins/wise-rag/js/wiki.js）。
 *
 * 请求优先走框架壳提供的 Wise.util.http.post（自带 CSRF / 统一响应拦截），
 * 不可用时退化为原生 fetch（同样带 csrf-token 头）。与 wise-rag 的 kb.js 同一套约定。
 *
 * BASE 必须与 config('wise-rag.wiki.web.prefix') 保持一致（admin/rag/wiki）：
 * 并入 wise-rag 前是 /admin/wiki，改前缀后这里漏改会让所有页签请求 404。
 */
(function () {
    'use strict';

    var BASE = '/admin/rag/wiki';

    // ── 基础工具 ────────────────────────────────────────────────
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
        var box = document.createElement('div');
        box.className = 'alert alert-' + (type === 'danger' ? 'danger' : 'info')
            + ' position-fixed top-0 start-50 translate-middle-x mt-3';
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

    function confirmBox(msg) {
        if (window.Wise && window.Wise.ui && window.Wise.ui.dialog) {
            return window.Wise.ui.dialog.confirm(msg, { title: '操作确认' });
        }
        return Promise.resolve(window.confirm(msg));
    }

    function $(id) { return document.getElementById(id); }

    function badge(status) {
        var map = {
            published: 'success', review: 'warning', draft: 'secondary', archived: 'dark',
            ok: 'success', failed: 'danger', running: 'primary', skipped: 'secondary',
            active: 'success', paused: 'warning', disabled: 'secondary'
        };
        return '<span class="badge bg-' + (map[status] || 'secondary') + '">' + esc(status) + '</span>';
    }

    function drawBadge(reason) {
        if (!reason) { return '<span class="text-muted">—</span>'; }
        var text = { doc_changed: '源文档变化', prompt_changed: 'prompt 变更', manual: '人工标记', quality: '质量降级' }[reason] || reason;
        return '<span class="badge bg-warning text-dark">' + esc(text) + '</span>';
    }

    // ── 状态 ────────────────────────────────────────────────────
    var spaces = [];
    var pagePage = 1;
    var pageLimit = 20;
    var pageTotal = 0;
    var drawerPage = null;
    var diffSelection = [];

    // ── 概览 ────────────────────────────────────────────────────
    function loadStats() {
        return request(BASE + '/stats').then(function (res) {
            if (res.code !== 0) { return; }
            var d = res.data || {};
            var cards = [
                ['Wiki 空间', d.spaces], ['页面', d.pages], ['已发布', d.published],
                ['待重编译', d.dirty], ['失败任务', d.jobs_failed]
            ];
            $('wiki-stats').innerHTML = cards.map(function (c) {
                return '<div class="col-6 col-md-2"><div class="card h-100"><div class="card-body py-3">'
                    + '<div class="text-secondary small">' + c[0] + '</div>'
                    + '<div class="fs-4 fw-semibold">' + (c[1] === undefined ? '—' : c[1]) + '</div>'
                    + '</div></div></div>';
            }).join('');

            var b = d.budget || {};
            var ratio = b.ratio || 0;
            var bar = $('wiki-budget-bar');
            bar.style.width = Math.round(ratio * 100) + '%';
            bar.className = 'progress-bar' + (b.exhausted ? ' bg-danger' : (ratio > 0.8 ? ' bg-warning' : ''));

            $('wiki-budget-text').textContent = b.limit > 0
                ? (b.used || 0) + ' / ' + b.limit + '（' + Math.round(ratio * 100) + '%）'
                : (b.used || 0) + '（未设上限）';
        });
    }

    // ── 页签 ────────────────────────────────────────────────────
    function bindTabs() {
        document.querySelectorAll('#wiki-tabs .nav-link').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#wiki-tabs .nav-link').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');

                var tab = btn.getAttribute('data-tab');
                document.querySelectorAll('.wiki-pane').forEach(function (pane) {
                    pane.classList.toggle('d-none', pane.getAttribute('data-pane') !== tab);
                });

                if (tab === 'pages') { loadPages(); }
                if (tab === 'jobs') { loadJobs(); }
                if (tab === 'spaces') { loadSpaces(); }
            });
        });
    }

    // ── 空间 ────────────────────────────────────────────────────
    function fillSpaceSelect(id, withAll) {
        var el = $(id);
        if (!el) { return; }
        var html = withAll ? '<option value="">全部空间</option>' : '';
        el.innerHTML = html + spaces.map(function (s) {
            return '<option value="' + s.id + '">' + esc(s.name) + '（' + esc(s.space_key) + '）</option>';
        }).join('');
    }

    function loadKbOptions() {
        return request(BASE + '/kb-list').then(function (res) {
            var el = $('space-source-kb');
            if (!el) { return; }
            var list = (res.code === 0 && res.data && res.data.list) || [];
            el.innerHTML = '<option value="">请选择源知识库</option>' + list.map(function (kb) {
                return '<option value="' + kb.id + '">' + esc(kb.name) + '（' + esc(kb.kb_key) + ' · ' + kb.doc_count + ' 文档）</option>';
            }).join('');
        });
    }

    function loadSpaces() {
        return request(BASE + '/spaces').then(function (res) {
            if (res.code !== 0) { toast(res.msg || '加载失败', 'danger'); return; }
            spaces = (res.data && res.data.list) || [];

            var body = $('space-body');
            if (!spaces.length) {
                body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">还没有空间：先在上方选择源知识库并创建。</td></tr>';
            } else {
                body.innerHTML = spaces.map(function (s) {
                    var ratio = Math.round((s.budget_ratio || 0) * 100);
                    var budget = (s.budget_tokens_month > 0)
                        ? '<div class="progress" style="height:6px"><div class="progress-bar '
                          + (ratio >= 100 ? 'bg-danger' : (ratio > 80 ? 'bg-warning' : ''))
                          + '" style="width:' + Math.min(100, ratio) + '%"></div></div>'
                          + '<div class="small text-secondary mt-1">' + s.tokens_used_month + ' / ' + s.budget_tokens_month + '</div>'
                        : '<span class="text-muted small">未设上限（已用 ' + s.tokens_used_month + '）</span>';

                    return '<tr>'
                        + '<td>' + s.id + '</td>'
                        + '<td><div class="fw-semibold">' + esc(s.name) + '</div>'
                        + '<div class="text-muted small">' + esc(s.space_key) + ' · 影子库 #' + s.shadow_kb_id + '</div></td>'
                        + '<td>' + esc(s.source_kb_name || ('#' + s.source_kb_id)) + '</td>'
                        + '<td>' + s.page_count + '</td>'
                        + '<td>' + (s.dirty_count > 0 ? '<span class="text-warning fw-semibold">' + s.dirty_count + '</span>' : '0') + '</td>'
                        + '<td>' + budget + '</td>'
                        + '<td>' + badge(s.status) + '</td>'
                        + '<td>'
                        + '<button class="btn btn-sm btn-outline-primary me-1" data-act="edit-space" data-id="' + s.id + '">编辑</button>'
                        + '<button class="btn btn-sm btn-outline-secondary me-1" data-act="toggle-space" data-id="' + s.id + '" data-status="' + s.status + '">'
                        + (s.status === 'active' ? '暂停' : '恢复') + '</button>'
                        + '<button class="btn btn-sm btn-outline-danger" data-act="delete-space" data-id="' + s.id + '" data-name="' + esc(s.name) + '">删除</button>'
                        + '</td></tr>';
                }).join('');
            }

            fillSpaceSelect('page-space-filter', true);
            fillSpaceSelect('job-space-filter', true);
            fillSpaceSelect('debug-space', false);
        });
    }

    function bindSpaceForm() {
        $('space-save').addEventListener('click', function () {
            var id = parseInt($('space-id').value || '0', 10);
            var payload = {
                id: id,
                space_key: $('space-key').value.trim(),
                name: $('space-name').value.trim(),
                source_kb_id: parseInt($('space-source-kb').value || '0', 10),
                visibility: $('space-visibility').value,
                budget_tokens_month: parseInt($('space-budget').value || '0', 10)
            };

            if (!payload.name) { toast('请填写空间名称', 'danger'); return; }
            if (id === 0 && !payload.source_kb_id) { toast('请选择源知识库', 'danger'); return; }

            request(BASE + '/space/save', payload).then(function (res) {
                if (res.code !== 0) { toast(res.msg || '保存失败', 'danger'); return; }
                toast(res.msg || '已保存', 'success');
                resetSpaceForm();
                loadSpaces();
                loadStats();
            });
        });

        $('space-reset').addEventListener('click', resetSpaceForm);

        $('space-body').addEventListener('click', function (ev) {
            var btn = ev.target.closest('button[data-act]');
            if (!btn) { return; }
            var id = parseInt(btn.getAttribute('data-id'), 10);
            var act = btn.getAttribute('data-act');

            if (act === 'edit-space') {
                var s = spaces.filter(function (x) { return x.id === id; })[0];
                if (!s) { return; }
                $('space-id').value = s.id;
                $('space-key').value = s.space_key;
                $('space-key').disabled = true;
                $('space-name').value = s.name;
                $('space-visibility').value = s.visibility;
                $('space-budget').value = s.budget_tokens_month;
                $('space-source-kb').value = String(s.source_kb_id);
                $('space-source-kb').disabled = true;
                $('space-save').textContent = '保存修改';
                return;
            }

            if (act === 'toggle-space') {
                var status = btn.getAttribute('data-status') === 'active' ? 'paused' : 'active';
                request(BASE + '/space/save', { id: id, name: btn.closest('tr').querySelector('.fw-semibold').textContent, status: status })
                    .then(function (res) {
                        if (res.code !== 0) { toast(res.msg || '操作失败', 'danger'); return; }
                        toast('已' + (status === 'active' ? '恢复' : '暂停'), 'success');
                        loadSpaces();
                        loadStats();
                    });
                return;
            }

            if (act === 'delete-space') {
                var name = btn.getAttribute('data-name');
                confirmBox('删除空间「' + name + '」会同时删除其影子知识库与全部页面，确定？').then(function (ok) {
                    if (!ok) { return; }
                    request(BASE + '/space/delete', { id: id }).then(function (res) {
                        if (res.code !== 0) { toast(res.msg || '删除失败', 'danger'); return; }
                        toast('已删除', 'success');
                        loadSpaces();
                        loadStats();
                    });
                });
            }
        });
    }

    function resetSpaceForm() {
        $('space-id').value = '';
        $('space-key').value = '';
        $('space-key').disabled = false;
        $('space-name').value = '';
        $('space-budget').value = '';
        $('space-visibility').value = 'private';
        $('space-source-kb').disabled = false;
        $('space-save').textContent = '保存';
    }

    // ── 页面 ────────────────────────────────────────────────────
    function loadPages() {
        var spaceId = parseInt($('page-space-filter').value || '0', 10);

        if (!spaceId) {
            $('page-body').innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">请选择空间后查询</td></tr>';
            $('page-total').textContent = '共 0 条';
            return Promise.resolve();
        }

        return request(BASE + '/pages', {
            space_id: spaceId,
            status: $('page-status-filter').value,
            keyword: $('page-keyword').value.trim(),
            dirty: $('page-dirty-only').checked ? 1 : 0,
            page: pagePage,
            limit: pageLimit
        }).then(function (res) {
            if (res.code !== 0) { toast(res.msg || '加载失败', 'danger'); return; }

            var list = (res.data && res.data.list) || [];
            pageTotal = (res.data && res.data.total) || 0;
            $('page-total').textContent = '共 ' + pageTotal + ' 条 · 第 ' + pagePage + ' 页';

            var body = $('page-body');
            if (!list.length) {
                body.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">没有页面：可在编译台用「触发编译」生成。</td></tr>';
                return;
            }

            body.innerHTML = list.map(function (p) {
                return '<tr>'
                    + '<td>' + p.id + '</td>'
                    + '<td><div class="fw-semibold">' + esc(p.title) + '</div>'
                    + '<div class="text-muted small">' + esc(p.slug) + '</div></td>'
                    + '<td><span class="badge bg-light text-dark">' + esc(p.kind) + '</span></td>'
                    + '<td>' + badge(p.status) + '</td>'
                    + '<td>rev' + p.rev_no + '</td>'
                    + '<td>' + (p.quality_score || 0) + '</td>'
                    + '<td>' + drawBadge(p.dirty_reason) + '</td>'
                    + '<td class="small text-secondary">' + esc(p.update_time) + '</td>'
                    + '<td>'
                    + '<button class="btn btn-sm btn-outline-primary me-1" data-act="edit-page" data-id="' + p.id + '">编辑</button>'
                    + '<button class="btn btn-sm btn-outline-success me-1" data-act="publish-page" data-id="' + p.id + '">发布</button>'
                    + '<button class="btn btn-sm btn-outline-secondary me-1" data-act="compile-page" data-id="' + p.id + '">重编译</button>'
                    + '<button class="btn btn-sm btn-outline-danger" data-act="delete-page" data-id="' + p.id + '" data-title="' + esc(p.title) + '">删除</button>'
                    + '</td></tr>';
            }).join('');
        });
    }

    function bindPageList() {
        $('page-search').addEventListener('click', function () { pagePage = 1; loadPages(); });
        $('page-refresh').addEventListener('click', function () { loadPages(); loadStats(); });
        $('page-prev').addEventListener('click', function () {
            if (pagePage > 1) { pagePage--; loadPages(); }
        });
        $('page-next').addEventListener('click', function () {
            if (pagePage * pageLimit < pageTotal) { pagePage++; loadPages(); }
        });
        $('page-space-filter').addEventListener('change', function () { pagePage = 1; loadPages(); });

        $('page-body').addEventListener('click', function (ev) {
            var btn = ev.target.closest('button[data-act]');
            if (!btn) { return; }
            var id = parseInt(btn.getAttribute('data-id'), 10);
            var act = btn.getAttribute('data-act');

            if (act === 'edit-page') { openDrawer(id); return; }

            if (act === 'publish-page') {
                request(BASE + '/page/publish', { id: id }).then(function (res) {
                    toast(res.msg || '已发布', res.code === 0 ? 'success' : 'danger');
                    loadPages();
                    loadStats();
                });
                return;
            }

            if (act === 'compile-page') {
                request(BASE + '/compile', { space_id: parseInt($('page-space-filter').value || '0', 10), page_id: id })
                    .then(function (res) {
                        toast(res.msg || '已触发', res.code === 0 ? 'success' : 'danger');
                        loadPages();
                        loadStats();
                    });
                return;
            }

            if (act === 'delete-page') {
                confirmBox('删除页面「' + btn.getAttribute('data-title') + '」？影子库文档会一并撤下。').then(function (ok) {
                    if (!ok) { return; }
                    request(BASE + '/page/delete', { id: id }).then(function (res) {
                        toast(res.msg || '已删除', res.code === 0 ? 'success' : 'danger');
                        loadPages();
                        loadStats();
                    });
                });
            }
        });
    }

    // ── 编辑抽屉 ────────────────────────────────────────────────
    function drawerAlert(html, type) {
        var el = $('drawer-alert');
        if (!html) {
            el.className = 'alert d-none py-2 small';
            el.innerHTML = '';
            return;
        }
        el.className = 'alert alert-' + (type || 'info') + ' py-2 small';
        el.innerHTML = html;
    }

    function openDrawer(pageId) {
        diffSelection = [];
        drawerAlert('');

        request(BASE + '/page', { id: pageId }).then(function (res) {
            if (res.code !== 0) { toast(res.msg || '加载失败', 'danger'); return; }

            var data = res.data || {};
            var page = data.page || {};
            drawerPage = page;

            $('drawer-page-id').value = page.id;
            $('drawer-rev-no').value = page.rev_no;
            $('drawer-rev-label').textContent = page.rev_no;
            $('drawer-title').value = page.title || '';
            $('drawer-summary').value = page.summary || '';
            $('drawer-content').value = page.content_md || '';
            $('drawer-status').value = page.status;
            $('page-drawer-title').textContent = '编辑：' + (page.title || page.slug);

            if (page.dirty_reason) {
                drawerAlert('该页面待重编译（' + esc(page.dirty_reason) + '）。点「重新编译」会生成候选版本，不会覆盖你的人工修订。', 'warning');
            }

            renderRevisions(data.revisions || []);
            renderCandidate(data.candidate);

            var drawer = new bootstrap.Offcanvas($('page-drawer'));
            drawer.show();
        });
    }

    function renderCandidate(candidate) {
        var box = $('drawer-candidate');
        if (!candidate) {
            box.classList.add('d-none');
            box.innerHTML = '';
            return;
        }

        box.classList.remove('d-none');
        box.innerHTML = '<div class="d-flex justify-content-between align-items-center">'
            + '<div><i class="bi bi-stars"></i> 存在待应用的候选版本（rev' + candidate.rev_no + '，'
            + esc(candidate.model_name || 'LLM') + ' 生成，' + esc(candidate.create_time) + '）</div>'
            + '<button class="btn btn-sm btn-warning" id="drawer-apply">应用候选版本</button>'
            + '</div>';

        $('drawer-apply').addEventListener('click', function () {
            confirmBox('应用候选版本会用它覆盖当前正文（原正文保留在版本记录里），确定？').then(function (ok) {
                if (!ok) { return; }
                request(BASE + '/page/apply', { id: drawerPage.id }).then(function (r) {
                    toast(r.msg || '已应用', r.code === 0 ? 'success' : 'danger');
                    if (r.code === 0) { openDrawer(drawerPage.id); loadPages(); loadStats(); }
                });
            });
        });
    }

    function renderRevisions(revisions) {
        var body = $('drawer-revisions');
        if (!revisions.length) {
            body.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">暂无版本</td></tr>';
            return;
        }

        body.innerHTML = revisions.map(function (r) {
            var reasonMap = {
                compile: '编译', rebuild: '重编译', rebuild_from_sources: '重编译（待应用）', manual: '人工'
            };
            var isCandidate = r.change_reason === 'rebuild_from_sources';
            return '<tr' + (isCandidate ? ' class="table-warning"' : '') + '>'
                + '<td>rev' + r.rev_no + '</td>'
                + '<td>' + (r.editor_type === 'admin' ? '<span class="badge bg-primary">人工</span>' : '<span class="badge bg-light text-dark">系统</span>') + '</td>'
                + '<td>' + esc(reasonMap[r.change_reason] || r.change_reason) + '</td>'
                + '<td class="small text-secondary">' + esc(r.prompt_version || '—') + '</td>'
                + '<td class="small text-secondary">' + esc(r.create_time) + '</td>'
                + '<td><button class="btn btn-sm btn-outline-secondary" data-rev="' + r.rev_no + '" data-act="diff">选择</button></td>'
                + '</tr>';
        }).join('');

        body.querySelectorAll('button[data-act="diff"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var rev = parseInt(btn.getAttribute('data-rev'), 10);

                if (diffSelection.indexOf(rev) >= 0) {
                    diffSelection = diffSelection.filter(function (x) { return x !== rev; });
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-outline-secondary');
                    btn.textContent = '选择';
                    return;
                }

                if (diffSelection.length >= 2) { toast('最多选择两个版本', 'warning'); return; }

                diffSelection.push(rev);
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-primary');
                btn.textContent = '已选';

                if (diffSelection.length === 2) { showDiff(); }
            });
        });
    }

    function showDiff() {
        var a = diffSelection[0];
        var b = diffSelection[1];

        Promise.all([
            request(BASE + '/page/revision', { id: drawerPage.id, rev_no: a }),
            request(BASE + '/page/revision', { id: drawerPage.id, rev_no: b })
        ]).then(function (results) {
            if (results[0].code !== 0 || results[1].code !== 0) {
                toast('版本加载失败', 'danger');
                return;
            }

            $('diff-left-label').textContent = 'rev' + a + '（' + (results[0].data.editor_type === 'admin' ? '人工' : '系统') + '）';
            $('diff-right-label').textContent = 'rev' + b + '（' + (results[1].data.editor_type === 'admin' ? '人工' : '系统') + '）';
            $('diff-left').textContent = results[0].data.content_md || '';
            $('diff-right').textContent = results[1].data.content_md || '';

            new bootstrap.Modal($('diff-modal')).show();
        });
    }

    function bindDrawer() {
        $('drawer-save').addEventListener('click', function () {
            var id = parseInt($('drawer-page-id').value || '0', 10);

            request(BASE + '/page/save', {
                id: id,
                rev_no: parseInt($('drawer-rev-no').value || '0', 10),
                title: $('drawer-title').value,
                summary: $('drawer-summary').value,
                content_md: $('drawer-content').value,
                status: $('drawer-status').value
            }).then(function (res) {
                if (res.code !== 0) {
                    toast(res.msg || '保存失败', 'danger');
                    if ((res.msg || '').indexOf('刷新') >= 0) {
                        drawerAlert('检测到并发编辑：' + esc(res.msg || '') + ' 请关闭抽屉重新打开后再改。', 'danger');
                    }
                    return;
                }
                toast(res.msg || '已保存', 'success');
                $('drawer-rev-no').value = res.data.rev_no;
                $('drawer-rev-label').textContent = res.data.rev_no;
                drawerAlert('');
                openDrawer(id);
                loadPages();
                loadStats();
            });
        });

        $('drawer-publish').addEventListener('click', function () {
            request(BASE + '/page/publish', { id: drawerPage.id }).then(function (res) {
                toast(res.msg || '已发布', res.code === 0 ? 'success' : 'danger');
                openDrawer(drawerPage.id);
                loadPages();
                loadStats();
            });
        });

        $('drawer-archive').addEventListener('click', function () {
            confirmBox('归档后该页面会从检索中撤下，确定？').then(function (ok) {
                if (!ok) { return; }
                request(BASE + '/page/archive', { id: drawerPage.id }).then(function (res) {
                    toast(res.msg || '已归档', res.code === 0 ? 'success' : 'danger');
                    openDrawer(drawerPage.id);
                    loadPages();
                    loadStats();
                });
            });
        });

        $('drawer-recompile').addEventListener('click', function () {
            request(BASE + '/compile', { space_id: drawerPage.space_id, page_id: drawerPage.id }).then(function (res) {
                toast(res.msg || '已触发', res.code === 0 ? 'success' : 'danger');
                openDrawer(drawerPage.id);
                loadPages();
                loadStats();
            });
        });

        $('drawer-delete').addEventListener('click', function () {
            confirmBox('删除页面「' + drawerPage.title + '」？影子库文档会一并撤下。').then(function (ok) {
                if (!ok) { return; }
                request(BASE + '/page/delete', { id: drawerPage.id }).then(function (res) {
                    toast(res.msg || '已删除', res.code === 0 ? 'success' : 'danger');
                    bootstrap.Offcanvas.getInstance($('page-drawer')).hide();
                    loadPages();
                    loadStats();
                });
            });
        });
    }

    // ── 编译台 ──────────────────────────────────────────────────
    function loadJobs() {
        return request(BASE + '/jobs', {
            space_id: parseInt($('job-space-filter').value || '0', 10),
            status: $('job-status-filter').value,
            limit: 50
        }).then(function (res) {
            if (res.code !== 0) { toast(res.msg || '加载失败', 'danger'); return; }

            var list = (res.data && res.data.list) || [];
            var body = $('job-body');

            if (!list.length) {
                body.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">暂无任务记录</td></tr>';
                return;
            }

            body.innerHTML = list.map(function (j) {
                var tokens = (j.tokens_in || 0) + (j.tokens_out || 0);
                return '<tr>'
                    + '<td>' + j.id + '</td>'
                    + '<td>' + (j.page_id ? '#' + j.page_id : '批量') + '</td>'
                    + '<td><span class="badge bg-light text-dark">' + esc(j.trigger_type) + '</span></td>'
                    + '<td>' + badge(j.status) + '</td>'
                    + '<td>' + (j.evidence_count || 0) + '</td>'
                    + '<td class="small text-secondary">' + tokens + '</td>'
                    + '<td class="small text-secondary">' + (j.latency_ms || 0) + 'ms</td>'
                    + '<td class="small text-danger" style="max-width:420px;word-break:break-all">' + esc(j.error || '') + '</td>'
                    + '<td>' + ((j.status === 'failed' || j.status === 'skipped')
                        ? '<button class="btn btn-sm btn-outline-primary" data-act="retry-job" data-id="' + j.id + '">重试</button>'
                        : '') + '</td>'
                    + '</tr>';
            }).join('');
        });
    }

    function bindJobs() {
        $('job-search').addEventListener('click', loadJobs);

        $('job-body').addEventListener('click', function (ev) {
            var btn = ev.target.closest('button[data-act="retry-job"]');
            if (!btn) { return; }

            request(BASE + '/job/retry', { id: parseInt(btn.getAttribute('data-id'), 10) }).then(function (res) {
                toast(res.msg || '已重试', res.code === 0 ? 'success' : 'danger');
                loadJobs();
                loadStats();
            });
        });
    }

    // ── 检索调试 ────────────────────────────────────────────────
    function renderHits(containerId, hits, emptyText) {
        var el = $(containerId);
        if (!hits || !hits.length) {
            el.innerHTML = '<div class="text-muted small">' + emptyText + '</div>';
            return;
        }
        el.innerHTML = hits.map(function (h, i) {
            var cite = h.cite || {};
            var title = cite.title || ('文档 #' + h.doc_id);
            var url = cite.url ? '<a href="' + esc(cite.url) + '" target="_blank" class="small">打开</a>' : '';
            return '<div class="border-bottom pb-2 mb-2">'
                + '<div class="d-flex justify-content-between align-items-center">'
                + '<div class="small fw-semibold">' + (i + 1) + '. ' + esc(title) + '</div>'
                + '<div class="small text-secondary">score ' + h.score + ' ' + url + '</div>'
                + '</div>'
                + '<div class="small text-secondary">' + esc(cite.heading || '') + '</div>'
                + '<div class="small mt-1" style="white-space:pre-wrap">' + esc(h.content) + '</div>'
                + '</div>';
        }).join('');
    }

    function bindDebug() {
        $('debug-run').addEventListener('click', function () {
            var query = $('debug-query').value.trim();
            var spaceId = parseInt($('debug-space').value || '0', 10);

            if (!query) { toast('请输入查询内容', 'warning'); return; }
            if (!spaceId) { toast('请选择空间', 'warning'); return; }

            request(BASE + '/test-retrieval', {
                space_id: spaceId,
                query: query,
                top_k: parseInt($('debug-topk').value || '5', 10)
            }).then(function (res) {
                if (res.code !== 0) { toast(res.msg || '检索失败', 'danger'); return; }
                renderHits('debug-pages', res.data.pages, '影子库无命中（页面可能仍是 review / draft，未发布）');
                renderHits('debug-chunks', res.data.chunks, '源库无命中');
            });
        });
    }

    // ── 启动 ────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        bindTabs();
        bindSpaceForm();
        bindPageList();
        bindDrawer();
        bindJobs();
        bindDebug();

        loadStats();
        loadKbOptions();
        loadSpaces();
    });
}());
