<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

function town_asset_contact(array $item, string $path): array
{
    if (!function_exists('imagecreatefrompng') || !is_file($path)) {
        throw new ApiError('footprint_unavailable', '无法读取图集透明轮廓，不能自动计算占地。', 400);
    }
    $image = @imagecreatefrompng($path);
    if ($image === false) throw new ApiError('footprint_unavailable', '图集 PNG 无法解码。', 400);
    $frames = $item['frames'] ?? [];
    if (!$frames) $frames = [$item];
    $union = [1.0, 1.0, 0.0, 0.0];
    foreach ($frames as $frame) {
        $x = (int)$frame['x']; $y = (int)$frame['y'];
        $w = (int)($frame['w'] ?? $frame['width']); $h = (int)($frame['h'] ?? $frame['height']);
        if ($x < 0 || $y < 0 || $w < 1 || $h < 1 || $x+$w > imagesx($image) || $y+$h > imagesy($image)) {
            throw new ApiError('invalid_region', '占地计算区域超出图集。', 400);
        }
        $top = $h; $bottom = -1;
        for ($py=0; $py<$h; $py++) for ($px=0; $px<$w; $px++) {
            $color = imagecolorsforindex($image, imagecolorat($image,$x+$px,$y+$py));
            if ($color['alpha'] < 127) { $top=min($top,$py); $bottom=max($bottom,$py); }
        }
        if ($bottom < 0) throw new ApiError('empty_region', '存在全透明帧，无法计算脚底占地。', 400);
        $cut = $top + (int)floor(($bottom-$top+1)*2/3);
        $left=$w; $right=-1; $contactTop=$h; $contactBottom=-1;
        for ($py=$cut; $py<=$bottom; $py++) for ($px=0; $px<$w; $px++) {
            $color=imagecolorsforindex($image,imagecolorat($image,$x+$px,$y+$py));
            if ($color['alpha'] < 127) { $left=min($left,$px);$right=max($right,$px);$contactTop=min($contactTop,$py);$contactBottom=max($contactBottom,$py); }
        }
        $union=[min($union[0],$left/$w),min($union[1],$contactTop/$h),max($union[2],($right+1)/$w),max($union[3],($contactBottom+1)/$h)];
    }
    $width=(int)ceil(round(($union[2]-$union[0])*(int)$item['display_width']/16,8));
    $height=(int)ceil(round(($union[3]-$union[1])*(int)$item['display_height']/16,8));
    if ($width<1 || $height<1 || $width>16 || $height>16) throw new ApiError('invalid_footprint','计算后的占地超过 16×16 格，请调整显示尺寸或手动设置。',400);
    return ['footprint'=>['width'=>$width,'height'=>$height], 'collision'=>['x'=>$union[0],'y'=>$union[1],'width'=>$union[2]-$union[0],'height'=>$union[3]-$union[1]]];
}

function town_asset_recalculate_item(PDO $pdo, array $item): array
{
    [$path] = town_asset_file($pdo,(string)$item['sheet_id']);
    $contact = town_asset_contact($item,$path);
    return array_replace($item,$contact);
}

