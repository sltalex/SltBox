/**
 * 后台表单局部提交
 * ------------------------------------------------------------------
 * 目的：分页后在第 2 页编辑/删除，提交完仍停在第 2 页、滚动位置不变，
 *      不再整页刷新跳回第一页。
 *
 * 做法：拦截 method="post" 表单 -> fetch 带上 X-Partial 头 ->
 *      服务端执行同样的写入逻辑后直接返回渲染好的整页 HTML（不 303 跳转）->
 *      前端只把 <section class="grid"> 那一段换掉，并重新初始化分页与素材编辑器。
 *
 * 兜底：文件上传表单（enctype）、退出登录不做拦截；
 *      任何异常都退回整页加载，且用 sessionStorage 记住页码和滚动位置。
 */
(function () {
  'use strict';

  if (window.__townPartialLoaded) return;
  window.__townPartialLoaded = true;

  var RETURN_KEY = 'townReturn';
  var SKIP_OPS = ['logout', 'login'];
  var busy = false;

  var style = document.createElement('style');
  style.textContent =
    'body.town-busy main.shell{opacity:.5;pointer-events:none;transition:opacity .12s}' +
    'main.shell>.notice,main.shell>.error{transition:opacity .35s}';
  document.head.appendChild(style);

  /* ---------- 工具 ---------- */

  function formOp(form) {
    var el = form.elements ? form.elements.namedItem('operation') : null;
    return el && el.value ? String(el.value) : '';
  }

  function eligible(form) {
    if (!form || form.nodeName !== 'FORM') return false;
    if (String(form.getAttribute('method') || '').toLowerCase() !== 'post') return false;
    if (form.hasAttribute('target')) return false;
    if (form.hasAttribute('data-full-reload')) return false;
    if (form.getAttribute('enctype')) return false;          // 文件上传走整页，避免大文件卡住
    if (SKIP_OPS.indexOf(formOp(form)) >= 0) return false;   // 登录/退出必须整页
    return true;
  }

  function pageSnapshot() {
    var out = [];
    document.querySelectorAll('.pager[data-pg-index]').forEach(function (p) {
      var i = parseInt(p.getAttribute('data-pg-index'), 10);
      var v = parseInt(p.getAttribute('data-pg-page'), 10);
      if (!isNaN(i) && !isNaN(v)) out[i] = v;
    });
    return out;
  }

  function flushPageMemory(snapshot) {
    var mem = window.__pgMemory || (window.__pgMemory = []);
    mem.length = 0;
    snapshot.forEach(function (v, i) { mem[i] = v; });
  }

  function toast(message, isError) {
    var shell = document.querySelector('main.shell');
    if (!shell) return;
    var p = document.createElement('p');
    p.className = isError ? 'error' : 'notice';
    p.textContent = message;
    shell.insertBefore(p, shell.firstChild);
    setTimeout(function () {
      p.style.opacity = '0';
      setTimeout(function () { if (p.parentNode) p.remove(); }, 400);
    }, isError ? 6000 : 2500);
  }

  /* ---------- 替换 DOM ---------- */

  function applyGrid(html, snapshot, scrollY) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var newGrid = doc.querySelector('main.shell > section.grid');
    var oldGrid = document.querySelector('main.shell > section.grid');
    // 会话失效会返回登录页，结构对不上就整页加载
    if (!newGrid || !oldGrid) { location.reload(); return; }

    var oldTitle = oldGrid.querySelector('.panel h2');
    var newTitle = newGrid.querySelector('.panel h2');
    if (oldTitle && newTitle && oldTitle.textContent.trim() !== newTitle.textContent.trim()) {
      location.reload();
      return;
    }

    oldGrid.innerHTML = newGrid.innerHTML;
    // innerHTML 插入的 script 不会执行，但会残留，清掉避免重复引用
    oldGrid.querySelectorAll('script').forEach(function (s) { s.remove(); });

    // 顶部提示条（"操作已完成"/报错）一起换掉
    var shell = document.querySelector('main.shell');
    Array.prototype.slice.call(shell.children).forEach(function (node) {
      if (node === oldGrid) return;
      if (node.classList && (node.classList.contains('notice') || node.classList.contains('error'))) node.remove();
    });
    doc.querySelectorAll('main.shell > .notice, main.shell > .error').forEach(function (node) {
      shell.insertBefore(document.importNode(node, true), oldGrid);
    });

    // 恢复页码，再重建分页器与素材编辑器
    flushPageMemory(snapshot);
    if (typeof window.initPagination === 'function') window.initPagination();
    if (window.TownAssets && typeof window.TownAssets.init === 'function') {
      try { window.TownAssets.init(); } catch (err) { console.warn('[partial]', err); }
    }

    window.scrollTo(0, scrollY | 0);

    // 替换后焦点落在新面板上，方便继续操作
    var focusTarget = document.getElementById('town-sheet-canvas');
    if (!focusTarget) {
      var panel = oldGrid.querySelector('.panel');
      if (panel) { panel.setAttribute('tabindex', '-1'); panel.focus({ preventScroll: true }); }
    }
  }

  /* ---------- 提交 ---------- */

  function submit(form) {
    if (busy) return;
    busy = true;
    document.body.classList.add('town-busy');

    var snapshot = pageSnapshot();
    var scrollY = window.scrollY;
    try {
      sessionStorage.setItem(RETURN_KEY, JSON.stringify({
        pages: snapshot, scrollY: scrollY, at: Date.now()
      }));
    } catch (e) { /* 隐私模式忽略 */ }

    fetch(location.href, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Partial': '1', 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    }).then(function (res) {
      // 服务端没走局部返回（已执行过写入），整页重载并用 sessionStorage 恢复页码
      if (res.redirected || !/text\/html/i.test(res.headers.get('content-type') || '')) {
        location.reload();
        return null;
      }
      return res.text();
    }).then(function (html) {
      if (html === null) return;
      try { sessionStorage.removeItem(RETURN_KEY); } catch (e) {}
      document.body.classList.remove('town-busy');
      busy = false;
      applyGrid(html, snapshot, scrollY);
    }).catch(function () {
      // 网络层失败：请求可能没到达服务端，允许用户重试，不做整页提交
      document.body.classList.remove('town-busy');
      busy = false;
      try { sessionStorage.removeItem(RETURN_KEY); } catch (e) {}
      toast('提交失败，请检查网络后重试。', true);
    });
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!eligible(form)) return;
    e.preventDefault();
    submit(form);
  }, true);

  /* ---------- 整页加载时恢复页码（兜底路径） ---------- */

  function restoreReturn() {
    var raw;
    try { raw = sessionStorage.getItem(RETURN_KEY); } catch (e) { return; }
    if (!raw) return;
    try { sessionStorage.removeItem(RETURN_KEY); } catch (e) {}
    var data;
    try { data = JSON.parse(raw); } catch (e) { return; }
    if (!data || !data.pages || Date.now() - (data.at || 0) > 60000) return;

    flushPageMemory(data.pages);
    if (typeof window.initPagination === 'function') window.initPagination();
    if (window.TownAssets && typeof window.TownAssets.init === 'function') {
      try { window.TownAssets.init(); } catch (e) {}
    }
    window.scrollTo(0, data.scrollY | 0);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', restoreReturn);
  else restoreReturn();
}());
