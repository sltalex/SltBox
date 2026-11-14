<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/_app/bootstrap.php';
require dirname(__DIR__) . '/_app/account_data.php';
require dirname(__DIR__) . '/_app/admin.php';
require dirname(__DIR__) . '/_app/admin_web.php';
require dirname(__DIR__) . '/_app/fruit.php';
require dirname(__DIR__) . '/_app/wildlife.php';
require dirname(__DIR__) . '/_app/catalog_report.php';
require dirname(__DIR__) . '/_app/town_assets.php';
require dirname(__DIR__) . '/_app/town_navigation.php';
require dirname(__DIR__) . '/_app/town_npcs.php';
require dirname(__DIR__) . '/_app/town_model_provider.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; style-src 'unsafe-inline'; script-src 'self'");
header('Referrer-Policy: no-referrer');

function admin_page_escape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_page_json(mixed $value, mixed $fallback = []): string
{
    if (!is_array($value)) $value = $fallback;
    return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function admin_page_time_value(mixed $value, int $fallback = 0): string
{
    $minute = is_string($value) && preg_match('/\A(\d{1,2}):(\d{2})\z/D', trim($value), $match)
        ? ((int)$match[1] * 60 + (int)$match[2]) : (int)$value;
    $minute = max(0, min(1440, $minute === 0 && $value === null ? $fallback : $minute));
    return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
}

function admin_town_asset_category_label(string $category): string
{
    static $labels = [
        'character' => '人物', 'animal' => '动物', 'monster' => '怪物', 'terrain' => '地形',
        'water' => '水面', 'farmland' => '农田', 'fence' => '围栏', 'building' => '建筑',
        'tree' => '树木', 'path' => '道路', 'object' => '物件', 'tool' => '工具',
        'ui' => '界面', 'portrait' => '头像', 'effect' => '特效', 'font' => '字体', 'other' => '其他',
    ];
    return $labels[$category] ?? $category;
}

function admin_page_redirect(string $location = ''): never
{
    header('Location: ' . ($location === '' ? './' : $location), true, 303);
    exit;
}

function admin_page_session_actor(PDO $pdo): array
{
    return web_admin_validate_session(
        $pdo,
        isset($_SESSION['user_id']) && is_string($_SESSION['user_id']) ? $_SESSION['user_id'] : '',
        isset($_SESSION['auth_session_id']) && is_string($_SESSION['auth_session_id']) ? $_SESSION['auth_session_id'] : null,
        'api_clock');
}

function admin_page_login(PDO $pdo, array $post): void
{
    $username = isset($post['username']) && is_string($post['username']) ? $post['username'] : '';
    $password = isset($post['password']) && is_string($post['password']) ? $post['password'] : '';
    $login = auth_login($pdo, $username, $password, 'web-admin', api_remote_address(), 'api_clock');
    $sessionId = web_admin_find_session_id($pdo, (string)$login['session']['access_token']);
    $actor = web_admin_validate_session($pdo, (string)$login['user']['id'], $sessionId, 'api_clock');
    if ($sessionId === null) { throw new ApiError('invalid_session', 'The management session could not be created.', 401); }
    session_regenerate_id(true);
    $_SESSION = ['user_id' => $actor['user_id'], 'auth_session_id' => $sessionId];
    web_admin_csrf_token();
}

web_admin_start_session();
$error = '';
$message = '';
$actor = null;
$pdo = null;
try {
    $pdo = api_database();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? $_POST['operation'] : '';
        if ($operation === 'login') {
            admin_page_login($pdo, $_POST);
            admin_page_redirect();
        }
        $actor = admin_page_session_actor($pdo);
        $complete = admin_page_mutate($pdo, $actor, $_POST, $_FILES);
        if ($complete === 'logged_out') { admin_page_redirect(); }
        $returnId = isset($_POST['return_id']) && is_string($_POST['return_id']) ? $_POST['return_id'] : '';
        $returnPanel = isset($_POST['panel']) && is_string($_POST['panel']) && $_POST['panel'] !== ''
            ? '&panel=' . rawurlencode($_POST['panel']) : '';
        admin_page_redirect($returnId !== '' ? './?id=' . rawurlencode($returnId) . '&done=' . rawurlencode($complete) . $returnPanel
            : './?done=' . rawurlencode($complete) . $returnPanel);
    }
    $actor = admin_page_session_actor($pdo);
    if (isset($_GET['sheet']) && is_string($_GET['sheet']) && $_GET['sheet'] !== '') {
        [$sheetPath, $sheetMime] = town_asset_file($pdo, $_GET['sheet']);
        header('Content-Type: ' . $sheetMime); header('Cache-Control: private, max-age=300');
        readfile($sheetPath); exit;
    }
    $done = isset($_GET['done']) && is_string($_GET['done']) ? $_GET['done'] : '';
    if ($done === 'town_model_provider_test') { $message = '模型连接成功，未发送对话请求。'; }
    elseif ($done === 'town_navigation_rebuild') { $message = '导航已重建，运行中的村民会在下一次行动校验时重新规划路线。'; }
    elseif ($done === 'town_asset_bulk_item') { $message = '自动识别的素材候选已批量加入草稿，可在下方逐项调整后发布。'; }
    elseif ($done !== '') { $message = '操作已完成。'; }
} catch (ApiError $exception) {
    if ($exception->apiCode === 'invalid_session' || $exception->apiCode === 'admin_forbidden') {
        web_admin_sign_out();
        $actor = null;
    }
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('[SltBoxAdminWeb] ' . get_class($exception) . ': ' . $exception->getMessage());
    $error = 'The management service is temporarily unavailable.';
}

