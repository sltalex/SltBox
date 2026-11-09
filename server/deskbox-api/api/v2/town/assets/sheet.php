<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/shared_town.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { header('Allow: GET'); throw new ApiError('method_not_allowed', 'Method not allowed.', 405); }
    $pdo = api_database(); $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $sheetId = isset($_GET['sheet_id']) && is_string($_GET['sheet_id']) ? trim($_GET['sheet_id']) : '';
    $active = town_asset_active_config($pdo); $allowed = false;
    foreach (($active['sheets'] ?? []) as $sheet) if (is_array($sheet) && ($sheet['sheet_id'] ?? '') === $sheetId) { $allowed = true; break; }
    if (!$allowed) throw new ApiError('sheet_not_found', 'The requested published sheet was not found.', 404);
    [$path, $mime] = town_asset_file($pdo, $sheetId);
    header('Content-Type: ' . $mime); header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff'); readfile($path); exit;
} catch (ApiError $error) { api_json(['code' => $error->apiCode, 'message' => $error->getMessage()], $error->httpStatus); }
catch (Throwable $error) { error_log('[DeskBoxTownAsset] ' . get_class($error) . ': ' . $error->getMessage()); api_json(['code' => 'server_error', 'message' => 'The town asset service is temporarily unavailable.'], 500); }
