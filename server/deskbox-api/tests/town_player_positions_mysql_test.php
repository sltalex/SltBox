<?php
declare(strict_types=1);
$dsn=getenv('DESKBOX_TEST_MYSQL_DSN');
if (!is_string($dsn) || $dsn==='') {
    echo "town_player_positions_mysql_test: skipped (MySQL DSN not configured)\n";
    exit;
}
define('DESKBOX_API_ENTRY',true);
require dirname(__DIR__).'/api/_app/bootstrap.php';
require dirname(__DIR__).'/api/_app/account_data.php';
require dirname(__DIR__).'/api/_app/shared_town.php';
$pdo=new PDO($dsn,getenv('DESKBOX_TEST_MYSQL_USER')?:'',getenv('DESKBOX_TEST_MYSQL_PASSWORD')?:'',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$tables=['town_worlds','town_objects','town_presence','town_player_positions','auth_users'];
$created=[];
try {
    $users=$pdo->query('SELECT id,username FROM auth_users ORDER BY id LIMIT 2')->fetchAll();
    if(count($users)<2)throw new RuntimeException('Two existing accounts are required for isolated temporary-table fixtures.');
    $pdo->exec('CREATE TEMPORARY TABLE auth_users (id CHAR(32) PRIMARY KEY,username VARCHAR(120))');
    $created[]='auth_users';
    foreach($users as $user)$pdo->prepare('INSERT INTO auth_users VALUES (?,?)')->execute([$user['id'],$user['username']]);
    $pdo->exec('CREATE TEMPORARY TABLE town_worlds AS SELECT * FROM town_worlds');$created[]='town_worlds';
    $pdo->exec("UPDATE town_worlds SET map_width=192,map_height=192,terrain_version=3 WHERE world_id='main'");
    foreach(['town_objects','town_presence'] as $table){
        $pdo->exec('CREATE TEMPORARY TABLE '.$table.' AS SELECT * FROM '.$table.' WHERE 1=0');$created[]=$table;
    }
    $pdo->exec('ALTER TABLE town_presence ADD PRIMARY KEY (world_id,user_id)');
    $pdo->prepare('INSERT INTO town_presence VALUES (?,?,?,?,?,?,?,?,?)')->execute([
        'main',$users[0]['id'],$users[0]['username'],12,12,300,300,'2026-11-10 00:00:00','2026-11-10 00:00:30']);
    $migration=file_get_contents(dirname(__DIR__).'/database/026_shared_town_player_positions.sql');
    $migration=preg_replace('/^--.*$/m','',$migration);
    $migration=str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$migration);
    $migration=preg_replace('/,\s*CONSTRAINT \w+ FOREIGN KEY \([^)]+\)\s+REFERENCES \w+ \([^)]+\) ON DELETE \w+/','',$migration);
    $created[]='town_player_positions';
    $run=static function() use($pdo,$migration):void{
        foreach(explode(';',$migration) as $sql)if(trim($sql)!=='')$pdo->exec($sql);
    };
    $run();
    $world=$pdo->query("SELECT * FROM town_worlds WHERE world_id='main'")->fetch();
    $run();
    if($world!==$pdo->query("SELECT * FROM town_worlds WHERE world_id='main'")->fetch())throw new RuntimeException('Repeated migration changed the world again.');
    if((int)$world['map_width']!==384 || (int)$world['map_height']!==384)throw new RuntimeException('World expansion failed.');
    $expansion=file_get_contents(dirname(__DIR__).'/database/027_shared_town_map_768.sql');
    $expand=static function() use($pdo,$expansion):void{
        $pdo->exec($expansion);
    };
    $expand();
    $expanded=$pdo->query("SELECT * FROM town_worlds WHERE world_id='main'")->fetch();
    $expand();
    if($expanded!==$pdo->query("SELECT * FROM town_worlds WHERE world_id='main'")->fetch())throw new RuntimeException('Repeated 768 migration changed the world.');
    if((int)$expanded['map_width']!==768 || (int)$expanded['map_height']!==768 || (int)$expanded['terrain_version']!==5)throw new RuntimeException('768 expansion failed.');
    $now=api_clock();$a=$users[0]['id'];$b=$users[1]['id'];
    $saved=shared_town_player_position($pdo,$a,$now);
    if($saved['tile_x']!==300 || $saved['tile_y']!==300)throw new RuntimeException('Upgrade lost the legacy last known position.');
    $new=shared_town_player_position($pdo,$b,$now,static fn()=>40);
    if($new['tile_x']!==40 || shared_town_player_position($pdo,$b,$now)!==$new)throw new RuntimeException('First-entry random position was not persistent.');
    shared_town_presence_upsert($pdo,$a,['tile_x'=>301,'tile_y'=>302,'pixel_x'=>4824.25,'pixel_y'=>4847.5],$now);
    shared_town_presence($pdo,-1,-1,$now->modify('+1 hour'));
    $saved=shared_town_player_position($pdo,$a,$now->modify('+1 hour'));
    if($saved['pixel_x']!==4824.25 || $saved['pixel_y']!==4847.5)throw new RuntimeException('MySQL did not preserve pixel coordinates after presence expiry.');
    if(shared_town_player_position($pdo,$b,$now)!==$new)throw new RuntimeException('Account positions were mixed.');
    $run();
    $expand();
    if(shared_town_player_position($pdo,$a,$now)!==$saved)throw new RuntimeException('Migration replay overwrote a moved position.');
    shared_town_presence_upsert($pdo,$a,['tile_x'=>767,'tile_y'=>767,'pixel_x'=>12280.25,'pixel_y'=>12287.5],$now);
    if(shared_town_player_position($pdo,$a,$now)['pixel_x']!==12280.25)throw new RuntimeException('Expanded corner position was not persistent.');
    shared_town_chunk($pdo,31,31);
    echo "town_player_positions_mysql_test: ok (expansion, migration replay, first entry, account isolation, pixel persistence)\n";
} finally {
    foreach(array_reverse($created) as $table)$pdo->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);
}
