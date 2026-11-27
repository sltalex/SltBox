<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

const TOWN_NPC_PERSONALITY_KEYS = ['courage', 'kindness', 'curiosity', 'sociability', 'diligence', 'caution', 'creativity', 'independence'];

function town_npc_personality_defaults(): array
{
    return array_fill_keys(TOWN_NPC_PERSONALITY_KEYS, 50);
}

function town_npc_personality_table_available(PDO $pdo): bool
{
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') return (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='town_npc_personality'")->fetchColumn() > 0;
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='town_npc_personality'")->fetchColumn() > 0;
    } catch (Throwable $error) { return false; }
}

function town_npc_personality_normalize(array $values, ?array $fallback = null): array
{
    $result = $fallback ?? town_npc_personality_defaults();
    foreach (TOWN_NPC_PERSONALITY_KEYS as $key) if (array_key_exists($key, $values)) $result[$key] = max(1, min(100, (int)$values[$key]));
    return $result;
}

function town_npc_personality_level(int $value): string
{
    $value = max(1, min(100, $value));
    return $value <= 20 ? '很低' : ($value <= 40 ? '较低' : ($value <= 60 ? '一般' : ($value <= 80 ? '较高' : '很高')));
}

function town_npc_personality_public(array $values, int $revision = 1, string $updatedAt = ''): array
{
    $values = town_npc_personality_normalize($values); $levels = [];
    foreach ($values as $key => $value) $levels[$key] = town_npc_personality_level((int)$value);
    return ['values' => $values, 'levels' => $levels, 'revision' => max(1, $revision), 'updated_at' => $updatedAt];
}

function town_npc_personality_for(PDO $pdo, string $npcId): array
{
    $defaults = town_npc_personality_public(town_npc_personality_defaults());
    if (!town_npc_personality_table_available($pdo)) return $defaults;
    try {
        $q = $pdo->prepare('SELECT courage,kindness,curiosity,sociability,diligence,caution,creativity,independence,revision,updated_at FROM town_npc_personality WHERE npc_id=:npc LIMIT 1');
        $q->execute([':npc' => $npcId]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $defaults;
        $values = []; foreach (TOWN_NPC_PERSONALITY_KEYS as $key) $values[$key] = (int)$row[$key];
        return town_npc_personality_public($values, (int)$row['revision'], (string)$row['updated_at']);
    } catch (Throwable $error) { return $defaults; }
}

function town_npc_attach_personality(PDO $pdo, array $rows): array
{
    $found = [];
    if ($rows !== [] && town_npc_personality_table_available($pdo)) {
        $ids = array_values(array_unique(array_filter(array_map(static fn($row): string => (string)($row['npc_id'] ?? ''), $rows))));
        if ($ids !== []) try {
            $q = $pdo->prepare('SELECT npc_id,courage,kindness,curiosity,sociability,diligence,caution,creativity,independence,revision,updated_at FROM town_npc_personality WHERE npc_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $q->execute($ids); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) { $v=[]; foreach (TOWN_NPC_PERSONALITY_KEYS as $key) $v[$key]=(int)$row[$key]; $found[(string)$row['npc_id']] = town_npc_personality_public($v,(int)$row['revision'],(string)$row['updated_at']); }
        } catch (Throwable $error) { }
    }
    foreach ($rows as $index => $row) $rows[$index]['personality'] = $found[(string)($row['npc_id'] ?? '')] ?? town_npc_personality_public(town_npc_personality_defaults());
    return $rows;
}

function town_npc_personality_save(PDO $pdo, string $npcId, array $values, string $reason = 'admin', ?DateTimeImmutable $now = null): array
{
    if (!town_npc_personality_table_available($pdo)) throw new ApiError('personality_migration_required', 'NPC 人格迁移尚未执行。', 503);
    $now ??= api_clock(); $next = town_npc_personality_normalize($values); $stamp = auth_sql_time($now); $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME); $owns = !$pdo->inTransaction(); if ($owns) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT courage,kindness,curiosity,sociability,diligence,caution,creativity,independence,revision FROM town_npc_personality WHERE npc_id=:npc' . ($driver === 'mysql' ? ' FOR UPDATE' : '')); $q->execute([':npc'=>$npcId]); $row=$q->fetch(PDO::FETCH_ASSOC); $old=is_array($row)?town_npc_personality_normalize($row):town_npc_personality_defaults(); $revision=is_array($row)?(int)$row['revision']:0; $delta=[]; foreach(TOWN_NPC_PERSONALITY_KEYS as $key) if($next[$key]!==$old[$key]) $delta[$key]=$next[$key]-$old[$key];
        if ($revision < 1) { $sql='INSERT INTO town_npc_personality (npc_id,courage,kindness,curiosity,sociability,diligence,caution,creativity,independence,revision,updated_at) VALUES (:npc,:courage,:kindness,:curiosity,:sociability,:diligence,:caution,:creativity,:independence,1,:updated)'; $revision=1; } else { $sql='UPDATE town_npc_personality SET courage=:courage,kindness=:kindness,curiosity=:curiosity,sociability=:sociability,diligence=:diligence,caution=:caution,creativity=:creativity,independence=:independence,revision=revision+1,updated_at=:updated WHERE npc_id=:npc'; $revision++; }
        $pdo->prepare($sql)->execute([':npc'=>$npcId,':courage'=>$next['courage'],':kindness'=>$next['kindness'],':curiosity'=>$next['curiosity'],':sociability'=>$next['sociability'],':diligence'=>$next['diligence'],':caution'=>$next['caution'],':creativity'=>$next['creativity'],':independence'=>$next['independence'],':updated'=>$stamp]);
        if($delta!==[] && town_npc_life_table_exists($pdo,'town_npc_personality_changes')) $pdo->prepare('INSERT INTO town_npc_personality_changes (npc_id,before_json,delta_json,after_json,reason,created_at) VALUES (:npc,:before,:delta,:after,:reason,:created)')->execute([':npc'=>$npcId,':before'=>json_encode($old,JSON_UNESCAPED_UNICODE),':delta'=>json_encode($delta,JSON_UNESCAPED_UNICODE),':after'=>json_encode($next,JSON_UNESCAPED_UNICODE),':reason'=>substr($reason,0,255),':created'=>$stamp]);
        if(function_exists('town_npc_emit_event') && $delta!==[]) town_npc_emit_event($pdo,$npcId,'personality_changed',['personality'=>town_npc_personality_public($next,$revision,$stamp),'delta'=>$delta,'reason'=>substr($reason,0,255)],$now);
        if($owns) $pdo->commit(); return town_npc_personality_public($next,$revision,$stamp);
    } catch(Throwable $error){ if($owns && $pdo->inTransaction())$pdo->rollBack(); throw $error; }
}

