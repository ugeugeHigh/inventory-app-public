<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/part_taxonomy.php';
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

$message = '';
$errorMessage = '';

$q = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
$subcategory = trim($_GET['subcategory'] ?? '');
$tag = trim($_GET['tag'] ?? '');
$lowOnly = isset($_GET['low_only']) && $_GET['low_only'] === '1';

$parts = [];
$partStorageMap = [];
$totalCount = 0;

$categoryOptions = [];
$subcategoryOptions = [];
$taxonomyOptions = part_taxonomy_options();
ensure_storage_schema($pdo);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_id'])) {
        $deleteId = (int)$_POST['delete_id'];

        $check = $pdo->prepare("SELECT COUNT(*) FROM product_components WHERE part_id = ?");
        $check->execute([$deleteId]);
        $usedCount = (int)$check->fetchColumn();

        if ($usedCount > 0) {
            $errorMessage = 'この部品は製品構成で使用されているため削除できません。先に製品構成から外してください。';
        } else {
            $stmt = $pdo->prepare("DELETE FROM parts WHERE id = ?");
            $stmt->execute([$deleteId]);
            $message = '部品を削除しました。';
        }
    }

    $dbCategoryOptions = $pdo->query("
        SELECT DISTINCT category
        FROM parts
        WHERE category IS NOT NULL AND category <> ''
        ORDER BY category
    ")->fetchAll(PDO::FETCH_COLUMN);

    $dbSubcategoryOptions = $pdo->query("
        SELECT DISTINCT subcategory
        FROM parts
        WHERE subcategory IS NOT NULL AND subcategory <> ''
        ORDER BY subcategory
    ")->fetchAll(PDO::FETCH_COLUMN);

    $categoryOptions = array_values(array_unique(array_merge(array_keys($taxonomyOptions), $dbCategoryOptions)));
    sort($categoryOptions, SORT_NATURAL);

    if ($category !== '' && isset($taxonomyOptions[$category])) {
        $subcategoryOptions = $taxonomyOptions[$category];
    } else {
        $taxonomySubcategories = [];
        foreach ($taxonomyOptions as $options) {
            $taxonomySubcategories = array_merge($taxonomySubcategories, $options);
        }
        $subcategoryOptions = array_values(array_unique(array_merge($taxonomySubcategories, $dbSubcategoryOptions)));
        sort($subcategoryOptions, SORT_NATURAL);
    }

    $sql = "SELECT * FROM parts WHERE 1=1";
    $params = [];

    if ($q !== '') {
        $sql .= " AND (
            name LIKE ?
            OR mpn LIKE ?
            OR location LIKE ?
            OR supplier LIKE ?
            OR part_code LIKE ?
            OR category LIKE ?
            OR subcategory LIKE ?
            OR tags LIKE ?
            OR manufacturer LIKE ?
        )";
        $like = "%{$q}%";
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    if ($category !== '') {
        $sql .= " AND category = ?";
        $params[] = $category;
    }

    if ($subcategory !== '') {
        $sql .= " AND subcategory = ?";
        $params[] = $subcategory;
    }

    if ($tag !== '') {
        $sql .= " AND tags LIKE ?";
        $params[] = '%' . $tag . '%';
    }

    if ($lowOnly) {
        $sql .= " AND quantity <= minimum_stock";
    }

    $sql .= " ORDER BY category, subcategory, name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $parts = $stmt->fetchAll();
    $partStorageMap = get_parts_storage_codes($pdo, array_column($parts, 'id'));

    $countStmt = $pdo->query("SELECT COUNT(*) FROM parts");
    $totalCount = (int)$countStmt->fetchColumn();

} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>部品一覧</title>
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

.filters-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
    gap: 12px;
    align-items: end;
}

label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
    font-size: 14px;
}

input[type="text"],
select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fff;
    font-size: 14px;
}

.check-row {
    display: flex;
    align-items: center;
    gap: 8px;
    height: 42px;
}

.check-row input[type="checkbox"] {
    width: auto;
}

