<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/product_bom_schema.php';
require_once __DIR__ . '/storage_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!function_exists('part_type_label')) {
    function part_type_label($type) {
        $map = [
            'electronic' => '電子部品',
            'board' => '基板',
            'wire' => '配線',
            '3dp' => '3DP品',
            'mechanical' => '機構部品',
            'other' => 'その他',
        ];
        return $map[$type] ?? $type;
    }
}

$product_id = (int)($_GET['product_id'] ?? 0);
ensure_product_bom_schema($pdo);
ensure_storage_schema($pdo);

$stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();
if (!$product) {
    die('製品が見つかりません');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_bom_group'])) {
    $bomName = trim($_POST['bom_name'] ?? '');
    $boardPartId = (int)($_POST['board_part_id'] ?? 0);
    $bomQty = (float)($_POST['bom_qty_per_product'] ?? 1);
    $bomNote = trim($_POST['bom_note'] ?? '');

    if ($bomName === '') {
        $bomName = '新規BOM';
    }
    if ($bomQty <= 0) {
        $bomQty = 1;
    }

    $stmt = $pdo->prepare("
        INSERT INTO product_boms (product_id, name, board_part_id, qty_per_product, note)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $product_id,
        $bomName,
        $boardPartId > 0 ? $boardPartId : null,
        $bomQty,
        $bomNote,
    ]);
    $newBomId = (int)$pdo->lastInsertId();

    redirect_to("product_bom.php?product_id={$product_id}&bom_id={$newBomId}");
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['update_bom_group'])) {
    $productBomId = (int)($_POST['product_bom_id'] ?? 0);
    $bomName = trim($_POST['bom_name'] ?? '');
    $boardPartId = (int)($_POST['board_part_id'] ?? 0);
    $bomQty = (float)($_POST['bom_qty_per_product'] ?? 1);
    $bomNote = trim($_POST['bom_note'] ?? '');

    if ($bomName === '') {
        $bomName = 'BOM';
    }
    if ($bomQty <= 0) {
        $bomQty = 1;
    }

    $stmt = $pdo->prepare("
        UPDATE product_boms
        SET name=?, board_part_id=?, qty_per_product=?, note=?
        WHERE id=? AND product_id=?
    ");
    $stmt->execute([
        $bomName,
        $boardPartId > 0 ? $boardPartId : null,
        $bomQty,
        $bomNote,
        $productBomId,
        $product_id,
    ]);

    redirect_to("product_bom.php?product_id={$product_id}&bom_id={$productBomId}");
}

if (isset($_GET['delete_bom'])) {
    $deleteBomId = (int)$_GET['delete_bom'];
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM product_boms WHERE product_id=?");
    $countStmt->execute([$product_id]);
    $bomCount = (int)$countStmt->fetchColumn();

    if ($bomCount > 1) {
        $pdo->prepare("DELETE FROM product_boms WHERE id=? AND product_id=?")
            ->execute([$deleteBomId, $product_id]);
    }

    redirect_to("product_bom.php?product_id={$product_id}");
}

$requestedBomId = (int)($_GET['bom_id'] ?? ($_POST['product_bom_id'] ?? 0));
$selectedBom = get_selected_product_bom($pdo, $product_id, $requestedBomId);
$productBomId = (int)$selectedBom['id'];
$productBoms = get_product_boms($pdo, $product_id);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_component'])) {
    $productBomId = (int)($_POST['product_bom_id'] ?? $productBomId);
    $part_id = (int)($_POST['part_id'] ?? 0);
    $qty = (float)($_POST['qty_per_unit'] ?? 0);
    $role = $_POST['component_role'] ?? 'electronic';
    $refs = trim($_POST['reference_designators'] ?? '');

    if ($part_id > 0 && $qty > 0) {
        $check = $pdo->prepare("SELECT id FROM product_components WHERE product_bom_id=? AND part_id=?");
        $check->execute([$productBomId, $part_id]);
        $existing = $check->fetch();

        if ($existing) {
            $pdo->prepare("
                UPDATE product_components
                SET qty_per_unit=?, component_role=?, reference_designators=?, source_type='manual'
                WHERE id=?
            ")->execute([$qty, $role, $refs, $existing['id']]);
        } else {
            $pdo->prepare("
                INSERT INTO product_components (
                    product_id, product_bom_id, part_id, qty_per_unit, component_role, reference_designators, source_type
                ) VALUES (?,?,?,?,?,?, 'manual')
            ")->execute([$product_id, $productBomId, $part_id, $qty, $role, $refs]);
        }
    }

    redirect_to("product_bom.php?product_id={$product_id}&bom_id={$productBomId}");
}

if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM product_components WHERE id=? AND product_id=?")
        ->execute([$delete_id, $product_id]);
    redirect_to("product_bom.php?product_id={$product_id}&bom_id={$productBomId}");
}

$parts = $pdo->query("
    SELECT
        id,
        name,
        mpn,
        part_code,
        part_type,
        category,
        subcategory,
        tags,
        quantity,
        location
    FROM parts
    ORDER BY
        category IS NULL,
        category,
        subcategory IS NULL,
        subcategory,
        name
")->fetchAll();

$boardParts = $pdo->query("
    SELECT id, name, mpn
    FROM parts
    WHERE part_type = 'board'
    ORDER BY name
")->fetchAll();

$listStmt = $pdo->prepare("
    SELECT pc.*, pb.name AS bom_name, pb.qty_per_product AS bom_qty_per_product,
           p.name, p.mpn, p.part_type, p.part_code, p.category, p.subcategory, p.quantity, p.location
    FROM product_components pc
    JOIN product_boms pb ON pb.id = pc.product_bom_id
    JOIN parts p ON pc.part_id = p.id
    WHERE pc.product_id = ? AND pc.product_bom_id = ?
    ORDER BY pc.component_role, p.category, p.name
");
$listStmt->execute([$product_id, $productBomId]);
$components = $listStmt->fetchAll();
$componentStorageMap = get_parts_storage_codes($pdo, array_column($components, 'part_id'));
$partsStorageMap = get_parts_storage_codes($pdo, array_column($parts, 'id'));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>製品構成編集</title>
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
    --danger: #d9534f;
    --danger-hover: #bf3f3b;
    --chip-bg: #eef4ff;
    --chip-text: #1f3b75;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
}
.wrapper {
    max-width: 1400px;
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
    padding: 20px;
    box-shadow: 0 8px 24px rgba(20, 40, 80, 0.05);
    margin-bottom: 18px;
}
label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
    font-size: 14px;
}
input, select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fff;
    font-size: 14px;
}
button, a.btn {
    display: inline-block;
    padding: 9px 14px;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
}
button:hover, a.btn:hover {
    background: var(--primary-hover);
}
a.btn.delete-btn {
    background: var(--danger);
}
a.btn.delete-btn:hover {
    background: var(--danger-hover);
}
.form-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1.5fr auto;
    gap: 12px;
    align-items: end;
}
.search-box {
    position: relative;
}
.search-results {
    margin-top: 8px;
    border: 1px solid var(--border);
    border-radius: 12px;
    max-height: 320px;
    overflow-y: auto;
    background: #fff;
}
.search-item {
    padding: 10px 12px;
    border-bottom: 1px solid #edf0f5;
    cursor: pointer;
}
.search-item:last-child {
    border-bottom: none;
}
.search-item:hover {
    background: #f7faff;
}
.search-item.active {
    background: #eef5ff;
}
.search-main {
    font-weight: bold;
}
.search-sub {
    font-size: 12px;
    color: var(--sub);
    margin-top: 4px;
}
.selected-part {
    margin-top: 10px;
    padding: 10px 12px;
    border-radius: 12px;
    background: #eef6ff;
    border: 1px solid #cfe0ff;
    display: none;
}
.tag-chip {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    background: var(--chip-bg);
    color: var(--chip-text);
    font-size: 12px;
    margin-right: 6px;
    margin-top: 4px;
}
.bom-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}
.bom-tab {
    display: inline-block;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: #fff;
    color: var(--text);
    text-decoration: none;
    font-size: 13px;
    font-weight: bold;
}
.bom-tab.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}
.bom-grid {
    display: grid;
    grid-template-columns: 1.4fr 1.4fr 0.7fr 1.6fr auto;
    gap: 12px;
    align-items: end;
}
.table-wrap {
    overflow-x: auto;
}
table {
    border-collapse: collapse;
    width: 100%;
}
th, td {
    border: 1px solid #e5e7eb;
    padding: 9px;
    vertical-align: top;
}
th {
    background: #eef4ff;
    color: #1f3b75;
    text-align: left;
}
.small {
    font-size: 12px;
    color: var(--sub);
}
@media (max-width: 1100px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    .bom-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 720px) {
    .wrapper {
        padding: 16px;
    }
    .card {
        padding: 16px;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>
    <a class="top-link" href="product_detail.php?id=<?= h($product_id) ?>">← 製品詳細へ戻る</a>

    <h1 class="page-title">製品構成編集</h1>
    <p class="page-subtitle"><?= h($product['name']) ?> の複数基板BOMと構成部品を追加・更新します。</p>

    <div class="card">
        <h2 style="margin-top:0;">BOMグループ</h2>
        <div class="bom-tabs">
            <?php foreach ($productBoms as $bom): ?>
                <a
                    class="bom-tab <?= (int)$bom['id'] === $productBomId ? 'active' : '' ?>"
                    href="product_bom.php?product_id=<?= h($product_id) ?>&bom_id=<?= h($bom['id']) ?>"
                >
                    <?= h($bom['name']) ?> × <?= h($bom['qty_per_product']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="post" class="bom-grid">
            <input type="hidden" name="product_bom_id" value="<?= h($productBomId) ?>">
            <div>
                <label>BOM名</label>
                <input type="text" name="bom_name" value="<?= h($selectedBom['name']) ?>" required>
            </div>
            <div>
                <label>対応する基板部品</label>
                <select name="board_part_id">
                    <option value="0">選択しない</option>
                    <?php foreach ($boardParts as $board): ?>
                        <option value="<?= h($board['id']) ?>" <?= (int)($selectedBom['board_part_id'] ?? 0) === (int)$board['id'] ? 'selected' : '' ?>>
                            <?= h($board['name']) ?> / <?= h($board['mpn']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>数量/商品</label>
                <input type="number" step="0.001" name="bom_qty_per_product" value="<?= h($selectedBom['qty_per_product']) ?>" required>
            </div>
            <div>
                <label>メモ</label>
                <input type="text" name="bom_note" value="<?= h($selectedBom['note'] ?? '') ?>" placeholder="例: メイン基板 / LED基板">
            </div>
            <div>
                <button type="submit" name="update_bom_group" value="1">BOM更新</button>
            </div>
        </form>

        <form method="post" class="bom-grid" style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
            <div>
                <label>新しいBOM名</label>
                <input type="text" name="bom_name" placeholder="例: 表示基板BOM">
            </div>
            <div>
                <label>対応する基板部品</label>
                <select name="board_part_id">
                    <option value="0">選択しない</option>
                    <?php foreach ($boardParts as $board): ?>
                        <option value="<?= h($board['id']) ?>"><?= h($board['name']) ?> / <?= h($board['mpn']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>数量/商品</label>
                <input type="number" step="0.001" name="bom_qty_per_product" value="1">
            </div>
            <div>
                <label>メモ</label>
                <input type="text" name="bom_note" placeholder="任意">
            </div>
            <div>
                <button type="submit" name="add_bom_group" value="1">BOM追加</button>
            </div>
        </form>

        <?php if (count($productBoms) > 1): ?>
            <div class="small" style="margin-top:12px;">
                <a class="btn delete-btn" href="product_bom.php?product_id=<?= h($product_id) ?>&bom_id=<?= h($productBomId) ?>&delete_bom=<?= h($productBomId) ?>" onclick="return confirm('このBOMグループと中の構成を削除しますか？')">選択中BOMを削除</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <form method="post" id="bomForm">
            <input type="hidden" name="product_bom_id" value="<?= h($productBomId) ?>">
            <div class="form-grid">
                <div class="search-box">
                    <label>部品検索</label>
                    <input type="text" id="partSearch" placeholder="部品名 / MPN / 部品コード / 分類 / タグ / 場所 で検索">
                    <input type="hidden" name="part_id" id="partId" required>

                    <div id="selectedPart" class="selected-part"></div>
                    <div id="searchResults" class="search-results"></div>
                </div>

                <div>
                    <label>数量 / 1BOM</label>
                    <input type="number" step="0.001" name="qty_per_unit" value="1" required>
                </div>

                <div>
                    <label>役割</label>
                    <select name="component_role">
                        <?php foreach (['board','electronic','wire','3dp','mechanical','other'] as $role): ?>
                            <option value="<?= h($role) ?>"><?= h(part_type_label($role)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>RefDes / メモ</label>
                    <input type="text" name="reference_designators" placeholder="例: R1, R2 / ケース上">
                </div>

                <div>
                    <button type="submit" name="add_component" value="1">追加 / 更新</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card table-wrap">
        <h2 style="margin-top:0;">登録済み構成: <?= h($selectedBom['name']) ?></h2>
        <table>
            <tr>
                <th>ID</th>
                <th>役割</th>
                <th>部品</th>
                <th>分類</th>
                <th>MPN</th>
                <th>数量/1BOM</th>
                <th>数量/商品</th>
                <th>在庫</th>
                <th>場所</th>
                <th>RefDes</th>
                <th>登録元</th>
                <th>削除</th>
            </tr>
            <?php foreach ($components as $c): ?>
            <tr>
                <td><?= h($c['id']) ?></td>
                <td><?= h(part_type_label($c['component_role'])) ?></td>
                <td>
                    <strong><?= h($c['name']) ?></strong>
                    <div class="small">部品コード: <?= h($c['part_code']) ?></div>
                </td>
                <td>
                    <?= h($c['category']) ?><br>
                    <span class="small"><?= h($c['subcategory']) ?></span>
                </td>
                <td><?= h($c['mpn']) ?></td>
                <td><?= h($c['qty_per_unit']) ?></td>
                <td><?= h((float)$c['qty_per_unit'] * (float)$selectedBom['qty_per_product']) ?></td>
                <td><?= h($c['quantity']) ?></td>
                <td><?= h(implode(', ', $componentStorageMap[(int)$c['part_id']] ?? parse_storage_codes($c['location'] ?? ''))) ?></td>
                <td><?= h($c['reference_designators']) ?></td>
                <td><?= h($c['source_type']) ?></td>
                <td>
                    <a class="btn delete-btn" href="product_bom.php?product_id=<?= h($product_id) ?>&delete=<?= h($c['id']) ?>" onclick="return confirm('削除しますか？')">削除</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<script>
const parts = <?= json_encode(array_map(function($p) {
    return [
        'id' => $p['id'],
        'name' => $p['name'] ?? '',
        'mpn' => $p['mpn'] ?? '',
        'part_code' => $p['part_code'] ?? '',
        'part_type' => $p['part_type'] ?? '',
        'category' => $p['category'] ?? '',
        'subcategory' => $p['subcategory'] ?? '',
        'tags' => $p['tags'] ?? '',
        'quantity' => $p['quantity'] ?? 0,
        'location' => implode(', ', $partsStorageMap[(int)$p['id']] ?? parse_storage_codes($p['location'] ?? '')),
        'search_text' => strtolower(
            ($p['name'] ?? '') . ' ' .
            ($p['mpn'] ?? '') . ' ' .
            ($p['part_code'] ?? '') . ' ' .
            ($p['part_type'] ?? '') . ' ' .
            ($p['category'] ?? '') . ' ' .
            ($p['subcategory'] ?? '') . ' ' .
            ($p['tags'] ?? '') . ' ' .
            ($p['location'] ?? '')
        ),
    ];
}, $parts), JSON_UNESCAPED_UNICODE) ?>;

const searchInput = document.getElementById('partSearch');
const resultsBox = document.getElementById('searchResults');
const selectedPartBox = document.getElementById('selectedPart');
const hiddenPartId = document.getElementById('partId');

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderSelectedPart(part) {
    if (!part) {
        selectedPartBox.style.display = 'none';
        selectedPartBox.innerHTML = '';
        hiddenPartId.value = '';
        return;
    }

    selectedPartBox.style.display = 'block';
    selectedPartBox.innerHTML = `
        <div><strong>選択中:</strong> ${escapeHtml(part.name)}</div>
        <div class="small">部品コード: ${escapeHtml(part.part_code)} / MPN: ${escapeHtml(part.mpn)}</div>
        <div class="small">分類: ${escapeHtml(part.category)} / ${escapeHtml(part.subcategory)} / 在庫: ${escapeHtml(part.quantity)} / 場所: ${escapeHtml(part.location)}</div>
    `;
    hiddenPartId.value = part.id;
}

function renderResults(keyword) {
    const q = keyword.trim().toLowerCase();
    let filtered = parts;

    if (q !== '') {
        filtered = parts.filter(p => p.search_text.includes(q));
    }

    filtered = filtered.slice(0, 80);

    if (filtered.length === 0) {
        resultsBox.innerHTML = `<div class="search-item"><div class="search-sub">該当部品がありません</div></div>`;
        return;
    }

    resultsBox.innerHTML = filtered.map(part => {
        const tags = (part.tags || '')
            .split(/,|、|\n/)
            .map(t => t.trim())
            .filter(Boolean)
            .slice(0, 4)
            .map(t => `<span class="tag-chip">${escapeHtml(t)}</span>`)
            .join('');

        return `
            <div class="search-item" data-id="${part.id}">
                <div class="search-main">${escapeHtml(part.name)}</div>
                <div class="search-sub">
                    部品コード: ${escapeHtml(part.part_code)} /
                    MPN: ${escapeHtml(part.mpn)} /
                    分類: ${escapeHtml(part.category)} ${escapeHtml(part.subcategory)} /
                    在庫: ${escapeHtml(part.quantity)} /
                    場所: ${escapeHtml(part.location)}
                </div>
                <div>${tags}</div>
            </div>
        `;
    }).join('');

    document.querySelectorAll('.search-item[data-id]').forEach(el => {
        el.addEventListener('click', () => {
            const id = Number(el.dataset.id);
            const part = parts.find(p => Number(p.id) === id);
            renderSelectedPart(part);
            searchInput.value = part.name;
        });
    });
}

searchInput.addEventListener('input', () => {
    renderResults(searchInput.value);
});

renderResults('');
</script>
</body>
</html>
