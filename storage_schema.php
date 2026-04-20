<?php

function storage_table_exists(PDO $pdo): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'storage_locations'
    ");
    $stmt->execute();
    return (int)$stmt->fetchColumn() > 0;
}

function storage_part_locations_table_exists(PDO $pdo): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'part_storage_locations'
    ");
    $stmt->execute();
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_storage_schema(PDO $pdo): void
{
    if (!storage_table_exists($pdo)) {
        $pdo->exec("
            CREATE TABLE storage_locations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                location_type ENUM('box','small_box','pouch','other') NOT NULL DEFAULT 'small_box',
                parent_id INT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_storage_parent (parent_id),
                CONSTRAINT fk_storage_parent FOREIGN KEY (parent_id) REFERENCES storage_locations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    if (!storage_part_locations_table_exists($pdo)) {
        $pdo->exec("
            CREATE TABLE part_storage_locations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                part_id INT NOT NULL,
                storage_location_id INT NOT NULL,
                note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_part_storage_location (part_id, storage_location_id),
                KEY idx_psl_part (part_id),
                KEY idx_psl_location (storage_location_id),
                CONSTRAINT fk_psl_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE,
                CONSTRAINT fk_psl_location FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    seed_storage_locations_from_parts($pdo);
    seed_part_storage_locations_from_parts($pdo);
}

function get_storage_location_by_code(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM storage_locations WHERE code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_storage_box(PDO $pdo, string $boxCode, ?string $note = null): void
{
    $boxCode = strtoupper(trim($boxCode));
    if ($boxCode === '' || !preg_match('/^[A-Z]$/', $boxCode)) {
        return;
    }

    $existing = get_storage_location_by_code($pdo, $boxCode);
    if (!$existing) {
        $stmt = $pdo->prepare("
            INSERT INTO storage_locations (code, name, location_type, sort_order, note)
            VALUES (?, ?, 'box', ?, ?)
        ");
        $stmt->execute([$boxCode, "部品箱 {$boxCode}", ord($boxCode), $note]);
        $boxId = (int)$pdo->lastInsertId();
    } else {
        $boxId = (int)$existing['id'];
    }

    $insertSmall = $pdo->prepare("
        INSERT IGNORE INTO storage_locations (code, name, location_type, parent_id, sort_order)
        VALUES (?, ?, 'small_box', ?, ?)
    ");

    for ($i = 1; $i <= 14; $i++) {
        $code = $boxCode . $i;
        $insertSmall->execute([$code, "{$boxCode}箱 小部品箱 {$i}", $boxId, $i]);
    }
}

function next_storage_box_code(PDO $pdo): string
{
    $codes = $pdo->query("
        SELECT code
        FROM storage_locations
        WHERE location_type = 'box' AND code REGEXP '^[A-Z]$'
        ORDER BY code
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!$codes) {
        return 'A';
    }

    $last = end($codes);
    $next = chr(ord($last) + 1);
    return $next <= 'Z' ? $next : 'Z';
}

function next_storage_pouch_code(PDO $pdo): string
{
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(code, 2) AS UNSIGNED))
        FROM storage_locations
        WHERE location_type = 'pouch' AND code REGEXP '^P[0-9]{3}$'
    ");
    $max = (int)$stmt->fetchColumn();
    return sprintf('P%03d', $max + 1);
}

function create_storage_pouch(PDO $pdo, ?string $code = null, ?string $name = null, ?string $note = null): string
{
    $code = strtoupper(trim((string)$code));
    if ($code === '') {
        $code = next_storage_pouch_code($pdo);
    }

    $name = trim((string)$name);
    if ($name === '') {
        $name = "チャック袋 {$code}";
    }

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO storage_locations (code, name, location_type, sort_order, note)
        VALUES (?, ?, 'pouch', ?, ?)
    ");
    $stmt->execute([$code, $name, 10000 + (int)substr($code, 1), $note]);

    return $code;
}

function seed_storage_locations_from_parts(PDO $pdo): void
{
    $locations = $pdo->query("
        SELECT DISTINCT location
        FROM parts
        WHERE location IS NOT NULL AND location <> ''
        ORDER BY location
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($locations as $locationValue) {
        foreach (parse_storage_codes($locationValue) as $location) {
            if ($location === '') {
                continue;
            }

            if (preg_match('/^([A-Z])([1-9]|1[0-4])$/', $location, $m)) {
                create_storage_box($pdo, $m[1]);
                continue;
            }

            if (preg_match('/^P[0-9]{3}$/', $location)) {
                create_storage_pouch($pdo, $location);
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT IGNORE INTO storage_locations (code, name, location_type, sort_order)
                VALUES (?, ?, 'other', 90000)
            ");
            $stmt->execute([$location, $location]);
        }
    }
}

function get_storage_options(PDO $pdo): array
{
    ensure_storage_schema($pdo);
    return $pdo->query("
        SELECT child.*, parent.code AS parent_code
        FROM storage_locations child
        LEFT JOIN storage_locations parent ON parent.id = child.parent_id
        WHERE child.location_type <> 'box'
        ORDER BY
            CASE child.location_type
                WHEN 'small_box' THEN 1
                WHEN 'pouch' THEN 2
                ELSE 3
            END,
            COALESCE(parent.code, child.code),
            child.sort_order,
            child.code
    ")->fetchAll();
}

function seed_part_storage_locations_from_parts(PDO $pdo): void
{
    $stmt = $pdo->query("
        SELECT id, location
        FROM parts
        WHERE location IS NOT NULL AND location <> ''
    ");
    $insert = $pdo->prepare("
        INSERT IGNORE INTO part_storage_locations (part_id, storage_location_id)
        SELECT ?, id
        FROM storage_locations
        WHERE code = ?
    ");

    foreach ($stmt as $part) {
        foreach (parse_storage_codes($part['location']) as $code) {
            $insert->execute([(int)$part['id'], $code]);
        }
    }
}

function get_part_storage_codes(PDO $pdo, int $partId): array
{
    ensure_storage_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT sl.code
        FROM part_storage_locations psl
        JOIN storage_locations sl ON sl.id = psl.storage_location_id
        WHERE psl.part_id = ?
        ORDER BY sl.code
    ");
    $stmt->execute([$partId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function parse_storage_codes($value): array
{
    if (is_array($value)) {
        $raw = $value;
    } else {
        $raw = preg_split('/\s*,\s*|\s*、\s*|\s+|\s*\n\s*/u', (string)$value);
    }

    return array_values(array_unique(array_filter(array_map(function ($code) {
        return strtoupper(trim((string)$code));
    }, $raw ?: []))));
}

function get_parts_storage_codes(PDO $pdo, array $partIds): array
{
    ensure_storage_schema($pdo);
    $partIds = array_values(array_unique(array_filter(array_map('intval', $partIds))));
    if (!$partIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($partIds), '?'));
    $stmt = $pdo->prepare("
        SELECT psl.part_id, sl.code
        FROM part_storage_locations psl
        JOIN storage_locations sl ON sl.id = psl.storage_location_id
        WHERE psl.part_id IN ({$placeholders})
        ORDER BY sl.code
    ");
    $stmt->execute($partIds);

    $map = [];
    foreach ($stmt as $row) {
        $map[(int)$row['part_id']][] = $row['code'];
    }
    return $map;
}

function get_storage_usage(PDO $pdo, array $codes, ?int $excludePartId = null): array
{
    $codes = array_values(array_filter(array_map(fn($v) => strtoupper(trim((string)$v)), $codes)));
    if (!$codes) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $params = $codes;
    $excludeSql = '';

    if ($excludePartId !== null && $excludePartId > 0) {
        $excludeSql = ' AND p.id <> ?';
        $params[] = $excludePartId;
    }

    $stmt = $pdo->prepare("
        SELECT sl.code, p.id AS part_id, p.name, p.part_code
        FROM storage_locations sl
        JOIN part_storage_locations psl ON psl.storage_location_id = sl.id
        JOIN parts p ON p.id = psl.part_id
        WHERE sl.code IN ({$placeholders}){$excludeSql}
        ORDER BY sl.code, p.name
    ");
    $stmt->execute($params);

    $usage = [];
    foreach ($stmt as $row) {
        $usage[$row['code']][] = $row;
    }
    return $usage;
}

function sync_part_storage_locations(PDO $pdo, int $partId, array $codes): array
{
    ensure_storage_schema($pdo);

    $codes = array_values(array_unique(array_filter(array_map(function ($code) {
        return strtoupper(trim((string)$code));
    }, $codes))));

    foreach ($codes as $code) {
        if (preg_match('/^([A-Z])([1-9]|1[0-4])$/', $code, $m)) {
            create_storage_box($pdo, $m[1]);
        } elseif (preg_match('/^P[0-9]{3}$/', $code)) {
            create_storage_pouch($pdo, $code);
        } else {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO storage_locations (code, name, location_type, sort_order)
                VALUES (?, ?, 'other', 90000)
            ");
            $stmt->execute([$code, $code]);
        }
    }

    $usage = get_storage_usage($pdo, $codes, $partId);

    $pdo->prepare("DELETE FROM part_storage_locations WHERE part_id = ?")->execute([$partId]);

    $insert = $pdo->prepare("
        INSERT IGNORE INTO part_storage_locations (part_id, storage_location_id)
        SELECT ?, id
        FROM storage_locations
        WHERE code = ?
    ");

    foreach ($codes as $code) {
        $insert->execute([$partId, $code]);
    }

    $primaryLocation = $codes[0] ?? '';
    $pdo->prepare("UPDATE parts SET location = ? WHERE id = ?")->execute([$primaryLocation, $partId]);

    return $usage;
}

function storage_type_label(string $type): string
{
    $map = [
        'box' => '部品箱',
        'small_box' => '小部品箱',
        'pouch' => 'チャック袋',
        'other' => 'その他',
    ];
    return $map[$type] ?? $type;
}
