<?php
declare(strict_types=1);
if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__.'/aquarium.php';

function pet_account_wallet(PDO $db, string $id, bool $lock=false): array {
    $row=pets_query($db,'SELECT initialized,coins FROM aquarium_profiles WHERE user_id=?'.($lock && $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$id])->fetch(PDO::FETCH_ASSOC);
    $initialized=$row && (bool)$row['initialized'];
    return ['initialized'=>(bool)$initialized,'coins'=>$initialized?(int)$row['coins']:0,'currency'=>'account_coins'];
}
function pet_purchase(PDO $db, string $id, array $item, array $body, int $now): bool {
    if (($body['currency']??'')!=='account_coins') pets_error('商店已改用 Box 账户金币，请更新客户端后购买。',409);
    $wallet=pet_account_wallet($db,$id,true);
    if (!$wallet['initialized']) pets_error('请先在 Box 水族箱中初始化账户金币钱包。',409);
    $operation=hash('sha256','pet-shop:'.$body['operation_id']);
    $previous=pets_query($db,'SELECT delta,item_id,reason FROM aquarium_wallet_entries WHERE user_id=? AND operation_id=?',[$id,$operation])->fetch(PDO::FETCH_ASSOC);
    if ($previous) {
        if ($previous['item_id']!=='pet:'.$item['id'] || $previous['reason']!=='pet_purchase' || (int)$previous['delta']!==-$item['price']) pets_error('操作编号已被其他购买使用。',409);
        return false;
    }
    $quantity=pet_quantity($db,$id,$item['id']);
    if ($item['kind']!=='food' && $quantity>0) pets_error('已经拥有这件物品。',409);
    if ($quantity>=99) pets_error('小鱼饭最多存放 99 份。',409);
    if ($wallet['coins']<$item['price']) pets_error('账户金币不足。',409);
    $time=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC'));
    $updated=pets_query($db,'UPDATE aquarium_profiles SET coins=coins-?,updated_at=? WHERE user_id=? AND initialized=1 AND coins>=?',[$item['price'],auth_sql_time($time),$id,$item['price']]);
    if ($updated->rowCount()!==1) pets_error('账户金币不足，请刷新后重试。',409);
    aquarium_record_wallet($db,$id,$operation,-$item['price'],$wallet['coins']-$item['price'],'pet_purchase','pet:'.$item['id'],$time);
    return true;
}

