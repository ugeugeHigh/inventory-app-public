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
    CONSTRAINT fk_unmatched_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_unmatched_product_bom FOREIGN KEY (product_bom_id) REFERENCES product_boms(id) ON DELETE CASCADE,
    CONSTRAINT fk_unmatched_part FOREIGN KEY (resolved_part_id) REFERENCES parts(id) ON DELETE SET NULL
);
