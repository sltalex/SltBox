<?php
declare(strict_types=1);

if (($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Usage: php scripts/reset_wildlife_catalog.php --apply\n");
    exit(2);
}

$required = ['DESKBOX_DB_HOST', 'DESKBOX_DB_PORT', 'DESKBOX_DB_NAME', 'DESKBOX_DB_USER', 'DESKBOX_DB_PASSWORD'];
foreach ($required as $name) {
    if (($value = getenv($name)) === false || $value === '') {
        fwrite(STDERR, "Missing environment variable: {$name}\n");
        exit(2);
    }
}

$host = (string)getenv('DESKBOX_DB_HOST');
$port = (int)getenv('DESKBOX_DB_PORT');
$database = (string)getenv('DESKBOX_DB_NAME');
$user = (string)getenv('DESKBOX_DB_USER');
$password = (string)getenv('DESKBOX_DB_PASSWORD');
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ((int)$pdo->query('SELECT VERSION()')->fetchColumn() < 8)
    throw new RuntimeException('MySQL 8 or later is required.');

$stateTables = [
    'wildlife_encounter_state',
    'wildlife_inventory',
    'wildlife_discoveries',
    'wildlife_capture_operations',
    'wildlife_catalog_settings',
];
$migration = file_get_contents(dirname(__DIR__) . '/server/deskbox-api/database/016_wildlife_catalog_v2.sql');
if ($migration === false) throw new RuntimeException('Wildlife catalog migration could not be read.');
$migrationStatements = array_values(array_filter(array_map('trim',
    preg_split('/;\s*(?:\r?\n|$)/', trim($migration)) ?: [])));
foreach ($migrationStatements as $statement)
    if (preg_match('/\bCREATE\s+TABLE\b/i', $statement)) $pdo->exec($statement);

$tables = [...$stateTables, 'wildlife_catalog_entries'];
if ((int)$pdo->query('SELECT COUNT(*) FROM wildlife_catalog_entries WHERE catalog_version=2')->fetchColumn() > 0)
    throw new RuntimeException('Catalog v2 is already installed. Refusing to repeat the destructive reset.');
$stamp = gmdate('Ymd_His');
$before = [];
$backups = [];
foreach ($tables as $table) {
    $before[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $backup = "{$table}_backup_{$stamp}";
    $pdo->exec("CREATE TABLE `{$backup}` LIKE `{$table}`");
    $backups[$table] = $backup;
}

$pdo->beginTransaction();
try {
    // Copy and reset under the same transaction. InnoDB holds source row/range
    // locks while copying, so captures cannot disappear between backup and reset.
    foreach ($tables as $table) {
        $backup = $backups[$table];
        $pdo->exec("INSERT INTO `{$backup}` SELECT * FROM `{$table}`");
        $before[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$backup}`")->fetchColumn();
    }
    foreach ($tables as $table) $pdo->exec("DELETE FROM `{$table}`");
    foreach ($migrationStatements as $statement)
        if (!preg_match('/\bCREATE\s+TABLE\b/i', $statement)) $pdo->exec($statement);
    $after = [];
    foreach ($tables as $table) $after[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $versions = $pdo->query('SELECT catalog_version,COUNT(*) AS species_count FROM wildlife_catalog_entries GROUP BY catalog_version ORDER BY catalog_version')
        ->fetchAll();
    if (array_intersect_key($after, array_fill_keys($stateTables, true)) !== array_fill_keys($stateTables, 0) ||
        $after['wildlife_catalog_entries'] !== 291 || $versions !== [
        ['catalog_version' => 1, 'species_count' => 107],
        ['catalog_version' => 2, 'species_count' => 184],
    ]) throw new RuntimeException('Wildlife reset verification failed.');
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

echo json_encode(['before' => $before, 'after' => $after, 'backups' => $backups, 'catalogs' => $versions],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
