<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../part_taxonomy.php';

$dryRun = in_array('--dry-run', $argv, true);

$stmt = $pdo->query("
    SELECT id, name, manufacturer, mpn, footprint, part_type
    FROM parts
    ORDER BY id
");
$parts = $stmt->fetchAll();

$update = $pdo->prepare("
    UPDATE parts
    SET category = ?, subcategory = ?, tags = ?
    WHERE id = ?
");

$changed = 0;

foreach ($parts as $part) {
    $classified = classify_part($part);
    $changed++;

    echo sprintf(
        "#%d\t%s\t%s / %s\t%s\n",
        (int)$part['id'],
        $part['name'],
        $classified['category'],
        $classified['subcategory'],
        $classified['tags']
    );

    if (!$dryRun) {
        $update->execute([
            $classified['category'],
            $classified['subcategory'],
            $classified['tags'],
            (int)$part['id'],
        ]);
    }
}

echo ($dryRun ? 'Dry run' : 'Updated') . ": {$changed} parts\n";
