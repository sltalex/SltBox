<?php
$storyState = is_array($townStoryNextPreview['generation'] ?? null) ? $townStoryNextPreview['generation'] : [];
$storyPolicy = is_array($townStoryPolicy ?? null) ? $townStoryPolicy : [];
$storyStatLabels = ['mood'=>'心情','health'=>'健康','stamina'=>'体能','labor'=>'劳动','academics'=>'学业','culture'=>'文化','social'=>'社交','wealth'=>'财富','stress'=>'压力','reputation'=>'声望'];
$storyActionLabels = ['walk'=>'行走','work'=>'工作','rest'=>'休息','talk'=>'交谈','trade'=>'交易'];
$storyStatusLabels = ['active'=>'进行中','resolved'=>'已结束','success'=>'成功','partial'=>'部分完成','expired'=>'已过期','joined'=>'已加入','executing'=>'执行中','completed'=>'已完成','blocked'=>'受阻','failed'=>'失败'];
$storySourceLabels = ['rule'=>'规则','model'=>'AI 模型','manual'=>'手工'];
$storyToneLabels = ['positive'=>'正面','negative'=>'负面','mixed'=>'混合'];
$storyJsonText = static function (mixed $value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
};
$storyDeltaText = static function (array $delta) use ($storyStatLabels): string {
    $parts = [];
    foreach ($delta as $key => $value) $parts[] = ($storyStatLabels[$key] ?? $key) . ' ' . ((int)$value > 0 ? '+' : '') . (int)$value;
    return $parts === [] ? '无数值变化' : implode('、', $parts);
};
?>
<style>
.story-toolbar{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.story-toolbar .muted{max-width:760px}
.story-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:12px 0 16px}
.story-summary-grid .card{min-height:76px}
.story-event{margin:0 0 14px;padding:0;border:1px solid var(--border);border-radius:var(--radius);background:var(--surface);box-shadow:var(--shadow-sm);overflow:hidden}
.story-event>summary{padding:13px 16px;background:var(--surface-2);font-weight:600}
.story-event[open]>summary{border-bottom:1px solid var(--border)}
.story-event-body{padding:14px 16px}
.story-event-meta{display:flex;gap:7px;align-items:center;flex-wrap:wrap;color:var(--text-2);font-size:12px;margin-bottom:10px}
.story-event-title{font-size:15px;font-weight:650;color:var(--text)}
.story-story{padding:10px 12px;border-left:3px solid var(--brand);border-radius:6px;background:#f7faff;color:var(--text-2);white-space:pre-wrap}
.story-beats{margin:8px 0 0;padding-left:20px;color:var(--text-2)}
.story-person{display:grid;grid-template-columns:minmax(120px,.7fr) minmax(0,1.3fr);gap:8px;padding:9px 0;border-bottom:1px solid #f0f3f9}
.story-person:last-child{border-bottom:0}
.story-person strong{color:var(--text)}
.story-person small{display:block;color:var(--text-3);margin-top:2px}
.story-forecast{margin-top:14px}
.story-forecast-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.story-forecast-card{padding:11px 12px;border:1px solid var(--border);border-radius:10px;background:#fff}
.story-forecast-card h4{margin:0 0 4px;font-size:13px}
.story-forecast-card p{font-size:12px;color:var(--text-2);margin:0 0 6px}
.story-muted{font-size:12px;color:var(--text-3)}
.story-code{margin:6px 0 0;padding:8px;max-height:160px;overflow:auto;border-radius:7px;background:#f7f9fc;border:1px solid var(--border);font:11px/1.45 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-wrap}
@media (max-width:760px){.story-person{grid-template-columns:1fr}.story-event-body{padding:12px}}
</style>
<section class="panel" id="town-events-panel" style="grid-column:1/-1">
<div class="story-toolbar">
<div><h2>共享小镇事件中心</h2><p class="muted">查看每次事件的完整叙事、参与 NPC、执行任务、数值影响和记忆内容。自动事件的具体主题可能由 Worker 的规则或模型决定；管理员也可以手工立即生成一个事件。</p></div>
<form method="post" class="form-row" style="flex:0 0 auto">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_story_event_generate_manual">
<input type="hidden" name="panel" value="town_events">
<label style="min-width:190px">事件模板<select name="story_template_key"><option value="">随机选择</option><?php foreach (town_story_event_templates() as $template): ?><option value="<?= admin_page_escape((string)$template['key']) ?>"><?= admin_page_escape((string)$template['title']) ?></option><?php endforeach; ?></select></label>
<label style="min-width:100px">随机种子<input name="story_seed" type="number" min="1" placeholder="自动"></label>
<button type="submit">手工生成事件</button>
</form>
</div>
<div class="story-summary-grid">
<div class="card"><div class="metric"><?= count($townStoryAdminEvents) ?></div><div class="muted">已记录事件</div></div>
<div class="card"><div class="metric"><?= (int)($storyState['daily_count'] ?? 0) ?>/<?= (int)($storyPolicy['daily_max_events'] ?? 0) ?></div><div class="muted">今日事件数 / 上限</div></div>
<div class="card"><div class="metric"><?= !empty($storyPolicy['enabled']) ? '开启' : '关闭' ?></div><div class="muted">自动生成</div></div>
<div class="card"><div class="metric"><?= admin_page_escape((string)($townStoryNextPreview['earliest_at'] ?? '未知')) ?></div><div class="muted">最早允许生成</div></div>
</div>
<?php if ($townStoryNextPreview !== null): ?>
<div class="notice"><strong>下一事件预测：</strong><?= admin_page_escape((string)($townStoryNextPreview['note'] ?? '自动事件主题将在生成时确定。')) ?><br><span class="story-muted">最早时间：<?= admin_page_escape((string)($townStoryNextPreview['earliest_at'] ?? '未知')) ?>；冷却 <?= (int)($storyPolicy['cooldown_minutes'] ?? 0) ?> 分钟；每日上限 <?= (int)($storyPolicy['daily_max_events'] ?? 0) ?> 个。</span></div>
<div class="story-forecast"><h3>可能发生的事件与影响</h3><div class="story-forecast-grid">
<?php foreach (($townStoryNextPreview['candidates'] ?? []) as $candidate): ?><article class="story-forecast-card"><h4><?= admin_page_escape((string)($candidate['title'] ?? '')) ?></h4><p><?= admin_page_escape((string)($candidate['summary'] ?? '')) ?></p><div class="story-muted">类型：<?= admin_page_escape($storyToneLabels[(string)($candidate['tone'] ?? 'mixed')] ?? '混合') ?>；条件：<?= admin_page_escape((string)($candidate['condition'] ?? 'always')) ?>；预期影响：<?php $impact=[]; foreach (($candidate['effects'] ?? []) as $effect) $impact[]=$storyDeltaText(is_array($effect)?$effect:[]); ?><?= admin_page_escape(implode('；', $impact)) ?></div></article><?php endforeach; ?>
</div></div>
<?php endif; ?>
<h3 style="margin-top:20px">事件记录与详细过程</h3>
<?php if ($townStoryAdminEvents === []): ?><p class="story-muted">暂时没有事件记录。可以点击右上角“手工生成事件”创建第一条。</p><?php endif; ?>
<?php foreach ($townStoryAdminEvents as $event): $eventStory = is_array($event['admin_story'] ?? null) ? $event['admin_story'] : (is_array($event['story'] ?? null) ? $event['story'] : []); ?>
<details class="story-event" <?= ((string)($event['status'] ?? '') === 'active' && $event === $townStoryAdminEvents[0]) ? 'open' : '' ?>>
<summary><span class="story-event-title"><?= admin_page_escape((string)($event['title'] ?? '未命名事件')) ?></span><span class="story-muted"> · <?= admin_page_escape($storySourceLabels[(string)($event['generation_source'] ?? '')] ?? (string)($event['generation_source'] ?? '')) ?> · <?= admin_page_escape($storyStatusLabels[(string)($event['status'] ?? '')] ?? (string)($event['status'] ?? '')) ?></span></summary>
<div class="story-event-body">
<div class="story-event-meta"><span>发生：<?= admin_page_escape((string)($event['occurred_at'] ?? '')) ?></span><span>开始：<?= admin_page_escape((string)($event['starts_at'] ?? '')) ?></span><span>到期：<?= admin_page_escape((string)($event['expires_at'] ?? '')) ?></span><?php if (!empty($event['resolved_at'])): ?><span>结束：<?= admin_page_escape((string)$event['resolved_at']) ?></span><?php endif; ?><span>模板：<?= admin_page_escape((string)($event['template_key'] ?? '')) ?></span></div>
<p><?= admin_page_escape((string)($event['summary'] ?? '')) ?></p>
<?php if (($eventStory['opening'] ?? '') !== ''): ?><div class="story-story"><strong>事件描述：</strong><?= admin_page_escape((string)$eventStory['opening']) ?></div><?php endif; ?>
<?php if (($eventStory['location'] ?? '') !== ''): ?><p class="story-muted">地点：<?= admin_page_escape((string)$eventStory['location']) ?></p><?php endif; ?>
<?php if (is_array($eventStory['beats'] ?? null) && $eventStory['beats'] !== []): ?><ol class="story-beats"><?php foreach ($eventStory['beats'] as $beat): ?><li><?= admin_page_escape((string)$beat) ?></li><?php endforeach; ?></ol><?php endif; ?>
<h4>NPC 在事件中做了什么</h4>
<?php if (($event['participants'] ?? []) === []): ?><p class="story-muted">没有参与者记录。</p><?php else: ?><?php foreach ($event['participants'] as $participant): $task=is_array($participant['task']??null)?$participant['task']:[]; $outcome=is_array($participant['outcome']??null)?$participant['outcome']:[]; ?>
<div class="story-person"><div><strong><?= admin_page_escape((string)($participant['name'] ?? $participant['npc_id'])) ?></strong><small><?= admin_page_escape((string)($participant['role'] ?? '参与者')) ?> · <?= admin_page_escape($storyStatusLabels[(string)($participant['status'] ?? '')] ?? (string)($participant['status'] ?? '')) ?></small></div><div><div><?= !empty($task['action']) ? admin_page_escape($storyActionLabels[(string)$task['action']] ?? (string)$task['action']) : '观察/参与' ?><?php if (!empty($task['job'])): ?>：<?= admin_page_escape((string)$task['job']) ?><?php endif; ?><?php if (!empty($task['target_id'])): ?> · 目标 <?= admin_page_escape((string)$task['target_id']) ?><?php endif; ?></div><small>任务状态：<?= admin_page_escape($storyStatusLabels[(string)($task['status'] ?? '')] ?? (string)($task['status'] ?? '未分配')) ?><?php if (!empty($task['failure_reason'])): ?>；失败：<?= admin_page_escape((string)$task['failure_reason']) ?><?php endif; ?></small><?php if ($outcome !== []): ?><div class="story-code"><?= admin_page_escape($storyJsonText($outcome)) ?></div><?php endif; ?></div></div>
<?php endforeach; ?><?php endif; ?>
<h4>数值、关系和记忆影响</h4>
<?php if (($event['effects'] ?? []) === []): ?><p class="story-muted">暂无影响记录。</p><?php else: ?><?php foreach ($event['effects'] as $effect): $memory=is_array($effect['memory']??null)?$effect['memory']:[]; ?>
<div class="story-person"><div><strong><?= admin_page_escape((string)($effect['npc_id'] ?? '')) ?></strong><small><?= !empty($effect['applied']) ? '已结算' : '待结算' ?></small></div><div><div>数值：<?= admin_page_escape($storyDeltaText(is_array($effect['stat_delta']??null)?$effect['stat_delta']:[])) ?></div><?php if (($effect['relationship_delta'] ?? []) !== []): ?><div>关系：<?= admin_page_escape($storyJsonText($effect['relationship_delta'])) ?></div><?php endif; ?><?php if (($memory['content'] ?? '') !== ''): ?><div>记忆：<?= admin_page_escape((string)$memory['content']) ?></div><?php endif; ?></div></div>
<?php endforeach; ?><?php endif; ?>
</div></details>
<?php endforeach; ?>
</section>
