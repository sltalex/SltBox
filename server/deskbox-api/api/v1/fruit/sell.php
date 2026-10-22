<?php
declare(strict_types=1); define('DESKBOX_API_ENTRY',true); require dirname(__DIR__,2).'/_app/bootstrap.php'; require dirname(__DIR__,2).'/_app/account_data.php'; require dirname(__DIR__,2).'/_app/aquarium.php'; require dirname(__DIR__,2).'/_app/fruit.php';
api_run('POST',static function(PDO $pdo):array{$u=account_authenticated_user($pdo,'api_clock');return fruit_sell($pdo,(string)$u['user_id'],api_body(), 'api_clock');});
