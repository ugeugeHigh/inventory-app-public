<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ensure_storage_schema($pdo);

$message = '';
$errorMessage = '';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_box') {
            $code = strtoupper(trim($_POST['box_code'] ?? ''));
            if ($code === '') {
                $code = next_storage_box_code($pdo);
            }
            create_storage_box($pdo, $code, trim($_POST['note'] ?? ''));
            $message = "部品箱 {$code} を作成しました。";
        }

        if ($action === 'add_pouch') {
            $code = create_storage_pouch(
                $pdo,
                $_POST['pouch_code'] ?? '',
                $_POST['pouch_name'] ?? '',
                $_POST['note'] ?? ''
            );
            $message = "チャック袋 {$code} を作成しました。";
        }

        if ($action === 'assign_part') {
            $partId = (int)($_POST['part_id'] ?? 0);
            $code = strtoupper(trim($_POST['location_code'] ?? ''));

            if ($partId > 0 && $code !== '') {
                $current = get_part_storage_codes($pdo, $partId);
                if (!in_array($code, $current, true)) {
                    $current[] = $code;
                }
                $usage = sync_part_storage_locations($pdo, $partId, $current);
                $message = "部品を {$code} に追加しました。";
                if (!empty($usage[$code])) {
                    $message .= ' 既に他の部品も登録されています。';
                }
            }
        }

        if ($action === 'remove_part') {
            $partId = (int)($_POST['part_id'] ?? 0);
            $code = strtoupper(trim($_POST['location_code'] ?? ''));

            if ($partId > 0 && $code !== '') {
                $current = array_values(array_filter(
                    get_part_storage_codes($pdo, $partId),
                    fn($existingCode) => $existingCode !== $code
                ));
                sync_part_storage_locations($pdo, $partId, $current);
                $message = "部品を {$code} から外しました。";
            }
        }
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

