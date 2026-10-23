<?php
declare(strict_types=1);

const FRUIT_MANIFEST_PATH = __DIR__ . '/../../config/fruits.json';
const FRUIT_WEB_ASSET_PREFIX = '/assets/fruits/';

/**
 * Returns the reviewed individual-PNG file used by the web catalogue preview.
 * The mapping deliberately stays outside the mutable database catalogue so a
 * renamed fruit can never turn into an arbitrary URL or filesystem path.
 */
function fruit_web_asset_file(string $fruitId): ?string
{
    static $files = null;
    if ($files === null) {
        $files = [];
        try {
            $manifest = json_decode((string)file_get_contents(FRUIT_MANIFEST_PATH), true, 512, JSON_THROW_ON_ERROR);
            foreach ($manifest['items'] ?? [] as $item) {
                if (!is_array($item)) continue;
                $id = isset($item['id']) && is_string($item['id']) ? $item['id'] : '';
                $file = isset($item['assetFile']) && is_string($item['assetFile']) ? $item['assetFile'] : '';
                if (preg_match('/\A[a-z0-9-]{1,80}\z/D', $id) &&
                    preg_match('/\Afruit_r[0-9]{2}_c[0-9]{2}\.png\z/D', $file)) {
                    $files[$id] = $file;
                }
            }
        } catch (Throwable) {
            // The admin page remains usable even if a deployment omitted its
            // static catalogue mapping; it simply displays the empty preview.
        }
    }
    return $files[$fruitId] ?? null;
}

function fruit_web_asset_url(string $fruitId): ?string
{
    $file = fruit_web_asset_file($fruitId);
    return $file === null ? null : FRUIT_WEB_ASSET_PREFIX . rawurlencode($file);
}

