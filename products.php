<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/product_bom_schema.php';
require_once __DIR__ . '/storage_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ensure_product_bom_schema($pdo);
ensure_storage_schema($pdo);

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

$q = trim($_GET['q'] ?? '');

$products = [];
$message = '';
$errorMessage = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
        $deleteId = (int)$_POST['delete_id'];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_components WHERE product_id = ?");
        $stmt->execute([$deleteId]);
        $componentCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM unmatched_bom_items WHERE product_id = ?");
        $stmt->execute([$deleteId]);
        $unmatchedCount = (int)$stmt->fetchColumn();

        if ($componentCount > 0 || $unmatchedCount > 0) {
            $errorMessage = 'この製品はBOMや未一致MPNデータが登録されているため削除できません。先に構成データを整理してください。';
        } else {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$deleteId]);
            $message = '製品を削除しました。';
        }
    }

    $sql = "
        SELECT 
            p.*,
            (
                SELECT COUNT(*) 
                FROM product_components pc 
                WHERE pc.product_id = p.id
            ) AS bom_count
        FROM products p
        WHERE 1=1
    ";
    $params = [];

    if ($q !== '') {
        $sql .= " AND (
            p.name LIKE ?
            OR p.switch_science_sku LIKE ?
            OR p.xian_diy_id LIKE ?
            OR CAST(p.id AS CHAR) LIKE ?
        )";
        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like];
    }

    $sql .= " ORDER BY p.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

function load_product_bom_preview(PDO $pdo, int $productId): array {
    $stmt = $pdo->prepare("
        SELECT 
            pc.component_role,
            pc.qty_per_unit,
            pc.source_type,
            pb.name AS bom_name,
            pb.qty_per_product AS bom_qty_per_product,
            prt.name,
            prt.mpn,
            prt.quantity,
            prt.location,
            (
                SELECT GROUP_CONCAT(sl.code ORDER BY sl.code SEPARATOR ', ')
                FROM part_storage_locations psl
                JOIN storage_locations sl ON sl.id = psl.storage_location_id
                WHERE psl.part_id = prt.id
            ) AS storage_codes
        FROM product_components pc
        JOIN product_boms pb ON pb.id = pc.product_bom_id
        JOIN parts prt ON prt.id = pc.part_id
        WHERE pc.product_id = ?
        ORDER BY pb.id, pc.component_role, prt.name
        LIMIT 30
    ");
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>製品一覧</title>
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
    --success-bg: #eaf8ee;
    --success-border: #9fd5ab;
    --success-text: #1f6b34;
    --error-bg: #fff0f0;
    --error-border: #f0b0b0;
    --error-text: #9b1c1c;
    --info-bg: #eef6ff;
    --info-border: #bfd7ff;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
}

.wrapper {
    max-width: 1450px;
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

.toolbar-card,
.table-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 8px 24px rgba(20, 40, 80, 0.05);
    margin-bottom: 18px;
}

.toolbar {
    display: flex;
    gap: 12px;
    align-items: end;
    justify-content: space-between;
    flex-wrap: wrap;
}

.search-group {
    display: flex;
    gap: 10px;
    align-items: end;
    flex-wrap: wrap;
}

.search-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
    font-size: 14px;
}

.search-group input {
    width: 320px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fff;
    font-size: 14px;
}

a.btn, button {
    display: inline-block;
    padding: 8px 12px;
    border-radius: 10px;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    border: none;
    cursor: pointer;
    margin-right: 6px;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: bold;
}

a.btn:hover, button:hover {
    background: var(--primary-hover);
}

button.delete-btn {
    background: var(--danger);
}