/** Returns a deterministic complete plan, without mutating drafts or the active release. */
function town_asset_reflow_plan(PDO $pdo): array
{
    $active=town_asset_active_config($pdo);
    $activeItems=[]; $draftItems=[];
    foreach ($active['items'] ?? [] as $item) $activeItems[$item['id']]=town_asset_recalculate_item($pdo,$item);
    foreach (town_asset_read_admin_items($pdo) as $item) $draftItems[$item['id']]=town_asset_recalculate_item($pdo,$item);
    $rows=$pdo->query("SELECT * FROM town_objects WHERE world_id='main' AND deleted_at IS NULL ORDER BY object_id")->fetchAll(PDO::FETCH_ASSOC);
    $occupied=[]; $moving=[]; $changes=[]; $historical=[];
    foreach($pdo->query('SELECT config_json FROM town_asset_releases ORDER BY release_version DESC')->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach(json_decode($json,true,32,JSON_THROW_ON_ERROR)['items']??[] as $item) if(!isset($historical[$item['id']]))$historical[$item['id']]=$item;
    }
    foreach ($rows as $row) {
        $item=$activeItems[$row['asset_id']] ?? $draftItems[$row['asset_id']] ?? null;
        if (($row['asset_source'] ?? 'legacy') !== 'town') { $occupied[]=shared_town_rectangle($row); continue; }
        if(!$item && isset($historical[$row['asset_id']]))$item=town_asset_recalculate_item($pdo,$historical[$row['asset_id']]);
        if(!$item)throw new ApiError('missing_asset_metadata','旧物件缺少历史素材配置，整批未应用：'.$row['asset_id'],409);
        $moving[]=[$row,$item];
    }
    // Keep every legal original position before relocating any conflict.
    $relocate=[];
    $valid=static function(int $x,int $y,array $foot) use (&$occupied): bool {
        try { $rect=shared_town_validate_rectangle($x,$y,$foot); } catch (ApiError) { return false; }
        foreach($occupied as $other) if(shared_town_intersects($rect,$other))return false;
        return true;
    };
    $append=static function(array $row,array $item,int $x,int $y) use (&$occupied,&$changes): void {
        $occupied[]=shared_town_validate_rectangle($x,$y,$item['footprint']);
        $changes[]=['object_id'=>$row['object_id'],'asset_id'=>$row['asset_id'],'owner_user_id'=>$row['owner_user_id'],
            'before'=>['x'=>(int)$row['tile_x'],'y'=>(int)$row['tile_y'],'footprint'=>json_decode($row['footprint_json'],true),'version'=>(int)$row['object_version']],
            'after'=>['x'=>$x,'y'=>$y,'footprint'=>$item['footprint']]];
    };
    foreach($moving as [$row,$item]) {
        if($valid((int)$row['tile_x'],(int)$row['tile_y'],$item['footprint']))$append($row,$item,(int)$row['tile_x'],(int)$row['tile_y']);
        else $relocate[]=[$row,$item];
    }
    foreach($relocate as [$row,$item]) {
        $found=false;$ox=(int)$row['tile_x'];$oy=(int)$row['tile_y'];
        for($d=1;$d<SHARED_TOWN_MAP_SIZE*2 && !$found;$d++) for($y=max(0,$oy-$d);$y<=min(SHARED_TOWN_MAP_SIZE-1,$oy+$d) && !$found;$y++) {
            $dx=$d-abs($y-$oy);$xs=array_unique([$ox-$dx,$ox+$dx]);sort($xs,SORT_NUMERIC);
            foreach($xs as $x) if($valid($x,$y,$item['footprint'])){$append($row,$item,$x,$y);$found=true;break;}
        }
        if(!$found)throw new ApiError('reflow_no_space','物件 '.$row['object_id'].' 找不到合法位置，整批未应用。',409);
    }
    usort($changes,static fn($a,$b)=>strcmp($a['object_id'],$b['object_id']));
    $active['items']=array_values($activeItems);
    unset($active['release_id'],$active['release_version'],$active['config_sha256']);
    $plan=['world'=>shared_town_world($pdo),'draft_items'=>array_values($draftItems),'config'=>$active,'objects'=>$changes];
    $plan['plan_sha256']=hash('sha256',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return $plan;
}

function town_asset_apply_reflow(PDO $pdo,string $actor,string $expected,DateTimeImmutable $now): array
{
    $pdo->beginTransaction();
    try {
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $pdo->query("SELECT world_id FROM town_worlds WHERE world_id='main'".$lock)->fetch();
        $pdo->query('SELECT asset_id FROM town_asset_items ORDER BY asset_id'.$lock)->fetchAll();
        $pdo->query('SELECT singleton_id FROM town_asset_active_release'.$lock)->fetchAll();
        $plan=town_asset_reflow_plan($pdo);
        if(!hash_equals($plan['plan_sha256'],$expected))throw new ApiError('reflow_conflict','数据已变化，请重新生成预演。',409);
        $stamp=auth_sql_time($now);
        foreach($plan['draft_items'] as $item)$pdo->prepare('UPDATE town_asset_items SET footprint_json=:foot,collision_json=:collision,updated_at=:at WHERE asset_id=:id')->execute([
            ':foot'=>json_encode($item['footprint'],JSON_THROW_ON_ERROR),':collision'=>json_encode($item['collision'],JSON_THROW_ON_ERROR),':at'=>$stamp,':id'=>$item['id']]);
        foreach($plan['objects'] as $change){
            $before=$change['before'];$after=$change['after'];
            if($before['x']===$after['x'] && $before['y']===$after['y'] && $before['footprint']===$after['footprint'])continue;
            $query=$pdo->prepare('SELECT * FROM town_objects WHERE object_id=:id');$query->execute([':id'=>$change['object_id']]);$row=$query->fetch(PDO::FETCH_ASSOC);
            if(intdiv($before['x'],24)!==intdiv($after['x'],24)||intdiv($before['y'],24)!==intdiv($after['y'],24))shared_town_change($pdo,$row,'delete',$now);
            $row['tile_x']=$after['x'];$row['tile_y']=$after['y'];$row['footprint_json']=json_encode($after['footprint'],JSON_THROW_ON_ERROR);
            $row['chunk_x']=intdiv($after['x'],24);$row['chunk_y']=intdiv($after['y'],24);$row['object_version']++;
            $pdo->prepare('UPDATE town_objects SET tile_x=:x,tile_y=:y,chunk_x=:cx,chunk_y=:cy,footprint_json=:foot,object_version=:v,updated_at=:at WHERE object_id=:id')->execute([
                ':x'=>$row['tile_x'],':y'=>$row['tile_y'],':cx'=>$row['chunk_x'],':cy'=>$row['chunk_y'],':foot'=>$row['footprint_json'],':v'=>$row['object_version'],':at'=>$stamp,':id'=>$row['object_id']]);
            shared_town_change($pdo,$row,'move',$now);
        }
        $current=town_asset_active_config($pdo);$config=$plan['config'];
        $json=json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
        if($hash!==($current['config_sha256']??null) && count($config['items'])) {
            $version=(int)$pdo->query('SELECT COALESCE(MAX(release_version),0) FROM town_asset_releases')->fetchColumn()+1;$id=auth_random_id();
            $pdo->prepare('INSERT INTO town_asset_releases (release_id,release_version,config_json,config_sha256,published_by,published_at) VALUES (?,?,?,?,?,?)')->execute([$id,$version,$json,$hash,$actor,$stamp]);
            $pdo->prepare('UPDATE town_asset_active_release SET release_id=? WHERE singleton_id=1')->execute([$id]);
        }
        $pdo->prepare("UPDATE town_worlds SET terrain_version=CASE WHEN terrain_version<4 THEN 4 ELSE terrain_version END,world_version=world_version+1,updated_at=? WHERE world_id='main'")->execute([$stamp]);
        $pdo->commit();return $plan;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
