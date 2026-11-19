<style>
/* ===== 已标记素材：紧凑排版（只作用于本面板，不影响其他页面） ===== */
.town-assets-items .table-scroll{margin-top:6px}
.town-assets-items .tai-table{table-layout:fixed}
.town-assets-items th,.town-assets-items td{padding:4px 7px;vertical-align:top}
.town-assets-items th{white-space:nowrap;font-size:11.5px;letter-spacing:.02em;background:#f7f9fc}
.town-assets-items td{line-height:1.35}
.town-assets-items tbody tr:hover td{background:#fbfdff}
/* 内部分组网格：一格里排多个字段，避免控件被挤到换行 */
.town-assets-items .grp{display:grid;column-gap:6px;align-items:center}
.town-assets-items .grp-cat{grid-template-columns:1.15fr .85fr .95fr}
.town-assets-items .grp-geo{grid-template-columns:repeat(4,1fr)}
.town-assets-items .grp-more{grid-template-columns:repeat(2,1fr);row-gap:2px}
/* 字段小标签：标签在左、控件在右，只占一行 */
.town-assets-items .tf{display:flex;align-items:center;gap:4px;min-width:0;margin:0}
.town-assets-items .tf>span{flex:0 0 auto;font-size:11px;color:#6b7789;white-space:nowrap}
.town-assets-items .tf>input,.town-assets-items .tf>select{flex:1 1 auto;width:100%;min-width:0;height:25px;padding:1px 5px;font-size:12px}
/* 名称与 ID */
.town-assets-items .tai-name{display:block;width:100%;height:25px;margin-bottom:3px;padding:1px 7px;font-size:12.5px;font-weight:600}
.town-assets-items .tai-id{display:block;font-size:10.5px;color:#8b96a7;word-break:break-all;font-family:ui-monospace,Consolas,monospace;line-height:1.3}
.town-assets-items .tai-sub{display:flex;align-items:center;gap:8px;min-width:0;flex-wrap:wrap}
.town-assets-items .tai-sub .tai-id{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.town-assets-items .tai-sub .tai-more{margin-top:0;flex:0 0 auto}
.town-assets-items .tai-sub .tai-more[open]{flex:1 1 100%}
.town-assets-items .tai-actions .town-region-preview{margin:0 6px 0 0}
/* 折叠的高级项 */
.town-assets-items .tai-more{margin-top:3px}
.town-assets-items .tai-more>summary{display:inline-block;font-size:11px;color:#2167d4;cursor:pointer;list-style:none;user-select:none;line-height:1.4}
.town-assets-items .tai-more>summary::-webkit-details-marker{display:none}
.town-assets-items .tai-more>summary::before{content:'▸ '}
.town-assets-items .tai-more[open]>summary::before{content:'▾ '}
.town-assets-items .tai-more[open]{padding:4px 6px 2px;margin-top:4px;border:1px dashed #d5dded;border-radius:8px;background:#fbfcff}
.town-assets-items .tai-more textarea{width:100%;margin-top:4px;padding:4px 6px;font-size:11.5px;min-height:46px}
.town-assets-items .tai-more button{height:24px;padding:0 8px;font-size:11.5px;margin-top:3px}
.town-assets-items .tai-more small{font-size:11px;color:#6b7789;margin-left:6px}
/* 操作列 */
.town-assets-items .tai-actions{display:grid;grid-template-columns:auto minmax(0,1fr);gap:3px 6px;align-items:center}
.town-assets-items .tai-btns{display:flex;flex-wrap:wrap;gap:4px;align-items:center}
.town-assets-items .tai-btns>form{margin:0;display:inline-flex}
.town-assets-items button{height:25px;padding:1px 9px;font-size:12px;border-radius:6px}
.town-assets-items .town-frame-list{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
.town-assets-items .town-frame-item{font-size:11px;color:#4a5568}
.town-assets-items .town-frame-item button{height:21px;padding:0 5px;font-size:11px}
.town-assets-items .town-region-preview{margin:0 8px 4px 0}
.town-assets-items .town-region-preview small{font-size:10.5px}
/* 帧预览同样压成缩略图，避免独占一大块 */
.town-assets-items .town-frame-preview-host{display:inline-flex;flex-basis:auto;margin:0}
.town-assets-items .town-frame-preview{padding:2px 4px;gap:5px;border-radius:6px}
.town-assets-items .town-frame-preview-canvas{max-width:48px;max-height:48px}
.town-assets-items .town-frame-preview-controls{gap:2px}
.town-assets-items .town-frame-preview-controls button{height:20px;padding:0 5px;font-size:10.5px}
.town-assets-items .town-frame-preview-label{font-size:10.5px}
.town-assets-items .town-frame-item{padding:1px 5px;gap:3px;font-size:11px}
.town-assets-items .town-frame-item button{height:19px;padding:0 4px;font-size:10.5px}
/* 窄屏：图集单独一行，避免文件名被截断 */
@media (max-width:1400px){
.town-assets-items .grp-cat{grid-template-columns:1fr 1fr}
.town-assets-items .grp-cat>.tf:first-child{grid-column:1/-1}
}
.town-assets-items .tai-hint{margin:0 0 6px;font-size:11.5px;color:#6b7789;line-height:1.5}
/* ===== 新增标记表单：紧凑分组排版 ===== */
.town-new-asset{margin-top:12px;padding:10px 12px 11px;border:1px solid #e3e9f3;border-radius:10px;background:#fbfcff;display:grid;gap:7px}
.town-new-asset .tn-title{display:flex;align-items:baseline;flex-wrap:wrap;gap:3px 8px;margin:0;font-size:12.5px;font-weight:600;color:#16202f}
.town-new-asset .tn-title small{font-weight:400;font-size:11px;color:#6b7789;min-width:0}
.town-new-asset .tn-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 8px}
.town-new-asset .tn-grid.tn-3{grid-template-columns:repeat(3,1fr)}
.town-new-asset .tn-grid.tn-4{grid-template-columns:repeat(4,1fr)}
.town-new-asset .tn-wide{grid-column:1/-1}
.town-new-asset .tn-span2{grid-column:span 2}
.town-new-asset .tn-f{display:flex;align-items:center;gap:5px;min-width:0;margin:0;font-weight:400}
.town-new-asset .tn-f>span{flex:0 0 auto;font-size:11px;color:#6b7789;white-space:nowrap}
.town-new-asset .tn-f>input,.town-new-asset .tn-f>select{flex:1 1 auto;width:100%;min-width:0;height:27px;padding:1px 6px;font-size:12.5px}
.town-new-asset .tn-f>button{flex:0 0 auto;height:27px;padding:0 8px;font-size:11.5px;white-space:nowrap}
.town-new-asset .tn-f>.tn-gen{background:#eef4ff;border-color:#bcd2f5;color:#2167d4}
.town-new-asset .tn-f>.tn-gen:hover{background:#e2ecff}
.town-new-asset .tn-f>input#town-new-asset-id{font-family:ui-monospace,Consolas,monospace;font-size:12px}
.town-new-asset .tn-more{margin:0}
.town-new-asset .tn-more>summary{font-size:11.5px;color:#2167d4;cursor:pointer;list-style:none;user-select:none;line-height:1.5}
.town-new-asset .tn-more>summary::-webkit-details-marker{display:none}
.town-new-asset .tn-more>summary::before{content:'▸ '}
.town-new-asset .tn-more[open]>summary::before{content:'▾ '}
.town-new-asset .tn-more>.tn-grid{margin-top:6px;padding:7px 8px;border:1px dashed #d5dded;border-radius:8px;background:#fff;grid-template-columns:1fr}
.town-new-asset .tn-status{grid-column:1/-1;font-size:10.5px;color:#6b7789;line-height:1.3}
.town-new-asset .tn-status:empty{display:none}
.town-new-asset .tn-frames{display:grid;gap:4px}
.town-new-asset .tn-frame-list{display:flex;flex-wrap:wrap;gap:4px}
.town-new-asset .tn-submit{display:flex;align-items:center;gap:10px;justify-content:flex-end}
.town-new-asset .tn-submit small{font-size:11px;color:#8b96a7}
.town-new-asset .tn-submit button{height:30px;padding:0 16px;font-size:12.5px}
@media (max-width:1400px){
.town-new-asset .tn-grid.tn-4{grid-template-columns:1fr 1fr}
.town-new-asset .tn-span2{grid-column:auto}
.town-new-asset .tn-grid.tn-3{grid-template-columns:1fr 1fr}
}
@media (max-width:1200px){
.town-new-asset .tn-grid,
.town-new-asset .tn-grid.tn-3,
.town-new-asset .tn-grid.tn-4{grid-template-columns:1fr}
.town-new-asset .tn-span2{grid-column:auto}
}
@media (max-width:850px){
.town-new-asset .tn-grid.tn-3{grid-template-columns:1fr 1fr}
.town-new-asset .tn-grid.tn-4{grid-template-columns:1fr 1fr}
}

/* ===== 新增标记：右侧悬浮面板（可收起） ===== */
.tn-open-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px}
.tn-open-bar small{font-size:11px;color:#8b96a7}
.tn-open{height:30px;padding:0 14px;font-size:12.5px}
.tn-open[aria-expanded="true"]{background:#eef4ff;border-color:#bcd2f5;color:#2167d4}
.town-new-asset-float{position:fixed;top:74px;right:18px;z-index:70;width:420px;max-width:calc(100vw - 24px);max-height:calc(100vh - 96px);display:flex;flex-direction:column;background:#fff;border:1px solid #dde6f4;border-radius:12px;box-shadow:0 20px 46px rgba(20,40,78,.20),0 2px 8px rgba(20,40,78,.08);overflow:hidden}
.town-new-asset-float[hidden]{display:none}
.town-new-asset-float .tnf-head{display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid #eef2f9;background:linear-gradient(#f9fbff,#f4f8ff)}
.town-new-asset-float .tnf-head strong{font-size:13px;color:#16202f}
.town-new-asset-float .tnf-head small{flex:1 1 auto;min-width:0;font-size:11px;color:#8b96a7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.town-new-asset-float .tnf-close{flex:0 0 auto;height:24px;padding:0 9px;font-size:11.5px;border-radius:6px}
.town-new-asset-float>.town-new-asset{margin:0;padding:10px 12px 12px;border:0;border-radius:0;background:#fff;overflow:auto;flex:1 1 auto;min-height:0}
@media (max-width:1100px){.town-new-asset-float{top:auto;bottom:10px;left:10px;right:10px;width:auto;max-height:72vh}}

/* ===== 发布 / 回滚条 ===== */
.release-bar{display:grid;gap:8px;margin-top:10px;padding:10px 12px;border:1px solid #e3e9f3;border-radius:10px;background:#fbfcff}
.release-bar .release-now{display:flex;align-items:center;flex-wrap:wrap;gap:6px 10px}
.release-bar .release-line{display:flex;align-items:center;flex-wrap:wrap;gap:4px 6px;margin:0;font-size:12px;color:#41506b}
.release-bar .release-line code{font-size:11px;color:#5a6a86;background:#eef2f9;border-radius:4px;padding:1px 5px}
.release-bar .release-sha{font-family:ui-monospace,Consolas,monospace;color:#8b96a7}
.release-bar .release-publish{margin:0}
.release-bar .release-publish button{height:29px;padding:0 14px;font-size:12.5px}
.release-bar .release-tip{font-size:11px;color:#8b96a7}
.release-bar .release-history{display:flex;align-items:center;flex-wrap:wrap;gap:5px 6px;padding-top:8px;border-top:1px dashed #e0e7f2}
.release-bar .release-label{font-size:11.5px;color:#6b7789;flex:0 0 auto}
.release-bar .release-history form{margin:0;display:inline-flex}
.release-bar .release-history button{height:24px;padding:0 9px;font-size:11.5px;border-radius:6px}
.release-bar .release-history button.is-current{background:#eef4ff;border-color:#bcd2f5;color:#2167d4;opacity:1;cursor:default}
.release-bar .release-hint{flex:1 1 100%;font-size:10.5px;color:#8b96a7}
@media (max-width:1200px){.release-bar .release-tip{display:none}}

</style>
<div class="panel" id="town-assets-panel" style="grid-column:1/-1">
<div class="actions" style="justify-content:space-between;align-items:center"><h2 style="margin:0">共享小镇素材 · 整图标记</h2><form method="post"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="town_navigation_rebuild"><input type="hidden" name="panel" value="town_assets"><button type="submit">导航重建</button></form></div>
<p class="muted">当前导航版本：v<?= (int)$townNavigationVersion ?>。保存素材的通行语义后点击“导航重建”，NPC 才会清除旧路线并重新规划。</p>
<p class="muted">上传整张 PNG 后只保存坐标标记，不会生成裁剪文件。草稿在点击发布前不会进入小镇。</p>
<div class="town-sheet-layout">
<div class="town-sheet-preview"><canvas id="town-sheet-canvas" width="620" height="1250"></canvas></div>
<aside class="town-sheet-controls"><form method="post" enctype="multipart/form-data" class="form-row"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="town_asset_upload"><input type="hidden" name="panel" value="town_assets"><label>整张 PNG<input type="file" name="sheet" accept="image/png" required></label><button type="submit">上传图集</button></form><label>框选图集<select id="town-sheet-picker"><?php foreach ($townAssetState['sheets'] as $sheet): ?><option value="<?= admin_page_escape($sheet['sheet_id']) ?>" data-width="<?= (int)$sheet['width'] ?>" data-height="<?= (int)$sheet['height'] ?>"><?= admin_page_escape($sheet['original_name']) ?></option><?php endforeach; ?></select></label><p class="muted">按 16×16 网格识别；拖动框选会自动吸附到 16 的倍数。<br><span id="town-frame-hint"></span></p><div class="town-auto-scan"><button type="button" id="town-auto-scan">自动识别透明素材</button><span id="town-auto-scan-status" class="muted"></span><div id="town-auto-scan-results" class="town-auto-scan-results"></div></div><div class="tn-open-bar"><button type="button" id="town-new-asset-open" class="tn-open" aria-controls="town-new-asset-float" aria-expanded="false" title="在右侧浮层里打开「新增标记」表单">＋ 新增标记</button><small>表单悬浮在右侧，可随时收起</small></div></aside></div><div class="town-new-asset-float" id="town-new-asset-float" hidden><div class="tnf-head"><strong>新增标记</strong><small>在左侧大图框选，再补齐字段</small><button type="button" class="tnf-close" data-tn-close title="收起表单（Esc 也可）">收起 ✕</button></div><form id="town-new-asset" method="post" class="town-new-asset">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_asset_item">
<input type="hidden" name="panel" value="town_assets">

<div class="tn-grid">
<label class="tn-f tn-wide" title="这条标记使用哪张图集大图；坐标都相对于这张图"><span>图集</span><select name="sheet_id" required><option value="">选择图集</option><?php foreach ($townAssetState['sheets'] as $sheet): ?><option value="<?= admin_page_escape($sheet['sheet_id']) ?>"><?= admin_page_escape($sheet['original_name']) ?> (<?= (int)$sheet['width'] ?>×<?= (int)$sheet['height'] ?>)</option><?php endforeach; ?></select></label>
<label class="tn-f tn-wide" title="程序内部引用的唯一标识，选定图集和分类后自动生成，也可以自己改；支持字母、数字、点、短横线、下划线，town-source- 前缀会自动补上"><span>资源 ID</span><input name="asset_id" id="town-new-asset-id" pattern="(?:town-source-)?[A-Za-z0-9][A-Za-z0-9._\-]{0,96}" placeholder="选定图集后自动生成" autocomplete="off" required><button type="button" class="tn-gen" id="town-new-asset-id-gen" title="按当前图集和分类重新生成一个不重复的资源 ID">自动</button></label>
<label class="tn-f tn-wide" title="在后台列表里显示的素材名字，仅用于辨认，不能与已有素材重名；新增完会自动带上你上一次填的名称，光标落在末尾，接着改即可"><span>名称</span><input name="name" required></label>
<input type="hidden" name="create_only" value="1">
</div>
<div class="tn-grid tn-3">
<label class="tn-f" title="素材类型，决定它在地图上的归类与筛选"><span>分类</span><select name="category"><?php foreach (TOWN_ASSET_CATEGORIES as $category): ?><option value="<?= admin_page_escape($category) ?>"><?= admin_page_escape(admin_town_asset_category_label($category)) ?></option><?php endforeach; ?></select></label>
<label class="tn-f" title="决定绘制先后顺序：自动按底边排序适合大多数物件；背景/前景强制置顶置底；前后景拆分用于房屋这类上半身要挡住人的物件"><span>排序</span><select name="sort_mode"><option value="y_sort">自动按底边排序</option><option value="background">背景</option><option value="foreground">前景</option><option value="split">房屋前后景拆分</option><option value="manual">手工层级兼容</option></select></label>
<label class="tn-f" title="细分标签，例如 house / tree / fence，可留空"><span>子分类</span><input name="subcategory" placeholder="house"></label>
</div>
<div class="tn-grid tn-4">
<label class="tn-f" title="框选区域左上角在整张图集上的横向像素位置（16 的倍数）"><span>X</span><input name="x" type="number" min="0" step="16" value="0" required></label>
<label class="tn-f" title="框选区域左上角在整张图集上的纵向像素位置（16 的倍数）"><span>Y</span><input name="y" type="number" min="0" step="16" value="0" required></label>
<label class="tn-f" title="框选区域的像素宽度（16 的倍数）"><span>宽</span><input name="width" type="number" min="16" step="16" value="16" required></label>
<label class="tn-f" title="框选区域的像素高度（16 的倍数）"><span>高</span><input name="height" type="number" min="16" step="16" value="16" required></label>
</div>
<div class="tn-grid tn-4">
<label class="tn-f tn-span2" title="画到地图上时实际显示的宽度，一般与框选宽度一致；放大显示就填更大的值"><span>显示宽</span><input name="display_width" type="number" min="16" step="16" value="16" required></label>
<label class="tn-f tn-span2" title="画到地图上时实际显示的高度，一般与框选高度一致"><span>显示高</span><input name="display_height" type="number" min="16" step="16" value="16" required></label>
</div>
<details class="tn-more">
<summary>更多参数（拆分线 / 标签 / 锚点 / 占地 / 碰撞 / 导航）</summary>
<div class="tn-grid">
<label class="tn-f tn-wide" title="仅在“房屋前后景拆分”排序下生效：按从上到下填写 Y 像素分界；两条分界线会生成上方前景、中段随人物脚底排序、下方背景三段，例如 48,96。高建筑未填写时客户端会按三等分自动兜底"><span>拆分线 Y</span><input name="split_lines" type="text" inputmode="numeric" placeholder="三段示例：48,96"></label>
<label class="tn-f tn-wide" title="给素材打的自定义标签，JSON 数组格式，例如 [&quot;house&quot;,&quot;autumn&quot;]，可留空"><span>标签</span><input name="tags" value="[]"></label>
<label class="tn-f" title="锚点位置，JSON 格式；x=0.5、y=1 表示以底边中点为落脚点，绝大多数素材都用这个值"><span>锚点</span><input name="anchor" value='{"x":0.5,"y":1}'></label>
<label class="tn-f" title="占地格数，JSON 格式，例如 {&quot;width&quot;:4,&quot;height&quot;:5}；框选后会自动识别，也可以手动改"><span>占地</span><input name="footprint" value="" placeholder="框选后自动识别"><button type="button" data-recalculate-footprint>重新计算</button></label>
<label class="tn-f" title="不可行走区域，JSON 格式；x/y/宽/高 都是相对素材的比例（0~1），留空表示整块都能走"><span>碰撞</span><input name="collision" value='{"x":0.1,"y":0.55,"width":0.8,"height":0.4}'></label>
<label class="tn-f" title="NPC 通行规则，JSON 格式；type=blocked 表示阻挡，blocked_npc=true 表示 NPC 不能通过，required_capabilities 为通过所需能力"><span>导航</span><input name="navigation" value='{"type":"blocked","blocked_npc":true,"required_capabilities":[]}'></label>
<small class="tn-status" data-footprint-status></small>
</div>
</details>
<div class="tn-frames">
<label class="tn-f" title="动画帧列表，JSON 数组；用右侧按钮在图上框选即可自动追加，一般不用手写"><span>动画帧</span><input id="town-new-frames" name="frames" value="[]"><button type="button" data-frame-add data-frame-input="town-new-frames">＋ 框选添加帧</button></label>
<span class="town-frame-list tn-frame-list" data-frame-list="town-new-frames"></span>
</div>
<div class="tn-submit"><button type="submit">＋ 新增标记</button><small>新增后不会跳页，可以接着框下一块</small></div>
</form></div><div class="town-assets-items">
<h3>已标记素材 · <?= count($townAssetState['items']) ?> 项</h3>
<p class="tai-hint">每行一条素材：左侧填名称，中间选图集/分类并对坐标，右侧看预览与动画帧。鼠标悬停任一输入框可看说明；占地、碰撞、显示尺寸、拆分线等高级项在「更多参数」里。列表每页显示 5 条，按添加时间倒序排列（后加的在前，编辑不会改变位置）。</p>
<div class="table-scroll">
<table class="tai-table" data-pg-size="5" data-pg-key="town-assets-items">
<colgroup><col style="width:23%"><col style="width:31%"><col style="width:22%"><col style="width:24%"></colgroup>
<thead>
<tr>
<th title="素材名称与内部 ID">资源</th>
<th title="取自哪张图集 / 什么分类 / 绘制层级">图集 · 分类 · 层级</th>
<th title="在图集大图上的裁切位置与尺寸（像素，16 的倍数）">坐标 / 尺寸</th>
<th title="框选预览、动画帧、保存与删除">操作 / 预览</th>
</tr>
</thead>
<tbody>
<?php foreach ($townAssetState['items'] as $item): $editId = 'town-edit-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)$item['id']); ?>
<tr>
<td>
<input form="<?= admin_page_escape($editId) ?>" name="name" class="tai-name" value="<?= admin_page_escape($item['name']) ?>" required title="素材名称：显示在小镇素材库与编辑列表中的名字" placeholder="素材名称">
<div class="tai-sub">
<code class="tai-id" title="素材 ID：程序内部引用标识，改名不影响"><?= admin_page_escape($item['id']) ?></code>
<details class="tai-more">
<summary title="NPC 能否穿过这块素材">导航语义</summary>
<textarea form="<?= admin_page_escape($editId) ?>" name="navigation" rows="2" title="JSON：type=blocked 表示阻挡；blocked_npc=true 表示 NPC 不可通过；required_capabilities 为通过所需能力"><?= admin_page_escape(admin_page_json($item['navigation'] ?? ['type'=>'blocked'])) ?></textarea>
</details>
</div>
</td>
<td>
<div class="grp grp-cat">
<label class="tf" title="这张素材从哪张图集大图上裁切"><span>图集</span>
<select form="<?= admin_page_escape($editId) ?>" name="sheet_id">
<?php foreach ($townAssetState['sheets'] as $sheet): ?>
<option value="<?= admin_page_escape($sheet['sheet_id']) ?>" <?= $sheet['sheet_id'] === $item['sheet_id'] ? 'selected' : '' ?>><?= admin_page_escape($sheet['original_name']) ?></option>
<?php endforeach; ?>
</select>
</label>
<label class="tf" title="素材分类：决定在小镇里的用途"><span>分类</span>
<select form="<?= admin_page_escape($editId) ?>" name="category">
<?php foreach (TOWN_ASSET_CATEGORIES as $category): ?>
<option value="<?= admin_page_escape($category) ?>" <?= $category === $item['category'] ? 'selected' : '' ?>><?= admin_page_escape(admin_town_asset_category_label($category)) ?></option>
<?php endforeach; ?>
</select>
</label>
<label class="tf" title="绘制层级：自动按底边排序 / 背景 / 前景 / 前后景拆分 / 手工"><span>层级</span>
<select form="<?= admin_page_escape($editId) ?>" name="sort_mode">
<option value="y_sort" <?= ($item['sort_mode'] ?? 'y_sort') === 'y_sort' ? 'selected' : '' ?>>自动排序</option>
<option value="background" <?= ($item['sort_mode'] ?? '') === 'background' ? 'selected' : '' ?>>背景</option>
<option value="foreground" <?= ($item['sort_mode'] ?? '') === 'foreground' ? 'selected' : '' ?>>前景</option>
<option value="split" <?= ($item['sort_mode'] ?? '') === 'split' ? 'selected' : '' ?>>前后景拆分</option>
<option value="manual" <?= ($item['sort_mode'] ?? '') === 'manual' ? 'selected' : '' ?>>手工层级</option>
</select>
</label>
</div>
<details class="tai-more">
<summary title="子分类、拆分线、显示尺寸、占地、碰撞与保存用的隐藏字段">更多参数（子分类 / 拆分线 / 显示尺寸 / 占地 / 碰撞）</summary>
<div class="grp grp-more">
<label class="tf" title="自由填写的二级分类，仅用于筛选检索"><span>子分类</span><input form="<?= admin_page_escape($editId) ?>" name="subcategory" value="<?= admin_page_escape($item['subcategory']) ?>" placeholder="如 house" title="二级分类，仅用于筛选检索"></label>
<label class="tf" title="仅“前后景拆分”层级生效"><span>拆分线Y</span><input form="<?= admin_page_escape($editId) ?>" name="split_lines" type="text" inputmode="numeric" value="<?= admin_page_escape(implode(',', array_map('intval', $item['split_lines'] ?? (($item['split_y'] ?? null) !== null ? [(int)$item['split_y']] : [])))) ?>" placeholder="三段示例：48,96" title="按从上到下填写 Y 像素分界；两条线生成上方前景、中段随人物脚底排序、下方背景；高建筑未填写时客户端按三等分自动兜底"></label>
<label class="tf" title="素材在小镇里实际显示的宽高（像素）"><span>显示宽</span><input form="<?= admin_page_escape($editId) ?>" name="display_width" type="number" min="16" step="16" value="<?= (int)$item['display_width'] ?>" title="显示宽度（像素），默认等于裁切宽度"></label>
<label class="tf" title="素材在小镇里实际显示的宽高（像素）"><span>显示高</span><input form="<?= admin_page_escape($editId) ?>" name="display_height" type="number" min="16" step="16" value="<?= (int)$item['display_height'] ?>" title="显示高度（像素），默认等于裁切高度"></label>
<label class="tf" title="占多少格：16 像素 = 1 格"><span>占地</span><input form="<?= admin_page_escape($editId) ?>" name="footprint" type="text" value="<?= admin_page_escape(json_encode($item['footprint'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" title="JSON：{width,height} 表示占几格，框选后自动计算，可手改"></label>
<label class="tf" title="可碰撞范围的相对比例"><span>碰撞</span><input form="<?= admin_page_escape($editId) ?>" name="collision" type="text" value="<?= admin_page_escape(json_encode($item['collision'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" title="JSON：{x,y,width,height} 为碰撞范围占整张素材的比例"></label>
</div>
<form id="<?= admin_page_escape($editId) ?>" method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_asset_item">
<input type="hidden" name="panel" value="town_assets">
<input type="hidden" name="asset_id" value="<?= admin_page_escape($item['id']) ?>">
<input type="hidden" name="tags" value="<?= admin_page_escape(json_encode($item['tags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
<input type="hidden" name="anchor" value="<?= admin_page_escape(json_encode($item['anchor'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
<input type="hidden" id="<?= admin_page_escape($editId) ?>-frames" name="frames" value="<?= admin_page_escape(json_encode($item['frames'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
<small id="<?= admin_page_escape($editId) ?>-foot-status" data-footprint-status></small>
</form>
</details>
</td>
<td>
<div class="grp grp-geo">
<label class="tf" title="框选区左上角在图集上的 X 像素坐标（16 的倍数）"><span>X</span><input form="<?= admin_page_escape($editId) ?>" name="x" type="number" min="0" step="16" value="<?= (int)$item['x'] ?>" required title="裁切区左上角 X 坐标（像素）"></label>
<label class="tf" title="框选区左上角在图集上的 Y 像素坐标（16 的倍数）"><span>Y</span><input form="<?= admin_page_escape($editId) ?>" name="y" type="number" min="0" step="16" value="<?= (int)$item['y'] ?>" required title="裁切区左上角 Y 坐标（像素）"></label>
<label class="tf" title="框选区域的宽度（像素，16 的倍数）"><span>宽</span><input form="<?= admin_page_escape($editId) ?>" name="width" type="number" min="16" step="16" value="<?= (int)$item['width'] ?>" required title="裁切区宽度（像素，16 的倍数）"></label>
<label class="tf" title="框选区域的高度（像素，16 的倍数）"><span>高</span><input form="<?= admin_page_escape($editId) ?>" name="height" type="number" min="16" step="16" value="<?= (int)$item['height'] ?>" required title="裁切区高度（像素，16 的倍数）"></label>
</div>
</td>
<td>
<div class="tai-actions">
<span class="tai-btns">
<button type="button" data-frame-add data-frame-input="<?= admin_page_escape($editId) ?>-frames" title="在上方大图上拖拽，框出一帧动画">＋框选添加帧</button>
<button form="<?= admin_page_escape($editId) ?>" type="submit" title="保存本行修改">保存</button>
<form method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_asset_delete">
<input type="hidden" name="panel" value="town_assets">
<input type="hidden" name="asset_id" value="<?= admin_page_escape($item['id']) ?>">
<button class="danger" type="submit" title="删除这条素材">删除</button>
</form>
</span>
</div>
<span class="town-frame-list" data-frame-list="<?= admin_page_escape($editId) ?>-frames"></span>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<div class="panel"><a href="?panel=town_assets&amp;preview_reflow=1">预演全部占地重算与就近调整</a>
<?php if($townReflowError): ?><p class="error"><?= admin_page_escape($townReflowError) ?></p><?php endif; ?>
<?php if(is_array($townReflow)): ?><p>预演完成：<?= count($townReflow['draft_items']) ?> 项草稿，<?= count($townReflow['config']['items']) ?> 项已发布素材，<?= count($townReflow['objects']) ?> 个对象。仅更新当前已发布项目的配置，不发布其他草稿。</p>
<table><tr><th>对象</th><th>原坐标 / 占地</th><th>新坐标 / 占地</th></tr><?php foreach($townReflow['objects'] as $change): ?><tr><td><?= admin_page_escape($change['object_id']) ?></td><td><?= admin_page_escape(json_encode($change['before'])) ?></td><td><?= admin_page_escape(json_encode($change['after'])) ?></td></tr><?php endforeach; ?></table>
<form method="post"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="town_asset_reflow"><input type="hidden" name="panel" value="town_assets"><input type="hidden" name="plan_sha256" value="<?= admin_page_escape($townReflow['plan_sha256']) ?>"><button>应用此预演（保留历史版本）</button></form><?php endif; ?></div>
<div class="panel"><a href="?panel=town_assets&amp;preview_navigation=1">预览导航影响区块</a>
<?php if($townNavigationPreviewError): ?><p class="error"><?= admin_page_escape($townNavigationPreviewError) ?></p><?php endif; ?>
<?php if(is_array($townNavigationPreview)): ?><p>预览完成：<?= (int)$townNavigationPreview['object_count'] ?> 个对象，影响 <?= (int)$townNavigationPreview['chunk_count'] ?> 个区块。此操作只读，不发布素材。</p>
<table><tr><th>区块</th><th>对象数</th><th>导航类型</th></tr><?php foreach($townNavigationPreview['chunks'] as $chunk): ?><tr><td><?= (int)$chunk['chunk_x'] ?>, <?= (int)$chunk['chunk_y'] ?></td><td><?= (int)$chunk['object_count'] ?></td><td><?= admin_page_escape(json_encode($chunk['types'], JSON_UNESCAPED_UNICODE)) ?></td></tr><?php endforeach; ?></table><small class="muted">预览指纹：<?= admin_page_escape($townNavigationPreview['preview_sha256']) ?></small><?php endif; ?></div>
<script src="town-assets-scan.js?v=20261114-1" defer></script>
<script src="town-assets.js?v=20261115-8" defer></script>
<?php $releaseKeep = defined('TOWN_ASSET_RELEASE_KEEP') ? (int)TOWN_ASSET_RELEASE_KEEP : 6; ?>
<div class="release-bar">
<div class="release-now">
<p class="release-line">当前发布：<code><?= admin_page_escape($townAssetState['active']['release_id'] ?? '无') ?></code>
<span>·</span><span><?= (int)($townAssetState['active']['item_count'] ?? 0) ?> 项素材</span>
<span>·</span><span class="release-sha" title="配置指纹：内容有任何变化都会变"><?= admin_page_escape(substr((string)($townAssetState['active']['config_sha256'] ?? ''), 0, 12)) ?></span></p>
<form method="post" class="release-publish">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_asset_publish">
<input type="hidden" name="panel" value="town_assets">
<?php if (is_array($townNavigationPreview)): ?><input type="hidden" name="navigation_preview_sha256" value="<?= admin_page_escape($townNavigationPreview['preview_sha256']) ?>"><?php endif; ?>
<button type="submit" title="把当前草稿打包成新版本并立即生效">发布当前草稿</button>
</form>
<small class="release-tip">发布会立即把草稿推到小镇；下方的历史版本可随时回滚</small>
</div>
<?php if ($townAssetState['releases'] !== []): ?>
<div class="release-history">
<span class="release-label">历史版本</span>
<?php foreach ($townAssetState['releases'] as $release): ?>
<?php $releaseCurrent = ((string)($release['release_id'] ?? '')) === ((string)($townAssetState['active']['release_id'] ?? '')); ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_asset_rollback">
<input type="hidden" name="panel" value="town_assets">
<input type="hidden" name="release_id" value="<?= admin_page_escape($release['release_id']) ?>">
<button class="quiet<?= $releaseCurrent ? ' is-current' : '' ?>" type="submit"<?= $releaseCurrent ? ' disabled' : '' ?> title="<?= $releaseCurrent ? '这就是当前线上版本' : '把线上素材配置切回 v' . (int)$release['release_version'] . '，草稿不受影响' ?>">v<?= (int)$release['release_version'] ?><?= $releaseCurrent ? ' · 当前' : '' ?></button>
</form>
<?php endforeach; ?>
<small class="release-hint">仅保留最近 <?= $releaseKeep ?> 个版本，更早的每次发布会自动清理</small>
</div>
<?php endif; ?>
</div>
<?php if (($townAssetState['audits'] ?? []) !== []): ?>
<div class="panel release-audit-panel">
<h3>导航发布审计</h3>
<p class="muted">记录发布时使用的导航版本、预览指纹和发布账号，便于定位素材版本。</p>
<table><tr><th>版本</th><th>发布人</th><th>导航版本</th><th>预览指纹</th><th>时间</th></tr>
<?php foreach ($townAssetState['audits'] as $audit): ?><tr>
<td>v<?= (int)($audit['release_version'] ?? 0) ?></td>
<td><code><?= admin_page_escape($audit['published_by'] ?? '') ?></code></td>
<td><?= (int)($audit['navigation_version'] ?? 0) ?></td>
<td><code title="<?= admin_page_escape($audit['navigation_preview_sha256'] ?? '') ?>"><?= admin_page_escape(substr((string)($audit['navigation_preview_sha256'] ?? ''), 0, 12) ?: '未提供') ?></code></td>
<td><?= admin_page_escape($audit['created_at'] ?? '') ?></td>
</tr><?php endforeach; ?></table>
</div>
<?php endif; ?>

