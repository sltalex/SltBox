<div class="panel" id="town-npc-social-panel" style="grid-column:1/-1">
<h2>关系与长期记忆</h2>
<p class="muted">这里显示服务器已经沉淀的 NPC 对话关系和记忆。关系按方向保存，因此一次交谈会看到双方各一条记录；清除操作在村民卡片的「记忆与任务」标签页执行。</p>
<form method="get" class="form-row">
<input type="hidden" name="panel" value="town_npcs">
<label>按村民筛选<select name="social_npc_id">
<option value="">全部村民</option>
<?php foreach ($townNpcs as $npc): ?>
<option value="<?= admin_page_escape($npc['npc_id']) ?>" <?= $townNpcSocialNpcId === $npc['npc_id'] ? 'selected' : '' ?>><?= admin_page_escape($npc['name']) ?></option>
<?php endforeach; ?>
</select></label>
<button class="quiet" type="submit">筛选</button>
</form>
<h3>村民关系</h3>
<nav class="actions">
<span class="muted">第 <?= $townNpcRelationPage + 1 ?> 页</span>
<?php if ($townNpcRelationPage > 0): ?>
<a href="?panel=town_npcs&amp;social_npc_id=<?= rawurlencode((string)$townNpcSocialNpcId) ?>&amp;relationship_page=<?= $townNpcRelationPage - 1 ?>&amp;memory_page=<?= $townNpcMemoryPage ?>">上一页</a>
<?php endif; ?>
<?php if (count($townNpcRelationships) >= $townNpcSocialPageSize): ?>
<a href="?panel=town_npcs&amp;social_npc_id=<?= rawurlencode((string)$townNpcSocialNpcId) ?>&amp;relationship_page=<?= $townNpcRelationPage + 1 ?>&amp;memory_page=<?= $townNpcMemoryPage ?>">下一页</a>
<?php endif; ?>
</nav>
<div class="table-scroll">
<table data-no-paginate>
<thead><tr><th>村民</th><th>对象</th><th>好感</th><th>最近互动</th><th>更新时间</th><th>管理</th></tr></thead>
<tbody>
<?php if ($townNpcRelationships === []): ?>
<tr><td colspan="6" class="muted">暂无关系记录</td></tr>
<?php else: foreach ($townNpcRelationships as $relationship): $relationshipMeta = is_array($relationship['relationship'] ?? null) ? $relationship['relationship'] : []; ?>
<tr>
<td><?= admin_page_escape((string)($relationship['npc_name'] ?? $relationship['npc_id'])) ?></td>
<td><?= admin_page_escape((string)($relationship['other_name'] ?? $relationship['other_npc_id'])) ?></td>
<td><span class="pill"><?= (int)$relationship['affinity'] ?></span></td>
<td><?= admin_page_escape((string)($relationshipMeta['last_interaction'] ?? '')) ?><?php if (!empty($relationshipMeta['last_dialogue'])): ?><br><small><?= admin_page_escape((string)$relationshipMeta['last_dialogue']) ?></small><?php endif; ?></td>
<td><?= admin_page_escape((string)($relationship['updated_at'] ?? '')) ?></td>
<td>
<form method="post" class="form-row">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="town_npc_relationship_save">
<input type="hidden" name="panel" value="town_npcs">
<input type="hidden" name="npc_id" value="<?= admin_page_escape($relationship['npc_id']) ?>">
<input type="hidden" name="other_npc_id" value="<?= admin_page_escape($relationship['other_npc_id']) ?>">
<input name="affinity" type="number" min="-100" max="100" value="<?= (int)$relationship['affinity'] ?>" style="width:74px">
<button class="quiet" type="submit">保存双方好感</button>
</form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<h3>最近长期记忆</h3>
<nav class="actions">
<span class="muted">第 <?= $townNpcMemoryPage + 1 ?> 页</span>
<?php if ($townNpcMemoryPage > 0): ?>
<a href="?panel=town_npcs&amp;social_npc_id=<?= rawurlencode((string)$townNpcSocialNpcId) ?>&amp;relationship_page=<?= $townNpcRelationPage ?>&amp;memory_page=<?= $townNpcMemoryPage - 1 ?>">上一页</a>
<?php endif; ?>
<?php if (count($townNpcMemories) >= $townNpcSocialPageSize): ?>
<a href="?panel=town_npcs&amp;social_npc_id=<?= rawurlencode((string)$townNpcSocialNpcId) ?>&amp;relationship_page=<?= $townNpcRelationPage ?>&amp;memory_page=<?= $townNpcMemoryPage + 1 ?>">下一页</a>
<?php endif; ?>
</nav>
<div class="table-scroll">
<table data-no-paginate>
<thead><tr><th>时间</th><th>村民</th><th>类型</th><th>重要度</th><th>内容</th><th>过期时间</th></tr></thead>
<tbody>
<?php if ($townNpcMemories === []): ?>
<tr><td colspan="6" class="muted">暂无长期记忆</td></tr>
<?php else: foreach ($townNpcMemories as $memory): ?>
<tr>
<td><?= admin_page_escape((string)$memory['created_at']) ?></td>
<td><?= admin_page_escape((string)($memory['npc_name'] ?? $memory['npc_id'])) ?></td>
<td><span class="pill"><?= admin_page_escape((string)$memory['memory_type']) ?></span></td>
<td><?= (int)$memory['importance'] ?></td>
<td><?= admin_page_escape((string)$memory['content']) ?></td>
<td><?= admin_page_escape((string)($memory['expires_at'] ?? '')) ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