$boxes = $pdo->query("
    SELECT *
    FROM storage_locations
    WHERE location_type = 'box'
    ORDER BY code
")->fetchAll();

$smallBoxes = $pdo->query("
    SELECT child.*, parent.code AS box_code
    FROM storage_locations child
    JOIN storage_locations parent ON parent.id = child.parent_id
    WHERE child.location_type = 'small_box'
    ORDER BY parent.code, child.sort_order
")->fetchAll();

$pouches = $pdo->query("
    SELECT *
    FROM storage_locations
    WHERE location_type = 'pouch'
    ORDER BY code
")->fetchAll();

$partsByLocation = [];
$stmt = $pdo->query("
    SELECT
        p.id,
        p.part_code,
        p.name,
        p.mpn,
        p.category,
        p.subcategory,
        p.quantity,
        p.minimum_stock,
        sl.code AS location
    FROM part_storage_locations psl
    JOIN storage_locations sl ON sl.id = psl.storage_location_id
    JOIN parts p ON p.id = psl.part_id
    ORDER BY sl.code, p.category, p.subcategory, p.name
");

foreach ($stmt as $part) {
    $loc = trim((string)($part['location'] ?? ''));
    if ($loc === '') {
        $loc = '__unset__';
    }
    $partsByLocation[$loc][] = $part;
}

$unsetParts = $pdo->query("
    SELECT p.id, p.part_code, p.name, p.mpn, p.category, p.subcategory, p.quantity, p.minimum_stock, p.location
    FROM parts p
    LEFT JOIN part_storage_locations psl ON psl.part_id = p.id
    WHERE psl.id IS NULL
    ORDER BY p.category, p.subcategory, p.name
")->fetchAll();

$allParts = $pdo->query("
    SELECT id, part_code, name, mpn, category, subcategory
    FROM parts
    ORDER BY category, subcategory, name
")->fetchAll();

function render_part_list(array $parts, ?string $locationCode = null): void
{
    if (!$parts) {
        echo '<div class="small">空き</div>';
        return;
    }

    foreach ($parts as $part) {
        $low = (int)$part['quantity'] <= (int)$part['minimum_stock'];
        echo '<div class="part-row ' . ($low ? 'low' : '') . '">';
        echo '<label class="part-select">';
        echo '<input type="checkbox" class="label-part-checkbox" value="' . h($part['id']) . '">';
        echo '<span>印刷</span>';
        echo '</label>';
        echo '<a href="part_detail.php?id=' . h($part['id']) . '">';
        echo '<strong>' . h($part['name']) . '</strong>';
        echo '<span>' . h($part['part_code']) . ' / ' . h($part['category']) . ' / 在庫 ' . h($part['quantity']) . '</span>';
        echo '</a>';
        if ($locationCode !== null) {
            echo '<form method="post" class="inline-remove" onsubmit="return confirm(\'この場所から外しますか？\');">';
            echo '<input type="hidden" name="action" value="remove_part">';
            echo '<input type="hidden" name="location_code" value="' . h($locationCode) . '">';
            echo '<input type="hidden" name="part_id" value="' . h($part['id']) . '">';
            echo '<button type="submit" class="mini-btn">外す</button>';
            echo '</form>';
        }
        echo '</div>';
    }
}

function render_assign_form(string $locationCode, array $allParts): void
{
    echo '<form method="post" class="assign-form">';
    echo '<input type="hidden" name="action" value="assign_part">';
    echo '<input type="hidden" name="location_code" value="' . h($locationCode) . '">';
    echo '<select name="part_id" required>';
    echo '<option value="">部品を追加...</option>';
    foreach ($allParts as $part) {
        echo '<option value="' . h($part['id']) . '">' . h(($part['part_code'] ?? '') . ' / ' . ($part['name'] ?? '')) . '</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="mini-btn">追加</button>';
    echo '</form>';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>保管場所一覧</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
    --bg: #f5f7fb;
    --card: #fff;
    --border: #d9e1ec;
    --text: #223;
    --sub: #667085;
    --primary: #2d6cdf;
    --primary-hover: #1f57c5;
    --error-bg: #fff0f0;
    --error-border: #f0b0b0;
    --error-text: #9b1c1c;
    --success-bg: #eaf8ee;
    --success-border: #9fd5ab;
    --success-text: #1f6b34;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, "Noto Sans CJK JP", "Noto Sans JP", sans-serif;
    background: var(--bg);
    color: var(--text);
}

.wrapper {
    max-width: 1480px;
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
    margin: 0 0 8px;
    font-size: 28px;
}

.page-subtitle {
    margin: 0 0 22px;
    color: var(--sub);
    font-size: 14px;
}

.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 18px;
    box-shadow: 0 8px 24px rgba(20, 40, 80, 0.05);
    margin-bottom: 18px;
}

.message {
    margin-bottom: 18px;
    padding: 13px 15px;
    border-radius: 12px;
    border: 1px solid;
    font-size: 14px;
}

.success {
    background: var(--success-bg);
    border-color: var(--success-border);
    color: var(--success-text);
}

.error {
    background: var(--error-bg);
    border-color: var(--error-border);
    color: var(--error-text);
}

.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 14px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 10px;
    align-items: end;
}

label {
    display: block;
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: bold;
}

input,
select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 14px;
}

button,
a.btn {
    display: inline-block;
    padding: 10px 14px;
    border: none;
    border-radius: 10px;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
}

button:hover,
a.btn:hover {
    background: var(--primary-hover);
}

.section-title {
    margin: 0 0 14px;
    font-size: 20px;
}

.box-section {
    margin-bottom: 24px;
}

.box-heading {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: baseline;
    margin-bottom: 12px;
}

.box-title {
    margin: 0;
    font-size: 22px;
}

.small {
    color: var(--sub);
    font-size: 12px;
}

.small-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 12px;
}

.location-card {
    border: 1px solid var(--border);
    border-radius: 12px;
    background: #fff;
    min-height: 130px;
    overflow: hidden;
}

.location-head {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    padding: 10px 12px;
    background: #eef4ff;
    color: #1f3b75;
    font-weight: bold;
}

.location-body {
    padding: 10px 12px;
}

.part-row {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 8px;
    align-items: start;
    padding: 8px 0;
    border-bottom: 1px solid #edf0f5;
}

.part-row a {
    display: block;
    color: var(--text);
    text-decoration: none;
}

.part-select {
    display: inline-flex;
    gap: 4px;
    align-items: center;
    margin: 1px 0 0;
    color: var(--sub);
    font-size: 11px;
    font-weight: normal;
    white-space: nowrap;
}

.part-select input {
    width: auto;
    padding: 0;
}

.part-row:last-child {
    border-bottom: 0;
}

.part-row strong {
    display: block;
    font-size: 13px;
}

.part-row span {
    display: block;
    margin-top: 3px;
    color: var(--sub);
    font-size: 12px;
}

.part-row.low strong {
    color: #9b1c1c;
}

.inline-remove {
    grid-column: 2;
    margin-top: 6px;
}

.assign-form {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 8px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed var(--border);
}

.mini-btn {
    padding: 7px 9px;
    border-radius: 8px;
    font-size: 12px;
}

.pouch-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 12px;
}

@media (max-width: 720px) {
    .wrapper {
        padding: 16px;
    }

    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>

    <h1 class="page-title">保管場所一覧</h1>
    <p class="page-subtitle">部品箱A〜Z、小部品箱A1〜A14、チャック袋P001〜を辿って、中に入っている部品を確認できます。</p>

    <?php if ($message): ?>
        <div class="message success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="message error"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2 class="section-title">ラベル一括印刷</h2>
        <div class="small" style="margin-bottom:12px;">
            印刷したい部品にチェックを入れて、M110S用またはA4インクジェット用のQRラベルをまとめて印刷できます。
        </div>
        <div class="form-row" style="grid-template-columns:auto auto auto 1fr;">
            <button type="button" onclick="openSelectedLabels('m110s')">選択分をM110Sで印刷</button>
            <button type="button" onclick="openSelectedLabels('a4')">選択分をA4で印刷</button>
            <button type="button" onclick="toggleAllLabelParts(true)">全選択</button>
            <button type="button" onclick="toggleAllLabelParts(false)">選択解除</button>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">保管場所を追加</h2>
        <div class="actions-grid">
            <form method="post">
                <input type="hidden" name="action" value="add_box">
                <label>部品箱</label>
                <div class="form-row">
                    <input type="text" name="box_code" maxlength="1" placeholder="空なら次の箱: <?= h(next_storage_box_code($pdo)) ?>">
                    <button type="submit">箱を追加</button>
                </div>
                <div class="small" style="margin-top:8px;">箱を追加すると、A1〜A14のような小部品箱を自動作成します。</div>
            </form>

            <form method="post">
                <input type="hidden" name="action" value="add_pouch">
                <label>チャック袋</label>
                <div class="form-row">
                    <input type="text" name="pouch_code" placeholder="空なら次: <?= h(next_storage_pouch_code($pdo)) ?>">
                    <button type="submit">袋を追加</button>
                </div>
                <input type="text" name="pouch_name" placeholder="袋の説明名 例: 長尺ピンヘッダ袋" style="margin-top:10px;">
            </form>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">部品箱</h2>
        <?php if (!$boxes): ?>
            <div class="small">部品箱がありません。</div>
        <?php endif; ?>

        <?php foreach ($boxes as $box): ?>
            <section class="box-section">
                <div class="box-heading">
                    <h3 class="box-title">部品箱 <?= h($box['code']) ?></h3>
                    <span class="small">小部品箱 <?= h($box['code']) ?>1〜<?= h($box['code']) ?>14</span>
                </div>
                <div class="small-grid">
                    <?php foreach ($smallBoxes as $small): ?>
                        <?php if ($small['box_code'] !== $box['code']) continue; ?>
                        <?php $parts = $partsByLocation[$small['code']] ?? []; ?>
                        <div class="location-card">
                            <div class="location-head">
                                <span><?= h($small['code']) ?></span>
                                <span><?= h(count($parts)) ?>件</span>
                            </div>
                            <div class="location-body">
                                <?php render_part_list($parts, $small['code']); ?>
                                <?php render_assign_form($small['code'], $allParts); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2 class="section-title">チャック袋</h2>
        <?php if (!$pouches): ?>
            <div class="small">チャック袋はまだ登録されていません。箱に入らない部品は P001, P002... の系統で管理します。</div>
        <?php else: ?>
            <div class="pouch-grid">
                <?php foreach ($pouches as $pouch): ?>
                    <?php $parts = $partsByLocation[$pouch['code']] ?? []; ?>
                    <div class="location-card">
                        <div class="location-head">
                            <span><?= h($pouch['code']) ?></span>
                            <span><?= h(count($parts)) ?>件</span>
                        </div>
                        <div class="location-body">
                            <div class="small" style="margin-bottom:6px;"><?= h($pouch['name']) ?></div>
                            <?php render_part_list($parts, $pouch['code']); ?>
                            <?php render_assign_form($pouch['code'], $allParts); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 class="section-title">未設定</h2>
        <?php render_part_list($unsetParts); ?>
    </div>
</div>
<script>
function selectedLabelPartIds() {
    return Array.from(document.querySelectorAll('.label-part-checkbox:checked'))
        .map(input => input.value)
        .filter(Boolean);
}

function openSelectedLabels(mode) {
    const ids = selectedLabelPartIds();
    if (ids.length === 0) {
        alert('印刷する部品を選択してください。');
        return;
    }
    const url = 'part_label_sheet.php?mode=' + encodeURIComponent(mode) + '&ids=' + encodeURIComponent(ids.join(','));
    window.open(url, '_blank');
}

function toggleAllLabelParts(checked) {
    document.querySelectorAll('.label-part-checkbox').forEach(input => {
        input.checked = checked;
    });
}
</script>
</body>
</html>