button.delete-btn:hover {
    background: var(--danger-hover);
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

.inline-form {
    display: inline;
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
}

th {
    background: #eef4ff;
    color: #1f3b75;
    text-align: left;
}

.preview-row {
    display: none;
    background: #fbfcff;
}

.preview-row.open {
    display: table-row;
}

.preview-box {
    background: #fafafa;
    border: 1px solid #e3e7ee;
    padding: 12px;
    border-radius: 12px;
}

.preview-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.preview-table th, .preview-table td {
    border: 1px solid #ddd;
    padding: 6px;
}

.preview-table th {
    background: #f0f4ff;
    color: #333;
}

.bad {
    background: #ffe8e8;
}

.good {
    background: #e8ffe8;
}

.small {
    font-size: 12px;
    color: var(--sub);
}

.toggle-btn {
    min-width: 92px;
}

.empty {
    padding: 16px;
    background: #fafafa;
    border: 1px solid #ddd;
    border-radius: 12px;
}

@media (max-width: 720px) {
    .wrapper {
        padding: 16px;
    }
    .toolbar-card,
    .table-card {
        padding: 16px;
    }
    .search-group input {
        width: 100%;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>

    <h1 class="page-title">製品一覧</h1>
    <p class="page-subtitle">登録済み製品の検索、編集、BOM確認を行えます。</p>

    <div class="toolbar-card">
        <div class="toolbar">
            <form method="get" class="search-group">
                <div>
                    <label for="q">検索</label>
                    <input
                        id="q"
                        type="text"
                        name="q"
                        value="<?= h($q) ?>"
                        placeholder="製品名 / SKD / XianDIY ID / 製品ID"
                    >
                </div>
                <div>
                    <button type="submit">検索</button>
                    <a class="btn" href="products.php">クリア</a>
                </div>
            </form>

            <div>
                <a class="btn" href="product_form.php">製品追加</a>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="message error">エラー: <?= h($errorMessage) ?></div>
    <?php endif; ?>

    <div class="table-card">
        <?php if (count($products) === 0): ?>
            <div class="empty">該当する製品がありません。</div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <tr>
                    <th>ID</th>
                    <th>製品名</th>
                    <th>Switch Science SKD</th>
                    <th>XianDIY ID</th>
                    <th>BOM件数</th>
                    <th>BOM確認</th>
                    <th>操作</th>
                </tr>

                <?php foreach ($products as $product): ?>
                <?php $previewRows = load_product_bom_preview($pdo, (int)$product['id']); ?>
                <tr>
                    <td><?= h($product['id']) ?></td>
                    <td><?= h($product['name']) ?></td>
                    <td><?= h($product['switch_science_sku']) ?></td>
                    <td><?= h($product['xian_diy_id']) ?></td>
                    <td><?= h($product['bom_count']) ?></td>
                    <td>
                        <?php if ((int)$product['bom_count'] > 0): ?>
                            <button
                                type="button"
                                class="toggle-btn"
                                onclick="togglePreview(<?= h($product['id']) ?>, this)"
                            >BOMを見る</button>
                        <?php else: ?>
                            <span class="small">BOM未登録</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="btn" href="product_detail.php?id=<?= h($product['id']) ?>">詳細</a>
                        <a class="btn" href="product_form.php?id=<?= h($product['id']) ?>">編集</a>
                        <a class="btn" href="product_bom.php?product_id=<?= h($product['id']) ?>">構成編集</a>
                        <a class="btn" href="product_lot_labels.php?product_id=<?= h($product['id']) ?>">出荷ラベル</a>
                        <form method="post" class="inline-form" onsubmit="return confirm('この製品を削除しますか？');">
                            <input type="hidden" name="delete_id" value="<?= h($product['id']) ?>">
                            <button type="submit" class="delete-btn">削除</button>
                        </form>
                    </td>
                </tr>

                <tr id="preview-row-<?= h($product['id']) ?>" class="preview-row">
                    <td colspan="7">
                        <div class="preview-box">
                            <div style="font-weight:bold; margin-bottom:10px;">
                                BOMプレビュー: <?= h($product['name']) ?>
                            </div>

                            <?php if (count($previewRows) === 0): ?>
                                <div class="small">BOM未登録</div>
                            <?php else: ?>
                                <table class="preview-table">
                                    <tr>
                                        <th>種別</th>
                                        <th>BOM</th>
                                        <th>部品名</th>
                                        <th>MPN</th>
                                        <th>必要数</th>
                                        <th>在庫</th>
                                        <th>場所</th>
                                        <th>状態</th>
                                    </tr>
                                    <?php foreach ($previewRows as $row): ?>
                                        <?php
                                            $requiredQty = (float)$row['qty_per_unit'] * (float)$row['bom_qty_per_product'];
                                            $isBad = ((float)$row['quantity'] < $requiredQty);
                                        ?>
                                        <tr class="<?= $isBad ? 'bad' : 'good' ?>">
                                            <td><?= h(part_type_label($row['component_role'])) ?></td>
                                            <td><?= h($row['bom_name']) ?></td>
                                            <td><?= h($row['name']) ?></td>
                                            <td><?= h($row['mpn']) ?></td>
                                            <td><?= h($requiredQty) ?></td>
                                            <td><?= h($row['quantity']) ?></td>
                                            <td><?= h($row['storage_codes'] ?: $row['location']) ?></td>
                                            <td><?= $isBad ? '不足' : 'OK' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                                <?php if ((int)$product['bom_count'] > count($previewRows)): ?>
                                    <div class="small" style="margin-top:8px;">
                                        先頭 <?= h(count($previewRows)) ?> 件を表示中。続きは「詳細」で確認してください。
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function togglePreview(productId, btn) {
    const row = document.getElementById('preview-row-' + productId);
    if (!row) return;

    const isOpen = row.classList.contains('open');

    document.querySelectorAll('.preview-row.open').forEach(r => {
        r.classList.remove('open');
    });
    document.querySelectorAll('.toggle-btn').forEach(b => {
        b.textContent = 'BOMを見る';
    });

    if (!isOpen) {
        row.classList.add('open');
        btn.textContent = '閉じる';
    }
}
</script>
</body>
</html>