function fruit_admin_update(PDO $pdo,array $post,DateTimeImmutable $now): void
{
    $id=isset($post['fruit_id']) && is_string($post['fruit_id']) ? trim($post['fruit_id']) : '';
    $probabilityText=isset($post['probability']) && is_string($post['probability']) ? trim($post['probability']) : '';
    $name=isset($post['fruit_name']) && is_string($post['fruit_name']) ? trim($post['fruit_name']) : '';
    $category=isset($post['category']) && is_string($post['category']) ? trim($post['category']) : '';
    $minText=isset($post['min_price']) && is_string($post['min_price']) ? trim($post['min_price']) : '';
    $maxText=isset($post['max_price']) && is_string($post['max_price']) ? trim($post['max_price']) : '';
    $categories=['常见水果','浆果','柑橘','热带水果','坚果','奇幻果实','特殊果实'];
    if(!preg_match('/\A[a-z0-9-]{1,80}\z/D',$id)
        || !preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?\z/D',$probabilityText)
        || (float)$probabilityText>999999
        || !preg_match('/\A(?:0|[1-9][0-9]{0,6})\z/D',$minText)
        || !preg_match('/\A(?:0|[1-9][0-9]{0,6})\z/D',$maxText)
        || (int)$maxText>1000000 || (int)$maxText<(int)$minText
        || $name==='' || strlen($name)>180 || !in_array($category,$categories,true))
        throw new ApiError('invalid_fruit','果实目录参数无效。',400);
    fruit_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
        $item=$pdo->prepare('SELECT id FROM fruit_catalog WHERE id=:id');$item->execute([':id'=>$id]);
        if($item->fetchColumn()===false)throw new ApiError('invalid_fruit','果实不存在。',404);
        $update=$pdo->prepare('UPDATE fruit_catalog SET name=:name,category=:category,enabled=:enabled,probability=:probability,min_price=:min,max_price=:max,updated_at=:updated WHERE id=:id');
        $update->execute([':name'=>$name,':category'=>$category,':enabled'=>isset($post['enabled'])&&$post['enabled']==='1'?1:0,
            ':probability'=>(float)$probabilityText,':min'=>(int)$minText,':max'=>(int)$maxText,
            ':updated'=>auth_sql_time($now),':id'=>$id]);
        if((int)$pdo->query('SELECT COUNT(*) FROM fruit_catalog WHERE enabled=1 AND probability>0')->fetchColumn()<1)
            throw new ApiError('invalid_probability','至少保留一种启用且概率大于 0 的果实。',400);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fruit_ensure_schema(PDO $pdo): void
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $types = $driver === 'sqlite'
        ? ['VARCHAR(80)','VARCHAR(120)','VARCHAR(40)','INTEGER','INTEGER','REAL','INTEGER','INTEGER','TEXT']
        : ['VARCHAR(80)','VARCHAR(120)','VARCHAR(40)','INT','TINYINT','DECIMAL(12,4)','INT','INT','DATETIME'];
    $pdo->exec("CREATE TABLE IF NOT EXISTS fruit_catalog (id {$types[0]} PRIMARY KEY,name {$types[1]} NOT NULL,category {$types[2]} NOT NULL,atlas_index {$types[3]} NOT NULL,enabled {$types[4]} NOT NULL DEFAULT 1,probability {$types[5]} NOT NULL DEFAULT 1,min_price {$types[6]} NOT NULL DEFAULT 15,max_price {$types[7]} NOT NULL DEFAULT 20,created_at {$types[8]} NOT NULL,updated_at {$types[8]} NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_fruit_inventory (user_id VARCHAR(64) NOT NULL,fruit_id VARCHAR(80) NOT NULL,quantity BIGINT NOT NULL DEFAULT 0,discovered_at {$types[8]} NOT NULL,updated_at {$types[8]} NOT NULL,PRIMARY KEY(user_id,fruit_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_fruit_discoveries (user_id VARCHAR(64) NOT NULL,fruit_id VARCHAR(80) NOT NULL,discovered_at {$types[8]} NOT NULL,PRIMARY KEY(user_id,fruit_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fruit_daily_prices (fruit_id VARCHAR(80) NOT NULL,price_day DATE NOT NULL,price INT NOT NULL,PRIMARY KEY(fruit_id,price_day))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fruit_sale_operations (user_id VARCHAR(64) NOT NULL,operation_id VARCHAR(128) NOT NULL,fruit_id VARCHAR(80) NOT NULL,quantity BIGINT NOT NULL,unit_price INT NOT NULL,total BIGINT NOT NULL,created_at {$types[8]} NOT NULL,PRIMARY KEY(user_id,operation_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fruit_pick_operations (user_id VARCHAR(64) NOT NULL,operation_id VARCHAR(128) NOT NULL,pending_id BIGINT NOT NULL,fruit_id VARCHAR(80) NOT NULL,quantity INT NOT NULL,created_at {$types[8]} NOT NULL,PRIMARY KEY(user_id,operation_id))");
    // The first pilot build did not persist the pending tree-fruit row. Add the
    // column in place so existing installations keep their idempotency records.
    $columns = $driver === 'sqlite'
        ? array_map(static fn(array $row): string => (string)$row['name'], $pdo->query('PRAGMA table_info(fruit_pick_operations)')->fetchAll(PDO::FETCH_ASSOC))
        : array_map('strval', $pdo->query('SHOW COLUMNS FROM fruit_pick_operations')->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('pending_id', $columns, true)) {
        $pdo->exec('ALTER TABLE fruit_pick_operations ADD COLUMN pending_id BIGINT NOT NULL DEFAULT 0');
    }
    $pdo->exec($driver === 'sqlite'
        ? "CREATE TABLE IF NOT EXISTS user_fruit_pending (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id VARCHAR(64) NOT NULL,fruit_id VARCHAR(80) NOT NULL,created_at {$types[8]} NOT NULL)"
        : "CREATE TABLE IF NOT EXISTS user_fruit_pending (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id VARCHAR(64) NOT NULL,fruit_id VARCHAR(80) NOT NULL,created_at {$types[8]} NOT NULL,INDEX idx_fruit_pending_user(user_id,id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_fruit_legacy (user_id VARCHAR(64) PRIMARY KEY,remaining BIGINT NOT NULL DEFAULT 0,created_at {$types[8]} NOT NULL)");
    $count = (int)$pdo->query('SELECT COUNT(*) FROM fruit_catalog')->fetchColumn();
    if ($count > 0 || !is_file(FRUIT_MANIFEST_PATH)) return;
    $manifest = json_decode((string)file_get_contents(FRUIT_MANIFEST_PATH), true, 512, JSON_THROW_ON_ERROR);
    $now = auth_sql_time(api_clock());
    $insert = $pdo->prepare('INSERT INTO fruit_catalog (id,name,category,atlas_index,enabled,probability,min_price,max_price,created_at,updated_at) VALUES (:id,:name,:category,:atlas,1,:prob,:min,:max,:created,:updated)');
    foreach ($manifest['items'] ?? [] as $item) $insert->execute([':id'=>$item['id'],':name'=>$item['name'],':category'=>$item['category'],':atlas'=>(int)$item['atlasIndex'],':prob'=>(float)$item['probability'],':min'=>(int)$item['minPrice'],':max'=>(int)$item['maxPrice'],':created'=>$now,':updated'=>$now]);
}


