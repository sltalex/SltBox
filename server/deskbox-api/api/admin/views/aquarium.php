<div class="panel" id="aquarium-panel" style="grid-column:1/-1">
<h2>水族馆藏品图鉴 · <?= count($aquariumCatalog) ?> 项</h2>
<nav class="actions"><a href="?panel=aquarium">全部</a><?php foreach (array_unique(array_column($aquariumCatalog, 'category')) as $category): ?><a href="?panel=aquarium&amp;category=<?= rawurlencode($category) ?>"><?= admin_page_escape($aquariumCategories[$category] ?? $category) ?></a><?php endforeach; ?></nav>
<p class="muted">藏品目录沿用现有水族馆配置。卡包售价请到“商城管理”修改。</p>
<table><thead><tr><th>名称</th><th>ID</th><th>分类</th><th>金币</th><th>资源</th></tr></thead><tbody>
<?php foreach ($aquariumCatalog as $item): if ($aquariumCategory !== '' && $item['category'] !== $aquariumCategory) continue; ?><tr><td><?= admin_page_escape($item['name']) ?></td><td><?= admin_page_escape($item['id']) ?></td><td><?= admin_page_escape($aquariumCategories[$item['category']] ?? $item['category']) ?></td><td><?= (int)$item['price'] ?></td><td><?= admin_page_escape($item['asset_key']) ?></td></tr><?php endforeach; ?>
</tbody></table>