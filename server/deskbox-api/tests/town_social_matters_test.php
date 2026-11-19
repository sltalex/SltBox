<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';
require dirname(__DIR__) . '/api/_app/town_social_matters.php';

function social_matter_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = api_clock(); $stamp = auth_sql_time($now);
$alice = str_repeat('a', 32); $bob = str_repeat('b', 32); $carol = str_repeat('c', 32);
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,navigation_version INTEGER,world_version INTEGER DEFAULT 1,updated_at TEXT);
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,name TEXT,world_id TEXT,avatar_asset_id TEXT,appearance_json TEXT,occupation TEXT,enabled INTEGER,agent_paused INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,state TEXT,current_task_id TEXT,dialogue TEXT,mood_json TEXT,capabilities_json TEXT,state_version INTEGER,updated_at TEXT,facing TEXT);
CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE town_npc_tasks (task_id TEXT PRIMARY KEY,npc_id TEXT,action TEXT,target_id TEXT,payload_json TEXT,status TEXT,attempts INTEGER,scheduled_at TEXT,started_at TEXT,completed_at TEXT,failure_reason TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);
CREATE TABLE town_world_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,world_id TEXT,source_key TEXT,event_type TEXT,actor_npc_id TEXT,target_npc_id TEXT,public_payload_json TEXT,visibility TEXT,occurred_at TEXT,expires_at TEXT,created_at TEXT,UNIQUE(world_id,source_key));
CREATE TABLE town_social_matters (matter_id TEXT PRIMARY KEY,world_id TEXT,kind TEXT,source_event_id INTEGER,creator_npc_id TEXT,state TEXT,public_summary TEXT,options_json TEXT,deadline_at TEXT,idempotency_key TEXT,revision INTEGER,failure_reason TEXT,created_at TEXT,updated_at TEXT,closed_at TEXT,UNIQUE(world_id,idempotency_key));
CREATE TABLE town_social_responses (response_id TEXT PRIMARY KEY,matter_id TEXT,resident_id TEXT,option_id TEXT,response_kind TEXT,public_text TEXT,idempotency_key TEXT,created_at TEXT,UNIQUE(matter_id,resident_id),UNIQUE(matter_id,idempotency_key));
CREATE TABLE town_social_commitments (commitment_id TEXT PRIMARY KEY,matter_id TEXT,owner_npc_id TEXT,option_id TEXT,action_id TEXT,task_id TEXT,conditions_json TEXT,state TEXT,due_at TEXT,failure_reason TEXT,idempotency_key TEXT,created_at TEXT,updated_at TEXT,completed_at TEXT,UNIQUE(matter_id,idempotency_key));");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?,?,?)')->execute(['main', 1, 1, $stamp]);
$npcInsert = $pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?,?,?,?,?)');
$npcInsert->execute([$alice, '甲', 'main', '', '{}', 'farmer', 1, 0, $stamp, $stamp]);
$npcInsert->execute([$bob, '乙', 'main', '', '{}', 'craftsman', 1, 0, $stamp, $stamp]);
$npcInsert->execute([$carol, '丙', 'main', '', '{}', 'merchant', 1, 0, $stamp, $stamp]);
$runtimeInsert = $pdo->prepare("INSERT INTO town_npc_runtime_states (npc_id,tile_x,tile_y,state,current_task_id,dialogue,mood_json,capabilities_json,state_version,updated_at,facing) VALUES (?,?,?,'rest',NULL,'','{}','[]',1,?,'south')");
$runtimeInsert->execute([$alice, 10, 10, $stamp]);
$runtimeInsert->execute([$bob, 12, 10, $stamp]);
$runtimeInsert->execute([$carol, 14, 10, $stamp]);

$options = [
    ['option_id' => 'repair_market', 'label' => '修复集市摊位', 'candidate_npc_ids' => [$bob], 'max_commitments' => 1, 'action_id' => 'repair_market', 'npc_action' => 'rest', 'task_payload' => ['duration_minutes' => 1], 'conditions' => ['requires_item' => 'wood']],
    ['option_id' => 'supply_seeds', 'label' => '供应种子', 'allowed_occupations' => ['farmer', 'merchant'], 'max_commitments' => 2, 'action_id' => 'supply_seeds'],
];

// api_body() keeps nested JSON objects as stdClass values. Exercise the same
// wire shape used by the Worker so the public endpoint cannot reject a valid
// options array before candidate validation begins.
$wireOptions = json_decode(json_encode($options, JSON_THROW_ON_ERROR), false, 16, JSON_THROW_ON_ERROR);
$wireMatter = town_social_create_matter($pdo, [
    'kind' => 'wire_shape', 'public_summary' => '嵌套 JSON 入参探针。',
    'options' => $wireOptions, 'idempotency_key' => 'wire-shape-1',
], $now);
social_matter_expect(($wireMatter['created'] ?? false) === true
    && is_array($wireMatter['matter']['options'] ?? null)
    && is_array($wireMatter['matter']['options'][0] ?? null),
    'Nested stdClass options from api_body must normalize to option arrays.');
