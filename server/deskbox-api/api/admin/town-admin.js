(function(){
"use strict";

/* ============ 悬浮窗 ============ */
function openModal(id){ var m = document.getElementById(id); if(m) m.hidden = false; }
function closeModal(id){ var m = document.getElementById(id); if(m) m.hidden = true; }
window.openModal = openModal;
window.closeModal = closeModal;

/* 统一用 document 级事件委托：局部刷新替换 DOM 后无需重新绑定 */
document.addEventListener('click', function(e){
  if(!e.target || !e.target.closest) return;

  var label = e.target.closest('.side-group-label');
  if(label){ label.parentElement.classList.toggle('collapsed'); return; }

  var tab = e.target.closest('.npc-tab');
  if(tab){
    var tabs = tab.closest('.npc-tabs');
    if(tabs){
      var card = tabs.closest('.npc-card');
      tabs.querySelectorAll('.npc-tab').forEach(function(t){ t.classList.remove('active'); });
      tab.classList.add('active');
      if(card){
        card.querySelectorAll('.npc-tab-panel').forEach(function(p){ p.classList.remove('active'); });
        var target = card.querySelector('.npc-tab-panel[data-panel="'+tab.getAttribute('data-tab')+'"]');
        if(target) target.classList.add('active');
      }
    }
    return;
  }

  var opener = e.target.closest('.modal-open');
  if(opener){ openModal(opener.getAttribute('data-modal')); return; }

  var closer = e.target.closest('.modal-close');
  if(closer){ closeModal(closer.getAttribute('data-close')); return; }

  var bd = e.target.closest('.modal-backdrop');
  if(bd && e.target === bd){ bd.hidden = true; }
});

document.addEventListener('keydown', function(e){
  if(e.key === 'Escape'){ document.querySelectorAll('.modal-backdrop').forEach(function(bd){ bd.hidden = true; }); }
});

/* ============ JSON 友好控件 -> 隐藏字段序列化 ============ */
function serializeJsonTags(scope){
  (scope||document).querySelectorAll('.json-tags').forEach(function(inp){
    var form = inp.form; if(!form) return;
    var target = inp.getAttribute('data-target');
    var format = inp.getAttribute('data-format') || 'array';
    var hidden = form.querySelector('input[type=hidden][name="'+target+'"]');
    if(!hidden) return;
    var items = inp.value.split(/[,，、\s]+/).map(function(s){ return s.trim(); }).filter(Boolean);
    hidden.value = (format === 'traits') ? JSON.stringify({traits: items}) : JSON.stringify(items);
  });
}
document.addEventListener('submit', function(e){
  if(e.target && e.target.querySelectorAll) serializeJsonTags(e.target);
}, true);

/* ============ 前端分页（带页码记忆，供局部刷新恢复） ============ */
var pgMemory = window.__pgMemory || (window.__pgMemory = []);

function pagerWindow(page, pages){
  var set = [1];
  var lo = Math.max(2, page-2), hi = Math.min(pages-1, page+2);
  if(lo > 2) set.push('…');
  for(var i=lo;i<=hi;i++) set.push(i);
  if(hi < pages-1) set.push('…');
  if(pages > 1) set.push(pages);
  return set;
}

function initPagination(){
  /* 重建前先记下每个分页器当前停在第几页（有 data-pg-key 的按 key 存，避免表格顺序变动错位） */
  document.querySelectorAll('.pager').forEach(function(p){
    var v = parseInt(p.getAttribute('data-pg-page'), 10);
    if(isNaN(v)) return;
    var k = p.getAttribute('data-pg-key');
    if(k){ pgMemory['k:'+k] = v; return; }
    var i = parseInt(p.getAttribute('data-pg-index'), 10);
    if(!isNaN(i)) pgMemory[i] = v;
  });

  var index = 0;
  document.querySelectorAll('.panel table, .npc-card table, .audit table').forEach(function(table){
    if(table.closest('form')) return;
    if(table.hasAttribute('data-no-paginate')) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    /* 每页行数：表格用 data-pg-size 覆盖，默认 15 */
    var size = parseInt(table.getAttribute('data-pg-size'), 10) || 15;
    if(size < 1) size = 15;
    if(rows.length <= size) return;
    var pages = Math.ceil(rows.length/size);
    var key = table.getAttribute('data-pg-key');
    var memKey = key ? ('k:' + key) : String(index);
    /* 恢复上次停留的页码（超出范围则收敛到最后一页） */
    var page = Math.min(Math.max(1, parseInt(pgMemory[memKey], 10) || 1), pages);
    var host = table.closest('.table-scroll') || table.closest('.audit') || table;
    var pager = document.createElement('div'); pager.className = 'pager';
    pager.setAttribute('data-pg-index', String(index));
    if(key) pager.setAttribute('data-pg-key', key);
    pager.setAttribute('data-pg-page', String(page));
    var info = document.createElement('span'); info.className = 'pg-info';
    function render(){
      rows.forEach(function(r,i){
        r.style.display = (i >= (page-1)*size && i < page*size) ? '' : 'none';
      });
      pager.querySelectorAll('button[data-p]').forEach(function(b){
        var p = b.getAttribute('data-p');
        var on = p === String(page);
        b.classList.toggle('cur', on); b.disabled = on;
      });
      info.textContent = '第 '+page+' / '+pages+' 页 · 共 '+rows.length+' 条';
      pager.setAttribute('data-pg-page', String(page));
      pgMemory[memKey] = page;
    }
    function makeBtn(label, p){
      var b = document.createElement('button'); b.type='button'; b.textContent=label; b.setAttribute('data-p', p); pager.appendChild(b);
    }
    makeBtn('上一页','prev'); pager.appendChild(info);
    pagerWindow(page,pages).forEach(function(p){ if(p==='…'){ var s=document.createElement('span'); s.className='pg-info'; s.textContent='…'; pager.appendChild(s);} else makeBtn(String(p), String(p)); });
    makeBtn('下一页','next');
    pager.addEventListener('click', function(e){
      var b = e.target.closest('button'); if(!b) return;
      var v = b.getAttribute('data-p');
      if(v==='prev') page = Math.max(1, page-1);
      else if(v==='next') page = Math.min(pages, page+1);
      else page = parseInt(v,10);
      Array.prototype.slice.call(pager.children).forEach(function(c){
        if(c.tagName==='BUTTON' && c.getAttribute('data-p') && ['prev','next'].indexOf(c.getAttribute('data-p'))<0) c.remove();
      });
      pagerWindow(page,pages).forEach(function(p){
        if(p==='…'){ var s=document.createElement('span'); s.className='pg-info'; s.textContent='…'; pager.insertBefore(s, pager.lastChild);}
        else { var nb=document.createElement('button'); nb.type='button'; nb.textContent=String(p); nb.setAttribute('data-p',String(p)); pager.insertBefore(nb, pager.lastChild); }
      });
      render();
    });
    host.parentNode.insertBefore(pager, host.nextSibling);
    render();
    index++;
  });
  applyNpcCardState();
  syncNpcPicks();
}
window.initPagination = initPagination;

/* ============ 村民卡片：默认收起，点头部展开 ============ */
var npcCardKey = 'townNpcOpenCards';
function npcOpenState(){
  try { return JSON.parse(sessionStorage.getItem(npcCardKey) || '{}') || {}; } catch(e){ return {}; }
}
function npcRemember(id, open){
  if(!id) return;
  var map = npcOpenState();
  if(open) map[id] = 1; else delete map[id];
  try { sessionStorage.setItem(npcCardKey, JSON.stringify(map)); } catch(e){}
}
function applyNpcCardState(){
  var map = npcOpenState();
  document.querySelectorAll('.npc-card').forEach(function(card){
    var open = map[card.getAttribute('data-npc-id') || ''] === 1;
    card.classList.toggle('is-open', open);
    var btn = card.querySelector('[data-npc-toggle]');
    if(!btn) return;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    var icon = btn.querySelector('.npc-fold-icon');
    if(icon) icon.textContent = open ? '▾' : '▸';
    var label = btn.querySelector('.npc-fold-label');
    if(label) label.textContent = open ? '收起' : '展开';
  });
}
window.applyNpcCardState = applyNpcCardState;

/* ============ 村民批量操作：全选 / 反选 / 清空 ============ */
var npcPickLimit = 100;
function npcPickBoxes(){
  return Array.prototype.slice.call(
    document.querySelectorAll('input[name="npc_ids[]"][form="town-npc-bulk-form"]')
  );
}
function syncNpcPicks(){
  var boxes = npcPickBoxes();
  var picked = 0;
  boxes.forEach(function(box){
    if(box.checked) picked++;
    var card = box.closest ? box.closest('.npc-card') : null;
    if(card) card.classList.toggle('is-picked', box.checked);
  });
  var out = document.querySelector('[data-npc-pick-count]');
  if(out){
    out.classList.toggle('is-over', picked > npcPickLimit);
    out.innerHTML = '已选 <b>' + picked + '</b> 个' + (picked >= npcPickLimit ? ' · 上限 ' + npcPickLimit : '');
  }
  document.querySelectorAll('[data-npc-pick]').forEach(function(btn){
    var mode = btn.getAttribute('data-npc-pick');
    var none = boxes.length === 0;
    btn.disabled = none
      || (mode === 'all' && picked === boxes.length)
      || (mode === 'none' && picked === 0);
  });
}
function setNpcPicks(mode){
  var boxes = npcPickBoxes();
  var left = npcPickLimit;
  boxes.forEach(function(box){
    if(mode === 'all'){
      box.checked = left > 0;
      if(box.checked) left--;
    } else if(mode === 'invert'){
      box.checked = !box.checked;
    } else {
      box.checked = false;
    }
  });
  syncNpcPicks();
}
window.syncNpcPicks = syncNpcPicks;

document.addEventListener('click', function(e){
  if(!e.target || !e.target.closest) return;
  var btn = e.target.closest('[data-npc-pick]');
  if(!btn || btn.disabled) return;
  e.preventDefault();
  setNpcPicks(btn.getAttribute('data-npc-pick'));
});
document.addEventListener('change', function(e){
  if(!e.target || !e.target.matches) return;
  if(!e.target.matches('input[name="npc_ids[]"]')) return;
  syncNpcPicks();
});

document.addEventListener('click', function(e){
  if(!e.target || !e.target.closest) return;
  /* 卡片头右侧的操作按钮（暂停/停用/删除）不触发折叠 */
  if(e.target.closest('.npc-card-head .head-actions')) return;
  var hit = e.target.closest('[data-npc-toggle]') || e.target.closest('.npc-card-head .name');
  if(!hit) return;
  var card = hit.closest('.npc-card');
  if(!card) return;
  npcRemember(card.getAttribute('data-npc-id') || '', !card.classList.contains('is-open'));
  applyNpcCardState();
});

if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initPagination);
else initPagination();
})();
