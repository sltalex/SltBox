<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/wildlife.php';

$root = dirname(__DIR__, 3);
$server = json_decode(file_get_contents(WILDLIFE_MANIFEST_PATH), true, 512, JSON_THROW_ON_ERROR);
$client = json_decode(file_get_contents($root . '/src/DeskBox/Assets/Tree/Wildlife/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
if ($server !== $client || $server['catalog_version'] !== 2)
    throw new RuntimeException('Client and server catalogs differ.');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
wildlife_ensure_catalog_schema($pdo);
$sql = file_get_contents(dirname(__DIR__) . '/database/016_wildlife_catalog_v2.sql');
$insert = substr($sql, strpos($sql, 'INSERT INTO wildlife_catalog_entries'));
$insert = substr($insert, 0, strpos($insert, 'ON DUPLICATE KEY UPDATE'));
$pdo->exec($insert);
foreach ([1 => 107, 2 => 184] as $version => $expected) {
    $catalog = wildlife_catalog($pdo, $version);
    $manifest = wildlife_manifest_catalog($version);
    if (count($catalog) !== $expected) throw new RuntimeException('Wrong versioned catalog count.');
    foreach ($catalog as $index => $item) {
        unset($item['enabled'], $item['encounter_weight']);
        $reference = $manifest[$index];
        ksort($item);
        ksort($reference);
        if ($item !== $reference) throw new RuntimeException('SQL seed differs: ' . $item['id']);
    }
}
if (wildlife_catalog_version(null) !== 1 || wildlife_catalog_version('2') !== 2)
    throw new RuntimeException('Version negotiation failed.');
foreach ([true, 2.0, 3, '02', [], 'invalid'] as $invalid) {
    try {
        wildlife_catalog_version($invalid);
        throw new RuntimeException('Invalid version was accepted.');
    } catch (ApiError $error) {
        if ($error->apiCode !== 'invalid_wildlife_catalog_version') throw $error;
    }
}
echo "wildlife_catalog_v2_test: OK\n";