function pet_shop(): array {
    return [
        ['id'=>'fish_meal','name'=>'小鱼饭','price'=>20,'kind'=>'food','description'=>'喂食一次增加 25 饱食度、5 心情和 10 经验。'],
        ['id'=>'wool_ball','name'=>'毛线球','price'=>1500,'kind'=>'toy','description'=>'永久玩具，每 10 分钟可陪玩一次，增加 12 心情和 5 经验。'],
        ['id'=>'bell_badge','name'=>'铃铛名牌','price'=>2000,'kind'=>'badge','description'=>'永久装饰，在桌面小猫下方显示铃铛名牌。'],
        ['id'=>'star_glow','name'=>'星光特效','price'=>3000,'kind'=>'effect','description'=>'永久装饰，在桌面小猫周围显示星光。'],
    ];
}
function pet_rewards(): array {
    return [
        ['id'=>'celebrate','name'=>'开心转圈','level'=>2,'kind'=>'action','description'=>'解锁专属庆祝动作，可随时表演。'],
        ['id'=>'companion_title','name'=>'贴心伙伴','level'=>3,'kind'=>'badge','description'=>'专属称号名牌，免费装备到桌面小猫。'],
        ['id'=>'heart_glow','name'=>'爱心环绕','level'=>5,'kind'=>'effect','description'=>'专属爱心特效，可与名牌同时装备。'],
    ];
}
function pet_rewards_sync(PDO $db, string $id, array $care, int $now): void {
    $level=1+intdiv((int)$care['experience'],50);
    foreach(pet_rewards() as $reward) {
        if ($level<$reward['level'] || pet_quantity($db,$id,$reward['id'])>0) continue;
        pets_query($db,'INSERT INTO pet_inventory VALUES(?,?,1)',[$id,$reward['id']]);
        pet_event($db,$id,$id,'reward_'.$reward['level'],$now);
    }
}
function pet_care_ensure(PDO $db, string $id, int $now): array {
    $row=pets_query($db,'SELECT * FROM pet_care WHERE user_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        pets_query($db,'INSERT INTO pet_care(user_id,updated_at) VALUES(?,?)',[$id,$now]);
        $row=pets_query($db,'SELECT * FROM pet_care WHERE user_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    }
    $hours=max(0,intdiv($now-(int)$row['updated_at'],3600));
    if ($hours>0) {
        $row['hunger']=max(0,(int)$row['hunger']-5*$hours);
        $row['mood']=max(0,(int)$row['mood']-2*$hours);
        $row['updated_at']=(int)$row['updated_at']+$hours*3600;
        pets_query($db,'UPDATE pet_care SET hunger=?,mood=?,updated_at=? WHERE user_id=?',[$row['hunger'],$row['mood'],$row['updated_at'],$id]);
    }
    pet_rewards_sync($db,$id,$row,$now);
    return $row;
}
function pet_care_view(array $row): array {
    $xp=(int)$row['experience'];
    return ['experience'=>$xp,'level'=>1+intdiv($xp,50),'level_progress'=>$xp%50,'level_goal'=>50,
        'hunger'=>(int)$row['hunger'],'mood'=>(int)$row['mood'],'last_play_at'=>(int)$row['last_play_at'],
        'badge'=>$row['badge'],'effect'=>$row['effect']];
}
function pet_inventory(PDO $db, string $id): array {
    $items=[];
    foreach(pets_query($db,'SELECT item_id,quantity FROM pet_inventory WHERE user_id=? AND quantity>0',[$id])->fetchAll(PDO::FETCH_ASSOC) as $row) $items[$row['item_id']]=(int)$row['quantity'];
    return $items;
}
function pet_quantity(PDO $db, string $id, string $item): int {
    return (int)pets_query($db,'SELECT quantity FROM pet_inventory WHERE user_id=? AND item_id=?',[$id,$item])->fetchColumn();
}
function pet_event(PDO $db, string $sender, string $recipient, string $kind, int $now): void {
    pets_query($db,'INSERT INTO pet_events VALUES(?,?,?,?,?)',[auth_random_id(),$sender,$recipient,$kind,$now]);
}
function pet_care_action(PDO $db, string $id, string $op, array $body, int $now): bool {
    if (!in_array($op,['buy','feed','play','equip','celebrate'],true)) return false;
    $care=pet_care_ensure($db,$id,$now);
    if ($op==='celebrate') {
        if ((int)$care['experience']<50) pets_error('升到 Lv.2 后解锁开心转圈。',409);
        $recent=pets_query($db,"SELECT COUNT(*) FROM pet_events WHERE sender=? AND kind='celebrate' AND created_at>?",[$id,$now-10])->fetchColumn();
        if ((int)$recent>0) pets_error('小猫正在转圈，稍等一下。',429);
        pet_event($db,$id,$id,'celebrate',$now); return true;
    }
    if ($op==='buy') {
        $item=null;
        foreach(pet_shop() as $candidate) if($candidate['id']===($body['item']??'')) $item=$candidate;
        if (!$item) pets_error('商品不存在。',404);
        if (!pet_purchase($db,$id,$item,$body,$now)) return true;
        $quantity=pet_quantity($db,$id,$item['id']);
        if ($quantity>0) pets_query($db,'UPDATE pet_inventory SET quantity=quantity+1 WHERE user_id=? AND item_id=?',[$id,$item['id']]);
        else {
            pets_query($db,'DELETE FROM pet_inventory WHERE user_id=? AND item_id=?',[$id,$item['id']]);
            pets_query($db,'INSERT INTO pet_inventory VALUES(?,?,1)',[$id,$item['id']]);
        }
        return true;
    }
    if ($op==='feed') {
        if ((int)$care['hunger']>=100) pets_error('小猫已经吃饱啦，晚点再喂。',409);
        if (pet_quantity($db,$id,'fish_meal')<1) pets_error('先到金币商店买一份小鱼饭吧。',409);
        pets_query($db,'UPDATE pet_inventory SET quantity=quantity-1 WHERE user_id=? AND item_id=?',[$id,'fish_meal']);
        pets_query($db,'UPDATE pet_care SET hunger=?,mood=?,experience=experience+10 WHERE user_id=?',[min(100,(int)$care['hunger']+25),min(100,(int)$care['mood']+5),$id]);
        pet_event($db,$id,$id,'feed',$now); return true;
    }
    if ($op==='play') {
        if (pet_quantity($db,$id,'wool_ball')<1) pets_error('先到金币商店买一个毛线球吧。',409);
        if ((int)$care['last_play_at']>0 && $now-(int)$care['last_play_at']<600) pets_error('小猫休息一下，10 分钟后再陪玩。',409);
        pets_query($db,'UPDATE pet_care SET mood=?,experience=experience+5,last_play_at=? WHERE user_id=?',[min(100,(int)$care['mood']+12),$now,$id]);
        pet_event($db,$id,$id,'play',$now); return true;
    }
    $slot=$body['slot']??''; $item=$body['item']??'';
    $allowed=['badge'=>['bell_badge','companion_title'],'effect'=>['star_glow','heart_glow']];
    if (!is_string($slot) || !isset($allowed[$slot]) || !is_string($item) || ($item!=='' && !in_array($item,$allowed[$slot],true))) pets_error('装饰位置无效。');
    if ($item!=='' && pet_quantity($db,$id,$item)<1) pets_error('请先购买这件装饰。',409);
    pets_query($db,"UPDATE pet_care SET $slot=? WHERE user_id=?",[$item,$id]); return true;
}
function pet_presence_touch(PDO $db, string $id, int $now): void {
    $exists=pets_query($db,'SELECT user_id FROM pet_presence WHERE user_id=?',[$id])->fetchColumn();
    if ($exists===false) pets_query($db,'INSERT INTO pet_presence VALUES(?,?)',[$id,$now]);
    else pets_query($db,'UPDATE pet_presence SET last_seen_at=? WHERE user_id=?',[$now,$id]);
}
