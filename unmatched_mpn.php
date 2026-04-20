<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/product_bom_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ensure_product_bom_schema($pdo);

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

$product = null;
if ($product_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'resolve') {
        $unmatched_id = (int)($_POST['unmatched_id'] ?? 0);
        $part_id = (int)($_POST['part_id'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT u.*, p.part_type
            FROM unmatched_bom_items u
            JOIN parts p ON p.id = ?
            WHERE u.id = ?
        ");
        $stmt->execute([$part_id, $unmatched_id]);
        $row = $stmt->fetch();

        if ($row) {
            $role = $row['part_type'] === 'board' ? 'board' : $row['part_type'];

            $exists = $pdo->prepare("
                SELECT id FROM product_components
                WHERE product_bom_id=? AND part_id=?
            ");
            $exists->execute([(int)$row['product_bom_id'], $part_id]);
            $found = $exists->fetch();

            if ($found) {
                $pdo->prepare("
                    UPDATE product_components
                    SET qty_per_unit=?, reference_designators=?, component_role=?, source_type='bom_import'
                    WHERE id=?
                ")->execute([
                    $row['qty_per_unit'],
                    $row['reference_designators'],
                    $role,
                    $found['id']
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO product_components (
                        product_id, product_bom_id, part_id, qty_per_unit, component_role, reference_designators, source_type
                    ) VALUES (?,?,?,?,?,?, 'bom_import')
                ")->execute([
                    $row['product_id'],
                    $row['product_bom_id'],
                    $part_id,
                    $row['qty_per_unit'],
                    $role,
                    $row['reference_designators']
                ]);
            }

            $pdo->prepare("
                UPDATE unmatched_bom_items
                SET status='resolved', resolved_part_id=?
                WHERE id=?
            ")->execute([$part_id, $unmatched_id]);
        }

        $redirect = "unmatched_mpn.php";
        if ($product_id > 0) {
            $redirect .= "?product_id={$product_id}";
        }
        redirect_to($redirect);
    }

    if ($action === 'ignore') {
        $unmatched_id = (int)($_POST['unmatched_id'] ?? 0);
        $pdo->prepare("
            UPDATE unmatched_bom_items
            SET status='ignored'
            WHERE id=?
        ")->execute([$unmatched_id]);

        $redirect = "unmatched_mpn.php";
        if ($product_id > 0) {
            $redirect .= "?product_id={$product_id}";
        }
        redirect_to($redirect);
    }
}

$sql = "
SELECT u.*, pr.name AS product_name, pb.name AS bom_name
FROM unmatched_bom_items u
JOIN products pr ON pr.id = u.product_id
LEFT JOIN product_boms pb ON pb.id = u.product_bom_id
WHERE u.status='pending'
";
$params = [];

if ($product_id > 0) {
    $sql .= " AND u.product_id=?";
    $params[] = $product_id;
}

$sql .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>未一致MPN一覧</title>
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
    --warn-bg: #fff7e8;
    --warn-border: #f3d08a;
    --warn-text: #8a5a00;
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
    max-width: 1500px;
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

.notice {
    background: var(--warn-bg);
    border: 1px solid var(--warn-border);
    color: var(--warn-text);
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 18px;
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
    padding: 10px;
    vertical-align: top;
    text-align: left;
}

th {
    background: #eef4ff;
    color: #1f3b75;
}

.search-box {
    min-width: 380px;
}

.search-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fff;
    font-size: 14px;
}

.search-results {
    margin-top: 8px;
    border: 1px solid var(--border);
    border-radius: 12px;
    max-height: 280px;
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

.search-main {
    font-weight: bold;
}

.search-sub {
    font-size: 12px;
    color: var(--sub);
    margin-top: 4px;
    line-height: 1.5;
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

button, a.btn {
    display: inline-block;
    padding: 9px 14px;
    border-radius: 10px;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    border: none;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
}

button:hover, a.btn:hover {
    background: var(--primary-hover);
}

button.ignore-btn {
    background: var(--danger);
}

button.ignore-btn:hover {
    background: var(--danger-hover);
}

.actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.small {
    font-size: 12px;
    color: var(--sub);
}

.no-data {
    padding: 16px;
    background: #fafafa;
    border: 1px solid #ddd;
    border-radius: 12px;
}

.inline-form {
    margin: 0;
}

@media (max-width: 1100px) {
    .search-box {
        min-width: 300px;
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

    <h1 class="page-title">未一致MPN一覧</h1>
    <p class="page-subtitle">
        BOM取込で一致しなかったMPNを既存部品へ紐付ける画面です。
    </p>

    <?php if ($product): ?>
        <div class="notice">
            対象製品: <strong><?= h($product['name']) ?></strong>
            / <a href="product_detail.php?id=<?= h($product['id']) ?>">製品詳細へ</a>
        </div>
    <?php endif; ?>

    <div class="card table-wrap">
        <?php if (!$items): ?>
            <div class="no-data">未一致MPNはありません。</div>
        <?php else: ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>製品</th>
                    <th>BOMのMPN</th>
                    <th>数量/1BOM</th>
                    <th>RefDes</th>
                    <th>既存部品を検索して紐付け</th>
                    <th>操作</th>
                </tr>

                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= h($item['id']) ?></td>
                    <td>
                        <?= h($item['product_name']) ?>
                        <div class="small">BOM: <?= h($item['bom_name'] ?? 'メインBOM') ?></div>
                    </td>
                    <td>
                        <strong><?= h($item['raw_mpn']) ?></strong>
                        <div class="small">normalized: <?= h($item['normalized_mpn']) ?></div>
                    </td>
                    <td><?= h($item['qty_per_unit']) ?></td>
                    <td><?= h($item['reference_designators']) ?></td>

                    <td class="search-box">
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="resolve">
                            <input type="hidden" name="unmatched_id" value="<?= h($item['id']) ?>">
                            <input type="hidden" name="part_id" id="part_id_<?= h($item['id']) ?>" required>

                            <input
                                type="text"
                                class="search-input"
                                id="search_<?= h($item['id']) ?>"
                                placeholder="部品名 / MPN / 部品コード / 分類 / タグ / 場所 で検索"
                            >

                            <div class="selected-part" id="selected_<?= h($item['id']) ?>"></div>
                            <div class="search-results" id="results_<?= h($item['id']) ?>"></div>
                        </form>
                    </td>

                    <td>
                        <div class="actions">
                            <button type="submit" form="<?= '' ?>" onclick="return submitResolve(<?= h($item['id']) ?>)">紐付け</button>

                            <form method="post" class="inline-form" onsubmit="return confirm('無視しますか？');">
                                <input type="hidden" name="action" value="ignore">
                                <input type="hidden" name="unmatched_id" value="<?= h($item['id']) ?>">
                                <button type="submit" class="ignore-btn">無視</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
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
        'location' => $p['location'] ?? '',
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

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderSelectedPart(itemId, part) {
    const selectedBox = document.getElementById(`selected_${itemId}`);
    const hidden = document.getElementById(`part_id_${itemId}`);

    if (!part) {
        selectedBox.style.display = 'none';
        selectedBox.innerHTML = '';
        hidden.value = '';
        return;
    }

    selectedBox.style.display = 'block';
    selectedBox.innerHTML = `
        <div><strong>選択中:</strong> ${escapeHtml(part.name)}</div>
        <div class="small">部品コード: ${escapeHtml(part.part_code)} / MPN: ${escapeHtml(part.mpn)}</div>
        <div class="small">分類: ${escapeHtml(part.category)} / ${escapeHtml(part.subcategory)} / 在庫: ${escapeHtml(part.quantity)} / 場所: ${escapeHtml(part.location)}</div>
    `;
    hidden.value = part.id;
}

function renderResults(itemId, keyword) {
    const resultsBox = document.getElementById(`results_${itemId}`);
    const input = document.getElementById(`search_${itemId}`);
    const q = keyword.trim().toLowerCase();

    let filtered = parts;
    if (q !== '') {
        filtered = parts.filter(p => p.search_text.includes(q));
    }

    filtered = filtered.slice(0, 60);

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
            <div class="search-item" data-item-id="${itemId}" data-id="${part.id}">
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

    resultsBox.querySelectorAll('.search-item[data-id]').forEach(el => {
        el.addEventListener('click', () => {
            const id = Number(el.dataset.id);
            const part = parts.find(p => Number(p.id) === id);
            renderSelectedPart(itemId, part);
            input.value = part.name;
        });
    });
}

function submitResolve(itemId) {
    const hidden = document.getElementById(`part_id_${itemId}`);
    if (!hidden.value) {
        alert('紐付ける部品を選択してください。');
        return false;
    }

    const input = document.getElementById(`search_${itemId}`);
    const form = input.closest('form');
    form.submit();
    return false;
}

document.querySelectorAll('.search-input').forEach(input => {
    const itemId = input.id.replace('search_', '');
    input.addEventListener('input', () => {
        renderResults(itemId, input.value);
    });
    renderResults(itemId, '');
});
</script>
</body>
</html>
