<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY',true);
require dirname(__DIR__).'/api/_app/bootstrap.php';
require dirname(__DIR__).'/api/_app/account_data.php';
require dirname(__DIR__).'/api/_app/fruit.php';
require dirname(__DIR__).'/api/_app/tree_visit.php';
function group_check(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
function group_error(string $code,callable $action):void {
    try{$action();}catch(ApiError $e){group_check($e->apiCode===$code,$code.' got '.$e->apiCode);return;}
    throw new RuntimeException('Expected '.$code);
}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$clock=static fn()=>new DateTimeImmutable('2026-11-07T08:00:00Z');
$owner=auth_register($pdo,'group_owner','secret1','device1','127.0.0.1',$clock)['user']['id'];
$viewer=auth_register($pdo,'group_viewer','secret2','device2','127.0.0.2',$clock)['user']['id'];
$other=auth_register($pdo,'group_other','secret3','device3','127.0.0.3',$clock)['user']['id'];
fruit_ensure_schema($pdo);
$pdo->exec("UPDATE fruit_catalog SET enabled=CASE WHEN id='apple' THEN 1 ELSE 0 END");
$advance=static function(int $energy,string $operation)use($pdo,$owner,$clock):array{
    return tree_apply_progress($pdo,$owner,['operation_id'=>$operation,'device_id'=>'device1','day'=>'2026-11-07',
        'energy_delta'=>$energy,'day_growth_delta'=>$energy,'fruit_delta'=>0,'harvested_delta'=>0,
        'typed_fruit'=>true,'fruit_rules_version'=>2],$clock);
};
$advance(143999,'group-progress-0001');
group_check(count(fruit_on_tree($pdo,$owner))===0,'No immature fruit');
$advance(1,'group-progress-0002');
group_check(count(fruit_on_tree($pdo,$owner))===0,'Maturity alone does not grant a batch');
$advance(2999,'group-progress-0003');
group_check(count(fruit_on_tree($pdo,$owner))===0,'2999 is below a batch');
$first=$advance(1,'group-progress-0004');
$one=count(fruit_on_tree($pdo,$owner));
group_check($one>=1&&$one<=5&&$first['state']['fruit_inventory']===$one,'One batch is 1-5 actual units');
$second=$advance(6001,'group-progress-0005');
$all=fruit_on_tree($pdo,$owner);$two=count($all)-$one;
group_check($two>=2&&$two<=10&&$second['state']['fruit_energy']===1,'Two independent batches and remainder');
group_check(count(array_unique(array_column($all,'fruit_id')))===1,'Repeated type accumulates');
group_check($advance(6001,'group-progress-0005')['duplicate']&&count(fruit_on_tree($pdo,$owner))===count($all),'Lost-response replay cannot reroll fruit');
// Legacy counters cannot mint or harvest real typed units.
$legacy=tree_apply_progress($pdo,$owner,['operation_id'=>'group-legacy-000001','device_id'=>'old','day'=>'2026-11-07',
    'energy_delta'=>1,'day_growth_delta'=>1,'fruit_delta'=>900,'harvested_delta'=>800],$clock);
group_check($legacy['state']['fruit_inventory']===count($all)&&$legacy['state']['harvested_total']===0,'Legacy deltas never overwrite confirmed fruit');
tree_apply_state($pdo,$owner,['operation_id'=>'group-snapshot-0001','device_id'=>'old','base_version'=>$legacy['state']['state_version'],
    'day'=>'2026-11-07','today_growth'=>153002,'total_energy'=>153002,'stage'=>5,
    'fruit_energy'=>0,'fruit_total'=>999,'harvested_total'=>998],$clock);
group_check(count(fruit_on_tree($pdo,$owner))===count($all)&&tree_read_state($pdo,$owner,'2026-11-07')['fruit_inventory']===count($all),'Snapshot cannot replace typed totals');
// Owner harvest includes the entire selected type, never another type.
$insert=$pdo->prepare('INSERT INTO user_fruit_pending(user_id,fruit_id,created_at) VALUES(?,?,?)');
for($i=0;$i<3;$i++)$insert->execute([$owner,'pear',auth_sql_time($clock())]);
$pdo->prepare('UPDATE tree_profiles SET fruit_total=fruit_total+3 WHERE user_id=?')->execute([$owner]);
$request=['pending_id'=>$all[0]['id'],'operation_id'=>'group-owner-pick-0001'];
$picked=fruit_pick($pdo,$owner,$request,$clock);
group_check($picked['quantity']===count($all),'Owner receives exactly the selected type total');
group_check(array_column(fruit_on_tree($pdo,$owner),'fruit_id')===['pear','pear','pear'],'Other types stay on the tree');
$insert->execute([$owner,'apple',auth_sql_time($clock())]);
$pdo->prepare('UPDATE tree_profiles SET fruit_total=fruit_total+1 WHERE user_id=?')->execute([$owner]);
group_check(fruit_pick($pdo,$owner,$request,$clock)['duplicate']&&count(fruit_on_tree($pdo,$owner))===4,'Retry leaves newly spawned fruit intact');
$steal=tree_visit_pick_fruit($pdo,$owner,$viewer,'pear','group-visit-pick-0001',$clock());
group_check($steal['quantity']===1&&$steal['remaining_on_tree']===2,'Visitor takes exactly one');
group_check(tree_visit_pick_fruit($pdo,$owner,$viewer,'pear','group-visit-pick-0001',$clock())['duplicate'],'Visitor replay is idempotent');
group_error('visit_fruit_daily_limit',fn()=>tree_visit_pick_fruit($pdo,$owner,$viewer,'pear','group-visit-pick-0002',$clock()));
group_check(tree_visit_pick_fruit($pdo,$owner,$other,'pear','group-visit-pick-0003',$clock())['remaining_on_tree']===1,'Different visitor can take one, preserving last unit');
group_error('visit_fruit_requires_two',fn()=>tree_visit_pick_fruit($pdo,$owner,$viewer,'apple','group-visit-pick-0004',$clock()));
$insert->execute([$owner,'apple',auth_sql_time($clock())]);
$pdo->prepare('UPDATE tree_profiles SET fruit_total=fruit_total+1 WHERE user_id=?')->execute([$owner]);
group_check(tree_visit_pick_fruit($pdo,$owner,$viewer,'apple','group-visit-pick-0005',$clock())['remaining_on_tree']===1,'Each type has its own daily allowance');
$insert->execute([$owner,'pear',auth_sql_time($clock())]);
$pdo->prepare('UPDATE tree_profiles SET fruit_total=fruit_total+1 WHERE user_id=?')->execute([$owner]);
group_check(tree_visit_pick_fruit($pdo,$owner,$viewer,'pear','group-visit-pick-0006',$clock()->modify('+1 day'))['remaining_on_tree']===1,'Daily allowance resets');
$insert->execute([$owner,'pear',auth_sql_time($clock())]);
$pdo->prepare('UPDATE tree_profiles SET fruit_total=fruit_total+1 WHERE user_id=?')->execute([$owner]);
$pear=$pdo->query("SELECT MIN(id) FROM user_fruit_pending WHERE user_id='$owner' AND fruit_id='pear'")->fetchColumn();
$insert->execute([$owner,'pear',auth_sql_time($clock())]);
$pdo->prepare('UPDATE tree_profiles SET fruit_total=fruit_total+1 WHERE user_id=?')->execute([$owner]);
tree_visit_pick_fruit($pdo,$owner,$other,'pear','group-race-visit-0001',$clock()->modify('+1 day'));
$racePick=fruit_pick($pdo,$owner,['pending_id'=>(int)$pear,'fruit_id'=>'pear','operation_id'=>'group-race-owner-0001'],$clock);
group_check($racePick['quantity']===2,'Owner can harvest the type after a visitor consumed its representative id');
echo "fruit_group_harvest_test: ok\n";