a.btn, button {
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

.message.info {
    background: var(--info-bg);
    border-color: var(--info-border);
    color: #2457a6;
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

.low {
    background: #fff6f6;
}

.small {
    font-size: 12px;
    color: var(--sub);
}

.empty {
    padding: 16px;
    background: #fafafa;
    border: 1px solid #ddd;
    border-radius: 12px;
}

.inline-form {
    display: inline;
}

.tag-chip {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    background: var(--chip-bg);
    color: var(--chip-text);
    font-size: 12px;
    margin: 2px 4px 2px 0;
    white-space: nowrap;
}

.meta-line {
    margin-top: 4px;
}

@media (max-width: 1100px) {
    .filters-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 720px) {
    .wrapper {
        padding: 16px;
    }

    .card {
        padding: 16px;
    }

    .filters-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>

    <h1 class="page-title">部品一覧</h1>
    <p class="page-subtitle">
        在庫管理だけでなく、カテゴリ・タグで設計用部品検索もできる画面です。
    </p>

    <?php if ($message): ?>
        <div class="message success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="message error">エラー: <?= h($errorMessage) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="get">
            <div class="filters-grid">
                <div>
                    <label>キーワード</label>
                    <input
                        type="text"
                        name="q"
                        value="<?= h($q) ?>"
                        placeholder="部品名 / MPN / 部品コード / タグ / 購入先 など"
                    >
                </div>

                <div>
                    <label>大分類</label>
                    <select name="category">
                        <option value="">すべて</option>
                        <?php foreach ($categoryOptions as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= $category === $opt ? 'selected' : '' ?>>
                                <?= h($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>小分類</label>
                    <select id="subcategory_filter" name="subcategory" data-current="<?= h($subcategory) ?>">
                        <option value="">すべて</option>
                        <?php foreach ($subcategoryOptions as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= $subcategory === $opt ? 'selected' : '' ?>>
                                <?= h($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>タグ</label>
                    <input
                        type="text"
                        name="tag"
                        value="<?= h($tag) ?>"
                        placeholder="例: USB / AVR / 2.54mm"
                    >
                </div>

                <div>
                    <label>在庫条件</label>
                    <div class="check-row">
                        <input type="checkbox" id="low_only" name="low_only" value="1" <?= $lowOnly ? 'checked' : '' ?>>
                        <label for="low_only" style="margin:0;">在庫不足のみ</label>
                    </div>
                </div>

                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit">検索</button>
                    <a class="btn" href="parts.php">クリア</a>
                    <a class="btn" href="part_form.php">部品追加</a>
                </div>
            </div>
        </form>
    </div>

    <div class="message info">
        partsテーブル総件数: <?= h($totalCount) ?> 件　
        / 現在表示件数: <?= h(count($parts)) ?> 件
    </div>

    <div class="card table-wrap">
        <?php if (!$errorMessage && count($parts) === 0): ?>
            <div class="empty">条件に合う部品がありません。</div>
        <?php else: ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>部品情報</th>
                    <th>分類</th>
                    <th>タグ</th>
                    <th>在庫</th>
                    <th>場所</th>
                    <th>購入先</th>
                    <th>操作</th>
                </tr>
                <?php foreach ($parts as $p): ?>
                <tr class="<?= ((int)($p['quantity'] ?? 0) <= (int)($p['minimum_stock'] ?? 0)) ? 'low' : '' ?>">
                    <td><?= h($p['id'] ?? '') ?></td>

                    <td>
                        <div><strong><?= h($p['name'] ?? '') ?></strong></div>
                        <div class="meta-line small">部品コード: <?= h($p['part_code'] ?? '') ?></div>
                        <div class="meta-line small">MPN: <?= h($p['mpn'] ?? '') ?></div>
                        <?php if (!empty($p['manufacturer'])): ?>
                            <div class="meta-line small">メーカー: <?= h($p['manufacturer']) ?></div>
                        <?php endif; ?>
                        <div class="meta-line small">種別: <?= h(part_type_label($p['part_type'] ?? 'other')) ?></div>
                    </td>

                    <td>
                        <div><strong><?= h($p['category'] ?? '') ?></strong></div>
                        <div class="small"><?= h($p['subcategory'] ?? '') ?></div>
                    </td>

                    <td>
                        <?php
                        $tagList = [];
                        if (!empty($p['tags'])) {
                            $tagList = preg_split('/\s*,\s*|\s*、\s*|\s*\n\s*/u', (string)$p['tags']);
                            $tagList = array_filter($tagList, fn($v) => trim($v) !== '');
                        }
                        ?>
                        <?php if ($tagList): ?>
                            <?php foreach ($tagList as $tg): ?>
                                <span class="tag-chip"><?= h($tg) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="small">-</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div><strong><?= h($p['quantity'] ?? 0) ?></strong></div>
                        <div class="small">最低在庫: <?= h($p['minimum_stock'] ?? 0) ?></div>
                    </td>

                    <td>
                        <?php $storageCodes = $partStorageMap[(int)($p['id'] ?? 0)] ?? parse_storage_codes($p['location'] ?? ''); ?>
                        <?php if ($storageCodes): ?>
                            <?php foreach ($storageCodes as $code): ?>
                                <span class="tag-chip"><?= h($code) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="small">-</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($p['supplier_url'])): ?>
                            <a href="<?= h($p['supplier_url']) ?>" target="_blank">
                                <?= h(($p['supplier'] ?? '') !== '' ? $p['supplier'] : 'URL') ?>
                            </a>
                        <?php else: ?>
                            <?= h($p['supplier'] ?? '') ?>
                        <?php endif; ?>
                    </td>

                    <td style="min-width:190px;">
                        <a class="btn" href="part_detail.php?id=<?= h($p['id'] ?? '') ?>">詳細</a>
                        <a class="btn" href="part_form.php?id=<?= h($p['id'] ?? '') ?>">編集</a>
                        <a class="btn" href="part_qr.php?id=<?= h($p['id'] ?? '') ?>" target="_blank">QR</a>
                        <form method="post" class="inline-form" onsubmit="return confirm('この部品を削除しますか？');">
                            <input type="hidden" name="delete_id" value="<?= h($p['id'] ?? '') ?>">
                            <button type="submit" class="delete-btn">削除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>
<script>
const taxonomyOptions = <?= taxonomy_json() ?>;

function refreshFilterSubcategories() {
    const categorySelect = document.querySelector('select[name="category"]');
    const subcategorySelect = document.getElementById('subcategory_filter');
    if (!categorySelect || !subcategorySelect) return;

    const selectedCategory = categorySelect.value;
    const current = subcategorySelect.dataset.current || subcategorySelect.value;

    let options = [];
    if (selectedCategory && taxonomyOptions[selectedCategory]) {
        options = taxonomyOptions[selectedCategory];
    } else {
        Object.values(taxonomyOptions).forEach(values => {
            options = options.concat(values);
        });
        options = [...new Set(options)].sort((a, b) => a.localeCompare(b, 'ja'));
    }

    subcategorySelect.innerHTML = '';

    const emptyOption = document.createElement('option');
    emptyOption.value = '';
    emptyOption.textContent = 'すべて';
    subcategorySelect.appendChild(emptyOption);

    options.forEach(optionValue => {
        const option = document.createElement('option');
        option.value = optionValue;
        option.textContent = optionValue;
        if (optionValue === current) {
            option.selected = true;
        }
        subcategorySelect.appendChild(option);
    });

    subcategorySelect.dataset.current = '';
}

document.addEventListener('DOMContentLoaded', () => {
    refreshFilterSubcategories();
    const categorySelect = document.querySelector('select[name="category"]');
    if (categorySelect) {
        categorySelect.addEventListener('change', () => {
            const subcategorySelect = document.getElementById('subcategory_filter');
            if (subcategorySelect) {
                subcategorySelect.dataset.current = '';
            }
            refreshFilterSubcategories();
        });
    }
});
</script>
</body>
</html>