function town_npc_decision_context(PDO $pdo, string $npcId, ?DateTimeImmutable $now = null): array
{
    $now ??= api_clock(); $stats = function_exists('town_npc_stats_for') ? town_npc_stats_for($pdo,$npcId) : ['values'=>[]]; $personality = town_npc_personality_for($pdo,$npcId); $values = is_array($stats['values'] ?? null) ? $stats['values'] : town_npc_stat_defaults();
    $stamina=(int)($values['stamina']??60); $health=(int)($values['health']??60); $stress=(int)($values['stress']??40); $mood=(int)($values['mood']??60);
    $profile = [];
    try { $q=$pdo->prepare('SELECT p.short_term_goal,p.long_term_goal FROM town_npc_profiles p WHERE p.npc_id=:npc LIMIT 1'); $q->execute([':npc'=>$npcId]); $profile=$q->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $error) { }
    $runtime = [];
    try {
        $columns = ['current_goal','desire_json','plan_json','needs_json','last_decision_context_json'];
        $select = [];
        foreach ($columns as $column) if (function_exists('town_npc_life_column_exists') && town_npc_life_column_exists($pdo,'town_npc_runtime_states',$column)) $select[]=$column;
        if ($select !== []) { $q=$pdo->prepare('SELECT '.implode(',',$select).' FROM town_npc_runtime_states WHERE npc_id=:npc LIMIT 1'); $q->execute([':npc'=>$npcId]); $runtime=$q->fetch(PDO::FETCH_ASSOC) ?: []; }
    } catch (Throwable $error) { }
    $needs = town_npc_personality_decode($runtime['needs_json'] ?? null, []);
    if ($needs === []) $needs=['rest'=>100-$stamina,'safety'=>100-$health,'social'=>100-(int)($values['social']??50),'pressure'=>$stress,'hunger'=>0,'thirst'=>0];
    return ['personality'=>$personality,'stats'=>$stats,'needs'=>$needs,'current_state'=>['mood'=>$mood,'stamina'=>$stamina,'health'=>$health,'stress'=>$stress],'current_goal'=>(string)($runtime['current_goal'] ?? $profile['short_term_goal'] ?? ''),'desire'=>town_npc_personality_decode($runtime['desire_json'] ?? null, []),'plan'=>town_npc_personality_decode($runtime['plan_json'] ?? null, []),'memory_summary'=>[],'read_at'=>auth_sql_time($now)];
}