function fruit_daily_price(PDO $pdo, array $item, string $day): int
{
    $q = $pdo->prepare('SELECT price FROM fruit_daily_prices WHERE fruit_id=:id AND price_day=:day'); $q->execute([':id'=>$item['id'],':day'=>$day]);
    $existing = $q->fetchColumn(); if ($existing !== false) return (int)$existing;
    $min=(int)$item['min_price']; $max=(int)$item['max_price']; $price=$min === $max ? $min : random_int($min,$max);
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' ? 'INSERT OR IGNORE INTO fruit_daily_prices (fruit_id,price_day,price) VALUES (:id,:day,:price)' : 'INSERT IGNORE INTO fruit_daily_prices (fruit_id,price_day,price) VALUES (:id,:day,:price)';
    $pdo->prepare($sql)->execute([':id'=>$item['id'],':day'=>$day,':price'=>$price]);
    $q->execute([':id'=>$item['id'],':day'=>$day]);
    return (int)$q->fetchColumn();
}

function fruit_catalog_read(PDO $pdo): array
{
    fruit_ensure_schema($pdo); $day=api_clock()->format('Y-m-d'); $items=[];
    $cutoff=(new DateTimeImmutable($day . 'T00:00:00Z'))->modify('-6 days')->format('Y-m-d');
    $pdo->prepare('DELETE FROM fruit_daily_prices WHERE price_day < :day')->execute([':day'=>$cutoff]);
    foreach ($pdo->query('SELECT * FROM fruit_catalog ORDER BY atlas_index,id')->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $item['atlas_index']=(int)$item['atlas_index']; $item['enabled']=(bool)$item['enabled']; $item['probability']=(float)$item['probability']; $item['min_price']=(int)$item['min_price']; $item['max_price']=(int)$item['max_price']; $item['current_price']=fruit_daily_price($pdo,$item,$day); $items[]=$item;
    }
    return ['columns'=>20,'rows'=>10,'cell_width'=>176,'cell_height'=>233.6,'items'=>$items];
}

function fruit_state_read(PDO $pdo, string $userId): array
{
    fruit_ensure_schema($pdo); $day=api_clock()->format('Y-m-d'); $catalog=fruit_catalog_read($pdo); $seen=[];
    $q=$pdo->prepare('SELECT fruit_id,quantity FROM user_fruit_inventory WHERE user_id=:user'); $q->execute([':user'=>$userId]); foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$seen[$row['fruit_id']]=(int)$row['quantity'];
    $d=$pdo->prepare('SELECT fruit_id FROM user_fruit_discoveries WHERE user_id=:user');$d->execute([':user'=>$userId]);$discovered=[];foreach($d->fetchAll(PDO::FETCH_COLUMN) as $id)$discovered[(string)$id]=true;
    $history=[];$h=$pdo->prepare('SELECT fruit_id,price_day,price FROM fruit_daily_prices WHERE fruit_id=:id AND price_day >= :day ORDER BY price_day');
    $cutoff=(new DateTimeImmutable($day . 'T00:00:00Z'))->modify('-6 days')->format('Y-m-d');
    foreach($catalog['items'] as $item){$h->execute([':id'=>$item['id'],':day'=>$cutoff]);$history[$item['id']]=array_map(fn($x)=>['day'=>$x['price_day'],'price'=>(int)$x['price']],$h->fetchAll(PDO::FETCH_ASSOC));}
    return ['catalog'=>$catalog['items'],'inventory'=>array_map(fn($i)=>['fruit_id'=>$i['id'],'quantity'=>$seen[$i['id']]??0,'discovered'=>isset($discovered[$i['id']])],$catalog['items']),'history'=>$history,'on_tree'=>fruit_on_tree($pdo,$userId)];
}

