<?php

function ensure_product_lot_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_label_settings (
            product_id INT NOT NULL PRIMARY KEY,
            lot_prefix VARCHAR(50) NULL,
            manual_url TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_pls_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_lots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            lot_code VARCHAR(100) NOT NULL UNIQUE,
            lot_prefix VARCHAR(50) NOT NULL,
            lot_month CHAR(2) NOT NULL,
            lot_sequence INT NOT NULL,
            lot_date DATE NOT NULL,
            manual_url TEXT NULL,
            batch_token VARCHAR(64) NOT NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_product_lots_product (product_id),
            KEY idx_product_lots_batch (batch_token),
            KEY idx_product_lots_prefix_month (lot_prefix, lot_month),
            CONSTRAINT fk_product_lots_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function default_lot_prefix(array $product): string
{
    $source = (string)($product['xian_diy_id'] ?? '');
    if (preg_match('/(\d+)/', $source, $matches)) {
        return 'SW' . str_pad($matches[1], 3, '0', STR_PAD_LEFT);
    }

    return 'SW' . str_pad((string)($product['id'] ?? 0), 3, '0', STR_PAD_LEFT);
}

function load_product_label_settings(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("SELECT * FROM product_label_settings WHERE product_id = ?");
    $stmt->execute([$productId]);
    return $stmt->fetch() ?: [];
}

function save_product_label_settings(PDO $pdo, int $productId, string $lotPrefix, string $manualUrl): void
{
    $stmt = $pdo->prepare("
        INSERT INTO product_label_settings (product_id, lot_prefix, manual_url)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            lot_prefix = VALUES(lot_prefix),
            manual_url = VALUES(manual_url)
    ");
    $stmt->execute([$productId, $lotPrefix, $manualUrl !== '' ? $manualUrl : null]);
}

function next_lot_sequence(PDO $pdo, string $lotPrefix, string $lotMonth): int
{
    $stmt = $pdo->prepare("
        SELECT MAX(lot_sequence)
        FROM product_lots
        WHERE lot_prefix = ? AND lot_month = ?
    ");
    $stmt->execute([$lotPrefix, $lotMonth]);
    return ((int)$stmt->fetchColumn()) + 1;
}

function build_lot_code(string $lotPrefix, string $lotMonth, int $sequence): string
{
    return $lotPrefix . '-' . $lotMonth . '-' . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT);
}
