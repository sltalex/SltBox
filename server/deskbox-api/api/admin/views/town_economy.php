<div class="panel" id="town-npc-economy-panel" style="grid-column:1/-1">
<h2>村民经济交易记录</h2>
<p class="muted">玩家不参与小镇经济；这里查看 NPC 初始化余额、工作收入和村民之间的交易。</p>
<div class="table-scroll">
<table>
<thead><tr><th>时间</th><th>卖方</th><th>买方</th><th>物品</th><th>数量</th><th>金额</th><th>原因</th></tr></thead>
<tbody>
<?php if ($townNpcTransactions === []): ?>
<tr><td colspan="7" class="muted">暂无 NPC 交易记录</td></tr>
<?php else: foreach ($townNpcTransactions as $transaction): ?>
<tr>
<td><?= admin_page_escape((string)$transaction['created_at']) ?></td>
<td><?= admin_page_escape((string)($transaction['seller_name'] ?? $transaction['seller_npc_id'])) ?></td>
<td><?= admin_page_escape((string)($transaction['buyer_name'] ?? $transaction['buyer_npc_id'])) ?></td>
<td><?= admin_page_escape((string)$transaction['item_id']) ?></td>
<td><?= (int)$transaction['quantity'] ?></td>
<td><?= (int)$transaction['amount'] ?></td>
<td><?= admin_page_escape((string)$transaction['reason']) ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
