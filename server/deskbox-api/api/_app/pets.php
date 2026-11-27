<?php
declare(strict_types=1);
if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/pet_care.php';

function pets_query(PDO $db, string $sql, array $args = []): PDOStatement {
    $stmt = $db->prepare($sql); $stmt->execute($args); return $stmt;
}
function pets_error(string $message, int $status = 400): never {
    throw new ApiError('pet_social_error', $message, $status);
}
function pets_profile(PDO $db, string $id): array {
    $row = pets_query($db, 'SELECT * FROM pet_profiles WHERE user_id=?', [$id])->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    $name = pets_query($db, 'SELECT username FROM auth_users WHERE id=?', [$id])->fetchColumn();
    if ($name === false) pets_error('账号不存在。', 404);
    pets_query($db, 'INSERT INTO pet_profiles(user_id,friend_code,name,outfit_json) VALUES(?,?,?,?)',
        [$id, strtoupper(bin2hex(random_bytes(5))), mb_substr((string)$name . '的小猫', 0, 40), '{"coat":"tuxedo","cats":[]}']);
    return pets_profile($db, $id);
}
function pets_public(array $row): array {
    return ['user_id'=>$row['user_id'], 'name'=>$row['name'], 'outfit'=>json_decode($row['outfit_json'], true),
        'revision'=>(int)$row['revision']];
}
function pets_pair(string $a, string $b): string {
    return strcmp($a, $b) < 0 ? "$a:$b" : "$b:$a";
}
function pets_outfit(mixed $raw): array {
    $o = is_object($raw) ? get_object_vars($raw) : $raw;
    if (!is_array($o) || !is_string($o['coat'] ?? null) || !preg_match('/\A[a-z0-9_-]{1,48}\z/', $o['coat'])) pets_error('猫咪外观无效。');
    $cats = $o['cats'] ?? [];
    if (!is_array($cats) || !array_is_list($cats) || count($cats)>16) pets_error('最多保存 16 只自定义猫。');
    $clean = []; $ids = [];
    foreach ($cats as $rawCat) {
        $c = (array)$rawCat;
        if (!is_string($c['id'] ?? null) || !preg_match('/\A[a-z0-9_-]{1,48}\z/', $c['id']) || in_array($c['id'],$ids,true)) pets_error('猫咪编号无效。');
        $ids[]=$c['id'];
        $item=['id'=>$c['id'], 'name'=>mb_substr((string)($c['name']??'我的猫'),0,40)];
        foreach (['fur','patch','accent','eye','eye2'] as $key) {
            $color=$c[$key]??($key==='eye2'?($c['eye']??''):null);
            if (!is_string($color) || !preg_match('/\A#[a-f0-9]{6}\z/i', $color)) pets_error('猫咪颜色无效。');
            $item[$key]=$color;
        }
        if (!in_array($c['pattern']??'', ['solid','bicolor','tabby','calico'],true)) pets_error('猫咪花纹无效。');
        $item['pattern']=$c['pattern']; $item['spots']=[];
        $spots=$c['spots']??[];
        if (!is_array($spots) || count($spots)>24) pets_error('猫咪斑点数量无效。');
        foreach ($spots as $rawSpot) {
            $s=(array)$rawSpot; $spot=[];
            if (!in_array($s['part']??'', ['head','body'],true)) pets_error('斑点位置无效。');
            $spot['part']=$s['part'];
            foreach (['x'=>[0,1],'y'=>[0,1],'rx'=>[0.005,0.6],'ry'=>[0.005,0.6],'rotation'=>[0,360],'opacity'=>[0,1]] as $key=>$range) {
                $v=$s[$key]??null;
                if (!is_numeric($v) || !is_finite((float)$v) || $v<$range[0] || $v>$range[1]) pets_error('斑点参数无效。');
                $spot[$key]=(float)$v;
            }
            if (!is_string($s['color']??null) || !preg_match('/\A#[a-f0-9]{6}\z/i',$s['color'])) pets_error('斑点颜色无效。');
            $spot['color']=$s['color']; $item['spots'][]=$spot;
        }
        $clean[]=$item;
    }
    $builtins=['tuxedo','orange','tabby','calico','siamese','black','white','blue','gray-white','orange-white','tortie','cream','silver'];
    if (!in_array($o['coat'],$builtins,true) && !in_array($o['coat'],$ids,true)) pets_error('找不到选中的猫咪。');
    return ['coat'=>$o['coat'],'cats'=>$clean];
}
function pets_expire(PDO $db, int $now): void {
    $rows=pets_query($db,"SELECT * FROM pet_sessions WHERE status IN ('invited','active') AND expires_at<=?",[$now])->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $s) {
        $finished=$s['kind']==='focus' && $s['status']==='active';
        pets_query($db,'UPDATE pet_sessions SET status=? WHERE id=?',[$finished?'completed':'expired',$s['id']]);
        if ($finished) foreach ([$s['sender'],$s['recipient']] as $id) {
            pets_query($db,'UPDATE pet_profiles SET coins=coins+10 WHERE user_id=?',[$id]);
            pet_care_ensure($db,$id,$now);
            pets_query($db,'UPDATE pet_care SET experience=experience+15 WHERE user_id=?',[$id]);
            pet_event($db,$id,$id,'focus_done',$now);
        }
    }
    pets_query($db,'DELETE FROM pet_events WHERE created_at<?',[$now-604800]);
    pets_query($db,'DELETE FROM pet_operations WHERE created_at<?',[$now-604800]);
}
function pets_snapshot(PDO $db, string $id, int $now, string $search = ''): array {
    $me=pets_profile($db,$id); $directory=[];
    $myCare=pet_care_view(pet_care_ensure($db,$id,$now));
    $search=mb_substr(trim($search),0,80);
    $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
    $rows=pets_query($db,"SELECT p.*,u.username FROM pet_profiles p JOIN auth_users u ON u.id=p.user_id WHERE p.user_id<>? AND u.status='active' AND (?='' OR p.name LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!' OR p.friend_code=?) ORDER BY p.name,p.user_id LIMIT 51",[$id,$search,$pattern,$pattern,strtoupper($search)])->fetchAll(PDO::FETCH_ASSOC);
    $online=[]; $busy=[];
    foreach(pets_query($db,'SELECT user_id FROM pet_presence WHERE last_seen_at>=?',[$now-30])->fetchAll(PDO::FETCH_ASSOC) as $r) $online[$r['user_id']]=true;
    foreach(pets_query($db,"SELECT sender,recipient FROM pet_sessions WHERE status='active' AND expires_at>?",[$now])->fetchAll(PDO::FETCH_ASSOC) as $r) { $busy[$r['sender']]=true; $busy[$r['recipient']]=true; }
    foreach (array_slice($rows,0,50) as $row) {
        $care=pets_query($db,'SELECT * FROM pet_care WHERE user_id=?',[$row['user_id']])->fetch(PDO::FETCH_ASSOC);
        $directory[]=pets_public($row)+['username'=>$row['username'],'status'=>'accepted','incoming'=>false,
            'presence'=>isset($online[$row['user_id']])?(isset($busy[$row['user_id']])?'busy':'online'):'offline',
            'care'=>$care?pet_care_view($care):null];
    }
    $sessions=pets_query($db,"SELECT * FROM pet_sessions WHERE (sender=? OR recipient=?) AND status IN ('invited','active') ORDER BY expires_at",[$id,$id])->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sessions as &$s) {
        $other=$s['sender']===$id?$s['recipient']:$s['sender']; $s['other']=pets_public(pets_profile($db,$other));
        $s['other']['care']=pet_care_view(pet_care_ensure($db,$other,$now));
        foreach (['expires_at','duration_seconds','started_at'] as $key) $s[$key]=(int)$s[$key];
    } unset($s);
    $events=pets_query($db,'SELECT e.*,p.name AS sender_name,q.name AS recipient_name FROM pet_events e LEFT JOIN pet_profiles p ON p.user_id=e.sender LEFT JOIN pet_profiles q ON q.user_id=e.recipient WHERE (e.sender=? OR e.recipient=?) AND e.created_at>=? ORDER BY e.created_at DESC,e.id DESC LIMIT 30',[$id,$id,$now-604800])->fetchAll(PDO::FETCH_ASSOC);
    return ['me'=>pets_public($me)+['friend_code'=>$me['friend_code'],'coins'=>(int)$me['coins'],'reward_day'=>$me['reward_day'],
        'care'=>$myCare], 'inventory'=>(object)pet_inventory($db,$id),'shop'=>pet_shop(),'rewards'=>pet_rewards(),'account_wallet'=>pet_account_wallet($db,$id),
        'directory'=>$directory,'directory_more'=>count($rows)>50,'directory_search'=>$search,
        'friends'=>$directory,'sessions'=>$sessions,'events'=>$events,'server_time'=>$now];
}
function pets_request(PDO $db, string $id, array $body, int $now): array {
    $op=(string)($body['action']??'state');
    $db->beginTransaction();
    try {
        pets_query($db,'SELECT id FROM pet_social_lock WHERE id=1' . ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'))->fetchColumn();
        pets_profile($db,$id); pet_presence_touch($db,$id,$now); pets_expire($db,$now);
        if (!in_array($op,['state','browse'],true)) {
            $key=$body['operation_id']??'';
            if (!is_string($key) || !preg_match('/\A[a-zA-Z0-9-]{16,64}\z/',$key)) pets_error('操作编号无效。');
            $hash=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));
            $old=pets_query($db,'SELECT body_hash FROM pet_operations WHERE user_id=? AND operation_id=?',[$id,$key])->fetchColumn();
            if ($old!==false) {
                if ($old!==$hash) pets_error('操作编号已被其他请求使用。',409);
            } else {
                pets_apply($db,$id,$op,$body,$now);
                pets_query($db,'INSERT INTO pet_operations VALUES(?,?,?,?)',[$id,$key,$hash,$now]);
            }
        }
        $result=pets_snapshot($db,$id,$now,(string)($body['search']??'')); $db->commit(); return $result;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function pets_apply(PDO $db, string $id, string $op, array $b, int $now): void {
    if (pet_care_action($db,$id,$op,$b,$now)) return;
    if ($op==='profile') {
        $me=pets_profile($db,$id);
        if (!is_int($b['revision']??null) || (int)$me['revision']!==$b['revision']) throw new ApiError('pet_revision_conflict','外观已在另一台设备更新，请先同步。',409);
        $name=trim((string)($b['name']??$me['name'])); if ($name==='' || mb_strlen($name)>40) pets_error('猫咪名字需要 1–40 个字。');
        $outfit=pets_outfit($b['outfit']??null);
        pets_query($db,'UPDATE pet_profiles SET name=?,outfit_json=?,revision=revision+1 WHERE user_id=?',[$name,json_encode($outfit,JSON_THROW_ON_ERROR),$id]); return;
    }
    if ($op==='daily') {
        $day=gmdate('Y-m-d',$now+28800); $me=pets_profile($db,$id);
        if ($me['reward_day']!==$day) pets_query($db,'UPDATE pet_profiles SET coins=coins+5,reward_day=? WHERE user_id=?',[$day,$id]); return;
    }
    if ($op==='friend_request') {
        pets_error('无需添加好友，请更新客户端后直接选择猫咪互动。',409);
    }
    if (in_array($op,['friend_accept','friend_remove'],true)) {
        $target=(string)($b['target']??''); $pair=pets_pair($id,$target);
        $f=pets_query($db,'SELECT * FROM pet_friends WHERE pair_id=?',[$pair])->fetch(PDO::FETCH_ASSOC);
        if (!$f) pets_error('好友申请不存在。',404);
        if ($op==='friend_accept') {
            if ($f['recipient']!==$id || $f['status']!=='pending') pets_error('只有收到申请的人可以接受。',403);
            pets_query($db,"UPDATE pet_friends SET status='accepted' WHERE pair_id=?",[$pair]);
        } else {
            pets_query($db,'DELETE FROM pet_friends WHERE pair_id=?',[$pair]);
            pets_query($db,"UPDATE pet_sessions SET status='ended' WHERE ((sender=? AND recipient=?) OR (sender=? AND recipient=?)) AND status IN ('invited','active')",[$id,$target,$target,$id]);
        } return;
    }
    if (in_array($op,['session_accept','session_end'],true)) {
        $s=pets_query($db,'SELECT * FROM pet_sessions WHERE id=?',[(string)($b['session']??'')])->fetch(PDO::FETCH_ASSOC);
        if (!$s || !in_array($id,[$s['sender'],$s['recipient']],true)) pets_error('无法访问这个邀请。',403);
        if ($op==='session_end') { pets_query($db,"UPDATE pet_sessions SET status='ended' WHERE id=? AND status IN ('invited','active')",[$s['id']]); return; }
        if ($s['recipient']!==$id || $s['status']!=='invited') pets_error('邀请已失效或不属于你。',409);
        $busy=pets_query($db,"SELECT COUNT(*) FROM pet_sessions WHERE kind=? AND status='active' AND (sender IN (?,?) OR recipient IN (?,?))",[$s['kind'],$s['sender'],$s['recipient'],$s['sender'],$s['recipient']])->fetchColumn();
        if ((int)$busy>0) pets_error('已有进行中的串门或专注，请先结束。',409);
        pets_query($db,"UPDATE pet_sessions SET status='active',started_at=?,expires_at=? WHERE id=?",[$now,$now+(int)$s['duration_seconds'],$s['id']]); return;
    }
    $target=(string)($b['target']??'');
    if ($id===$target) pets_error('请选择另一只猫咪。');
    $targetExists=pets_query($db,"SELECT p.user_id FROM pet_profiles p JOIN auth_users u ON u.id=p.user_id WHERE p.user_id=? AND u.status='active'",[$target])->fetchColumn();
    if ($targetExists===false) pets_error('这只猫咪暂不可用。',404);
    if ($op==='gift') {
        if ((int)pets_profile($db,$id)['coins']<3) pets_error('鱼干不足，领取每日奖励或完成双人专注后再来。',409);
        pets_query($db,'UPDATE pet_profiles SET coins=coins-3 WHERE user_id=?',[$id]);
        pets_query($db,'UPDATE pet_profiles SET coins=coins+3 WHERE user_id=?',[$target]);
        pets_query($db,'INSERT INTO pet_events VALUES(?,?,?,?,?)',[auth_random_id(),$id,$target,'gift',$now]); return;
    }
    if (in_array($op,['visit','focus'],true)) {
        $seen=pets_query($db,'SELECT last_seen_at FROM pet_presence WHERE user_id=?',[$target])->fetchColumn();
        if ($seen===false || (int)$seen<$now-30) pets_error('对方的小猫还没上线，暂时不能串门或一起专注，可以先送鱼干。',409);
        $busy=pets_query($db,"SELECT COUNT(*) FROM pet_sessions WHERE kind=? AND status='active' AND (sender IN (?,?) OR recipient IN (?,?))",[$op,$id,$target,$id,$target])->fetchColumn();
        if ((int)$busy>0) pets_error('已有进行中的串门或专注，请先结束。',409);
        $duration=$op==='visit'?600:1500;
        pets_query($db,'INSERT INTO pet_sessions(id,kind,sender,recipient,status,expires_at,duration_seconds,started_at) VALUES(?,?,?,?,?,?,?,?)',
            [auth_random_id(),$op,$id,$target,'active',$now+$duration,$duration,$now]);
        pet_event($db,$id,$target,$op==='visit'?'visit_start':'focus_start',$now); return;
    }
    if (in_array($op,['wave','highfive'],true)) {
        $active=pets_query($db,"SELECT COUNT(*) FROM pet_sessions WHERE status='active' AND ((sender=? AND recipient=?) OR (sender=? AND recipient=?))",[$id,$target,$target,$id])->fetchColumn();
        if (!(int)$active) pets_error('串门或双人专注时才能使用共同动作。');
        $recent=pets_query($db,"SELECT COUNT(*) FROM pet_events WHERE sender=? AND kind IN ('wave','highfive') AND created_at>?",[$id,$now-3])->fetchColumn();
        if ((int)$recent>0) pets_error('小猫正在做动作，稍等一下。',429);
        pets_query($db,'INSERT INTO pet_events VALUES(?,?,?,?,?)',[auth_random_id(),$id,$target,$op,$now]); return;
    }
    pets_error('不支持这个操作。');
}
