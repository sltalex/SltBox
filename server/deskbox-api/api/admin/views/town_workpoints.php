<div class="panel" id="town-work-points-panel" style="grid-column:1/-1">
<h2>地图工作点</h2>
<p class="muted">工作点是村民真正进入并完成工作的设施。目标 ID 必须使用稳定的英文/数字键，工作完成后会写入工作运行记录。</p>
<div class="panel-actions" style="margin:0 0 12px">
<button type="button" class="modal-open" data-modal="modal-add-point">＋ 新增工作点</button>
</div>
<div class="modal-backdrop" id="modal-add-point" hidden>
<div class="modal modal-lg">
<div class="modal-head"><h3>新增工作点</h3><button type="button" class="modal-close" data-close="modal-add-point" aria-label="关闭">×</button></div>
<div class="modal-body">
<details class="help-box">
<summary>填写示例与说明（照着下面填）</summary>
<div class="table-scroll" style="max-height:36vh">
<table class="help-table">
<thead><tr><th>字段</th><th>示例值</th><th>含义 / 为什么这样填</th></tr></thead>
<tbody>
<tr><td>工作点 ID</td><td>farm-zone-4</td><td>稳定且唯一的英文/数字键，村民日程里的 target_id 就是用它来引用。只能字母或数字开头，可含 . _ -，必填。</td></tr>
<tr><td>名称</td><td>玉米田</td><td>工作点的显示名，后台和地图里看到的名字，必填。</td></tr>
<tr><td>类型</td><td>农田</td><td>设施类型：农田 / 集市 / 工坊 / 公共设施，决定工作点的用途。</td></tr>
<tr><td>坐标 X/Y</td><td>96 / 96</td><td>工作点在地图上的位置（0～767），村民会走到这里来工作。</td></tr>
<tr><td>交互 JSON</td><td>{"radius":1,"occupations":["farmer"]}</td><td>定义哪些职业、多大范围内能在此工作。radius 是作用半径，occupations 是允许的职业列表。</td></tr>
<tr><td>结果 JSON</td><td>{"effect":"water_crop","resource":"crop_watered"}</td><td>工作完成后写入的结果，effect 是效果、resource 是产出的资源。</td></tr>
</tbody>
</table>
</div>
</details>
<form method="post" class="form-row" style="margin-top:14px">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_work_point_save">
<input type="hidden" name="panel" value="town_npcs">
<label>工作点 ID<input name="point_key" pattern="[A-Za-z0-9][A-Za-z0-9._\-]{0,127}" placeholder="farm-zone-4" required></label>
<label>名称<input name="point_name" maxlength="80" required></label>
<label>类型<select name="point_kind">
<option value="farm">农田</option>
<option value="market">集市</option>
<option value="workshop">工坊</option>
<option value="public">公共设施</option>
</select></label>
<label>坐标 X/Y<span class="inline-inputs"><input name="point_tile_x" type="number" min="0" value="96"><input name="point_tile_y" type="number" min="0" value="96"></span></label>
<label>交互 JSON<textarea name="point_interaction" rows="2">{"radius":1,"occupations":["farmer"]}</textarea></label>
<label>结果 JSON<textarea name="point_result" rows="2">{"effect":"water_crop","resource":"crop_watered"}</textarea></label>
<button type="submit">新增工作点</button>
</form>
</div>
</div>
</div>
<div class="table-scroll" style="margin-top:12px">
<table>
<thead><tr><th>ID / 名称</th><th>坐标 / 类型</th><th>交互和结果</th><th>操作</th></tr></thead>
<tbody>
<?php foreach ($townWorkPoints as $point): $pointForm = 'town-work-point-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)$point['point_id']); ?>
<tr>
<td>
<form id="<?= admin_page_escape($pointForm) ?>" method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_work_point_save">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="point_id" value="<?= admin_page_escape($point['point_id']) ?>">
</form>
<input form="<?= admin_page_escape($pointForm) ?>" name="point_key" value="<?= admin_page_escape($point['point_key']) ?>" pattern="[A-Za-z0-9][A-Za-z0-9._\-]{0,127}" required>
<br>
<input form="<?= admin_page_escape($pointForm) ?>" name="point_name" value="<?= admin_page_escape($point['name']) ?>" maxlength="80" required>
</td>
<td>
<select form="<?= admin_page_escape($pointForm) ?>" name="point_kind">
<?php foreach (['farm'=>'农田','market'=>'集市','workshop'=>'工坊','public'=>'公共设施'] as $kind => $label): ?>
<option value="<?= $kind ?>" <?= $kind === $point['kind'] ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
</select>
<br>
<input form="<?= admin_page_escape($pointForm) ?>" name="point_tile_x" type="number" min="0" value="<?= (int)$point['tile_x'] ?>" style="width:70px">
<input form="<?= admin_page_escape($pointForm) ?>" name="point_tile_y" type="number" min="0" value="<?= (int)$point['tile_y'] ?>" style="width:70px">
</td>
<td>
<textarea form="<?= admin_page_escape($pointForm) ?>" name="point_interaction" rows="2"><?= admin_page_escape(admin_page_json($point['interaction'] ?? [])) ?></textarea>
<textarea form="<?= admin_page_escape($pointForm) ?>" name="point_result" rows="2"><?= admin_page_escape(admin_page_json($point['result'] ?? [])) ?></textarea>
</td>
<td class="actions" style="margin-top:0">
<button form="<?= admin_page_escape($pointForm) ?>" type="submit">保存</button>
<form method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_work_point_toggle">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="point_id" value="<?= admin_page_escape($point['point_id']) ?>">
<input type="hidden" name="value" value="<?= $point['enabled'] ? '0' : '1' ?>">
<button class="quiet"><?= $point['enabled'] ? '停用' : '启用' ?></button>
</form>
<span class="pill <?= $point['enabled'] ? '' : 'off' ?>"><?= $point['enabled'] ? '启用中' : '已停用' ?></span>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
