<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

function shared_town_player_obstacles(PDO $pdo): array
{
    $query = $pdo->prepare('SELECT tile_x,tile_y,footprint_json FROM town_objects WHERE world_id=:world AND deleted_at IS NULL');
    $query->execute([':world'=>SHARED_TOWN_WORLD_ID]);
    return array_map('shared_town_rectangle', $query->fetchAll(PDO::FETCH_ASSOC));
}

function shared_town_player_can_stand(float $x, float $y, array $obstacles): bool
{
    $body = ['x'=>($x-3)/16, 'y'=>($y-4)/16, 'width'=>6/16, 'height'=>4/16];
    if ($body['x']<0 || $body['y']<0 || $body['x']+$body['width']>SHARED_TOWN_MAP_SIZE
        || $body['y']+$body['height']>SHARED_TOWN_MAP_SIZE) return false;
    for ($ty=(int)floor($body['y']); $ty<(int)ceil($body['y']+$body['height']); $ty++) {
        for ($tx=(int)floor($body['x']); $tx<(int)ceil($body['x']+$body['width']); $tx++) {
            if (in_array(shared_town_tile_type($tx,$ty), ['void','river','riverbank','forest'], true)) return false;
        }
    }
    foreach ($obstacles as $obstacle) if (shared_town_intersects($body,$obstacle)) return false;
    return true;
}

function shared_town_player_public(array $position): array
{
    $x=(float)$position['pixel_x']; $y=(float)$position['pixel_y'];
    return ['pixel_x'=>$x, 'pixel_y'=>$y, 'tile_x'=>(int)floor($x/16), 'tile_y'=>(int)floor($y/16)];
}

function shared_town_player_write(PDO $pdo, string $userId, float $x, float $y, DateTimeImmutable $now, bool $initial = false): void
{
    $sql='INSERT INTO town_player_positions (world_id,user_id,pixel_x,pixel_y,updated_at) VALUES (:world,:user,:x,:y,:at)';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $sql.=$initial ? ' ON CONFLICT(world_id,user_id) DO NOTHING'
            : ' ON CONFLICT(world_id,user_id) DO UPDATE SET pixel_x=excluded.pixel_x,pixel_y=excluded.pixel_y,updated_at=excluded.updated_at';
    } else {
        $sql.=$initial ? ' ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)'
            : ' ON DUPLICATE KEY UPDATE pixel_x=VALUES(pixel_x),pixel_y=VALUES(pixel_y),updated_at=VALUES(updated_at)';
    }
    $pdo->prepare($sql)->execute([':world'=>SHARED_TOWN_WORLD_ID, ':user'=>$userId,
        ':x'=>round($x,2), ':y'=>round($y,2), ':at'=>auth_sql_time($now)]);
}

/** First entry chooses a safe random tile; later entries restore the durable position. */
function shared_town_player_position(PDO $pdo, string $userId, DateTimeImmutable $now, ?callable $random = null): array
{
    $random ??= 'random_int';
    $read=$pdo->prepare('SELECT pixel_x,pixel_y FROM town_player_positions WHERE world_id=:world AND user_id=:user');
    $params=[':world'=>SHARED_TOWN_WORLD_ID, ':user'=>$userId];
    $read->execute($params); $saved=$read->fetch(PDO::FETCH_ASSOC);
    $obstacles=shared_town_player_obstacles($pdo);
    if (is_array($saved) && shared_town_player_can_stand((float)$saved['pixel_x'],(float)$saved['pixel_y'],$obstacles)) {
        return shared_town_player_public($saved);
    }
    if (!is_array($saved)) {
        for ($attempt=0; $attempt<512; $attempt++) {
            $x=$random(1,SHARED_TOWN_MAP_SIZE-2)*16+8;
            $y=$random(1,SHARED_TOWN_MAP_SIZE-2)*16+15;
            if (shared_town_player_can_stand($x,$y,$obstacles)) {
                shared_town_player_write($pdo,$userId,$x,$y,$now,true);
                $read->execute($params);
                return shared_town_player_public($read->fetch(PDO::FETCH_ASSOC));
            }
        }
    }
    // If a new building covers the saved location, relocate only as far as needed.
    $cx=is_array($saved)?(int)floor((float)$saved['pixel_x']/16):$random(1,SHARED_TOWN_MAP_SIZE-2);
    $cy=is_array($saved)?(int)floor((float)$saved['pixel_y']/16):$random(1,SHARED_TOWN_MAP_SIZE-2);
    $cx=max(0,min(SHARED_TOWN_MAP_SIZE-1,$cx)); $cy=max(0,min(SHARED_TOWN_MAP_SIZE-1,$cy));
    for ($distance=0; $distance<SHARED_TOWN_MAP_SIZE*2; $distance++) {
        for ($ty=max(0,$cy-$distance); $ty<=min(SHARED_TOWN_MAP_SIZE-1,$cy+$distance); $ty++) {
            $dx=$distance-abs($ty-$cy);
            foreach (array_unique([$cx-$dx,$cx+$dx]) as $tx) {
                if ($tx<0 || $tx>=SHARED_TOWN_MAP_SIZE) continue;
                $x=$tx*16+8; $y=$ty*16+15;
                if (!shared_town_player_can_stand($x,$y,$obstacles)) continue;
                shared_town_player_write($pdo,$userId,$x,$y,$now,!is_array($saved));
                $read->execute($params);
                return shared_town_player_public($read->fetch(PDO::FETCH_ASSOC));
            }
        }
    }
    throw new ApiError('town_no_spawn', '小镇没有可站立的位置，请稍后重试。', 409);
}