town_social_close_matter($pdo, [
    'matter_id' => $wireMatter['matter']['matter_id'], 'state' => 'cancelled',
    'failure_reason' => 'wire_shape_test_cleanup', 'force' => true,
], $now->modify('+1 second'));

$created = town_social_create_matter($pdo, ['kind' => 'market_dispute', 'public_summary' => '集市摊位需要在今天修好。', 'options' => $options, 'creator_npc_id' => $alice, 'idempotency_key' => 'market-1'], $now);
social_matter_expect(($created['created'] ?? false) === true, 'A social matter should be created.');
$matter = $created['matter']; $matterId = (string)$matter['matter_id'];
social_matter_expect($matter['state'] === 'open' && count($matter['options']) === 2 && count($matter['responses']) === 0, 'Created matter should expose its bounded options.');
social_matter_expect((int)$matter['source_event_id'] > 0 && (int)$matter['revision'] === 2, 'Creation should append one public event and advance the revision.');
$duplicate = town_social_create_matter($pdo, ['kind' => 'market_dispute', 'public_summary' => '不同文本不会覆盖原事务。', 'options' => $options, 'idempotency_key' => 'market-1'], $now->modify('+1 second'));
social_matter_expect(($duplicate['duplicate'] ?? false) === true && $duplicate['matter']['matter_id'] === $matterId, 'Creating the same idempotency key must return the original matter.');

try {
    town_social_submit_response($pdo, ['matter_id' => $matterId, 'resident_id' => $alice, 'option_id' => 'repair_market', 'idempotency_key' => 'resp-a'], $now->modify('+2 seconds'));
    throw new RuntimeException('Expected an ineligible candidate to be rejected.');
} catch (ApiError $error) { social_matter_expect($error->apiCode === 'social_candidate_ineligible', 'Candidate qualification must be server-authoritative.'); }
$response = town_social_submit_response($pdo, ['matter_id' => $matterId, 'resident_id' => $bob, 'option_id' => 'repair_market', 'response_kind' => 'support', 'public_text' => '我可以处理。', 'idempotency_key' => 'resp-b'], $now->modify('+3 seconds'));
social_matter_expect(($response['created'] ?? false) === true && $response['matter']['state'] === 'collecting', 'An eligible response should move the matter to collecting.');
$responseDuplicate = town_social_submit_response($pdo, ['matter_id' => $matterId, 'resident_id' => $bob, 'option_id' => 'repair_market', 'idempotency_key' => 'resp-b-again'], $now->modify('+4 seconds'));
social_matter_expect(($responseDuplicate['duplicate'] ?? false) === true && (int)$pdo->query('SELECT COUNT(*) FROM town_social_responses WHERE matter_id=' . $pdo->quote($matterId))->fetchColumn() === 1, 'A resident can respond only once and duplicate responses do not settle twice.');
$public = town_social_matter_row($pdo, $matterId, false);
social_matter_expect(($public['idempotency_key'] ?? '') === '' && ($public['options'][0]['candidate_npc_ids'] ?? []) === [], 'Public matter projection must not expose private candidate lists or idempotency keys.');

// Production NPCs keep an authoritative rest task while idle.  A social
// assignment may replace that idle task, but must not gain a blanket task
// interruption capability.
$idleRestTask = str_repeat('r', 32);
$pdo->prepare("INSERT INTO town_npc_tasks (task_id,npc_id,action,target_id,payload_json,status,attempts,scheduled_at,started_at) VALUES (?,?,'rest','npc-home','{}','running',1,?,?)")
    ->execute([$idleRestTask, $bob, $stamp, $stamp]);
