<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/product_bom_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ensure_product_bom_schema($pdo);

$products = $pdo->query("
    SELECT
        p.id,
        p.name,
        p.switch_science_sku,
        p.xian_diy_id,
        (
            SELECT COUNT(*)
            FROM product_components pc
            WHERE pc.product_id = p.id
        ) AS bom_count
    FROM products p
    ORDER BY p.name
")->fetchAll();

$boards = $pdo->query("SELECT id, name, mpn FROM parts WHERE part_type='board' ORDER BY name")->fetchAll();
$productBoms = $pdo->query("
    SELECT id, product_id, name, qty_per_product
    FROM product_boms
    ORDER BY product_id, id
")->fetchAll();
$message = '';
$debug = [];
$messageType = 'info';

function find_real_header_row($handle) {
    while (($row = fgetcsv($handle)) !== false) {
        $trimmed = array_map(fn($v) => trim((string)$v), $row);

        if (
            isset($trimmed[0], $trimmed[1], $trimmed[2]) &&
            $trimmed[0] === 'Item' &&
            $trimmed[1] === 'Qty' &&
            $trimmed[2] === 'Reference(s)'
        ) {
            return $row;
        }
    }
    return false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_FILES['bom_file'])) {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $board_part_id = (int)($_POST['board_part_id'] ?? 0);
    $product_bom_id = (int)($_POST['product_bom_id'] ?? 0);
    $bom_name = trim($_POST['bom_name'] ?? '');
    $bom_qty_per_product = (float)($_POST['bom_qty_per_product'] ?? 1);
    $replaceExisting = isset($_POST['replace_existing']) && $_POST['replace_existing'] === '1';

    if ($product_id <= 0) {
        $message = '製品を選択してください。';
        $messageType = 'error';
    } else {
        try {
            if ($bom_qty_per_product <= 0) {
                $bom_qty_per_product = 1;
            }

            if ($product_bom_id > 0) {
                $bomStmt = $pdo->prepare("SELECT * FROM product_boms WHERE id=? AND product_id=?");
                $bomStmt->execute([$product_bom_id, $product_id]);
                $targetBom = $bomStmt->fetch();
                if (!$targetBom) {
                    throw new Exception('選択されたBOMグループが見つかりません。');
                }

                if ($board_part_id > 0 || $bom_qty_per_product !== (float)$targetBom['qty_per_product']) {
                    $pdo->prepare("
                        UPDATE product_boms
                        SET board_part_id = COALESCE(?, board_part_id), qty_per_product = ?
                        WHERE id = ?
                    ")->execute([
                        $board_part_id > 0 ? $board_part_id : null,
                        $bom_qty_per_product,
                        $product_bom_id,
                    ]);
                }
            } else {
                if ($bom_name === '') {
                    $bom_name = pathinfo($_FILES['bom_file']['name'] ?? 'BOM', PATHINFO_FILENAME);
                }

                $bomStmt = $pdo->prepare("SELECT id FROM product_boms WHERE product_id=? AND name=? ORDER BY id LIMIT 1");
                $bomStmt->execute([$product_id, $bom_name]);
                $product_bom_id = (int)$bomStmt->fetchColumn();

                if ($product_bom_id <= 0) {
                    $pdo->prepare("
                        INSERT INTO product_boms (product_id, name, board_part_id, qty_per_product)
                        VALUES (?, ?, ?, ?)
                    ")->execute([
                        $product_id,
                        $bom_name,
                        $board_part_id > 0 ? $board_part_id : null,
                        $bom_qty_per_product,
                    ]);
                    $product_bom_id = (int)$pdo->lastInsertId();
                }
            }

            if ($replaceExisting) {
                $pdo->prepare("DELETE FROM product_components WHERE product_bom_id=? AND source_type='bom_import'")
                    ->execute([$product_bom_id]);
                $pdo->prepare("DELETE FROM unmatched_bom_items WHERE product_bom_id=? AND status='pending'")
                    ->execute([$product_bom_id]);
                $debug[] = '選択BOMの既存 bom_import データを削除しました。';
            }

            if ($board_part_id > 0) {
                $check = $pdo->prepare("SELECT id FROM product_components WHERE product_bom_id=? AND part_id=?");
                $check->execute([$product_bom_id, $board_part_id]);
                if (!$check->fetch()) {
                    $pdo->prepare("
                        INSERT INTO product_components (product_id, product_bom_id, part_id, qty_per_unit, component_role, source_type)
                        VALUES (?,?,?,?,?, 'manual')
                    ")->execute([$product_id, $product_bom_id, $board_part_id, 1, 'board']);
                }
            }

            $tmp = $_FILES['bom_file']['tmp_name'];
            $handle = fopen($tmp, 'r');

            if (!$handle) {
                throw new Exception('CSVファイルを開けませんでした。');
            }

            $headers = find_real_header_row($handle);
            if (!$headers) {
                throw new Exception('CSV内に有効なヘッダ行を見つけられませんでした。');
            }

            $headerMap = [];
            foreach ($headers as $idx => $name) {
                $headerMap[trim((string)$name)] = $idx;
            }

            $debug[] = '検出ヘッダ: ' . implode(' | ', $headers);

            $mpnIdx = $headerMap['MPN'] ?? null;
            $qtyIdx = $headerMap['Qty'] ?? null;
            $refIdx = $headerMap['Reference(s)'] ?? null;

            if ($mpnIdx === null) {
                throw new Exception('BOMに MPN 列が必要です。');
            }

            $aggregated = [];

            while (($row = fgetcsv($handle)) !== false) {
                $nonEmpty = array_filter($row, fn($v) => trim((string)$v) !== '');
                if (count($nonEmpty) === 0) {
                    continue;
                }

                $rawMpn = trim((string)($row[$mpnIdx] ?? ''));
                $mpn = normalize_mpn($rawMpn);

                if ($mpn === '') {
                    continue;
                }

                $qty = 1.0;
                if ($qtyIdx !== null && isset($row[$qtyIdx]) && trim((string)$row[$qtyIdx]) !== '') {
                    $qty = (float)$row[$qtyIdx];
                }

                $refs = ($refIdx !== null) ? trim((string)($row[$refIdx] ?? '')) : '';

                if (!isset($aggregated[$mpn])) {
                    $aggregated[$mpn] = [
                        'raw_mpn' => $rawMpn,
                        'normalized_mpn' => $mpn,
                        'qty' => 0,
                        'refs' => [],
                    ];
                }

                $aggregated[$mpn]['qty'] += $qty;
                if ($refs !== '') {
                    $aggregated[$mpn]['refs'][] = $refs;
                }
            }

            fclose($handle);

            $imported = 0;
            $missing = [];

            foreach ($aggregated as $mpn => $item) {
                $qty = $item['qty'];
                $refs = implode(', ', $item['refs']);

                $stmt = $pdo->prepare("SELECT id, part_type FROM parts WHERE mpn=?");
                $stmt->execute([$mpn]);
                $part = $stmt->fetch();

                if (!$part) {
                    $missing[] = $mpn;

                    $existsUnmatched = $pdo->prepare("
                        SELECT id FROM unmatched_bom_items
                        WHERE product_bom_id=? AND normalized_mpn=? AND status='pending'
                    ");
                    $existsUnmatched->execute([$product_bom_id, $mpn]);
                    $existingUnmatched = $existsUnmatched->fetch();

                    if ($existingUnmatched) {
                        $pdo->prepare("
                            UPDATE unmatched_bom_items
                            SET qty_per_unit=?, reference_designators=?, raw_row_text=?
                            WHERE id=?
                        ")->execute([
                            $qty,
                            $refs,
                            json_encode($item, JSON_UNESCAPED_UNICODE),
                            $existingUnmatched['id']
                        ]);
                    } else {
                        $pdo->prepare("
                            INSERT INTO unmatched_bom_items (
                                product_id, product_bom_id, raw_mpn, normalized_mpn, qty_per_unit,
                                reference_designators, raw_row_text, status
                            ) VALUES (?,?,?,?,?,?,?,'pending')
                        ")->execute([
                            $product_id,
                            $product_bom_id,
                            $item['raw_mpn'],
                            $mpn,
                            $qty,
                            $refs,
                            json_encode($item, JSON_UNESCAPED_UNICODE)
                        ]);
                    }

                    $debug[] = "未一致: {$mpn} / qty={$qty}";
                    continue;
                }

                $role = $part['part_type'] === 'board' ? 'board' : $part['part_type'];

                $exists = $pdo->prepare("SELECT id FROM product_components WHERE product_bom_id=? AND part_id=?");
                $exists->execute([$product_bom_id, $part['id']]);
                $found = $exists->fetch();

                if ($found) {
                    $pdo->prepare("
                        UPDATE product_components
                        SET qty_per_unit=?, reference_designators=?, component_role=?, source_type='bom_import'
                        WHERE id=?
                    ")->execute([$qty, $refs, $role, $found['id']]);
                } else {
                    $pdo->prepare("
                        INSERT INTO product_components (
                            product_id, product_bom_id, part_id, qty_per_unit, component_role, reference_designators, source_type
                        ) VALUES (?,?,?,?,?,?, 'bom_import')
                    ")->execute([$product_id, $product_bom_id, $part['id'], $qty, $role, $refs]);
                }

                $imported++;
                $debug[] = "取込OK: {$mpn} / qty={$qty}";
            }

            $message = "取込完了: {$imported}件";
            $messageType = 'success';

            if ($missing) {
                $message .= " / 未一致: " . count($missing) . "件";
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM product_components WHERE product_bom_id=?");
            $countStmt->execute([$product_bom_id]);
            $debug[] = "選択BOMの product_components 件数: " . $countStmt->fetchColumn();

        } catch (Throwable $e) {
            $message = 'エラー: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>KiCad BOM取込</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
    --bg: #f5f7fb;
    --card: #ffffff;
    --border: #d9e1ec;
    --text: #223;
    --sub: #667085;
    --primary: #2d6cdf;
    --primary-hover: #1f57c5;
    --success-bg: #eaf8ee;
    --success-border: #9fd5ab;
    --success-text: #1f6b34;
    --error-bg: #fff0f0;
    --error-border: #f0b0b0;
    --error-text: #9b1c1c;
    --info-bg: #eef6ff;
    --info-border: #bfd7ff;
    --warn-bg: #fff7e8;
    --warn-border: #f3d08a;
    --warn-text: #8a5a00;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
}

.wrapper {
    max-width: 980px;
    margin: 0 auto;
    padding: 24px;
}

.top-link {
    display: inline-block;
    margin-bottom: 18px;
    color: var(--primary);
    text-decoration: none;
}

.page-title {
    margin: 0 0 8px 0;
    font-size: 28px;
}

.page-subtitle {
    margin: 0 0 24px 0;
    color: var(--sub);
    font-size: 14px;
}

.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 8px 24px rgba(20, 40, 80, 0.05);
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.form-full {
    grid-column: 1 / -1;
}

label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
    font-size: 14px;
}

select,
input[type="text"],
input[type="file"] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fff;
    font-size: 14px;
}

.product-picker {
    border: 1px solid var(--border);
    border-radius: 12px;
    background: #fff;
    overflow: hidden;
}

.product-search {
    padding: 10px 12px;
    border: 0;
    border-bottom: 1px solid var(--border);
    border-radius: 0;
}

.product-select {
    border: 0;
    border-radius: 0;
    min-height: 220px;
    padding: 8px;
}

.warning-box {
    margin-top: 10px;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid var(--warn-border);
    background: var(--warn-bg);
    color: var(--warn-text);
    display: none;
}

.dropzone {
    border: 2px dashed #9db8ea;
    border-radius: 14px;
    background: #f8fbff;
    padding: 28px 20px;
    text-align: center;
    transition: 0.2s ease;
    cursor: pointer;
}

.dropzone.dragover {
    border-color: var(--primary);
    background: #eef5ff;
}

.dropzone-title {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 8px;
}

.dropzone-sub {
    font-size: 14px;
    color: var(--sub);
}

.file-name {
    margin-top: 12px;
    font-size: 14px;
    color: var(--primary);
    font-weight: bold;
}

.hidden-file {
    display: none;
}

.submit-row {
    margin-top: 22px;
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

button {
    padding: 11px 18px;
    border: none;
    border-radius: 10px;
    background: var(--primary);
    color: #fff;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
}

button:hover {
    background: var(--primary-hover);
}

.check-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.check-row input[type="checkbox"] {
    width: auto;
}

.message {
    margin-bottom: 18px;
    padding: 14px 16px;
    border-radius: 12px;
    border: 1px solid;
    font-size: 14px;
}

.message.success {
    background: var(--success-bg);
    border-color: var(--success-border);
    color: var(--success-text);
}

.message.error {
    background: var(--error-bg);
    border-color: var(--error-border);
    color: var(--error-text);
}

.message.info {
    background: var(--info-bg);
    border-color: var(--info-border);
    color: #2457a6;
}

.note {
    margin-top: 10px;
    color: var(--sub);
    font-size: 13px;
    line-height: 1.6;
}

.debug {
    margin-top: 24px;
    background: #0f172a;
    color: #d7e3ff;
    border-radius: 14px;
    padding: 16px;
    white-space: pre-wrap;
    font-family: Consolas, Monaco, monospace;
    font-size: 13px;
    line-height: 1.5;
    overflow-x: auto;
}

.section-title {
    margin: 28px 0 12px;
    font-size: 18px;
}

@media (max-width: 720px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .wrapper {
        padding: 16px;
    }

    .card {
        padding: 18px;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>

    <h1 class="page-title">KiCad BOM取込</h1>
    <p class="page-subtitle">
        製品を検索して選択し、KiCad の BOM CSV をドラッグ＆ドロップで取り込めます。
    </p>

    <?php if ($message): ?>
        <div class="message <?= h($messageType) ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="post" enctype="multipart/form-data" id="bomForm">
            <div class="form-grid">
                <div>
                    <label>対象製品</label>
                    <div class="product-picker">
                        <input
                            type="text"
                            id="productSearch"
                            class="product-search"
                            placeholder="製品名 / SKD / XianDIY ID で検索"
                        >
                        <select name="product_id" id="productSelect" class="product-select" size="10" required>
                            <option value="">選択してください</option>
                            <?php foreach ($products as $product): ?>
                                <option
                                    value="<?= h($product['id']) ?>"
                                    data-bom-count="<?= h($product['bom_count']) ?>"
                                    data-search="<?= h(mb_strtolower(($product['name'] ?? '') . ' ' . ($product['switch_science_sku'] ?? '') . ' ' . ($product['xian_diy_id'] ?? ''), 'UTF-8')) ?>"
                                >
                                    <?= h($product['name']) ?>
                                    <?php if (!empty($product['switch_science_sku'])): ?>
                                        / SKD: <?= h($product['switch_science_sku']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($product['xian_diy_id'])): ?>
                                        / ID: <?= h($product['xian_diy_id']) ?>
                                    <?php endif; ?>
                                    / BOM: <?= h($product['bom_count']) ?>件
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="bomWarning" class="warning-box"></div>
                </div>

                <div>
                    <label>取込先BOMグループ</label>
                    <select name="product_bom_id" id="productBomSelect">
                        <option value="0">新しいBOMグループを作成</option>
                        <?php foreach ($productBoms as $bom): ?>
                            <option
                                value="<?= h($bom['id']) ?>"
                                data-product-id="<?= h($bom['product_id']) ?>"
                                data-qty="<?= h($bom['qty_per_product']) ?>"
                            >
                                <?= h($bom['name']) ?> / 数量: <?= h($bom['qty_per_product']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label style="margin-top:12px;">新しいBOM名</label>
                    <input type="text" name="bom_name" id="bomNameInput" placeholder="例: メイン基板BOM / LED基板BOM">

                    <label style="margin-top:12px;">このBOMの数量 / 1商品</label>
                    <input type="number" step="0.001" name="bom_qty_per_product" id="bomQtyInput" value="1">

                    <label style="margin-top:12px;">対応する基板部品（任意）</label>
                    <select name="board_part_id">
                        <option value="0">選択しない</option>
                        <?php foreach ($boards as $board): ?>
                            <option value="<?= h($board['id']) ?>">
                                <?= h($board['name']) ?> / <?= h($board['mpn']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="check-row">
                        <input type="checkbox" id="replace_existing" name="replace_existing" value="1">
                        <label for="replace_existing" style="margin:0;">既存の bom_import データを置き換える</label>
                    </div>
                    <div class="note">
                        チェックを入れると、選択したBOMグループの既存BOM取込データと未一致MPN候補を削除してから再取込します。<br>
                        手動追加の構成は削除しません。
                    </div>
                </div>

                <div class="form-full">
                    <label>BOM CSV</label>

                    <div class="dropzone" id="dropzone">
                        <div class="dropzone-title">ここに CSV をドラッグ＆ドロップ</div>
                        <div class="dropzone-sub">またはクリックしてファイルを選択</div>
                        <div class="file-name" id="fileName">ファイル未選択</div>
                    </div>

                    <input
                        class="hidden-file"
                        type="file"
                        name="bom_file"
                        id="bom_file"
                        accept=".csv,text/csv"
                        required
                    >

                    <div class="note">
                        先頭に Source や Date などのメタ情報がある KiCad CSV に対応しています。<br>
                        本文中のヘッダ行 <strong>Item / Qty / Reference(s) / ... / MPN</strong> を自動検出します。
                    </div>
                </div>
            </div>

            <div class="submit-row">
                <button type="submit">取り込む</button>
            </div>
        </form>
    </div>

    <?php if ($debug): ?>
        <h2 class="section-title">取込ログ</h2>
        <div class="debug"><?= h(implode("\n", $debug)) ?></div>
    <?php endif; ?>
</div>

<script>
const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('bom_file');
const fileName = document.getElementById('fileName');
const productSearch = document.getElementById('productSearch');
const productSelect = document.getElementById('productSelect');
const productBomSelect = document.getElementById('productBomSelect');
const bomNameInput = document.getElementById('bomNameInput');
const bomQtyInput = document.getElementById('bomQtyInput');
const bomWarning = document.getElementById('bomWarning');
const replaceExisting = document.getElementById('replace_existing');

function updateFileName() {
    if (fileInput.files && fileInput.files.length > 0) {
        fileName.textContent = fileInput.files[0].name;
    } else {
        fileName.textContent = 'ファイル未選択';
    }
}

function normalizeText(text) {
    return (text || '').toLowerCase();
}

function updateProductFilter() {
    const keyword = normalizeText(productSearch.value);
    const options = Array.from(productSelect.options);

    options.forEach((opt, index) => {
        if (index === 0) {
            opt.hidden = false;
            return;
        }
        const hay = normalizeText(opt.dataset.search || opt.textContent);
        opt.hidden = keyword !== '' && !hay.includes(keyword);
    });
}

function updateBomWarning() {
    const selected = productSelect.options[productSelect.selectedIndex];
    if (!selected || !selected.value) {
        bomWarning.style.display = 'none';
        bomWarning.textContent = '';
        return;
    }

    const bomCount = parseInt(selected.dataset.bomCount || '0', 10);

    if (bomCount > 0) {
        bomWarning.style.display = 'block';
        bomWarning.textContent = `注意: この製品にはすでに ${bomCount} 件の構成データがあります。上書きしたい場合は「既存の bom_import データを置き換える」にチェックを入れてください。`;
    } else {
        bomWarning.style.display = 'none';
        bomWarning.textContent = '';
    }
}

function updateProductBomOptions() {
    const selectedProductId = productSelect.value;

    Array.from(productBomSelect.options).forEach((opt, index) => {
        if (index === 0) {
            opt.hidden = false;
            return;
        }

        opt.hidden = opt.dataset.productId !== selectedProductId;
    });

    const current = productBomSelect.options[productBomSelect.selectedIndex];
    if (!selectedProductId || (current && current.value !== '0' && current.hidden)) {
        productBomSelect.value = '0';
    }
}

function updateBomInputs() {
    const selected = productBomSelect.options[productBomSelect.selectedIndex];
    const isNew = !selected || selected.value === '0';

    bomNameInput.disabled = !isNew;
    bomNameInput.required = isNew;

    if (!isNew && selected.dataset.qty) {
        bomQtyInput.value = selected.dataset.qty;
    }
}

dropzone.addEventListener('click', () => {
    fileInput.click();
});

fileInput.addEventListener('change', updateFileName);

dropzone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropzone.classList.add('dragover');
});

dropzone.addEventListener('dragleave', () => {
    dropzone.classList.remove('dragover');
});

dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');

    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
        updateFileName();
    }
});

productSearch.addEventListener('input', updateProductFilter);
productSelect.addEventListener('change', () => {
    updateBomWarning();
    updateProductBomOptions();
    updateBomInputs();
});
productBomSelect.addEventListener('change', updateBomInputs);
replaceExisting.addEventListener('change', updateBomWarning);

updateBomWarning();
updateProductBomOptions();
updateBomInputs();
</script>
</body>
</html>
