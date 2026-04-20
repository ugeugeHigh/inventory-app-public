<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ensure_storage_schema($pdo);

$rawIds = $_GET['ids'] ?? ($_POST['ids'] ?? '');
if (is_array($rawIds)) {
    $rawIds = implode(',', $rawIds);
}

$partIds = array_values(array_unique(array_filter(array_map(
    'intval',
    preg_split('/[,\s]+/', (string)$rawIds)
))));
$mode = ($_GET['mode'] ?? 'a4') === 'm110s' ? 'm110s' : 'a4';

$parts = [];
$storageMap = [];

if ($partIds) {
    $placeholders = implode(',', array_fill(0, count($partIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, part_code, name, manufacturer, mpn, category, subcategory, location
        FROM parts
        WHERE id IN ({$placeholders})
        ORDER BY category, subcategory, name
    ");
    $stmt->execute($partIds);
    $parts = $stmt->fetchAll();
    $storageMap = get_parts_storage_codes($pdo, array_column($parts, 'id'));
}

function part_storage_text(array $part, array $storageMap): string
{
    $codes = $storageMap[(int)$part['id']] ?? [];
    if ($codes) {
        return implode(',', $codes);
    }
    return trim((string)($part['location'] ?? '')) ?: '-';
}

function part_label_payload_id_list(array $partIds): string
{
    return implode(',', array_map('intval', $partIds));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>部品QRラベル一括印刷</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
    --bg: #f5f7fb;
    --card: #fff;
    --border: #d9e1ec;
    --text: #111;
    --sub: #667085;
    --primary: #2d6cdf;
}

* { box-sizing: border-box; }
body { margin: 0; font-family: Arial, "Noto Sans CJK JP", "Noto Sans JP", sans-serif; background: var(--bg); color: var(--text); }
.wrapper { max-width: 1180px; margin: 0 auto; padding: 24px; }
.top-link { display: inline-block; margin-bottom: 18px; color: var(--primary); text-decoration: none; }
.toolbar { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 14px; margin-bottom: 18px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.btn, button { display: inline-block; padding: 9px 13px; border-radius: 8px; border: 0; background: var(--primary); color: #fff; text-decoration: none; font-size: 13px; font-weight: bold; cursor: pointer; }
.small { color: var(--sub); font-size: 13px; }
.empty { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
.a4-sheet { display: grid; grid-template-columns: repeat(3, 64mm); gap: 5mm 3mm; align-items: start; }
.a4-label {
    width: 64mm;
    height: 32mm;
    background: #fff;
    border: 0.25mm solid #222;
    border-radius: 1.5mm;
    padding: 2mm;
    display: grid;
    grid-template-columns: 18mm 1fr;
    gap: 2mm;
    overflow: hidden;
}
.m110s-label {
    width: 40mm;
    height: 30mm;
    overflow: hidden;
    background: #fff;
    color: #000;
    border: 0.2mm solid #000;
    display: grid;
    grid-template-columns: 18.5mm 1fr;
    gap: 1.4mm;
    align-items: center;
    padding: 1.4mm;
    margin-bottom: 8px;
}
.qr img { width: 18mm; height: 18mm; image-rendering: pixelated; }
.m110s-label .qr img { width: 18.5mm; height: 18.5mm; }
.part-name { margin: 0 0 1mm; font-size: 9pt; font-weight: bold; line-height: 1.12; overflow-wrap: anywhere; max-height: 12mm; overflow: hidden; }
.m110s-label .part-name { font-size: 8pt; max-height: 14mm; }
.meta { font-size: 6.5pt; line-height: 1.25; color: #111; overflow-wrap: anywhere; }
.location { margin-top: 1mm; padding-top: 1mm; border-top: 0.2mm solid #000; font-size: 9pt; font-weight: bold; line-height: 1.1; overflow-wrap: anywhere; max-height: 8mm; overflow: hidden; }
.m110s-label .location { font-size: 8pt; max-height: 7mm; }

@page { size: A4; margin: 8mm; }
@media print {
    body { background: #fff; }
    .wrapper { max-width: none; padding: 0; margin: 0; }
    .top-link, .toolbar { display: none !important; }
    .a4-sheet { gap: 5mm 3mm; }
    <?php if ($mode === 'm110s'): ?>
    @page { size: 40mm 30mm; margin: 0; }
    html, body { width: 40mm; margin: 0; padding: 0; }
    .m110s-label {
        border: 0;
        margin: 0;
        page-break-after: always;
        break-after: page;
    }
    .m110s-label:last-child { page-break-after: auto; break-after: auto; }
    <?php endif; ?>
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>
    <a class="top-link" href="storage_locations.php">← 保管場所一覧へ</a>
    <div class="toolbar">
        <strong>部品QRラベル一括印刷</strong>
        <span class="small"><?= h(count($parts)) ?>件選択中 / <?= $mode === 'a4' ? 'A4インクジェット用' : 'M110S 40x30mm用' ?></span>
        <?php if ($parts): ?>
            <button type="button" onclick="window.print()">印刷</button>
            <a class="btn" href="part_label_sheet.php?mode=a4&ids=<?= h(part_label_payload_id_list($partIds)) ?>">A4</a>
            <a class="btn" href="part_label_sheet.php?mode=m110s&ids=<?= h(part_label_payload_id_list($partIds)) ?>">M110S</a>
        <?php endif; ?>
    </div>

    <?php if (!$parts): ?>
        <div class="empty">印刷する部品が選択されていません。保管場所一覧で部品を選択してください。</div>
    <?php else: ?>
        <?php if ($mode === 'a4'): ?>
            <div class="a4-sheet">
                <?php foreach ($parts as $part): ?>
                    <div class="a4-label">
                        <div class="qr">
                            <img src="part_qr.php?id=<?= h($part['id']) ?>&format=qr" alt="QR">
                        </div>
                        <div>
                            <div class="part-name"><?= h($part['name']) ?></div>
                            <div class="meta">
                                <?= h($part['part_code']) ?><br>
                                <?= h($part['category']) ?> <?= h($part['subcategory']) ?><br>
                                MPN: <?= h($part['mpn'] ?: '-') ?>
                            </div>
                            <div class="location"><?= h(part_storage_text($part, $storageMap)) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php foreach ($parts as $part): ?>
                <div class="m110s-label">
                    <div class="qr">
                        <img src="part_qr.php?id=<?= h($part['id']) ?>&format=qr" alt="QR">
                    </div>
                    <div>
                        <div class="meta">部品名</div>
                        <div class="part-name"><?= h($part['name']) ?></div>
                        <div class="meta">保管場所</div>
                        <div class="location"><?= h(part_storage_text($part, $storageMap)) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
