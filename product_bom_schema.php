<?php

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
    ");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function foreign_key_exists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND constraint_name = ?
          AND constraint_type = 'FOREIGN KEY'
    ");
    $stmt->execute([$table, $constraint]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_product_bom_schema(PDO $pdo): void
{
    if (!table_exists($pdo, 'product_boms')) {
        $pdo->exec("
            CREATE TABLE product_boms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                board_part_id INT NULL,
                qty_per_product DECIMAL(10,3) NOT NULL DEFAULT 1,
                note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_product_boms_product (product_id),
                KEY idx_product_boms_board_part (board_part_id),
                CONSTRAINT fk_product_boms_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                CONSTRAINT fk_product_boms_board_part FOREIGN KEY (board_part_id) REFERENCES parts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    if (!column_exists($pdo, 'product_components', 'product_bom_id')) {
        $pdo->exec("ALTER TABLE product_components ADD product_bom_id INT NULL AFTER product_id");
    }

    if (table_exists($pdo, 'unmatched_bom_items') && !column_exists($pdo, 'unmatched_bom_items', 'product_bom_id')) {
        $pdo->exec("ALTER TABLE unmatched_bom_items ADD product_bom_id INT NULL AFTER product_id");
    }

    $products = $pdo->query("SELECT id, name FROM products ORDER BY id")->fetchAll();
    $insertBom = $pdo->prepare("
        INSERT INTO product_boms (product_id, name, qty_per_product)
        VALUES (?, ?, 1)
    ");
    $findBom = $pdo->prepare("SELECT id FROM product_boms WHERE product_id = ? ORDER BY id LIMIT 1");

    foreach ($products as $product) {
        $findBom->execute([(int)$product['id']]);
        if (!$findBom->fetchColumn()) {
            $insertBom->execute([(int)$product['id'], 'メインBOM']);
        }
    }

    $pdo->exec("
        UPDATE product_components pc
        JOIN (
            SELECT product_id, MIN(id) AS bom_id
            FROM product_boms
            GROUP BY product_id
        ) pb ON pb.product_id = pc.product_id
        SET pc.product_bom_id = pb.bom_id
        WHERE pc.product_bom_id IS NULL
    ");

    if (table_exists($pdo, 'unmatched_bom_items')) {
        $pdo->exec("
            UPDATE unmatched_bom_items u
            JOIN (
                SELECT product_id, MIN(id) AS bom_id
                FROM product_boms
                GROUP BY product_id
            ) pb ON pb.product_id = u.product_id
            SET u.product_bom_id = pb.bom_id
            WHERE u.product_bom_id IS NULL
        ");
    }

    if (!index_exists($pdo, 'product_components', 'idx_pc_product')) {
        $pdo->exec("ALTER TABLE product_components ADD KEY idx_pc_product (product_id)");
    }
    if (!index_exists($pdo, 'product_components', 'idx_pc_product_bom')) {
        $pdo->exec("ALTER TABLE product_components ADD KEY idx_pc_product_bom (product_bom_id)");
    }
    if (index_exists($pdo, 'product_components', 'uniq_product_part')) {
        $pdo->exec("ALTER TABLE product_components DROP INDEX uniq_product_part");
    }
    if (!index_exists($pdo, 'product_components', 'uniq_product_bom_part')) {
        $pdo->exec("ALTER TABLE product_components ADD UNIQUE KEY uniq_product_bom_part (product_bom_id, part_id)");
    }
    if (!foreign_key_exists($pdo, 'product_components', 'fk_pc_product_bom')) {
        $pdo->exec("
            ALTER TABLE product_components
            ADD CONSTRAINT fk_pc_product_bom
            FOREIGN KEY (product_bom_id) REFERENCES product_boms(id) ON DELETE CASCADE
        ");
    }

    if (table_exists($pdo, 'unmatched_bom_items')) {
        if (!index_exists($pdo, 'unmatched_bom_items', 'idx_unmatched_product_bom')) {
            $pdo->exec("ALTER TABLE unmatched_bom_items ADD KEY idx_unmatched_product_bom (product_bom_id)");
        }
        if (!foreign_key_exists($pdo, 'unmatched_bom_items', 'fk_unmatched_product_bom')) {
            $pdo->exec("
                ALTER TABLE unmatched_bom_items
                ADD CONSTRAINT fk_unmatched_product_bom
                FOREIGN KEY (product_bom_id) REFERENCES product_boms(id) ON DELETE CASCADE
            ");
        }
    }
}

function get_product_boms(PDO $pdo, int $productId): array
{
    ensure_product_bom_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT pb.*, p.name AS board_name, p.mpn AS board_mpn
        FROM product_boms pb
        LEFT JOIN parts p ON p.id = pb.board_part_id
        WHERE pb.product_id = ?
        ORDER BY pb.id
    ");
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

function get_default_product_bom_id(PDO $pdo, int $productId): int
{
    ensure_product_bom_schema($pdo);
    $stmt = $pdo->prepare("SELECT id FROM product_boms WHERE product_id = ? ORDER BY id LIMIT 1");
    $stmt->execute([$productId]);
    $id = (int)$stmt->fetchColumn();

    if ($id > 0) {
        return $id;
    }

    $stmt = $pdo->prepare("INSERT INTO product_boms (product_id, name, qty_per_product) VALUES (?, 'メインBOM', 1)");
    $stmt->execute([$productId]);
    return (int)$pdo->lastInsertId();
}

function get_selected_product_bom(PDO $pdo, int $productId, int $requestedBomId = 0): array
{
    $boms = get_product_boms($pdo, $productId);

    if (!$boms) {
        get_default_product_bom_id($pdo, $productId);
        $boms = get_product_boms($pdo, $productId);
    }

    foreach ($boms as $bom) {
        if ((int)$bom['id'] === $requestedBomId) {
            return $bom;
        }
    }

    return $boms[0];
}