$pdo->prepare('UPDATE town_npc_runtime_states SET current_task_id=? WHERE npc_id=?')->execute([$idleRestTask, $bob]);
$commitment = town_social_assign_commitment($pdo, ['matter_id' => $matterId, 'owner_npc_id' => $bob, 'option_id' => 'repair_market', 'action_id' => 'repair_market', 'idempotency_key' => 'commit-b'], $now->modify('+5 seconds'));
social_matter_expect(($commitment['created'] ?? false) === true && $commitment['matter']['state'] === 'executing' && preg_match('/\A[a-f0-9]{32}\z/D', (string)($commitment['commitment']['task_id'] ?? '')) === 1, 'An eligible response must create a bound authoritative NPC task.');
social_matter_expect(($pdo->query('SELECT status FROM town_npc_tasks WHERE task_id=' . $pdo->quote($idleRestTask))->fetchColumn() ?? '') === 'cancelled', 'Social assignment may replace an idle rest task.');
try {
    town_social_update_commitment($pdo, ['commitment_id' => $commitment['commitment']['commitment_id'], 'state' => 'completed'], $now->modify('+5 seconds'));
    throw new RuntimeException('Expected task-bound commitment completion to be rejected.');
} catch (ApiError $error) { social_matter_expect($error->apiCode === 'social_commitment_task_authority', 'Task-bound commitments may only advance from the authoritative task result.'); }
try {
    town_social_assign_commitment($pdo, ['matter_id' => $matterId, 'owner_npc_id' => $alice, 'option_id' => 'supply_seeds', 'action_id' => 'supply_seeds', 'idempotency_key' => 'commit-unbound'], $now->modify('+5 seconds'));
    throw new RuntimeException('Expected an unbound social option to be rejected.');
} catch (ApiError $error) { social_matter_expect($error->apiCode === 'social_action_unbound', 'Unbound social options must not create tasks.'); }
$commitmentDuplicate = town_social_assign_commitment($pdo, ['matter_id' => $matterId, 'owner_npc_id' => $bob, 'option_id' => 'repair_market', 'action_id' => 'repair_market', 'idempotency_key' => 'commit-b'], $now->modify('+6 seconds'));
social_matter_expect(($commitmentDuplicate['duplicate'] ?? false) === true, 'Commitment assignment is idempotent.');
try {
    town_social_close_matter($pdo, ['matter_id' => $matterId, 'state' => 'closed'], $now->modify('+7 seconds'));
    throw new RuntimeException('Expected pending commitments to block closure.');
} catch (ApiError $error) { social_matter_expect($error->apiCode === 'social_commitments_pending', 'Pending commitments must block closure.'); }
town_npc_advance_task($pdo, $bob, $now->modify('+2 minutes'), true);
$advanced = town_npc_advance_task($pdo, $bob, $now->modify('+3 minutes'), true);
social_matter_expect(($advanced['task_status'] ?? '') === 'completed', 'The authoritative NPC task should complete through the normal executor.');
$updated = town_social_require_matter($pdo, $matterId, true);
social_matter_expect(($updated['commitments'][0]['state'] ?? '') === 'completed', 'Task completion must update the social commitment.');
$closed = town_social_close_matter($pdo, ['matter_id' => $matterId, 'state' => 'closed'], $now->modify('+9 seconds'));
social_matter_expect(($closed['duplicate'] ?? false) === true && ($closed['matter']['state'] ?? '') === 'closed', 'A completed matter is idempotently closed.');
$closedAgain = town_social_close_matter($pdo, ['matter_id' => $matterId, 'state' => 'closed'], $now->modify('+10 seconds'));
social_matter_expect(($closedAgain['duplicate'] ?? false) === true, 'Closing an already closed matter is idempotent.');

$future = $now->modify('+30 minutes');
$expiring = town_social_create_matter($pdo, ['kind' => 'road_block', 'public_summary' => '道路需要清理。', 'options' => [['option_id' => 'clear_path', 'label' => '清理道路', 'candidate_npc_ids' => [$carol], 'action_id' => 'clear_path']], 'deadline_at' => $future->format(DateTimeInterface::ATOM), 'idempotency_key' => 'road-1'], $now);
$expired = town_social_expire($pdo, $future->modify('+1 second'));
social_matter_expect(($expired['expired_count'] ?? 0) === 1 && ($expired['matters'][0]['state'] ?? '') === 'failed', 'Expired matters must fail with a deterministic reason.');
$reason = $pdo->query('SELECT failure_reason FROM town_social_matters WHERE matter_id=' . $pdo->quote((string)$expiring['matter']['matter_id']))->fetchColumn();
social_matter_expect($reason === 'deadline_expired', 'Deadline expiry must record its reason.');
$events = (int)$pdo->query("SELECT COUNT(*) FROM town_world_events WHERE event_type LIKE 'social_matter_%'")->fetchColumn();
social_matter_expect($events >= 6, 'Matter lifecycle changes must be projected into the public event stream.');

$walking = town_social_create_matter($pdo, [
    'kind' => 'visit', 'public_summary' => '前往会合点', 'idempotency_key' => 'walk-regression',
    'options' => [['option_id' => 'visit_square', 'label' => '步行前往会合点', 'candidate_npc_ids' => [$alice],
        'action_id' => 'visit_square', 'npc_action' => 'walk', 'task_payload' => ['target' => ['tile_x' => 11, 'tile_y' => 10]]]],
], $now);
$walkingCommitment = town_social_assign_commitment($pdo, [
    'matter_id' => $walking['matter']['matter_id'], 'owner_npc_id' => $alice,
    'option_id' => 'visit_square', 'idempotency_key' => 'walk-assignment',
], $now);
$walkResult = town_npc_advance_task($pdo, $alice, $now->modify('+1 second'), true);
social_matter_expect($walkResult['task_status'] === 'completed', 'Walking fixture must reach the adjacent destination.');
$walkMatter = town_social_require_matter($pdo, $walking['matter']['matter_id'], true);
social_matter_expect($walkMatter['commitments'][0]['state'] === 'completed' && $walkMatter['state'] === 'closed',
    'Arrival must complete the bound walking commitment and close its matter in the same transaction.');
social_matter_expect(($public['options'][0]['conditions'] ?? []) === [],
    'Public options must exclude internal execution conditions, including nested task or memory data.');

echo "town_social_matters_test: checks ok\n";
