<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/_app/bootstrap.php';
require dirname(__DIR__) . '/_app/account_data.php';
require dirname(__DIR__) . '/_app/admin.php';
require dirname(__DIR__) . '/_app/admin_web.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; style-src 'unsafe-inline'");
header('Referrer-Policy: no-referrer');

function admin_page_escape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_page_redirect(string $location = ''): never
{
    header('Location: ' . ($location === '' ? './' : $location), true, 303);
    exit;
}

function admin_page_post_id(array $post, string $key): string
{
    $value = isset($post[$key]) && is_string($post[$key]) ? $post[$key] : '';
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $value)) {
        throw new ApiError('invalid_target', 'The selected account is invalid.', 400);
    }
    return $value;
}

function admin_page_nonnegative(array $post, string $key, string $code): int
{
    $value = isset($post[$key]) && is_string($post[$key]) ? trim($post[$key]) : '';
    if (!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $value) || (int)$value > 2147483647) {
        throw new ApiError($code, 'Please enter a nonnegative whole number.', 400);
    }
    return (int)$value;
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

function admin_page_mutate(PDO $pdo, array $actor, array $post): string
{
    web_admin_require_csrf($post);
    $operation = isset($post['operation']) && is_string($post['operation']) ? $post['operation'] : '';
    if ($operation === 'logout') { web_admin_sign_out(); return 'logged_out'; }

    $targetId = in_array($operation, ['fruit_price', 'shop_price'], true)
        ? $actor['user_id'] : admin_page_post_id($post, 'target_id');
    $body = ['action' => '', 'target_id' => $targetId, 'reason' => 'web_admin'];
    if ($operation === 'update_user') {
        $body += [
            'action' => 'update_user',
            'username' => isset($post['username']) && is_string($post['username']) ? $post['username'] : '',
            'status' => isset($post['status']) && is_string($post['status']) ? $post['status'] : '',
        ];
    } elseif ($operation === 'enable' || $operation === 'disable') {
        $body['action'] = $operation;
    } elseif ($operation === 'revoke_all') {
        $body['action'] = 'revoke_all_sessions';
    } elseif ($operation === 'revoke_session') {
        $body += ['action' => 'revoke_session', 'session_id' => admin_page_post_id($post, 'session_id')];
    } elseif ($operation === 'set_coins') {
        $body += ['action' => 'set_aquarium_coins', 'coins' => admin_page_nonnegative($post, 'coins', 'invalid_coins')];
    } elseif ($operation === 'delete_user') {
        $body += [
            'action' => 'delete_user',
            'confirm_username' => isset($post['confirm_username']) && is_string($post['confirm_username']) ? $post['confirm_username'] : '',
            'admin_password' => isset($post['admin_password']) && is_string($post['admin_password']) ? $post['admin_password'] : '',
        ];
    } elseif ($operation === 'fruit_price') {
        $body += ['action' => 'set_fruit_recycle_price', 'price_per_fruit' => admin_page_nonnegative($post, 'price_per_fruit', 'invalid_price')];
    } elseif ($operation === 'shop_price') {
        $body += ['action' => 'set_aquarium_card_pack_price', 'card_pack_price' => admin_page_nonnegative($post, 'card_pack_price', 'invalid_price')];
    } else {
        throw new ApiError('invalid_action', 'Unknown management operation.', 400);
    }
    admin_mutate($pdo, $actor['user_id'], $body, 'api_clock');
    return $operation;
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
        $complete = admin_page_mutate($pdo, $actor, $_POST);
        if ($complete === 'logged_out') { admin_page_redirect(); }
        $returnId = isset($_POST['return_id']) && is_string($_POST['return_id']) ? $_POST['return_id'] : '';
        $returnPanel = isset($_POST['panel']) && is_string($_POST['panel']) && $_POST['panel'] !== ''
            ? '&panel=' . rawurlencode($_POST['panel']) : '';
        admin_page_redirect($returnId !== '' ? './?id=' . rawurlencode($returnId) . '&done=' . rawurlencode($complete) . $returnPanel
            : './?done=' . rawurlencode($complete) . $returnPanel);
    }
    $actor = admin_page_session_actor($pdo);
    $done = isset($_GET['done']) && is_string($_GET['done']) ? $_GET['done'] : '';
    if ($done !== '') { $message = 'Operation completed.'; }
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
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SltBox 管理后台</title><style>
body{margin:0;background:#f4f7fb;color:#182033;font:15px/1.5 system-ui,"Microsoft YaHei",sans-serif}.login{width:min(400px,calc(100% - 40px));margin:12vh auto;padding:30px;border-radius:18px;background:#fff;box-shadow:0 16px 48px #1b2a4a1a}h1{margin:0 0 6px;font-size:26px}.muted{color:#65708a}.field{display:grid;gap:6px;margin-top:16px}input,select,button{box-sizing:border-box;font:inherit;border-radius:9px}input,select{width:100%;border:1px solid #c9d3e5;padding:10px}button{border:0;background:#2167d4;color:#fff;padding:10px 14px;cursor:pointer}.error{margin-top:16px;padding:10px;border-radius:9px;background:#fff0f0;color:#a32121}</style></head><body><main class="login"><h1>SltBox 管理后台</h1><p class="muted">使用管理员 SltBox 账号登录以管理用户。</p><?php if (!api_is_https($_SERVER)): ?><p class="error">当前连接使用 HTTP，账号密码和会话可能被截获。请仅在受信任网络中使用。</p><?php endif; ?><?php if ($error !== ''): ?><p class="error"><?= admin_page_escape($error) ?></p><?php endif; ?><form method="post"><input type="hidden" name="operation" value="login"><label class="field">账号<input name="username" autocomplete="username" required></label><label class="field">密码<input type="password" name="password" autocomplete="current-password" required></label><p><button type="submit">登录管理后台</button></p></form></main></body></html><?php
exit;
endif;

$csrf = web_admin_csrf_token();
$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$panel = isset($_GET['panel']) && is_string($_GET['panel']) && in_array($_GET['panel'], ['shop', 'fruit'], true)
    ? $_GET['panel'] : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$list = admin_read($pdo, $actor['user_id'], 'users', ['q' => $query, 'page' => $page, 'page_size' => 30], 'api_clock');
$overview = admin_read($pdo, $actor['user_id'], 'overview', [], 'api_clock')['overview'];
$recycle = admin_read($pdo, $actor['user_id'], 'fruit_recycle', [], 'api_clock');
$shop = admin_read($pdo, $actor['user_id'], 'shop', [], 'api_clock');
$selectedId = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
$detail = null;
if ($selectedId !== '') {
    try {
        $detail = admin_read($pdo, $actor['user_id'], 'user', ['id' => $selectedId], 'api_clock');
    } catch (ApiError $exception) {
        $error = $exception->getMessage();
    }
}
$audit = admin_read($pdo, $actor['user_id'], 'audit', [], 'api_clock')['audit'];
?><!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SltBox 管理后台</title><style>
:root{color-scheme:light}body{margin:0;background:#f4f7fb;color:#182033;font:14px/1.45 system-ui,"Microsoft YaHei",sans-serif}.shell{max-width:1240px;margin:auto;padding:22px}.top{display:flex;justify-content:space-between;align-items:center;gap:16px}.top h1{margin:0;font-size:25px}.muted{color:#65708a}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin:20px 0}.card{background:#fff;border:1px solid #e2e8f3;border-radius:13px;padding:15px;box-shadow:0 5px 16px #2736570b}.metric{font-size:24px;font-weight:700}.grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(330px,.9fr);gap:16px}.panel{background:#fff;border:1px solid #e2e8f3;border-radius:13px;padding:16px;overflow:auto}h2{font-size:17px;margin:0 0 12px}table{width:100%;border-collapse:collapse}th,td{padding:9px 7px;border-bottom:1px solid #edf1f7;text-align:left;vertical-align:top}th{font-size:12px;color:#65708a}input,select,button{box-sizing:border-box;font:inherit;border-radius:8px}input,select{border:1px solid #c9d3e5;padding:8px;max-width:100%}button{border:0;background:#2167d4;color:#fff;padding:8px 11px;cursor:pointer}.quiet{background:#e8eef9;color:#164c9f}.danger{background:#bc3131}.form-row{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.form-row label{display:grid;gap:4px;flex:1 1 120px}.notice{margin:12px 0;padding:10px;border-radius:9px;background:#e8f6ed;color:#16643a}.error{margin:12px 0;padding:10px;border-radius:9px;background:#fff0f0;color:#a32121}.pill{display:inline-block;padding:2px 7px;border-radius:99px;background:#e8f6ed;color:#16643a}.pill.off{background:#fff1e9;color:#a54a14}.audit{max-height:300px;overflow:auto}.actions{display:flex;flex-wrap:wrap;gap:7px}.actions form{display:inline}@media(max-width:850px){.grid{grid-template-columns:1fr}.shell{padding:14px}} </style></head><body><main class="shell">
<header class="top"><div><h1>SltBox 管理后台</h1><div class="muted">已登录：<?= admin_page_escape($actor['username']) ?></div><nav class="actions" style="margin-top:10px"><a class="<?= $panel === '' ? 'pill' : '' ?>" href="./">总览</a><a class="<?= $panel === 'shop' ? 'pill' : '' ?>" href="?panel=shop">商城设置</a><a class="<?= $panel === 'fruit' ? 'pill' : '' ?>" href="?panel=fruit">果实回收</a></nav></div><form method="post"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="logout"><button class="quiet">退出登录</button></form></header>
<?php if ($message !== ''): ?><p class="notice"><?= admin_page_escape($message) ?></p><?php endif; ?><?php if ($error !== ''): ?><p class="error"><?= admin_page_escape($error) ?></p><?php endif; ?>
<section class="cards"><div class="card"><div class="metric"><?= (int)$overview['users'] ?></div><div class="muted">用户总数</div></div><div class="card"><div class="metric"><?= (int)$overview['active_users'] ?></div><div class="muted">正常账号</div></div><div class="card"><div class="metric"><?= (int)$overview['sessions'] ?></div><div class="muted">有效会话</div></div><div class="card"><div class="metric"><?= (int)$overview['backups'] ?></div><div class="muted">云备份</div></div><div class="card"><div class="metric"><?= (int)$overview['tree_participants'] ?></div><div class="muted">种树参与者</div></div></section>
<section class="grid"><div class="panel"><h2>用户管理</h2><form method="get" class="form-row"><label>搜索账号<input name="q" value="<?= admin_page_escape($query) ?>" maxlength="32"></label><button type="submit">搜索</button></form><table><thead><tr><th>账号</th><th>状态</th><th>注册时间</th><th></th></tr></thead><tbody><?php foreach ($list['users'] as $user): ?><tr><td><?= admin_page_escape($user['username']) ?> <?= (int)$user['is_admin'] === 1 ? '<span class="pill">管理员</span>' : '' ?></td><td><span class="pill <?= $user['status'] === 'active' ? '' : 'off' ?>"><?= admin_page_escape($user['status']) ?></span></td><td><?= admin_page_escape($user['created_at']) ?></td><td><a href="?id=<?= rawurlencode($user['id']) ?>">管理</a></td></tr><?php endforeach; ?></tbody></table><p class="muted">第 <?= (int)$list['page'] ?> / <?= (int)$list['page_count'] ?> 页，共 <?= (int)$list['total'] ?> 个用户</p><div class="actions"><?php if ((int)$list['page'] > 1): ?><a class="quiet" href="?q=<?= rawurlencode($query) ?>&page=<?= (int)$list['page'] - 1 ?>">上一页</a><?php endif; ?><?php if ((int)$list['page'] < (int)$list['page_count']): ?><a class="quiet" href="?q=<?= rawurlencode($query) ?>&page=<?= (int)$list['page'] + 1 ?>">下一页</a><?php endif; ?></div></div>
<div class="panel" id="fruit-panel" <?= $panel !== '' && $panel !== 'fruit' ? 'hidden' : '' ?>><h2>果实回收</h2><form method="post" class="form-row"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="fruit_price"><input type="hidden" name="panel" value="fruit"><label>每个果实金币<input name="price_per_fruit" type="number" min="0" value="<?= (int)$recycle['price_per_fruit'] ?>" required></label><button type="submit">保存</button></form></div>
<div class="panel" id="shop-panel" <?= $panel !== '' && $panel !== 'shop' ? 'hidden' : '' ?>><h2>商城设置</h2><form method="post" class="form-row"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="shop_price"><input type="hidden" name="panel" value="shop"><label>水族卡包金币<input name="card_pack_price" type="number" min="0" value="<?= (int)$shop['card_pack_price'] ?>" required></label><button type="submit">保存</button></form><p class="muted">用户端下次刷新商城或进入水族馆设置时获取最新价格。</p></div>
<?php if (is_array($detail)): $user = $detail['user']; ?><div class="panel"><h2>管理用户：<?= admin_page_escape($user['username']) ?></h2><form method="post" class="form-row"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="update_user"><input type="hidden" name="target_id" value="<?= admin_page_escape($user['id']) ?>"><input type="hidden" name="return_id" value="<?= admin_page_escape($user['id']) ?>"><label>账号<input name="username" value="<?= admin_page_escape($user['username']) ?>" maxlength="32" required></label><label>状态<select name="status"><option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>正常</option><option value="disabled" <?= $user['status'] === 'disabled' ? 'selected' : '' ?> <?= (int)$user['is_admin'] === 1 ? 'disabled' : '' ?>>已禁用</option></select></label><button type="submit">保存用户</button></form><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="<?= $user['status'] === 'active' ? 'disable' : 'enable' ?>"><input type="hidden" name="target_id" value="<?= admin_page_escape($user['id']) ?>"><input type="hidden" name="return_id" value="<?= admin_page_escape($user['id']) ?>"><button class="<?= $user['status'] === 'active' ? 'danger' : 'quiet' ?>" <?= (int)$user['is_admin'] === 1 ? 'disabled' : '' ?>><?= $user['status'] === 'active' ? '禁用账号' : '启用账号' ?></button></form><form method="post"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="revoke_all"><input type="hidden" name="target_id" value="<?= admin_page_escape($user['id']) ?>"><input type="hidden" name="return_id" value="<?= admin_page_escape($user['id']) ?>"><button class="quiet" <?= (int)$user['is_admin'] === 1 ? 'disabled' : '' ?>>撤销全部会话</button></form></div><form method="post" class="form-row" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="set_coins"><input type="hidden" name="target_id" value="<?= admin_page_escape($user['id']) ?>"><input type="hidden" name="return_id" value="<?= admin_page_escape($user['id']) ?>"><label>水族馆金币<input name="coins" type="number" min="0" value="<?= (int)$detail['aquarium']['coins'] ?>" required></label><button type="submit">保存金币</button></form><h2 style="margin-top:20px">会话</h2><table><thead><tr><th>设备</th><th>最近更新</th><th></th></tr></thead><tbody><?php foreach ($detail['sessions'] as $session): ?><tr><td><?= admin_page_escape($session['device_id']) ?></td><td><?= admin_page_escape($session['updated_at']) ?></td><td><?php if ($session['revoked_at'] === null): ?><form method="post"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="revoke_session"><input type="hidden" name="target_id" value="<?= admin_page_escape($user['id']) ?>"><input type="hidden" name="session_id" value="<?= admin_page_escape($session['id']) ?>"><input type="hidden" name="return_id" value="<?= admin_page_escape($user['id']) ?>"><button class="quiet">撤销</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php if ((int)$user['is_admin'] !== 1): ?><h2 style="margin-top:20px">删除账号</h2><form method="post" class="form-row"><input type="hidden" name="csrf" value="<?= admin_page_escape($csrf) ?>"><input type="hidden" name="operation" value="delete_user"><input type="hidden" name="target_id" value="<?= admin_page_escape($user['id']) ?>"><label>确认账号<input name="confirm_username" placeholder="<?= admin_page_escape($user['username']) ?>" required></label><label>管理员密码<input type="password" name="admin_password" autocomplete="current-password" required></label><button class="danger" type="submit">删除账号</button></form><?php endif; ?></div><?php endif; ?>
<div class="panel"><h2>最近操作</h2><div class="audit"><table><thead><tr><th>时间</th><th>操作</th><th>结果</th></tr></thead><tbody><?php foreach ($audit as $entry): ?><tr><td><?= admin_page_escape($entry['created_at']) ?></td><td><?= admin_page_escape($entry['action']) ?></td><td><?= admin_page_escape($entry['result']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</section></main></body></html>
