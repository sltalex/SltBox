<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_model_provider.php';

api_run('GET', static function (PDO $pdo): array {
    town_agent_require_service();
    $provider = town_model_provider_for_worker($pdo);
    return ['ok' => true, 'provider' => $provider, 'quota' => $provider === null ? null : [
        'quota_tokens' => (int)($provider['monthly_quota'] ?? 0),
        'used_tokens' => (int)($provider['used_tokens'] ?? 0),
        'reserved_tokens' => (int)($provider['reserved_tokens'] ?? 0),
        'remaining_tokens' => (int)($provider['remaining_tokens'] ?? 0),
        'unlimited' => (int)($provider['monthly_quota'] ?? 0) <= 0,
    ]];
});