function fruit_weighted_pick(PDO $pdo): ?array
{
    $items=$pdo->query('SELECT * FROM fruit_catalog WHERE enabled=1 AND probability>0 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); $total=array_sum(array_map(fn($x)=>(float)$x['probability'],$items)); if($total<=0)return null; $needle=(random_int(0,PHP_INT_MAX)/PHP_INT_MAX)*$total; foreach($items as $item){$needle-=(float)$item['probability'];if($needle<=0)return $item;} return $items[array_key_last($items)]??null;
}

function fruit_record_spawn(PDO $pdo,string $userId,int $quantity,DateTimeImmutable $now): void
{
    if($quantity<=0)return;
    $insert=$pdo->prepare('INSERT INTO user_fruit_pending (user_id,fruit_id,created_at) VALUES (:user,:fruit,:created)');
    for($i=0;$i<$quantity;$i++){
        $item=fruit_weighted_pick($pdo);if(!$item)break;
        $insert->execute([':user'=>$userId,':fruit'=>$item['id'],':created'=>auth_sql_time($now)]);
    }
}

function fruit_prepare_legacy(PDO $pdo,string $userId,int $onTree,DateTimeImmutable $now): void
{
    $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
        ? 'INSERT OR IGNORE INTO user_fruit_legacy (user_id,remaining,created_at) VALUES (:user,:remaining,:created)'
        : 'INSERT IGNORE INTO user_fruit_legacy (user_id,remaining,created_at) VALUES (:user,:remaining,:created)';
    $pdo->prepare($sql)->execute([':user'=>$userId,':remaining'=>max(0,$onTree),':created'=>auth_sql_time($now)]);
}

function fruit_record_harvest(PDO $pdo,string $userId,int $quantity,DateTimeImmutable $now): void
{
    if($quantity<=0)return;
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $legacy=$pdo->prepare('SELECT remaining FROM user_fruit_legacy WHERE user_id=:user'.($sqlite?'':' FOR UPDATE'));
    $legacy->execute([':user'=>$userId]);
    $legacyRemaining=(int)$legacy->fetchColumn();
    $skip=min($quantity,$legacyRemaining);
    if($skip>0){
        $pdo->prepare('UPDATE user_fruit_legacy SET remaining=remaining-:used WHERE user_id=:user')->execute([':used'=>$skip,':user'=>$userId]);
        $quantity-=$skip;
    }
    $pending=$pdo->prepare('SELECT id,fruit_id FROM user_fruit_pending WHERE user_id=:user ORDER BY id LIMIT 1'.($sqlite?'':' FOR UPDATE'));
    $delete=$pdo->prepare('DELETE FROM user_fruit_pending WHERE id=:id');
    $insert=$pdo->prepare('INSERT INTO user_fruit_inventory (user_id,fruit_id,quantity,discovered_at,updated_at) VALUES (:user,:fruit,1,:created,:updated) '.($sqlite
        ? 'ON CONFLICT(user_id,fruit_id) DO UPDATE SET quantity=quantity+excluded.quantity,updated_at=excluded.updated_at'
        : 'ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity),updated_at=VALUES(updated_at)'));
    $discover=$pdo->prepare(($sqlite?'INSERT OR IGNORE':'INSERT IGNORE').' INTO user_fruit_discoveries (user_id,fruit_id,discovered_at) VALUES (:user,:fruit,:created)');
    for($i=0;$i<$quantity;$i++){
        $pending->execute([':user'=>$userId]);$row=$pending->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))break; // Pre-atlas fruit is intentionally not migrated.
        $delete->execute([':id'=>$row['id']]);
        $args=[':user'=>$userId,':fruit'=>$row['fruit_id'],':created'=>auth_sql_time($now)];
        $insert->execute($args+[':updated'=>auth_sql_time($now)]);
        $discover->execute($args);
    }
}

function fruit_on_tree(PDO $pdo,string $userId): array
{
    $q=$pdo->prepare('SELECT id,fruit_id FROM user_fruit_pending WHERE user_id=:user ORDER BY id');
    $q->execute([':user'=>$userId]);
    return array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'fruit_id'=>(string)$row['fruit_id']],$q->fetchAll(PDO::FETCH_ASSOC));
}

