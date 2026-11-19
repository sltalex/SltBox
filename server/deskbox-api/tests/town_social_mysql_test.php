<?php
declare(strict_types=1);

/**
 * Run against MySQL 8 with DESKBOX_TEST_MYSQL_DSN, DESKBOX_TEST_MYSQL_USER,
 * and DESKBOX_TEST_MYSQL_PASSWORD. The schema checks are read-only; the lock
 * probe uses a uniquely named scratch table and removes it in finally.
 */
$dsn = getenv('DESKBOX_TEST_MYSQL_DSN');
if (!is_string($dsn) || $dsn === '') {
    echo "town_social_mysql_test: skipped (MySQL DSN not configured)\n";
    exit;
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, getenv('DESKBOX_TEST_MYSQL_USER') ?: '', getenv('DESKBOX_TEST_MYSQL_PASSWORD') ?: '', $options);
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('This regression requires MySQL.');

$expectedTables = ['town_social_matters', 'town_social_responses', 'town_social_commitments'];
foreach ($expectedTables as $table) {
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
    $check->execute([':table' => $table]);
    if ((int)$check->fetchColumn() !== 1) throw new RuntimeException("Missing migrated table: {$table}");
}

$column = $pdo->prepare('SELECT DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column');
$column->execute([':table' => 'town_social_commitments', ':column' => 'task_id']);
$taskColumn = $column->fetch();
if (!is_array($taskColumn) || $taskColumn['DATA_TYPE'] !== 'char' || (int)$taskColumn['CHARACTER_MAXIMUM_LENGTH'] !== 32 || $taskColumn['IS_NULLABLE'] !== 'YES') {
    throw new RuntimeException('town_social_commitments.task_id does not match the authoritative task binding shape.');
}

$index = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:index_name');
$index->execute([':table' => 'town_social_commitments', ':index_name' => 'ix_town_social_commitments_task']);
if ((int)$index->fetchColumn() !== 1) throw new RuntimeException('Missing task binding index.');

$suffix = bin2hex(random_bytes(6));
$probe = 'town_social_lock_probe_' . $suffix;
$first = null;
$second = null;
try {
    $pdo->exec("CREATE TABLE `{$probe}` (id INT NOT NULL PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO `{$probe}` (id,value) VALUES (1,0)");
    $first = new PDO($dsn, getenv('DESKBOX_TEST_MYSQL_USER') ?: '', getenv('DESKBOX_TEST_MYSQL_PASSWORD') ?: '', $options);
    $second = new PDO($dsn, getenv('DESKBOX_TEST_MYSQL_USER') ?: '', getenv('DESKBOX_TEST_MYSQL_PASSWORD') ?: '', $options);
    $first->beginTransaction();
    $first->query("SELECT id FROM `{$probe}` WHERE id=1 FOR UPDATE")->fetchColumn();
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');
    $second->beginTransaction();
    try {
        $second->exec("UPDATE `{$probe}` SET value=value+1 WHERE id=1");
        throw new RuntimeException('A competing MySQL transaction unexpectedly bypassed FOR UPDATE.');
    } catch (PDOException $error) {
        if (!in_array((int)($error->errorInfo[1] ?? 0), [1205, 1213], true)) throw $error;
    } finally {
        if ($second->inTransaction()) $second->rollBack();
    }
    $first->rollBack();
    echo "town_social_mysql_test: ok (schema, task binding index, row lock)\n";
} finally {
    if ($second instanceof PDO && $second->inTransaction()) $second->rollBack();
    if ($first instanceof PDO && $first->inTransaction()) $first->rollBack();
    $pdo->exec("DROP TABLE IF EXISTS `{$probe}`");
}