if ($actor === null):
?><!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SltBox 管理后台</title><style>:root{color-scheme:light}
*,*::before,*::after{box-sizing:border-box}
body{
margin:0;
min-height:100vh;
display:flex;
align-items:center;
justify-content:center;
padding:24px;
background:#f4f6fb;
color:#16202f;
font:15px/1.6 system-ui,-apple-system,"Segoe UI","Microsoft YaHei",sans-serif;
-webkit-font-smoothing:antialiased;
}
.login{
width:min(408px,100%);
padding:34px 32px 30px;
border-radius:20px;
background:#fff;
border:1px solid #e5eaf3;
box-shadow:0 1px 2px rgba(16,24,40,.04),0 24px 60px rgba(16,24,40,.10);
}
.login h1{
display:flex;
align-items:center;
gap:11px;
margin:0 0 8px;
font-size:21px;
font-weight:600;
letter-spacing:-.01em;
}
.login h1::before{
content:"S";
flex:0 0 auto;
width:30px;
height:30px;
border-radius:9px;
background:#2563eb;
color:#fff;
font-size:16px;
font-weight:500;
display:inline-flex;
align-items:center;
justify-content:center;
box-shadow:0 4px 10px rgba(37,99,235,.28);
}
.login .muted{
margin:6px 0 0;
font-size:13px;
color:#5b6b85;
}
.field{
display:grid;
gap:6px;
margin-top:16px;
font-size:12.5px;
font-weight:500;
color:#5b6b85;
}
input{
width:100%;
height:40px;
padding:0 12px;
border:1px solid #d2dbea;
border-radius:10px;
background:#fff;
color:#16202f;
font:inherit;
font-size:14px;
transition:border-color .15s ease,box-shadow .15s ease;
}
input:hover{border-color:#bcc9dd}
input:focus{
outline:none;
border-color:#2563eb;
box-shadow:0 0 0 3px rgba(37,99,235,.15);
}
.login form p{margin:18px 0 0}
button{
width:100%;
height:42px;
border:0;
border-radius:10px;
background:#2563eb;
color:#fff;
font:inherit;
font-size:14px;
font-weight:500;
cursor:pointer;
transition:background .15s ease;
}
button:hover{background:#1d4ed8}
button:active{background:#1a43bd}
.login .error{
margin:16px 0 0;
padding:10px 12px;
border-radius:10px;
background:#fdeded;
color:#9c1c1c;
border:1px solid #f6d5d5;
font-size:13px;
line-height:1.55;
}
</style></head><body><main class="login"><h1>SltBox 管理后台</h1><p class="muted">使用管理员 SltBox 账号登录以管理用户。</p><?php if (!api_is_https($_SERVER)): ?><p class="error">当前连接使用 HTTP，账号密码和会话可能被截获。请仅在受信任网络中使用。</p><?php endif; ?><?php if ($error !== ''): ?><p class="error"><?= admin_page_escape($error) ?></p><?php endif; ?><form method="post"><input type="hidden" name="operation" value="login"><label class="field">账号<input name="username" autocomplete="username" required></label><label class="field">密码<input type="password" name="password" autocomplete="current-password" required></label><p><button type="submit">登录管理后台</button></p></form></main></body></html><?php
exit;
endif;

$csrf = web_admin_csrf_token();
$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$panel = isset($_GET['panel']) && is_string($_GET['panel']) && in_array($_GET['panel'], ['users', 'shop', 'tree', 'tree_settings', 'aquarium', 'wildlife', 'town_assets', 'town_npcs', 'town_agent', 'report'], true)
    ? $_GET['panel'] : 'users';
$townView = isset($_GET['view']) && is_string($_GET['view']) && in_array($_GET['view'], ['list', 'workpoints', 'memory', 'economy'], true) ? $_GET['view'] : 'list';
$panelView = $panel === 'town_npcs'
    ? ($townView === 'workpoints' ? 'town_workpoints.php' : ($townView === 'memory' ? 'town_memories.php' : ($townView === 'economy' ? 'town_economy.php' : 'town_npcs.php')))
    : $panel . '.php';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$list = null;
$overview = null;
$audit = [];
if ($panel === 'users') {
    $list = admin_read($pdo, $actor['user_id'], 'users', ['q' => $query, 'page' => $page, 'page_size' => 30], 'api_clock');
    $overview = admin_read($pdo, $actor['user_id'], 'overview', [], 'api_clock')['overview'];
    $audit = admin_read($pdo, $actor['user_id'], 'audit', [], 'api_clock')['audit'];
}
$fruitCatalog = [];
if ($panel === 'tree') {
    fruit_ensure_schema($pdo);
    $fruitCatalog = $pdo->query('SELECT id,name,category,atlas_index,enabled,probability,min_price,max_price FROM fruit_catalog ORDER BY atlas_index,id')->fetchAll(PDO::FETCH_ASSOC);
}
$aquariumCatalog = $panel === 'aquarium' ? aquarium_catalog($pdo)['items'] : [];
$wildlifeCatalog = $panel === 'wildlife' ? wildlife_catalog($pdo) : [];
$townAssetState = $panel === 'town_assets' ? town_asset_admin_state($pdo) : null;
$townNavigationVersion = $panel === 'town_assets' ? town_navigation_version($pdo) : 1;
$townNpcs = [];
$townCharacterAssets = [];
$townWorkPoints = [];
$townNpcRelationships = [];
$townNpcMemories = [];
$townNpcTransactions = [];
$townNpcGlobalPolicy = town_npc_life_policy_defaults();
$townNpcRelationPage = isset($_GET['relationship_page']) && is_string($_GET['relationship_page']) ? max(0, min(2000, (int)$_GET['relationship_page'])) : 0;
$townNpcMemoryPage = isset($_GET['memory_page']) && is_string($_GET['memory_page']) ? max(0, min(2000, (int)$_GET['memory_page'])) : 0;
$townNpcSocialNpcId = isset($_GET['social_npc_id']) && is_string($_GET['social_npc_id']) && preg_match('/\A[a-f0-9]{32}\z/D', $_GET['social_npc_id']) ? $_GET['social_npc_id'] : null;
$townNpcSocialPageSize = 50;
if (in_array($panel, ['town_npcs', 'town_agent'], true)) { try { $townNpcs = town_npc_rows_safe($pdo, true); } catch (Throwable $error) { $townNpcs = []; $error = 'NPC 数据表尚未迁移，请先执行 028_shared_town_npc_v1.sql。'; } }
if ($panel === 'town_npcs') {
    $townNpcGlobalPolicy = town_npc_life_global_policy($pdo);
    $townWorkPoints = town_npc_work_points($pdo, true);
    $townNpcRelationships = town_npc_relationship_rows($pdo, $townNpcSocialNpcId, $townNpcSocialPageSize, $townNpcRelationPage * $townNpcSocialPageSize);
    $townNpcMemories = town_npc_memory_rows($pdo, $townNpcSocialNpcId, $townNpcSocialPageSize, $townNpcMemoryPage * $townNpcSocialPageSize);
    $townNpcTransactions = town_npc_transaction_rows($pdo, $townNpcSocialNpcId, 100);
}
if ($panel === 'town_npcs') {
    try {
        $activeTownAssets = town_asset_active_config($pdo);
        foreach (($activeTownAssets['items'] ?? []) as $asset) {
            if (is_array($asset) && (($asset['category'] ?? '') === 'character' || ($asset['subcategory'] ?? '') === 'npc')) $townCharacterAssets[] = $asset;
        }
    } catch (Throwable) { $townCharacterAssets = []; }
}
$townModelProvider = $panel === 'town_agent' ? town_model_provider_public($pdo) : null;
$townAgentNpcId = isset($_GET['npc_id']) && is_string($_GET['npc_id']) && preg_match('/\A[a-f0-9]{32}\z/D', $_GET['npc_id']) ? $_GET['npc_id'] : null;
$townAgentDays = isset($_GET['days']) && is_string($_GET['days']) ? max(1, min(90, (int)$_GET['days'])) : 7;
$townAgentReport = $panel === 'town_agent' ? town_agent_admin_report($pdo, $townAgentDays, $townAgentNpcId) : null;
$townReflow = null; $townReflowError = null;
if ($panel === 'town_assets' && isset($_GET['preview_reflow'])) {
    require_once dirname(__DIR__) . '/_app/shared_town.php';
    try { $townReflow = town_asset_reflow_plan($pdo); } catch (Throwable $error) { $townReflowError=$error->getMessage(); }
}
$report = $panel === 'report' ? catalog_report($pdo) : [];
$reportType = isset($_GET['type']) && is_string($_GET['type']) && in_array($_GET['type'], ['fruit', 'aquarium', 'wildlife'], true)
    ? $_GET['type'] : 'fruit';
$reportCategory = isset($_GET['category']) && is_string($_GET['category']) ? $_GET['category'] : '';
$fruitCategory = isset($_GET['category']) && is_string($_GET['category']) ? $_GET['category'] : '';
$wildlifeCategory = isset($_GET['category']) && is_string($_GET['category']) ? $_GET['category'] : '';
$aquariumCategory = isset($_GET['category']) && is_string($_GET['category']) ? $_GET['category'] : '';
$fruitCategories = ['常见水果', '浆果', '柑橘', '热带水果', '坚果', '奇幻果实', '特殊果实'];
$aquariumCategories = ['fish'=>'鱼类', 'coral'=>'珊瑚', 'algae'=>'藻类', 'snail'=>'螺类',
    'jellyfish'=>'水母', 'decor'=>'装饰', 'plant'=>'植物', 'pack'=>'卡包'];
$reportNames = ['fruit' => '果实', 'aquarium' => '水族馆', 'wildlife' => '动物'];
$wildlifeSeasons = ['spring' => '春', 'summer' => '夏', 'autumn' => '秋', 'winter' => '冬'];
$wildlifePeriods = ['morning' => '清晨', 'day' => '白天', 'dusk' => '傍晚', 'night' => '夜间'];
$wildlifeWeathers = ['clear' => '晴', 'cloudy' => '多云', 'fog' => '雾', 'drizzle' => '毛毛雨',
    'rain' => '雨', 'snow' => '雪', 'thunderstorm' => '雷暴'];
$shop = $panel === 'shop' ? admin_read($pdo, $actor['user_id'], 'shop', [], 'api_clock') : null;
$treeFruitSettings = $panel === 'tree_settings'
    ? admin_read($pdo, $actor['user_id'], 'tree_fruit_settings', [], 'api_clock') : null;
$selectedId = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
$detail = null;
if ($panel === 'users' && $selectedId !== '') {
    try {
        $detail = admin_read($pdo, $actor['user_id'], 'user', ['id' => $selectedId], 'api_clock');
    } catch (ApiError $exception) {
        $error = $exception->getMessage();
    }
}
?><!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SltBox 管理后台</title><style>:root{
color-scheme:light;
--bg:#f2f5fa;
--surface:#fff;
--surface-2:#f7f9fc;
--border:#e6ebf3;
--border-strong:#d5dcea;
--text:#172033;
--text-2:#5b6b85;
--text-3:#8a97ab;
--brand:#2563eb;
--brand-ink:#1d4ed8;
--brand-soft:#eaf1fe;
--ok:#0b6b45;
--ok-soft:#e9f7f0;
--warn:#96540f;
--warn-soft:#fdf4e7;
--danger:#b91c1c;
--danger-soft:#fdeded;
--radius:12px;
--radius-sm:8px;
--shadow-sm:0 1px 2px rgba(16,24,40,.05);
--shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px rgba(16,24,40,.05);
}
*,*::before,*::after{box-sizing:border-box}
body{
margin:0;
background:var(--bg);
color:var(--text);
font:14px/1.6 system-ui,-apple-system,"Segoe UI","Microsoft YaHei",sans-serif;
-webkit-font-smoothing:antialiased;
}
a{color:var(--brand-ink);text-decoration:none}
a:hover{text-decoration:underline}
td a{white-space:nowrap}
code{
font-family:ui-monospace,SFMono-Regular,"Cascadia Mono",Consolas,monospace;
font-size:12px;
background:var(--surface-2);
border:1px solid var(--border);
border-radius:5px;
padding:1px 5px;
}
/* ---------- 整体框架：左侧栏 + 右侧内容 ---------- */
.app{display:flex;min-height:100vh}
.side{
flex:0 0 236px;
position:sticky;
top:0;
height:100vh;
overflow-y:auto;
background:#fff;
border-right:1px solid var(--border);
display:flex;
flex-direction:column;
}
.side-brand{
display:flex;
align-items:center;
gap:11px;
padding:18px 18px 14px;
border-bottom:1px solid var(--border);
}
.side-brand .logo{
flex:0 0 auto;
width:34px;height:34px;
border-radius:10px;
background:var(--brand);
color:#fff;
font-size:18px;font-weight:600;
display:inline-flex;align-items:center;justify-content:center;
box-shadow:0 4px 10px rgba(37,99,235,.28);
}
.side-brand .brand-title{font-size:15px;font-weight:600;line-height:1.25}
.side-brand .brand-sub{font-size:11.5px;color:var(--text-3);margin-top:1px}
.side-nav{flex:1 1 auto;padding:10px 10px 20px}
.side-group{margin-bottom:4px}
.side-group-label{
display:flex;align-items:center;justify-content:space-between;gap:6px;
width:100%;
padding:8px 10px;
border:0;border-radius:8px;
background:transparent;
color:var(--text-3);
font-size:12px;font-weight:600;letter-spacing:.02em;
cursor:pointer;white-space:nowrap;
}
.side-group-label:hover{background:var(--surface-2);color:var(--text-2)}
.side-group-label::after{
content:"";flex:0 0 auto;
width:6px;height:6px;
border-right:1.5px solid currentColor;
border-bottom:1.5px solid currentColor;
transform:rotate(-45deg);
transition:transform .15s ease;
}
.side-group.collapsed .side-group-label::after{transform:rotate(45deg)}
.side-items{display:flex;flex-direction:column;gap:2px;margin:2px 0 6px}
.side-group.collapsed .side-items{display:none}
.side-link{
display:flex;align-items:center;
height:34px;padding:0 10px 0 14px;
border-radius:8px;
font-size:13px;color:var(--text-2);
white-space:nowrap;text-decoration:none;
}
.side-link:hover{background:var(--surface-2);color:var(--text);text-decoration:none}
.side-link.active{
background:var(--brand-soft);color:var(--brand-ink);font-weight:600;
}
.main{flex:1 1 auto;min-width:0;display:flex;flex-direction:column}
.topbar{
position:sticky;top:0;z-index:20;
display:flex;align-items:center;justify-content:space-between;gap:16px;
padding:11px 24px;
background:#fff;border-bottom:1px solid var(--border);
}
.topbar .crumb{font-size:15px;font-weight:600}
.topbar .crumb .sep{color:var(--text-3);margin:0 8px;font-weight:400}
.topbar .crumb .current{color:var(--text-3);font-weight:400;font-size:13px}
.topbar form{margin:0}
.shell{flex:1 1 auto;width:100%;max-width:none;padding:18px 24px 48px}
/* ---------- 指标卡 ---------- */
.cards{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
gap:12px;
margin:0 0 16px;
}
.card{
position:relative;overflow:hidden;
background:var(--surface);
border:1px solid var(--border);
border-radius:var(--radius);
padding:13px 15px 12px;
box-shadow:var(--shadow-sm);
}
.card::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--brand)}
.cards .card:nth-child(2)::before{background:#0d9488}
.cards .card:nth-child(3)::before{background:#d97706}
.cards .card:nth-child(4)::before{background:#7c3aed}
.cards .card:nth-child(5)::before{background:#db2777}
.metric{font-size:24px;font-weight:600;line-height:1.15;letter-spacing:-.02em}
.card .muted{margin-top:2px;font-size:12px}
/* ---------- 面板与栅格 ---------- */
.grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(300px,.9fr);gap:14px}
.panel{
background:var(--surface);
border:1px solid var(--border);
border-radius:var(--radius);
padding:16px 18px;
box-shadow:var(--shadow);
overflow:auto;min-width:0;
}
h2{display:flex;align-items:center;gap:9px;margin:0 0 12px;font-size:15px;font-weight:600;letter-spacing:-.01em}
.panel>h2::before{content:"";flex:0 0 auto;width:3px;height:14px;border-radius:2px;background:var(--brand)}
.panel h3{margin:14px 0 8px;font-size:13px;font-weight:600}
.panel p{margin:0 0 10px}
.panel p:last-child{margin-bottom:0}
.panel img{max-width:100%;height:auto;border-radius:8px}
.muted{color:var(--text-2)}
/* ---------- 表格 ---------- */
table{width:100%;border-collapse:collapse;font-size:12.5px}
th,td{padding:8px 10px;text-align:left;vertical-align:top;border-bottom:1px solid #f0f3f9}
th{font-size:11.5px;font-weight:600;letter-spacing:.03em;color:var(--text-3);background:var(--surface-2);border-bottom:1px solid var(--border);white-space:nowrap}
tbody tr{transition:background .12s ease}
tbody tr:hover{background:#f8fbff}
tbody tr:last-child td{border-bottom:0}
.table-scroll{max-height:66vh;overflow:auto;margin-top:8px;border:1px solid var(--border);border-radius:10px}
.table-scroll table{font-size:12.5px}
.table-scroll th{position:sticky;top:0;z-index:2;background:var(--surface-2);box-shadow:inset 0 -1px 0 var(--border)}
.audit{max-height:320px;overflow:auto;border:1px solid var(--border);border-radius:10px}
.audit table{font-size:12.5px}
/* ---------- 表单控件 ---------- */
input,select,textarea,button{box-sizing:border-box;font:inherit}
input,select,textarea{
width:auto;max-width:100%;
padding:6px 9px;
border:1px solid var(--border-strong);
border-radius:var(--radius-sm);
background:#fff;color:var(--text);font-size:12.5px;
transition:border-color .15s ease,box-shadow .15s ease;
}
input,select{height:32px}
textarea{min-height:52px;line-height:1.5;resize:vertical}
input:hover,select:hover,textarea:hover{border-color:#bcc9dd}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(37,99,235,.14)}
input::placeholder{color:var(--text-3)}
button{
height:32px;padding:0 12px;
border:1px solid transparent;border-radius:var(--radius-sm);
background:var(--brand);color:#fff;font-size:12.5px;font-weight:500;
cursor:pointer;white-space:nowrap;
transition:background .15s ease,transform .06s ease;
}
button:hover{background:var(--brand-ink)}
button:active{transform:translateY(1px)}
button:disabled{opacity:.45;cursor:not-allowed;transform:none}
.quiet{background:var(--brand-soft);color:var(--brand-ink)}
.quiet:hover{background:#dbe8fd}
.danger{background:#dc2626}
.danger:hover{background:#b91c1c}
a.quiet{display:inline-flex;align-items:center;height:32px;padding:0 12px;border-radius:var(--radius-sm);background:var(--brand-soft);color:var(--brand-ink);font-size:12.5px;font-weight:500}
a.quiet:hover{background:#dbe8fd;text-decoration:none}
/* 表单行：标签顶对齐，控件内自适应，多控件并排 */
.form-row{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px 12px}
.form-row label{display:grid;gap:4px;flex:1 1 150px;min-width:0;align-content:start;font-size:12px;font-weight:500;color:var(--text-2)}
.form-row label input,.form-row label select,.form-row label textarea{font-size:12.5px;font-weight:400;color:var(--text)}
.form-row form{margin:0}
.form-row button{flex:0 0 auto;align-self:flex-end}
.inline-inputs{display:flex;gap:6px;flex-wrap:wrap;min-width:0}
.inline-inputs input,.inline-inputs select{flex:1 1 72px;min-width:60px}
.actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:12px}
.actions form{display:inline-flex;margin:0}
/* ---------- 提示 ---------- */
.notice{margin:0 0 14px;padding:10px 13px;border:1px solid #cdeddd;border-left:3px solid #12a06e;border-radius:var(--radius-sm);background:var(--ok-soft);color:#0b5f3d;font-size:13px}
.error{margin:0 0 14px;padding:10px 13px;border:1px solid #f6d5d5;border-left:3px solid #dc2626;border-radius:var(--radius-sm);background:var(--danger-soft);color:#9c1c1c;font-size:13px}
.pill{display:inline-flex;align-items:center;gap:4px;padding:1px 8px;border:1px solid #c9ecd9;border-radius:999px;background:var(--ok-soft);color:var(--ok);font-size:11px;font-weight:500;line-height:1.7;white-space:nowrap}
.pill.off{border-color:#f5dfc2;background:var(--warn-soft);color:var(--warn)}
.pill.neutral{border-color:var(--border-strong);background:var(--surface-2);color:var(--text-2)}
/* ---------- 折叠块 ---------- */
details{margin:8px 0;padding:0 14px;border:1px solid var(--border);border-radius:10px;background:var(--surface)}
details[open]{border-color:var(--border-strong);box-shadow:0 2px 10px rgba(16,24,40,.05)}
summary{display:flex;align-items:center;gap:4px;padding:10px 0;font-size:13px;color:var(--text);cursor:pointer;list-style:none}
summary::-webkit-details-marker{display:none}
summary::before{content:"";flex:0 0 auto;width:6px;height:6px;margin-right:6px;border-right:1.5px solid var(--text-3);border-bottom:1.5px solid var(--text-3);transform:rotate(-45deg);transition:transform .15s ease}
details[open]>summary::before{transform:rotate(45deg)}
summary:hover{color:var(--brand-ink)}
summary strong{font-weight:600}
details>form{padding-bottom:12px}
/* ---------- 村民卡片 ---------- */
.npc-card{border:1px solid var(--border);border-radius:var(--radius);background:var(--surface);box-shadow:var(--shadow-sm);margin-bottom:12px;overflow:hidden}
.npc-card-head{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--surface-2)}
.npc-card-head .name{font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:8px}
.npc-card-head .meta{font-size:12px;color:var(--text-2);display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap}
.npc-card-head .head-actions{margin-left:auto;display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap}
.npc-tabs{display:flex;flex-wrap:wrap;gap:2px;padding:6px 12px 0;border-bottom:1px solid var(--border);background:#fff}
.npc-tab{
height:34px;padding:0 14px;
border:0;border-radius:8px 8px 0 0;
background:transparent;color:var(--text-2);font-size:13px;font-weight:500;
cursor:pointer;white-space:nowrap;
border-bottom:2px solid transparent;
}
.npc-tab:hover{color:var(--text);background:var(--surface-2)}
.npc-tab.active{color:var(--brand-ink);font-weight:600;border-bottom-color:var(--brand)}
.npc-tab-body{padding:14px}
.npc-tab-panel{display:none}
.npc-tab-panel.active{display:block}
.npc-sub{margin:0 0 12px;font-size:12px;color:var(--text-3)}
/* ---------- 分页 ---------- */
.pager{display:flex;flex-wrap:wrap;align-items:center;gap:4px;margin-top:10px}
.pager button,.pager .pg-info{
height:28px;min-width:28px;padding:0 8px;
border:1px solid var(--border);border-radius:7px;
background:#fff;color:var(--text-2);font-size:12px;font-weight:500;
}
.pager button:hover{background:var(--surface-2);color:var(--text)}
.pager button.cur{background:var(--brand);border-color:var(--brand);color:#fff}
.pager .pg-info{border:0;background:transparent;color:var(--text-3)}
/* ---------- 素材编辑器（保持原布局） ---------- */
.town-frame-list{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px}
.town-frame-item{display:inline-flex;align-items:center;gap:4px;padding:3px 6px;border:1px solid var(--border);border-radius:7px;background:var(--surface-2);font-size:12px;color:var(--text-2)}
.town-frame-item button{height:24px;padding:0 7px;font-size:11.5px;border-radius:6px}
.town-frame-item button:disabled{opacity:.45}
.town-frame-list:empty{display:block;color:var(--text-3);font-size:12px}
.town-sheet-layout{display:grid;grid-template-columns:minmax(0,620px) minmax(260px,1fr);gap:16px;align-items:start}
.town-sheet-preview{max-width:620px;max-height:1250px;overflow:auto;border:1px solid var(--border-strong);border-radius:10px;background:var(--surface-2)}
.town-sheet-preview canvas{display:block;max-width:620px;max-height:1250px;width:auto;height:auto;image-rendering:pixelated;cursor:crosshair}
.town-sheet-controls{display:grid;gap:10px;align-content:start}
.town-assets-items{grid-column:1/-1;min-width:0}
.town-assets-items h3{margin:0 0 8px}
.town-assets-items .table-scroll{max-height:66vh}
.town-sheet-controls .form-row{margin:0}
.town-sheet-list{min-width:0;max-height:52vh;overflow:auto;border:1px solid var(--border);border-radius:10px}
.town-sheet-list table{font-size:12px}
.town-sheet-list code{word-break:break-all}
.town-frame-preview-host{display:block;flex-basis:100%;margin:6px 0}
.town-frame-preview{display:inline-flex;align-items:center;gap:8px;padding:5px 7px;border:1px solid var(--border);border-radius:8px;background:#fff}
.town-frame-preview-canvas{width:auto;height:auto;max-width:128px;max-height:96px;image-rendering:pixelated;background:repeating-conic-gradient(#f2f5fa 0 25%,#fff 0 50%) 0/10px 10px}
.town-frame-preview-controls{display:inline-flex;align-items:center;gap:4px;flex-wrap:wrap}
.town-frame-preview-controls button{height:26px;padding:0 7px;font-size:11.5px;border-radius:6px}
.town-frame-preview-label{color:var(--text-3);white-space:nowrap}
.town-auto-scan{display:grid;gap:6px;padding:8px;border:1px dashed var(--border);border-radius:8px;background:var(--surface-2)}
.town-auto-scan-results{display:flex;flex-wrap:wrap;gap:5px;max-height:180px;overflow:auto}
.town-auto-scan-results button{font-size:11px;padding:3px 6px}
/* ---------- 响应式 ---------- */
@media(max-width:1080px){
.side{flex-basis:200px}
.grid{grid-template-columns:1fr}
}
@media(max-width:850px){
.app{flex-direction:column}
.side{position:static;flex:none;height:auto;width:100%;max-height:46vh}
.shell{padding:14px 14px 40px}
.topbar{padding:10px 14px}
.panel{padding:14px}
.town-sheet-layout{grid-template-columns:1fr}
.town-sheet-preview{max-width:100%}
.town-sheet-preview canvas{max-width:100%;height:auto}
.form-row label{flex:1 1 100%}
}
/* ---------- 二级菜单 ---------- */
.side-sub{display:flex;flex-direction:column;gap:2px;margin:0 0 4px;padding-left:12px;border-left:2px solid var(--border)}
.side-link.sub{height:32px;padding-left:16px;font-size:12.5px;color:var(--text-3)}
.side-link.sub:hover{color:var(--text)}
.side-link.sub.active{color:var(--brand-ink);font-weight:600;background:var(--brand-soft)}
/* ---------- 面板操作栏 ---------- */
.panel-actions{display:flex;align-items:center;gap:8px}
.panel-actions .modal-open{background:var(--brand);color:#fff}
/* ---------- 悬浮窗 ---------- */
.modal-backdrop{position:fixed;inset:0;z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;background:rgba(15,23,42,.48);overflow-y:auto}
.modal-backdrop[hidden]{display:none}
.modal{width:100%;max-width:680px;background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(16,24,40,.32);overflow:hidden}
.modal-lg{max-width:920px}
.modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid var(--border)}
.modal-head h3{margin:0;font-size:15px;font-weight:600}
.modal-close{flex:0 0 auto;width:30px;height:30px;padding:0;border-radius:8px;background:var(--surface-2);color:var(--text-2);font-size:18px;line-height:1;border:1px solid var(--border)}
.modal-close:hover{background:#eef2f9;color:var(--text)}
.modal-body{padding:16px 18px 20px;max-height:78vh;overflow-y:auto}
.modal-body .form-row{margin:0}
/* 填写说明折叠块 */
.help-box{margin:0 0 4px;padding:0 14px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2)}
.help-box summary{font-size:13px;font-weight:600;color:var(--brand-ink)}
.help-table{font-size:12px}
.help-table th{white-space:nowrap}
.help-table td{vertical-align:top;line-height:1.55}
.help-table td:first-child{white-space:nowrap;font-weight:600;color:var(--text)}
.help-table td:nth-child(2){white-space:nowrap;color:var(--brand-ink)}
</style></head><body><div class="app">
<aside class="side">
<div class="side-brand">
<span class="logo">S</span>
<div>
<div class="brand-title">SltBox 管理后台</div>
<div class="brand-sub">已登录：<?= admin_page_escape($actor['username']) ?></div>
</div>
</div>
<nav class="side-nav">
<div class="side-group">
<button type="button" class="side-group-label">用户与运营</button>
<div class="side-items">
<a class="side-link<?= $panel === 'users' ? ' active' : '' ?>" href="?panel=users">用户管理</a>
<a class="side-link<?= $panel === 'report' ? ' active' : '' ?>" href="?panel=report">图鉴报表</a>
</div>
</div>
<div class="side-group">
<button type="button" class="side-group-label">商城与图鉴</button>
<div class="side-items">
<a class="side-link<?= $panel === 'shop' ? ' active' : '' ?>" href="?panel=shop">商城管理</a>
<a class="side-link<?= $panel === 'aquarium' ? ' active' : '' ?>" href="?panel=aquarium">水族馆图鉴</a>
<a class="side-link<?= $panel === 'tree' ? ' active' : '' ?>" href="?panel=tree">果实图鉴</a>
<a class="side-link<?= $panel === 'wildlife' ? ' active' : '' ?>" href="?panel=wildlife">动物图鉴</a>
</div>
</div>
<div class="side-group">
<button type="button" class="side-group-label">果树</button>
<div class="side-items">
<a class="side-link<?= $panel === 'tree_settings' ? ' active' : '' ?>" href="?panel=tree_settings">产果设置</a>
</div>
</div>
<div class="side-group">
<button type="button" class="side-group-label">共享小镇</button>
<div class="side-items">
<a class="side-link<?= $panel === 'town_assets' ? ' active' : '' ?>" href="?panel=town_assets">素材编辑</a>
<a class="side-link<?= $panel === 'town_npcs' ? ' active' : '' ?>" href="?panel=town_npcs">村民管理</a>
<div class="side-sub">
<a class="side-link sub<?= $panel === 'town_npcs' && $townView === 'list' ? ' active' : '' ?>" href="?panel=town_npcs">村民列表</a>
<a class="side-link sub<?= $panel === 'town_npcs' && $townView === 'workpoints' ? ' active' : '' ?>" href="?panel=town_npcs&amp;view=workpoints">工作地点</a>
<a class="side-link sub<?= $panel === 'town_npcs' && $townView === 'memory' ? ' active' : '' ?>" href="?panel=town_npcs&amp;view=memory">村民记忆</a>
<a class="side-link sub<?= $panel === 'town_npcs' && $townView === 'economy' ? ' active' : '' ?>" href="?panel=town_npcs&amp;view=economy">交易记录</a>
</div>
<a class="side-link<?= $panel === 'town_agent' ? ' active' : '' ?>" href="?panel=town_agent">Agent 模型</a>
</div>
</div>
</nav>
</aside>
<div class="main">
<header class="topbar">
<?php $panelTitles = ['users'=>'用户管理','shop'=>'商城管理','aquarium'=>'水族馆图鉴','tree'=>'果实图鉴','tree_settings'=>'产果设置','wildlife'=>'动物图鉴','town_assets'=>'素材编辑','town_npcs'=>'村民管理','town_agent'=>'Agent 模型','report'=>'图鉴报表'];
$townTitles = ['list'=>'村民列表','workpoints'=>'工作地点','memory'=>'村民记忆','economy'=>'交易记录'];
$crumbTitle = $panel === 'town_npcs' ? ($townTitles[$townView] ?? '村民管理') : ($panelTitles[$panel] ?? '控制台'); ?>
<div class="crumb">SltBox 管理后台<span class="sep">/</span><span class="current"><?= admin_page_escape($crumbTitle) ?></span></div>
<form method="post">
<input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>">
<input type="hidden" name="operation" value="logout">
<button class="quiet" type="submit">退出登录</button>
</form>
</header>
<main class="shell">


<?php if ($message !== ''): ?><p class="notice"><?= admin_page_escape($message) ?></p><?php endif; ?><?php if ($error !== ''): ?><p class="error"><?= admin_page_escape($error) ?></p><?php endif; ?>
<?php if ($panel === 'users'): ?><section class="cards"><div class="card"><div class="metric"><?= (int)$overview['users'] ?></div><div class="muted">用户总数</div></div><div class="card"><div class="metric"><?= (int)$overview['active_users'] ?></div><div class="muted">正常账号</div></div><div class="card"><div class="metric"><?= (int)$overview['sessions'] ?></div><div class="muted">有效会话</div></div><div class="card"><div class="metric"><?= (int)$overview['backups'] ?></div><div class="muted">云备份</div></div><div class="card"><div class="metric"><?= (int)$overview['tree_participants'] ?></div><div class="muted">种树参与者</div></div></section><?php endif; ?>
<section class="grid">
<?php require __DIR__ . '/views/' . $panelView; ?>
</section>
</main>
</div></div>
<script src="town-admin.js?v=20261114"></script>
</body></html>