function town_npc_personality_decode(mixed $value, array $fallback = []): array
{
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return $fallback;
    try { $decoded=json_decode($value,true,32,JSON_THROW_ON_ERROR); return is_array($decoded)?$decoded:$fallback; } catch (Throwable $error) { return $fallback; }
}

/** Persist the exact values/context read before a decision, for admin auditing. */
function town_npc_record_decision_context(PDO $pdo, string $npcId, array $context, DateTimeImmutable $now): void
{
    if (!function_exists('town_npc_life_column_exists') || !town_npc_life_column_exists($pdo,'town_npc_runtime_states','last_decision_context_json')) return;
    $params=[':context'=>json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),':updated'=>auth_sql_time($now),':npc'=>$npcId];
    $sql='UPDATE town_npc_runtime_states SET last_decision_context_json=:context,updated_at=:updated';
    if (town_npc_life_column_exists($pdo,'town_npc_runtime_states','current_goal') && (string)($context['current_goal'] ?? '') !== '') { $sql.=',current_goal=:goal'; $params[':goal']=substr((string)$context['current_goal'],0,255); }
    $sql.=' WHERE npc_id=:npc'; $pdo->prepare($sql)->execute($params);
}

/** Update needs and 1-100 state after elapsed simulation time or an action. */
function town_npc_tick_life(PDO $pdo, string $npcId, DateTimeImmutable $now, ?string $action = null, int $durationMinutes = 0): void
{
    if (!function_exists('town_npc_stats_for') || !function_exists('town_npc_stats_save')) return;
    $stats=town_npc_stats_for($pdo,$npcId); $values=is_array($stats['values'] ?? null)?$stats['values']:[]; if ($values===[]) return;
    $factor=max(1,min(60,(int)max(1,$durationMinutes ?: 1)));
    $runtimeNeeds = []; $lastTickAt = null; $hasTickColumn = false;
    if (function_exists('town_npc_life_table_exists') && town_npc_life_table_exists($pdo, 'town_npc_runtime_states') && function_exists('town_npc_life_column_exists') && town_npc_life_column_exists($pdo, 'town_npc_runtime_states', 'needs_json')) {
        try {
            $hasTickColumn = town_npc_life_column_exists($pdo, 'town_npc_runtime_states', 'last_life_tick_at');
            $select = 'needs_json' . ($hasTickColumn ? ',last_life_tick_at' : '');
            $needsQuery = $pdo->prepare('SELECT ' . $select . ' FROM town_npc_runtime_states WHERE npc_id=:npc LIMIT 1'); $needsQuery->execute([':npc'=>$npcId]); $runtimeRow = $needsQuery->fetch(PDO::FETCH_ASSOC) ?: [];
            $runtimeNeeds = town_npc_personality_decode($runtimeRow['needs_json'] ?? null, []); $lastTickAt = $runtimeRow['last_life_tick_at'] ?? null;
        } catch (Throwable $error) { $runtimeNeeds = []; $lastTickAt = null; }
    }
    if ($runtimeNeeds === []) $runtimeNeeds = ['rest'=>100-(int)($values['stamina']??60),'safety'=>100-(int)($values['health']??60),'social'=>100-(int)($values['social']??50),'pressure'=>(int)($values['stress']??40),'hunger'=>0,'thirst'=>0];
    $elapsedMinutes = max(1, min(60, (int)max(1, $durationMinutes ?: 1)));
    if ($action === null && $hasTickColumn && is_string($lastTickAt) && trim($lastTickAt) !== '' && function_exists('auth_parse_sql_time')) {
        try { $elapsedMinutes = max(0, min(60, intdiv(max(0, $now->getTimestamp() - auth_parse_sql_time($lastTickAt)->getTimestamp()), 60))); } catch (Throwable $error) { $elapsedMinutes = 1; }
    }
    $delta=[];
    switch ($action) {
        case 'rest': $delta=['stamina'=>min(8,$factor*2),'stress'=>-min(6,$factor),'health'=>min(2,(int)ceil($factor/10)),'mood'=>min(2,(int)ceil($factor/10))]; break;
        case 'work': $delta=['stamina'=>-min(12,(int)ceil($factor/2)),'stress'=>min(8,(int)ceil($factor/3)),'labor'=>min(2,(int)ceil($factor/20)),'mood'=>-min(3,(int)ceil($factor/10))]; break;
        case 'walk': $delta=['stamina'=>-min(8,(int)ceil($factor/3))]; break;
        case 'talk': case 'player_talk': $delta=['stamina'=>-min(4,(int)ceil($factor/5)),'social'=>min(3,(int)ceil($factor/10)),'stress'=>-min(3,(int)ceil($factor/10)),'mood'=>1]; break;
        case 'trade': $delta=['stamina'=>-1,'social'=>1,'stress'=>-1]; break;
    }
    $next = $values;
    if ($delta!==[]) { foreach($delta as $key=>$amount) if(array_key_exists($key,$next)) $next[$key]=(int)$next[$key]+(int)$amount; try { town_npc_stats_save($pdo,$npcId,$next,'action',$action ?? 'life_tick','行动后状态变化',$now); } catch(Throwable $error) { } }
    if ($action === null) {
        // A worker snapshot is a small simulation tick. Keep needs persistent
        // between snapshots so the NPC gradually seeks food, water, company
        // or shelter instead of being reset to a stat-derived baseline.
        $runtimeNeeds['hunger'] = min(100, max(0, (int)($runtimeNeeds['hunger'] ?? 0) + $elapsedMinutes));
        $runtimeNeeds['thirst'] = min(100, max(0, (int)($runtimeNeeds['thirst'] ?? 0) + $elapsedMinutes));
        $runtimeNeeds['rest'] = max(0, min(100, 100-(int)($values['stamina']??60)));
        $runtimeNeeds['safety'] = max(0, min(100, 100-(int)($values['health']??60)));
        $runtimeNeeds['social'] = max(0, min(100, (int)($runtimeNeeds['social'] ?? (100-(int)($values['social']??50)))));
        $runtimeNeeds['pressure'] = max(0, min(100, (int)($values['stress']??40)));
    } else {
        $runtimeNeeds['rest'] = max(0, min(100, 100-(int)($next['stamina']??60)));
        $runtimeNeeds['safety'] = max(0, min(100, 100-(int)($next['health']??60)));
        $runtimeNeeds['social'] = max(0, min(100, (int)($runtimeNeeds['social'] ?? (100-(int)($next['social']??50))) - ($action === 'talk' || $action === 'player_talk' ? 8 : 0)));
        $runtimeNeeds['pressure'] = max(0, min(100, (int)($next['stress']??40)));
        if ($action === 'rest') { $runtimeNeeds['hunger'] = min(100, (int)($runtimeNeeds['hunger'] ?? 0) + 1); $runtimeNeeds['thirst'] = min(100, (int)($runtimeNeeds['thirst'] ?? 0) + 1); }
    }
    if (function_exists('town_npc_life_column_exists') && town_npc_life_column_exists($pdo,'town_npc_runtime_states','needs_json')) {
        $sql = 'UPDATE town_npc_runtime_states SET needs_json=:needs,updated_at=:updated'; $params = [':needs'=>json_encode($runtimeNeeds,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),':updated'=>auth_sql_time($now),':npc'=>$npcId];
        if ($hasTickColumn) { $sql .= ',last_life_tick_at=:tick'; $params[':tick'] = auth_sql_time($now); }
        $pdo->prepare($sql . ' WHERE npc_id=:npc')->execute($params);
    }
}

