<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

/** Summarizes current holdings and distinct users who have ever obtained each item. */
function catalog_report(PDO $pdo): array
{
    fruit_ensure_schema($pdo);
    wildlife_ensure_schema($pdo);
    $catalogs = [
        'fruit' => array_map(static fn(array $item): array => [
            'id' => (string)$item['id'], 'name' => (string)$item['name'], 'category' => (string)$item['category'],
        ], $pdo->query('SELECT id,name,category FROM fruit_catalog ORDER BY atlas_index,id')->fetchAll(PDO::FETCH_ASSOC)),
        'aquarium' => array_values(array_map(static fn(array $item): array => [
            'id' => (string)$item['id'], 'name' => (string)$item['name'], 'category' => (string)$item['category'],
        ], array_filter(aquarium_catalog($pdo)['items'], static fn(array $item): bool => $item['category'] !== 'pack'))),
        'wildlife' => array_map(static fn(array $item): array => [
            'id' => (string)$item['id'], 'name' => (string)$item['name'], 'category' => (string)$item['category'],
        ], wildlife_catalog($pdo)),
    ];
    $queries = [
        'fruit' => [
            'SELECT fruit_id AS item_id,SUM(quantity) AS quantity,COUNT(DISTINCT user_id) AS holders FROM user_fruit_inventory WHERE quantity>0 GROUP BY fruit_id',
            'SELECT fruit_id AS item_id,COUNT(*) AS users FROM (SELECT user_id,fruit_id FROM user_fruit_discoveries UNION SELECT user_id,fruit_id FROM user_fruit_inventory WHERE quantity>0) AS seen GROUP BY fruit_id',
        ],
        'aquarium' => [
            'SELECT item_id,COUNT(*) AS quantity,COUNT(DISTINCT user_id) AS holders FROM aquarium_inventory GROUP BY item_id',
            "SELECT item_id,COUNT(*) AS users FROM (SELECT user_id,item_id FROM aquarium_inventory UNION SELECT user_id,item_id FROM aquarium_wallet_entries WHERE item_id IS NOT NULL AND reason IN ('purchase','pack_open')) AS seen GROUP BY item_id",
        ],
        'wildlife' => [
            'SELECT species_id AS item_id,SUM(quantity) AS quantity,COUNT(DISTINCT user_id) AS holders FROM wildlife_inventory WHERE quantity>0 GROUP BY species_id',
            'SELECT species_id AS item_id,COUNT(*) AS users FROM (SELECT user_id,species_id FROM wildlife_discoveries UNION SELECT user_id,species_id FROM wildlife_inventory WHERE quantity>0) AS seen GROUP BY species_id',
        ],
    ];
    $result = [];
    foreach ($catalogs as $type => $items) {
        $held = [];
        foreach ($pdo->query($queries[$type][0])->fetchAll(PDO::FETCH_ASSOC) as $row) $held[$row['item_id']] = $row;
        $seen = [];
        foreach ($pdo->query($queries[$type][1])->fetchAll(PDO::FETCH_ASSOC) as $row) $seen[$row['item_id']] = (int)$row['users'];
        $total = 0;
        $collected = 0;
        foreach ($items as &$item) {
            $id = $item['id'];
            $item['quantity'] = (int)($held[$id]['quantity'] ?? 0);
            $item['holders'] = (int)($held[$id]['holders'] ?? 0);
            $item['obtained_users'] = $seen[$id] ?? 0;
            $total += $item['quantity'];
            if ($item['obtained_users'] > 0) $collected++;
        }
        unset($item);
        $result[$type] = ['items' => $items, 'species' => count($items),
            'obtained_species' => $collected, 'quantity' => $total];
    }
    return $result;
}
