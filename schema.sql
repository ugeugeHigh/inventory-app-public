CREATE DATABASE IF NOT EXISTS xian_parts_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE xian_parts_v2;

CREATE TABLE IF NOT EXISTS parts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    part_code VARCHAR(100) NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    mpn VARCHAR(255) NULL UNIQUE,
    supplier VARCHAR(255) NULL,
    supplier_url TEXT NULL,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    quantity INT NOT NULL DEFAULT 0,
    minimum_stock INT NOT NULL DEFAULT 0,
    location VARCHAR(255) NULL,
    part_type ENUM('electronic','board','wire','3dp','mechanical','other') NOT NULL DEFAULT 'electronic',
    footprint VARCHAR(255) NULL,
    note TEXT NULL,
    qr_payload TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    switch_science_sku VARCHAR(100) NULL,
    xian_diy_id VARCHAR(100) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_boms (
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
);

CREATE TABLE IF NOT EXISTS product_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    product_bom_id INT NOT NULL,
    part_id INT NOT NULL,
    qty_per_unit DECIMAL(10,3) NOT NULL DEFAULT 1,
    component_role ENUM('board','electronic','wire','3dp','mechanical','other') NOT NULL DEFAULT 'electronic',
    reference_designators TEXT NULL,
    source_type ENUM('manual','bom_import') NOT NULL DEFAULT 'manual',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_product_bom_part (product_bom_id, part_id),
    KEY idx_pc_product (product_id),
    CONSTRAINT fk_pc_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_product_bom FOREIGN KEY (product_bom_id) REFERENCES product_boms(id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS unmatched_bom_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    product_bom_id INT NOT NULL,
    raw_mpn VARCHAR(255) NOT NULL,
    normalized_mpn VARCHAR(255) NOT NULL,
    qty_per_unit DECIMAL(10,3) NOT NULL DEFAULT 1,
    reference_designators TEXT NULL,
    raw_row_text TEXT NULL,
    status ENUM('pending','resolved','ignored') NOT NULL DEFAULT 'pending',
    resolved_part_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_unmatched_product_bom (product_bom_id),
    CONSTRAINT fk_unmatched_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_unmatched_product_bom FOREIGN KEY (product_bom_id) REFERENCES product_boms(id) ON DELETE CASCADE,
    CONSTRAINT fk_unmatched_part FOREIGN KEY (resolved_part_id) REFERENCES parts(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS product_label_settings (
    product_id INT NOT NULL PRIMARY KEY,
    lot_prefix VARCHAR(50) NULL,
    manual_url TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pls_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

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
);

CREATE TABLE IF NOT EXISTS storage_locations (
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
);

CREATE TABLE IF NOT EXISTS part_storage_locations (
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
);