/** Merge recent experience into one auditable reflection at most every six hours. */
function town_npc_reflect_if_due(PDO $pdo, string $npcId, DateTimeImmutable $now): void
{
    if (!function_exists('town_npc_insert_cognitive_memory') || !function_exists('town_npc_life_column_exists') || !town_npc_life_column_exists($pdo, 'town_npc_memories', 'observation') || !town_npc_life_column_exists($pdo, 'town_npc_runtime_states', 'last_reflection_at')) return;
    try {
        $q=$pdo->prepare('SELECT last_reflection_at FROM town_npc_runtime_states WHERE npc_id=:npc LIMIT 1'); $q->execute([':npc'=>$npcId]); $last=$q->fetchColumn();
        if (is_string($last) && $last !== '' && auth_parse_sql_time($last)->modify('+6 hours') > $now) return;
        $q=$pdo->prepare('SELECT memory_type,content,importance FROM town_npc_memories WHERE npc_id=:npc ORDER BY importance DESC,memory_id DESC LIMIT 8'); $q->execute([':npc'=>$npcId]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return;
        $failures=0; $latest=''; foreach($rows as $row){$latest=(string)($row['content']??$latest); if(str_contains((string)($row['memory_type']??''),'fail')||str_contains((string)($row['content']??''),'失败'))$failures++;}
        $context=town_npc_decision_context($pdo,$npcId,$now); $values=$context['stats']['values']??[]; $personality=$context['personality']['values']??[];
        $thought=((int)($personality['curiosity']??50)>=65)?'我会继续验证最近经历中的线索。':'我先记住最重要的结果，按计划稳步推进。';
        town_npc_insert_cognitive_memory($pdo,$npcId,'reflection','我回顾了最近的'.$rows[0]['memory_type'].'经历：'.mb_substr($latest,0,360,'UTF-8'),4,$now,['observation'=>'最近记忆条数：'.count($rows),'interpretation'=>$failures>0?'最近有行动没有按计划完成，需要准备备用方案。':'最近的行动大体符合计划。','thought_summary'=>$thought,'reasoning_summary'=>'综合近期行动、失败和对话记录，而不是保存模型隐藏推理。','belief'=>$failures>0?'原来的计划需要留出替代路线。':'当前地点和日程仍然可靠。','conclusion'=>$failures>0?'下次先检查阻碍再行动。':'可以继续当前生活计划。','intention'=>'根据新结论调整下一步行动。','plan_json'=>['next'=>$failures>0?'replan':'continue','memory_count'=>count($rows)],'confidence'=>0.7]);
        $pdo->prepare('UPDATE town_npc_runtime_states SET last_reflection_at=:reflected WHERE npc_id=:npc')->execute([':reflected'=>auth_sql_time($now),':npc'=>$npcId]);
    } catch (Throwable $error) { }
}

/** Store an auditable cognition summary; hidden chain-of-thought is never persisted. */
function town_npc_record_action_cognition(PDO $pdo, string $npcId, string $action, string $phase, array $payload, DateTimeImmutable $now, ?string $outcome = null): void
{
    // During a rolling upgrade keep legacy test/production schemas unchanged;
    // the structured record starts once migration 056 is present.
    if (!function_exists('town_npc_insert_cognitive_memory') || !function_exists('town_npc_life_column_exists') || !town_npc_life_column_exists($pdo, 'town_npc_memories', 'observation')) return;
    $context=town_npc_decision_context($pdo,$npcId,$now); $values=$context['stats']['values'] ?? []; $personality=$context['personality']['values'] ?? [];
    $target=(string)($payload['target_id'] ?? $payload['job'] ?? '当前地点'); $reason=(string)($payload['reason'] ?? '根据当前日程、状态和人格进行选择。');
    $observation='准备执行'.$action.'，目标：'.$target.'。';
    $interpretation='我根据当前环境和过去记忆判断这件事可以推进。';
    if ((int)($values['stamina']??60)<=25) $interpretation='我的体能偏低，需要把休息和安全放在前面。';
    if ((int)($values['stress']??40)>=80) $interpretation='我的压力偏高，应该减少冲突并选择稳妥行动。';
    $thought=((int)($personality['curiosity']??50)>=70)?'我想弄清楚更多细节，再决定下一步。':'我先把眼前最实际的事情做好。';
    $plan=['action'=>$action,'target'=>$target,'phase'=>$phase,'next'=>$action==='rest'?'恢复体能':'检查结果并继续计划'];
    town_npc_insert_cognitive_memory($pdo,$npcId,'action_'.$phase,$observation.' '.$reason,3,$now,['observation'=>$observation,'interpretation'=>$interpretation,'thought_summary'=>$thought,'reasoning_summary'=>$reason,'belief'=>'这项行动会影响我的目标和状态。','conclusion'=>$outcome ?? '行动尚未得到最终结果。','intention'=>$action==='rest'?'恢复后再行动':'完成目标后重新评估。','plan_json'=>$plan,'confidence'=>0.75,'source_task_id'=>(string)($payload['task_id'] ?? '')],[],null);
    try { town_npc_record_decision_context($pdo,$npcId,$context,$now); } catch(Throwable $error) { }
}

function town_npc_action_allowed_by_values(PDO $pdo, string $npcId, string $action): void
{
    if (!function_exists('town_npc_stats_for')) return;
    $values = town_npc_stats_for($pdo,$npcId)['values'] ?? []; $health=(int)($values['health']??60); $stamina=(int)($values['stamina']??60);
    if ($action !== 'rest' && ($health <= 20 || $stamina <= 20)) throw new ApiError('npc_too_tired','NPC 当前体力或健康过低，必须先休息。',409);
    if (in_array($action,['walk','talk','trade','use_item','observe','help'],true) && $health <= 5) throw new ApiError('npc_health_critical','NPC 当前健康过低，无法开始行动。',409);
}
