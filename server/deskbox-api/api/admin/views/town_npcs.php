<?php $occLabels = ['farmer'=>'农夫','merchant'=>'商人','craftsman'=>'工匠'];
$stateLabels = ['rest'=>'休息','work'=>'工作','walk'=>'行走','talk'=>'交谈','idle'=>'空闲']; ?>
<style>
/* ===== 村民卡片：默认收起，点头部展开 ===== */
.npc-card-head .name{cursor:pointer}
.npc-card-head .name:hover{text-decoration:underline;text-decoration-style:dotted;text-underline-offset:3px}
.npc-fold{flex:0 0 auto;display:inline-flex;align-items:center;gap:4px;height:22px;padding:0 8px;font-size:11.5px;line-height:1;border-radius:6px}
.npc-fold .npc-fold-icon{font-size:10px;opacity:.75}
.npc-card.is-open .npc-fold{background:#eef4ff;border-color:#bcd2f5;color:#2167d4}
.npc-card:not(.is-open)>.npc-tabs,.npc-card:not(.is-open)>.npc-tab-body{display:none}
.npc-card:not(.is-open)>.npc-card-head{border-bottom:0}
/* ===== 批量操作：勾选工具条 ===== */
.npc-sel-bar{flex:0 0 auto;display:inline-flex;align-items:center;gap:6px;padding:3px 8px;border:1px solid #e3ebf7;border-radius:8px;background:#f8faff}
.npc-sel-bar button[data-npc-pick]{height:24px;padding:0 10px;font-size:12px;line-height:1;border-radius:6px}
.npc-sel-count{font-size:12px;color:#5a6b83;font-variant-numeric:tabular-nums}
.npc-sel-count b{color:#16202f}
.npc-sel-count.is-over{color:#c0362c}
.npc-sel-count.is-over b{color:#c0362c}
.npc-card.is-picked{box-shadow:inset 3px 0 0 #2167d4;background:#fbfdff}
.npc-card-head input[type="checkbox"]{cursor:pointer;flex:0 0 auto}
</style>
<div class="panel" id="town-npcs-panel" style="grid-column:1/-1">
<h2>共享小镇村民 · <?= count($townNpcs) ?> 人</h2>
<p class="muted">配置姓名、外观、人格、家庭背景、目标、职业和日程。没有模型配置时，Worker 按日程状态机运行。每个村民一张卡片，资料、日程、住房、决策、经济、道具与记忆任务集中在卡片标签页里。</p>
<div class="panel-actions" style="margin:0 0 12px">
<button type="button" class="modal-open" data-modal="modal-add-npc">＋ 新增村民</button>
</div>
<div class="modal-backdrop" id="modal-add-npc" hidden>
<div class="modal modal-lg">
<div class="modal-head"><h3>新增村民</h3><button type="button" class="modal-close" data-close="modal-add-npc" aria-label="关闭">×</button></div>
<div class="modal-body">
<details class="help-box">
<summary>填写示例与说明（照着下面填）</summary>
<div class="table-scroll" style="max-height:40vh">
<table class="help-table">
<thead><tr><th>字段</th><th>示例值</th><th>含义 / 为什么这样填</th></tr></thead>
<tbody>
<tr><td>姓名</td><td>老王</td><td>村民的显示名，地图和对话里都用它。必填，最长 80 字。</td></tr>
<tr><td>职业</td><td>农夫</td><td>决定村民每天的工作类型（农夫种田、商人交易、工匠制作），必选。</td></tr>
<tr><td>人物素材</td><td>默认样式</td><td>村民在地图上的外观图片。不选就用默认样式，选了就用对应素材。</td></tr>
<tr><td>出生 X/Y</td><td>96 / 100</td><td>出生点地图坐标（0～767），决定村民一开始站在哪里。</td></tr>
<tr><td>性格特征</td><td>勤劳,节俭</td><td>逗号分隔多个词，保存时自动转成 JSON 的 traits 数组，影响 Agent 的说话和决策个性。</td></tr>
<tr><td>家庭背景</td><td>世代务农，为人憨厚</td><td>多行文字，作为人格生成的背景设定，写清楚出身和性格来源。</td></tr>
<tr><td>短期目标</td><td>收获玉米</td><td>Agent 近期想完成的小目标，影响每天优先做什么。</td></tr>
<tr><td>长期目标</td><td>经营自己的农场</td><td>Agent 的长远规划，影响整体行为方向。</td></tr>
<tr><td>说话方式</td><td>爽朗直接</td><td>对话的语气风格，控制村民说话的口吻。</td></tr>
<tr><td>价值观</td><td>诚实,勤劳</td><td>逗号分隔，转成 JSON 数组，是 Agent 做选择时的价值取向。</td></tr>
<tr><td>恐惧</td><td>蛇,火灾</td><td>逗号分隔，转成 JSON 数组，Agent 会主动回避这些事物。</td></tr>
<tr><td>日程 JSON</td><td>见下方默认值</td><td>JSON 数组，描述每天各时段做什么。weekday 用 0～6（周日到周六），时间用分钟数。默认值就是一个可照抄的例子。</td></tr>
</tbody>
</table>
</div>
</details>
<form method="post" class="form-row" style="margin-top:14px">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_save">
<input type="hidden" name="panel" value="town_npcs">
<label>姓名<input name="name" required maxlength="80" placeholder="老王"></label>
<label>职业<select name="occupation">
<option value="farmer">农夫</option>
<option value="merchant">商人</option>
<option value="craftsman">工匠</option>
</select></label>
<label>人物素材<select name="avatar_asset_id">
<option value="">默认样式</option>
<?php foreach ($townCharacterAssets as $asset): ?>
<option value="<?= admin_page_escape($asset['id'] ?? '') ?>"><?= admin_page_escape(($asset['name'] ?? $asset['id'] ?? '') . ' · ' . ($asset['id'] ?? '')) ?></option>
<?php endforeach; ?>
</select></label>
<label>出生 X/Y<span class="inline-inputs"><input name="tile_x" type="number" value="96"><input name="tile_y" type="number" value="100"></span></label>
<label>性格特征（逗号分隔）<input class="json-tags" data-target="personality" data-format="traits" value="勤劳"><input type="hidden" name="personality" value='{"traits":["勤劳"]}'></label>
<label>家庭背景<textarea name="family_background" rows="2"></textarea></label>
<label>短期目标<input name="short_term_goal"></label>
<label>长期目标<input name="long_term_goal"></label>
<label>说话方式<input name="speech_style"></label>
<label>价值观（逗号分隔）<input class="json-tags" data-target="values" value=""><input type="hidden" name="values" value="[]"></label>
<label>恐惧（逗号分隔）<input class="json-tags" data-target="fears" value=""><input type="hidden" name="fears" value="[]"></label>
<label>日程 JSON<textarea name="schedule" rows="3">[{"weekday":1,"start_minute":480,"end_minute":720,"action":"work","target_id":"farm-zone-3","job":"water_crop"}]</textarea></label>
<button type="submit">新增村民</button>
</form>
</div>
</div>
</div>
<section class="card" style="margin:12px 0">
<h3>全局 Agent 调用频次</h3>
<p class="npc-sub">全局策略是安全上限：全局间隔是所有村民的最小间隔，全局每小时上限是所有村民合计上限；单个村民只能设置得更慢，不能绕过全局限制。</p>
<form method="post" class="form-row">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_global_policy_save">
<input type="hidden" name="panel" value="town_npcs">
<label><input name="global_policy_enabled" type="checkbox" value="1" <?= !empty($townNpcGlobalPolicy['enabled']) ? 'checked' : '' ?>>启用全局限制</label>
<label>全局决策最小间隔（秒）<input name="global_decision_interval_seconds" type="number" min="5" max="86400" value="<?= (int)($townNpcGlobalPolicy['decision_interval_seconds'] ?? 60) ?>"></label>
<label>全局观察最小间隔（秒）<input name="global_observation_interval_seconds" type="number" min="5" max="86400" value="<?= (int)($townNpcGlobalPolicy['observation_interval_seconds'] ?? 300) ?>"></label>
<label>全局对话最小冷却（秒）<input name="global_dialogue_cooldown_seconds" type="number" min="0" max="86400" value="<?= (int)($townNpcGlobalPolicy['dialogue_cooldown_seconds'] ?? 60) ?>"></label>
<label>全局每小时最大决策<input name="global_max_decisions_per_hour" type="number" min="1" max="10000" value="<?= (int)($townNpcGlobalPolicy['max_decisions_per_hour'] ?? 60) ?>"></label>
<button type="submit">保存全局频次</button>
</form>
</section>
<section class="card" style="margin:12px 0">
<h3>故事事件策略</h3>
<p class="npc-sub">Worker 每个小镇日按此策略生成规则事件；模型事件仍需经过服务器审核。费用金额报表不参与此策略。</p>
<form method="post" class="form-row">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_story_event_policy_save">
<input type="hidden" name="panel" value="town_npcs">
<label><input name="story_enabled" type="checkbox" value="1" <?= !empty($townStoryPolicy['enabled']) ? 'checked' : '' ?>>启用故事事件</label>
<label>每日最少事件<input name="daily_min_events" type="number" min="0" max="10" value="<?= (int)($townStoryPolicy['daily_min_events'] ?? 0) ?>"></label>
<label>每日最多事件<input name="daily_max_events" type="number" min="0" max="10" value="<?= (int)($townStoryPolicy['daily_max_events'] ?? 2) ?>"></label>
<label>事件冷却（分钟）<input name="cooldown_minutes" type="number" min="5" max="10080" value="<?= (int)($townStoryPolicy['cooldown_minutes'] ?? 240) ?>"></label>
<label>最多参与村民<input name="max_participants" type="number" min="2" max="10" value="<?= (int)($townStoryPolicy['max_participants'] ?? 5) ?>"></label>
<label><input name="condition_enabled" type="checkbox" value="1" <?= !empty($townStoryPolicy['condition_enabled']) ? 'checked' : '' ?>>允许条件触发</label>
<button type="submit">保存故事策略</button>
</form>
</section>
<section class="card" style="margin:12px 0">
<h3>最近故事事件</h3>
<?php if ($townStoryEvents === []): ?><p class="npc-sub">暂时没有故事事件记录。Worker 会按上面的策略生成；没有模型时仍使用规则模板。</p>
<?php else: ?><div class="table-scroll"><table><thead><tr><th>时间</th><th>标题</th><th>来源</th><th>状态</th><th>参与者</th><th>效果</th></tr></thead><tbody>
<?php foreach ($townStoryEvents as $storyEvent): ?><tr><td><?= admin_page_escape($storyEvent['occurred_at'] ?? '') ?></td><td><strong><?= admin_page_escape($storyEvent['title'] ?? '') ?></strong><br><small><?= admin_page_escape($storyEvent['summary'] ?? '') ?></small></td><td><?= ($storyEvent['generation_source'] ?? 'rule') === 'model' ? 'AI 草案' : '规则模板' ?></td><td><?= ($storyEvent['status'] ?? '') === 'resolved' ? '已结算' : '进行中' ?></td><td><?php $names = []; foreach (($storyEvent['participants'] ?? []) as $participant) $names[] = ($participant['name'] ?? $participant['npc_id'] ?? '') . '（' . ($participant['role'] ?? '参与者') . '）'; ?><?= admin_page_escape(implode('、', $names)) ?></td><td><?php $effectCount = count($storyEvent['effects'] ?? []); $appliedCount = 0; foreach (($storyEvent['effects'] ?? []) as $effect) if (!empty($effect['applied'])) $appliedCount++; ?><?= $appliedCount ?>/<?= $effectCount ?> 已应用</td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>
<form id="town-npc-bulk-form" method="post" class="form-row" style="margin:12px 0">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_bulk_toggle">
<input type="hidden" name="panel" value="town_npcs">
<label style="flex:0 0 auto">批量操作<select name="field">
<option value="agent_paused">Agent 状态</option>
<option value="enabled">村民状态</option>
</select></label>
<label style="flex:0 0 auto">设为<select name="value">
<option value="0">关闭 / 停用</option>
<option value="1">开启 / 启用</option>
</select></label>
<span class="npc-sel-bar">
<button type="button" data-npc-pick="all" title="勾选列表里的全部村民">全选</button>
<button type="button" data-npc-pick="invert" title="已勾选的取消，未勾选的勾上">反选</button>
<button type="button" data-npc-pick="none" title="清空全部勾选">清空</button>
<span class="npc-sel-count" data-npc-pick-count>已选 <b>0</b> 个</span>
</span>
<button type="submit">应用批量操作</button>
</form>
<?php foreach ($townNpcs as $npc): $profile = is_array($npc['profile'] ?? null) ? $npc['profile'] : [];
$runtime = is_array($npc['runtime'] ?? null) ? $npc['runtime'] : [];
$lifecycle = is_array($npc['lifecycle'] ?? null) ? $npc['lifecycle'] : ['state'=>'active','reason_code'=>'','expected_return_at'=>null,'revision'=>1];
$avatarId = (string)($npc['avatar_asset_id'] ?? '');
$personalityTraits = is_array($profile['personality']['traits'] ?? null) ? $profile['personality']['traits'] : [];
$npcValues = is_array($profile['values'] ?? null) ? $profile['values'] : [];
$npcFears = is_array($profile['fears'] ?? null) ? $profile['fears'] : [];
$entries = is_array($npc['schedule'] ?? null) ? array_values($npc['schedule']) : [];
$npcStats = is_array($npc['stats']['values'] ?? null) ? $npc['stats']['values'] : town_npc_stat_defaults();
$npcStatHistory = town_npc_stat_history_recent($pdo, (string)$npc['npc_id'], 12);
$rowCount = max(7, count($entries));
?>
<div class="npc-card" data-npc-id="<?= admin_page_escape($npc['npc_id']) ?>">
<div class="npc-card-head">
<input type="checkbox" form="town-npc-bulk-form" name="npc_ids[]" value="<?= admin_page_escape($npc['npc_id']) ?>" title="勾选后可参与批量操作" aria-label="选择村民 <?= admin_page_escape($npc['name']) ?>">
<button type="button" class="npc-fold" data-npc-toggle aria-expanded="false" title="展开或收起这个村民的全部资料"><span class="npc-fold-icon">▸</span><span class="npc-fold-label">展开</span></button>
<span class="name"><?= admin_page_escape($npc['name']) ?></span>
<span class="meta">
<span class="pill neutral"><?= admin_page_escape($occLabels[$npc['occupation']] ?? $npc['occupation']) ?></span>
<span>位置 <?= (int)($runtime['tile_x'] ?? 0) ?>,<?= (int)($runtime['tile_y'] ?? 0) ?></span>
<span class="pill"><?= admin_page_escape($stateLabels[$runtime['state'] ?? ''] ?? ($runtime['state'] ?? 'rest')) ?></span>
<?php if (($runtime['dialogue'] ?? '') !== ''): ?><small><?= admin_page_escape($runtime['dialogue']) ?></small><?php endif; ?>
</span>
<span class="head-actions">
<form method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_toggle">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<input type="hidden" name="field" value="agent_paused">
<input type="hidden" name="value" value="<?= $npc['agent_paused'] ? '0' : '1' ?>">
<button class="quiet"><?= $npc['agent_paused'] ? '恢复 Agent' : '暂停 Agent' ?></button>
</form>
<form method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_toggle">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<input type="hidden" name="field" value="enabled">
<input type="hidden" name="value" value="<?= $npc['enabled'] ? '0' : '1' ?>">
<button class="quiet"><?= $npc['enabled'] ? '停用' : '启用' ?></button>
</form>
<form method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_delete">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<button class="danger">删除</button>
</form>
</span>
</div>
<form method="post" class="form-row npc-lifecycle-form" style="padding:8px 12px;border-top:1px solid #edf1f7;border-bottom:1px solid #edf1f7">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_lifecycle_save">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<label>居民状态<select name="lifecycle_state">
<?php foreach (['active'=>'在岗','absent'=>'暂时离开','injured'=>'受伤','recovering'=>'恢复中','returning'=>'返回中'] as $lifecycleState => $lifecycleLabel): ?>
<option value="<?= $lifecycleState ?>" <?= $lifecycleState === ($lifecycle['state'] ?? 'active') ? 'selected' : '' ?>><?= $lifecycleLabel ?></option>
<?php endforeach; ?>
</select></label>
<label>原因<input name="reason_code" value="<?= admin_page_escape($lifecycle['reason_code'] ?? '') ?>" maxlength="64" placeholder="例如：外出办事"></label>
<label>预计恢复时间（UTC）<input name="expected_return_at" value="<?= admin_page_escape(str_replace(' ', 'T', substr((string)($lifecycle['expected_return_at'] ?? ''), 0, 16))) ?>" type="datetime-local"></label>
<button type="submit">保存居民状态</button>
</form>
<nav class="npc-tabs">
<button type="button" class="npc-tab active" data-tab="basic">基本资料</button>
<button type="button" class="npc-tab" data-tab="schedule">工作日程</button>
<button type="button" class="npc-tab" data-tab="home">住房</button>
<button type="button" class="npc-tab" data-tab="policy">决策策略</button>
<button type="button" class="npc-tab" data-tab="stats">个体数值</button>
<button type="button" class="npc-tab" data-tab="economy">经济</button>
<button type="button" class="npc-tab" data-tab="tools">道具</button>
<button type="button" class="npc-tab" data-tab="memory">记忆与任务</button>
</nav>
<div class="npc-tab-body">
<div class="npc-tab-panel active" data-panel="basic">
<p class="npc-sub">保存位置前会检查当前任务；日程 JSON 的 weekday 使用 0～6（周日到周六），时间使用分钟数。</p>
<form method="post" class="form-row">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_save">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<input type="hidden" name="set_position" value="1">
<label>姓名<input name="name" value="<?= admin_page_escape($npc['name']) ?>" maxlength="80" required></label>
<label>职业<select name="occupation">
<?php foreach (['farmer'=>'农夫','merchant'=>'商人','craftsman'=>'工匠'] as $occupation => $label): ?>
<option value="<?= $occupation ?>" <?= $occupation === $npc['occupation'] ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
</select></label>
<label>人物素材<select name="avatar_asset_id">
<option value="">默认样式</option>
<?php if ($avatarId !== '' && !array_filter($townCharacterAssets, static fn(array $asset): bool => (string)($asset['id'] ?? '') === $avatarId)): ?>
<option value="<?= admin_page_escape($avatarId) ?>" selected><?= admin_page_escape($avatarId) ?>（当前）</option>
<?php endif; ?>
<?php foreach ($townCharacterAssets as $asset): $assetId = (string)($asset['id'] ?? ''); ?>
<option value="<?= admin_page_escape($assetId) ?>" <?= $assetId === $avatarId ? 'selected' : '' ?>><?= admin_page_escape(($asset['name'] ?? $assetId) . ' · ' . $assetId) ?></option>
<?php endforeach; ?>
</select></label>
<label>当前位置 X/Y<span class="inline-inputs"><input name="tile_x" type="number" min="0" value="<?= (int)($runtime['tile_x'] ?? 0) ?>"><input name="tile_y" type="number" min="0" value="<?= (int)($runtime['tile_y'] ?? 0) ?>"></span></label>
<label>性格特征（逗号分隔）<input class="json-tags" data-target="personality" data-format="traits" value="<?= admin_page_escape(implode('、', array_map('strval', $personalityTraits))) ?>"><input type="hidden" name="personality" value="<?= admin_page_escape(admin_page_json($profile['personality'] ?? ['traits' => []])) ?>"></label>
<label>家庭背景<textarea name="family_background" rows="2"><?= admin_page_escape($profile['family_background'] ?? '') ?></textarea></label>
<label>短期目标<input name="short_term_goal" value="<?= admin_page_escape($profile['short_term_goal'] ?? '') ?>"></label>
<label>长期目标<input name="long_term_goal" value="<?= admin_page_escape($profile['long_term_goal'] ?? '') ?>"></label>
<label>说话方式<input name="speech_style" value="<?= admin_page_escape($profile['speech_style'] ?? '') ?>"></label>
<label>价值观（逗号分隔）<input class="json-tags" data-target="values" value="<?= admin_page_escape(implode('、', array_map('strval', $npcValues))) ?>"><input type="hidden" name="values" value="<?= admin_page_escape(admin_page_json($npcValues)) ?>"></label>
<label>恐惧（逗号分隔）<input class="json-tags" data-target="fears" value="<?= admin_page_escape(implode('、', array_map('strval', $npcFears))) ?>"><input type="hidden" name="fears" value="<?= admin_page_escape(admin_page_json($npcFears)) ?>"></label>
<label>外观 JSON<textarea name="appearance" rows="2"><?= admin_page_escape(admin_page_json($npc['appearance'] ?? [])) ?></textarea></label>
<label>日程 JSON<textarea name="schedule" rows="4"><?= admin_page_escape(admin_page_json($npc['schedule'] ?? [])) ?></textarea></label>
<button type="submit">保存人物资料</button>
</form>
</div>
<div class="npc-tab-panel" data-panel="schedule">
<p class="npc-sub">每行代表一个时间段。空白动作不会保存；保存时会按星期和开始时间排序，并拒绝结束时间早于或等于开始时间的行。</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_schedule_save">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<div class="table-scroll">
<table>
<thead><tr><th>星期</th><th>开始</th><th>结束</th><th>动作</th><th>目标 ID</th><th>工作内容</th><th>附加参数 JSON</th><th>优先级</th></tr></thead>
<tbody>
<?php for ($i = 0; $i < $rowCount; $i++): $entry = is_array($entries[$i] ?? null) ? $entries[$i] : []; ?>
<tr>
<td><select name="schedule_rows[<?= $i ?>][weekday]">
<?php foreach (['周日','周一','周二','周三','周四','周五','周六'] as $weekday => $label): ?>
<option value="<?= $weekday ?>" <?= (int)($entry['weekday'] ?? 0) === $weekday ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
</select></td>
<td><input type="time" name="schedule_rows[<?= $i ?>][start_minute]" value="<?= admin_page_escape(admin_page_time_value($entry['start_minute'] ?? 0)) ?>"></td>
<td><input type="time" name="schedule_rows[<?= $i ?>][end_minute]" value="<?= admin_page_escape(admin_page_time_value($entry['end_minute'] ?? 1440, 1440)) ?>"></td>
<td><select name="schedule_rows[<?= $i ?>][action]">
<option value="">空</option>
<?php foreach (['walk'=>'行走','work'=>'工作','rest'=>'休息','talk'=>'交谈','trade'=>'交易'] as $action => $label): ?>
<option value="<?= $action ?>" <?= $action === ($entry['action'] ?? '') ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
</select></td>
<td><input name="schedule_rows[<?= $i ?>][target_id]" value="<?= admin_page_escape($entry['target_id'] ?? '') ?>" placeholder="farm-zone-3"></td>
<td><input name="schedule_rows[<?= $i ?>][job]" value="<?= admin_page_escape($entry['job'] ?? '') ?>" placeholder="water_crop"></td>
<td><input name="schedule_rows[<?= $i ?>][payload]" value="<?= admin_page_escape(admin_page_json($entry['payload'] ?? [])) ?>" placeholder='{"item_id":"seed","quantity":1,"amount":10}'></td>
<td><input name="schedule_rows[<?= $i ?>][priority]" type="number" min="0" max="255" value="<?= (int)($entry['priority'] ?? 0) ?>" style="width:70px"></td>
</tr>
<?php endfor; ?>
</tbody>
</table>
</div>
<button type="submit" style="margin-top:8px">保存日程</button>
</form>
</div>
<div class="npc-tab-panel" data-panel="home">
<form method="post" class="form-row">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_home_save">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<?php $home = is_array($npc['home'] ?? null) ? $npc['home'] : []; ?>
<label>家素材 ID<input name="home_asset_id" value="<?= admin_page_escape((string)($home['home_asset_id'] ?? '')) ?>" maxlength="128" placeholder="town-house-01"></label>
<label>家素材名称<input name="home_asset_name" value="<?= admin_page_escape((string)($home['home_asset_name'] ?? '')) ?>" maxlength="160" placeholder="我的小屋"></label>
<label>家坐标 X/Y<span class="inline-inputs"><input name="home_tile_x" type="number" min="0" max="767" value="<?= (int)($home['tile_x'] ?? 0) ?>"><input name="home_tile_y" type="number" min="0" max="767" value="<?= (int)($home['tile_y'] ?? 0) ?>"></span></label>
<label>休息交互 JSON<textarea name="home_interaction" rows="2"><?= admin_page_escape(admin_page_json($home['interaction'] ?? ['radius' => 1])) ?></textarea></label>
<button type="submit">保存住房</button>
</form>
</div>
<div class="npc-tab-panel" data-panel="policy">
<form method="post" class="form-row">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_policy_save">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<?php $policy = is_array($npc['decision_policy_override'] ?? null) ? $npc['decision_policy_override'] : (is_array($npc['decision_policy'] ?? null) ? $npc['decision_policy'] : []); $effectivePolicy = is_array($npc['decision_policy'] ?? null) ? $npc['decision_policy'] : $policy; ?>
<p class="npc-sub">当前生效：决策 <?= (int)($effectivePolicy['decision_interval_seconds'] ?? 60) ?> 秒、观察 <?= (int)($effectivePolicy['observation_interval_seconds'] ?? 300) ?> 秒、对话冷却 <?= (int)($effectivePolicy['dialogue_cooldown_seconds'] ?? 60) ?> 秒、每小时 <?= (int)($effectivePolicy['max_decisions_per_hour'] ?? 60) ?> 次。保存的是本村民策略，仍受上方全局限制。</p>
<label>决策间隔（秒）<input name="decision_interval_seconds" type="number" min="5" max="86400" value="<?= (int)($policy['decision_interval_seconds'] ?? 60) ?>"></label>
<label>环境观察间隔（秒）<input name="observation_interval_seconds" type="number" min="5" max="86400" value="<?= (int)($policy['observation_interval_seconds'] ?? 300) ?>"></label>
<label>对话冷却（秒）<input name="dialogue_cooldown_seconds" type="number" min="0" max="86400" value="<?= (int)($policy['dialogue_cooldown_seconds'] ?? 60) ?>"></label>
<label>每小时最大决策<input name="max_decisions_per_hour" type="number" min="1" max="10000" value="<?= (int)($policy['max_decisions_per_hour'] ?? 60) ?>"></label>
<button type="submit">保存调用频次</button>
</form>
</div>
<div class="npc-tab-panel" data-panel="stats">
<p class="npc-sub">所有个体数值为 1～100。普通事件通常变化 1～5，重大事件变化 5～15。手动调整必须填写原因，系统会保留变化历史。</p>
<form method="post" class="form-row">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_stats_save">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<?php $statLabels = ['mood'=>'心情','health'=>'健康','stamina'=>'体能','labor'=>'劳动','academics'=>'学业','culture'=>'文化','social'=>'社交','wealth'=>'财富','stress'=>'压力','reputation'=>'声望']; foreach ($statLabels as $statKey => $statLabel): ?>
<label><?= $statLabel ?><input name="<?= $statKey ?>" type="number" min="1" max="100" value="<?= (int)($npcStats[$statKey] ?? 50) ?>"></label>
<?php endforeach; ?>
<label style="min-width:240px">调整原因<input name="stat_reason" maxlength="255" placeholder="例如：管理员初始化村民状态" required></label>
<button type="submit">保存个体数值</button>
</form>
<div class="table-scroll" style="margin-top:8px"><table><thead><tr><th>维度</th><th>分数</th><th>等级</th></tr></thead><tbody>
<?php foreach ($statLabels as $statKey => $statLabel): $statValue = (int)($npcStats[$statKey] ?? 50); ?><tr><td><?= $statLabel ?></td><td><?= $statValue ?></td><td><?= admin_page_escape(town_npc_stat_level($statValue, $statKey)) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<details style="margin-top:8px"><summary>最近数值变化（<?= count($npcStatHistory) ?>）</summary>
<?php if ($npcStatHistory === []): ?><p class="npc-sub">暂无变化记录。</p><?php else: ?><div class="table-scroll"><table><thead><tr><th>时间</th><th>来源</th><th>变化</th><th>原因</th></tr></thead><tbody>
<?php foreach (array_reverse($npcStatHistory) as $change): $deltaParts = []; foreach (($change['delta'] ?? []) as $key => $delta) $deltaParts[] = ($statLabels[$key] ?? $key) . ' ' . ((int)$delta > 0 ? '+' : '') . (int)$delta; ?><tr><td><?= admin_page_escape((string)($change['created_at'] ?? '')) ?></td><td><?= admin_page_escape((string)($change['source_type'] ?? '')) ?></td><td><?= admin_page_escape(implode('、', $deltaParts)) ?></td><td><?= admin_page_escape((string)($change['reason'] ?? '')) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></details>
</div>
<div class="npc-tab-panel" data-panel="economy">
<form method="post" class="form-row">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_economy_save">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<label>NPC 余额<input name="wallet_balance" type="number" min="0" max="9223372036854775807" value="<?= (int)($npc['economy']['balance'] ?? 0) ?>"></label>
<label>NPC 交易库存 JSON<textarea name="economy_inventory" rows="2"><?php $economyInventory = is_array($npc['economy']['inventory'] ?? null) ? $npc['economy']['inventory'] : [];
$economyInventoryMap = [];
foreach ($economyInventory as $inventoryRow) if (is_array($inventoryRow) && isset($inventoryRow['item_id'])) $economyInventoryMap[(string)$inventoryRow['item_id']] = (int)($inventoryRow['quantity'] ?? 0);
?><?= admin_page_escape(admin_page_json($economyInventoryMap)) ?></textarea></label>
<button type="submit">保存经济初始化</button>
</form>
</div>
<div class="npc-tab-panel" data-panel="tools">
<form method="post" class="form-row">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_inventory_save">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<label>梯子数量<input name="ladder_quantity" type="number" min="0" max="9999" value="<?= (int)(array_column($runtime['inventory'] ?? [], 'quantity', 'item_id')['ladder'] ?? 0) ?>"></label>
<label>桥梁工具数量<input name="bridge_kit_quantity" type="number" min="0" max="9999" value="<?= (int)(array_column($runtime['inventory'] ?? [], 'quantity', 'item_id')['bridge_kit'] ?? 0) ?>"></label>
<button type="submit">保存道具库存</button>
</form>
</div>
<div class="npc-tab-panel" data-panel="memory">
<div class="actions" style="margin-top:0">
<form method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_clear_memory">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<button class="quiet">清除长期记忆</button>
</form>
</div>
<details>
<summary>强制分配任务</summary>
<form method="post" class="form-row" style="margin-top:8px">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_force_task">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($npc['npc_id']) ?>">
<label>动作<select name="force_action">
<option value="walk">行走</option>
<option value="work">工作</option>
<option value="talk">交谈</option>
<option value="rest">休息</option>
</select></label>
<label>目标 ID<input name="force_target_id" placeholder="farm-zone-3"></label>
<label>目标 X/Y<span class="inline-inputs"><input name="force_tile_x" type="number" min="0" value="<?= (int)($runtime['tile_x'] ?? 0) ?>"><input name="force_tile_y" type="number" min="0" value="<?= (int)($runtime['tile_y'] ?? 0) ?>"></span></label>
<label>工作内容<input name="force_job" placeholder="water_crop"></label>
<label>持续分钟<input name="force_duration_minutes" type="number" min="0" max="1440" value="20"></label>
<label>对话<input name="force_dialogue" maxlength="500"></label>
<button type="submit">分配任务</button>
</form>
</details>
</div>
</div>
</div>
<?php endforeach; ?>
