<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';
require dirname(__DIR__, 3) . '/_app/town_story_events.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $lease);
    $body = api_body(32768, false, 32768); $seed = filter_var($body['seed'] ?? null, FILTER_VALIDATE_INT);
    $draft = is_array($body['draft'] ?? null) ? $body['draft'] : null;
    $modelRunId = is_string($body['model_run_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', (string)$body['model_run_id']) ? (string)$body['model_run_id'] : null;
    return ['ok' => true] + town_story_event_generate($pdo, api_clock(), $seed === false ? null : (int)$seed, $draft, $modelRunId);
});