function fruit_pick(PDO $pdo,string $userId,array $body,callable $clock): array
{
    $operationId=data_string($body,'operation_id');
    if(!preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D',$operationId)) throw new ApiError('invalid_operation','A stable operation identifier is required.',400);
    $pendingId=data_nonnegative_int($body,'pending_id');
    if($pendingId<1) throw new ApiError('invalid_fruit_pick','A tree fruit identifier is required.',400);
    fruit_ensure_schema($pdo); $now=$clock(); $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $pdo->beginTransaction();
    try {
        $tree=$pdo->prepare('SELECT fruit_total,harvested_total FROM tree_profiles WHERE user_id=:user'.($mysql?' FOR UPDATE':''));
        $tree->execute([':user'=>$userId]); $profile=$tree->fetch(PDO::FETCH_ASSOC);
        if(!is_array($profile)) throw new ApiError('tree_not_initialized','Tree progress is unavailable.',409);
        $prior=$pdo->prepare('SELECT pending_id,fruit_id,quantity FROM fruit_pick_operations WHERE user_id=:user AND operation_id=:operation');
        $prior->execute([':user'=>$userId,':operation'=>$operationId]); $old=$prior->fetch(PDO::FETCH_ASSOC);
        if(is_array($old)){
            if((int)$old['pending_id']!==$pendingId) throw new ApiError('operation_conflict','Operation id was used for a different fruit.',409);
            $pdo->commit(); return ['fruit_id'=>$old['fruit_id'],'quantity'=>(int)$old['quantity'],'duplicate'=>true];
        }
        $onTree=(int)$profile['fruit_total']-(int)$profile['harvested_total'];
        $pendingCountQuery=$pdo->prepare('SELECT COUNT(*) FROM user_fruit_pending WHERE user_id=:user');
        $pendingCountQuery->execute([':user'=>$userId]); $pendingCount=(int)$pendingCountQuery->fetchColumn();
        if($onTree<1 || $pendingCount<1 || $onTree<$pendingCount)
            throw new ApiError('fruit_state_conflict','Sync the tree before picking a typed fruit.',409);
        $pending=$pdo->prepare('SELECT id,fruit_id FROM user_fruit_pending WHERE user_id=:user AND id=:id'.($mysql?' FOR UPDATE':''));
        $pending->execute([':user'=>$userId,':id'=>$pendingId]); $row=$pending->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new ApiError('no_fruit_on_tree','No typed fruit is ready to pick.',409);
        $quantity=random_int(1,5); $fruitId=(string)$row['fruit_id'];
        $pdo->prepare('UPDATE tree_profiles SET fruit_total=fruit_total+:extra,harvested_total=harvested_total+:quantity,state_version=state_version+1,updated_at=:now WHERE user_id=:user')
            ->execute([':extra'=>$quantity-1,':quantity'=>$quantity,':now'=>auth_sql_time($now),':user'=>$userId]);
        $pdo->prepare('DELETE FROM user_fruit_pending WHERE id=:id')->execute([':id'=>$row['id']]);
        $args=[':user'=>$userId,':fruit'=>$fruitId,':created'=>auth_sql_time($now)];
        $sql=$mysql ? 'INSERT INTO user_fruit_inventory (user_id,fruit_id,quantity,discovered_at,updated_at) VALUES (:user,:fruit,:quantity,:created,:updated) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity),updated_at=VALUES(updated_at)' : 'INSERT INTO user_fruit_inventory (user_id,fruit_id,quantity,discovered_at,updated_at) VALUES (:user,:fruit,:quantity,:created,:updated) ON CONFLICT(user_id,fruit_id) DO UPDATE SET quantity=quantity+excluded.quantity,updated_at=excluded.updated_at';
        $pdo->prepare($sql)->execute($args+[':quantity'=>$quantity,':updated'=>auth_sql_time($now)]);
        $pdo->prepare(($mysql?'INSERT IGNORE':'INSERT OR IGNORE').' INTO user_fruit_discoveries (user_id,fruit_id,discovered_at) VALUES (:user,:fruit,:created)')->execute($args);
        $pdo->prepare('INSERT INTO fruit_pick_operations (user_id,operation_id,pending_id,fruit_id,quantity,created_at) VALUES (:user,:operation,:pending,:fruit,:quantity,:created)')->execute([':user'=>$userId,':operation'=>$operationId,':pending'=>$pendingId,':fruit'=>$fruitId,':quantity'=>$quantity,':created'=>auth_sql_time($now)]);
        $pdo->commit(); return ['fruit_id'=>$fruitId,'quantity'=>$quantity,'duplicate'=>false];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function fruit_sell(PDO $pdo,string $userId,array $body,callable $clock): array
{
    fruit_ensure_schema($pdo);
    $id=data_string($body,'fruit_id');
    $operationId=data_string($body,'operation_id');
    $quantity=data_nonnegative_int($body,'quantity');
    if($id===''||$quantity<1||$quantity>1000000000)throw new ApiError('invalid_fruit_sale','Fruit id and positive quantity are required.',400);
    if(!preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D',$operationId))throw new ApiError('invalid_operation','A stable operation identifier is required.',400);
    $now=$clock();$day=$now->format('Y-m-d');
    $pdo->beginTransaction();
    try {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $wallet=$pdo->prepare('SELECT initialized,coins FROM aquarium_profiles WHERE user_id=:user'.($driver==='mysql'?' FOR UPDATE':''));
        $wallet->execute([':user'=>$userId]);$profile=$wallet->fetch(PDO::FETCH_ASSOC);
        if(!is_array($profile)||!(bool)$profile['initialized'])throw new ApiError('aquarium_not_initialized','Initialize the aquarium first.',409);
        $existing=$pdo->prepare('SELECT fruit_id,quantity,unit_price,total FROM fruit_sale_operations WHERE user_id=:user AND operation_id=:operation');
        $existing->execute([':user'=>$userId,':operation'=>$operationId]);$prior=$existing->fetch(PDO::FETCH_ASSOC);
        if(is_array($prior)){
            if($prior['fruit_id']!==$id||(int)$prior['quantity']!==$quantity)throw new ApiError('operation_conflict','Operation id was used for a different sale.',409);
            $pdo->commit();
            return ['fruit_id'=>$id,'quantity'=>$quantity,'unit_price'=>(int)$prior['unit_price'],'total'=>(int)$prior['total'],'duplicate'=>true];
        }
        $item=$pdo->prepare('SELECT * FROM fruit_catalog WHERE id=:id AND enabled=1');$item->execute([':id'=>$id]);$fruit=$item->fetch(PDO::FETCH_ASSOC);
        if(!is_array($fruit))throw new ApiError('invalid_fruit','Unknown fruit.',400);
        $stock=$pdo->prepare('SELECT quantity FROM user_fruit_inventory WHERE user_id=:user AND fruit_id=:fruit'.($driver==='mysql'?' FOR UPDATE':''));
        $stock->execute([':user'=>$userId,':fruit'=>$id]);
        if((int)$stock->fetchColumn()<$quantity)throw new ApiError('insufficient_fruit','Not enough fruits in the backpack.',409);
        $price=fruit_daily_price($pdo,$fruit,$day);$total=$quantity*$price;
        $coins=(int)$profile['coins']+$total;
        if($coins>2147483647)throw new ApiError('coin_limit','The aquarium wallet limit was exceeded.',409);
        $pdo->prepare('UPDATE user_fruit_inventory SET quantity=quantity-:quantity,updated_at=:now WHERE user_id=:user AND fruit_id=:fruit')->execute([':quantity'=>$quantity,':now'=>auth_sql_time($now),':user'=>$userId,':fruit'=>$id]);
        $pdo->prepare('UPDATE aquarium_profiles SET coins=:coins,updated_at=:now WHERE user_id=:user')->execute([':coins'=>$coins,':now'=>auth_sql_time($now),':user'=>$userId]);
        aquarium_record_wallet($pdo,$userId,$operationId,$total,$coins,'fruit_atlas_sale',$id,$now);
        $pdo->prepare('INSERT INTO fruit_sale_operations (user_id,operation_id,fruit_id,quantity,unit_price,total,created_at) VALUES (:user,:operation,:fruit,:quantity,:price,:total,:created)')->execute([':user'=>$userId,':operation'=>$operationId,':fruit'=>$id,':quantity'=>$quantity,':price'=>$price,':total'=>$total,':created'=>auth_sql_time($now)]);
        $pdo->commit();
        return ['fruit_id'=>$id,'quantity'=>$quantity,'unit_price'=>$price,'total'=>$total,'duplicate'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
