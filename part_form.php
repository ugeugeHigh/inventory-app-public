<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/part_taxonomy.php';
require_once __DIR__ . '/storage_schema.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$errorMessage = '';
$warningMessage = '';
$taxonomyOptions = part_taxonomy_options();
ensure_storage_schema($pdo);
$storageOptions = get_storage_options($pdo);

$part = [
    'part_code' => '',
    'name' => '',
    'manufacturer' => '',
    'mpn' => '',
    'supplier_part_number' => '',
    'supplier' => '',
    'supplier_url' => '',
    'unit_price' => '0',
    'quantity' => '0',
    'minimum_stock' => '0',
    'location' => '',
    'part_type' => 'electronic',
    'category' => '',
    'subcategory' => '',
    'tags' => '',
    'footprint' => '',
    'note' => '',
    'qr_payload' => ''
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM parts WHERE id=?");
    $stmt->execute([$id]);
    $loaded = $stmt->fetch();

    if (!$loaded) {
        die('部品が見つかりません');
    }

    $part = array_merge($part, $loaded);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $part['part_code'] = trim($_POST['part_code'] ?? '');
    $part['name'] = trim($_POST['name'] ?? '');
    $part['manufacturer'] = trim($_POST['manufacturer'] ?? '');
    $part['mpn'] = normalize_mpn($_POST['mpn'] ?? '');
    $part['supplier_part_number'] = trim($_POST['supplier_part_number'] ?? '');
    $part['supplier'] = trim($_POST['supplier'] ?? '');
    $part['supplier_url'] = trim($_POST['supplier_url'] ?? '');
    $part['unit_price'] = is_numeric($_POST['unit_price'] ?? null) ? $_POST['unit_price'] : 0;
    $part['quantity'] = (int)($_POST['quantity'] ?? 0);
    $part['minimum_stock'] = (int)($_POST['minimum_stock'] ?? 0);
    $postedLocations = parse_storage_codes($_POST['locations'] ?? []);
    $manualLocations = parse_storage_codes($_POST['location'] ?? '');
    $postedLocations = array_values(array_unique(array_merge($manualLocations, $postedLocations)));
    $part['location'] = $postedLocations[0] ?? '';
    $part['part_type'] = $_POST['part_type'] ?? 'electronic';
    $part['category'] = trim($_POST['category'] ?? '');
    $part['subcategory'] = trim($_POST['subcategory'] ?? '');
    $part['tags'] = trim($_POST['tags'] ?? '');
    $part['footprint'] = trim($_POST['footprint'] ?? '');
    $part['note'] = trim($_POST['note'] ?? '');
    $part['qr_payload'] = trim($_POST['qr_payload'] ?? '');

    if ($part['name'] === '') {
        $errorMessage = '部品名は必須です。';
    }

    if ($errorMessage === '') {
        $part = apply_auto_part_classification($part);
    }

    // 部品コード自動採番
    if ($errorMessage === '' && $part['part_code'] === '') {
        $prefixMap = [
            'electronic' => 'P',
            'board' => 'B',
            'wire' => 'W',
            '3dp' => 'D',
            'mechanical' => 'M',
            'other' => 'O',
        ];

        $prefix = $prefixMap[$part['part_type']] ?? 'P';

        if ($isEdit) {
            $nextId = $id;
        } else {
            $stmt = $pdo->query("SELECT MAX(id) AS max_id FROM parts");
            $maxId = (int)($stmt->fetch()['max_id'] ?? 0);
            $nextId = $maxId + 1;
        }

        $part['part_code'] = sprintf('%s%05d', $prefix, $nextId);
    }

    // MPN重複チェック
    if ($errorMessage === '' && $part['mpn'] !== '') {
        if ($isEdit) {
            $stmt = $pdo->prepare("SELECT id FROM parts WHERE mpn = ? AND id <> ?");
            $stmt->execute([$part['mpn'], $id]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM parts WHERE mpn = ?");
            $stmt->execute([$part['mpn']]);
        }

        if ($stmt->fetch()) {
            $errorMessage = '同じMPNの部品が既に登録されています。';
        }
    }

    // 部品コード重複チェック
    if ($errorMessage === '' && $part['part_code'] !== '') {
        if ($isEdit) {
            $stmt = $pdo->prepare("SELECT id FROM parts WHERE part_code = ? AND id <> ?");
            $stmt->execute([$part['part_code'], $id]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM parts WHERE part_code = ?");
            $stmt->execute([$part['part_code']]);
        }

        if ($stmt->fetch()) {
            $errorMessage = '同じ部品コードの部品が既に登録されています。';
        }
    }

    if ($errorMessage === '' && $part['qr_payload'] === '') {
        $part['qr_payload'] = json_encode([
            'id' => $id ?: null,
            'part_code' => $part['part_code'],
            'name' => $part['name'],
            'manufacturer' => $part['manufacturer'],
            'mpn' => $part['mpn'],
            'supplier_part_number' => $part['supplier_part_number'],
            'location' => $part['location'],
            'part_type' => $part['part_type'],
            'category' => $part['category'],
            'subcategory' => $part['subcategory'],
            'tags' => $part['tags'],
        ], JSON_UNESCAPED_UNICODE);
    }

    if ($errorMessage === '') {
        try {
            if ($isEdit) {
                $pdo->prepare("
                    UPDATE parts
                    SET part_code=?, name=?, manufacturer=?, mpn=?, supplier_part_number=?, supplier=?, supplier_url=?, unit_price=?, quantity=?,
                        minimum_stock=?, location=?, part_type=?, category=?, subcategory=?, tags=?, footprint=?, note=?, qr_payload=?
                    WHERE id=?
                ")->execute([
                    $part['part_code'],
                    $part['name'],
                    $part['manufacturer'],
                    $part['mpn'] !== '' ? $part['mpn'] : null,
                    $part['supplier_part_number'],
                    $part['supplier'],
                    $part['supplier_url'],
                    $part['unit_price'],
                    $part['quantity'],
                    $part['minimum_stock'],
                    $part['location'],
                    $part['part_type'],
                    $part['category'],
                    $part['subcategory'],
                    $part['tags'],
                    $part['footprint'],
                    $part['note'],
                    $part['qr_payload'],
                    $id
                ]);
                $savedPartId = $id;
            } else {
                $pdo->prepare("
                    INSERT INTO parts (
                        part_code, name, manufacturer, mpn, supplier_part_number, supplier, supplier_url, unit_price,
                        quantity, minimum_stock, location, part_type, category, subcategory, tags, footprint, note, qr_payload
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $part['part_code'],
                    $part['name'],
                    $part['manufacturer'],
                    $part['mpn'] !== '' ? $part['mpn'] : null,
                    $part['supplier_part_number'],
                    $part['supplier'],
                    $part['supplier_url'],
                    $part['unit_price'],
                    $part['quantity'],
                    $part['minimum_stock'],
                    $part['location'],
                    $part['part_type'],
                    $part['category'],
                    $part['subcategory'],
                    $part['tags'],
                    $part['footprint'],
                    $part['note'],
                    $part['qr_payload']
                ]);
                $savedPartId = (int)$pdo->lastInsertId();
            }

            $usage = sync_part_storage_locations($pdo, $savedPartId, $postedLocations);
            if ($usage) {
                $_SESSION['storage_warning'] = json_encode($usage, JSON_UNESCAPED_UNICODE);
            }

            redirect_to('part_qr.php?id=' . rawurlencode((string)$savedPartId) . '&saved=1');
        } catch (Throwable $e) {
            $errorMessage = '保存中にエラーが発生しました: ' . $e->getMessage();
        }
    }
}

$selectedStorageCodes = $isEdit ? get_part_storage_codes($pdo, $id) : [];
if (!$selectedStorageCodes && trim((string)$part['location']) !== '') {
    $selectedStorageCodes = [strtoupper(trim((string)$part['location']))];
}

$storageWarningJson = $_SESSION['storage_warning'] ?? '';
if ($storageWarningJson !== '') {
    unset($_SESSION['storage_warning']);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title><?= $isEdit ? '部品編集' : '部品追加' ?></title>
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
    --error-bg: #fff0f0;
    --error-border: #f0b0b0;
    --error-text: #9b1c1c;
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
    gap: 16px;
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

input, select, textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fff;
    font-size: 14px;
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

.autofill-box {
    margin-bottom: 20px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: #f8fbff;
}

.autofill-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.autofill-row input {
    flex: 1;
    min-width: 280px;
}

#autofill_msg {
    margin-top: 10px;
    color: var(--sub);
    font-size: 13px;
}

.error-box {
    margin-bottom: 16px;
    padding: 12px 14px;
    border: 1px solid var(--error-border);
    background: var(--error-bg);
    color: var(--error-text);
    border-radius: 12px;
}

.note {
    color: var(--sub);
    font-size: 12px;
    margin-top: 6px;
    line-height: 1.6;
}

.submit-row {
    margin-top: 20px;
}

@media (max-width: 720px) {
    .wrapper {
        padding: 16px;
    }

    .card {
        padding: 18px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="top-link" href="index.php">← ダッシュボード</a>
    <a class="top-link" href="parts.php">← 部品一覧</a>

    <h1 class="page-title"><?= $isEdit ? '部品編集' : '部品追加' ?></h1>
    <p class="page-subtitle">
        在庫管理用の情報に加えて、分類やタグも登録できます。
    </p>

    <?php if ($errorMessage !== ''): ?>
        <div class="error-box"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($storageWarningJson !== ''): ?>
        <?php $storageWarning = json_decode($storageWarningJson, true) ?: []; ?>
        <?php if ($storageWarning): ?>
            <div class="error-box">
                警告: 選択した保管場所には、すでに他の部品が登録されています。
                <?php foreach ($storageWarning as $code => $usedParts): ?>
                    <div style="margin-top:6px;">
                        <strong><?= h($code) ?></strong>:
                        <?= h(implode(', ', array_map(fn($p) => ($p['part_code'] ?? '') . ' ' . ($p['name'] ?? ''), $usedParts))) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card">
        <form method="post">
            <div class="autofill-box">
                <label>購入ページURLから自動入力</label>
                <div class="autofill-row">
                    <input
                        type="text"
                        id="autofill_url"
                        placeholder="秋月 / Mouser の商品URLを貼る"
                    >
                    <button type="button" onclick="fetchPartInfo()">自動入力</button>
                </div>
                <div id="autofill_msg"></div>
            </div>

            <div class="form-grid">
                <div>
                    <label>部品コード</label>
                    <input type="text" name="part_code" value="<?= h($part['part_code']) ?>" placeholder="空なら自動採番">
                    <div class="note">空欄なら自動で採番します。</div>
                </div>

                <div>
                    <label>種別</label>
                    <select name="part_type">
                        <?php foreach (['electronic','board','wire','3dp','mechanical','other'] as $type): ?>
                            <option value="<?= h($type) ?>" <?= $part['part_type'] === $type ? 'selected' : '' ?>>
                                <?= h(part_type_label($type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>部品名</label>
                    <input type="text" name="name" required value="<?= h($part['name']) ?>">
                </div>

                <div>
                    <label>メーカー</label>
                    <input type="text" name="manufacturer" value="<?= h($part['manufacturer']) ?>">
                </div>

                <div>
                    <label>MPN（KiCad BOM照合キー）</label>
                    <input type="text" name="mpn" value="<?= h($part['mpn']) ?>">
                    <div class="note">同じMPNは重複登録できません。</div>
                </div>

                <div>
                    <label>仕入先品番</label>
                    <input type="text" name="supplier_part_number" value="<?= h($part['supplier_part_number']) ?>">
                </div>

                <div>
                    <label>大分類</label>
                    <select id="category" name="category">
                        <option value="">選択してください</option>
                        <?php foreach ($taxonomyOptions as $cat => $subOptions): ?>
                            <option value="<?= h($cat) ?>" <?= $part['category'] === $cat ? 'selected' : '' ?>>
                                <?= h($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>小分類</label>
                    <select id="subcategory" name="subcategory" data-current="<?= h($part['subcategory']) ?>">
                        <option value="">大分類を選択してください</option>
                    </select>
                    <div class="note">未選択のまま保存すると、部品名やMPNから自動分類します。</div>
                </div>

                <div class="form-full">
                    <label>タグ</label>
                    <input type="text" name="tags" value="<?= h($part['tags']) ?>" placeholder="例: AVR, DIP, 28pin, 5V">
                    <div class="note">カンマ区切りで複数入力できます。保存時に自動タグも追記します。</div>
                </div>

                <div>
                    <label>在庫数</label>
                    <input type="number" name="quantity" value="<?= h($part['quantity']) ?>">
                </div>

                <div>
                    <label>最低在庫</label>
                    <input type="number" name="minimum_stock" value="<?= h($part['minimum_stock']) ?>">
                </div>

                <div>
                    <label>単価</label>
                    <input type="number" step="0.01" name="unit_price" value="<?= h($part['unit_price']) ?>">
                </div>

                <div>
                    <label>保管場所</label>
                    <input
                        type="text"
                        name="location"
                        value="<?= h(implode(',', $selectedStorageCodes ?: parse_storage_codes($part['location']))) ?>"
                        list="storage_locations"
                        placeholder="例: A1,A2,P001"
                    >
                    <datalist id="storage_locations">
                        <?php foreach ($storageOptions as $storage): ?>
                            <option value="<?= h($storage['code']) ?>">
                                <?= h($storage['code'] . ' / ' . storage_type_label($storage['location_type']) . ' / ' . $storage['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="note">
                        複数ある場合は A1,A2,P001 のようにカンマ区切りで入力できます。
                        <a href="storage_locations.php" target="_blank">保管場所一覧</a>
                    </div>
                </div>

                <div class="form-full">
                    <label>保管場所（複数選択）</label>
                    <select name="locations[]" id="locationsSelect" multiple size="8">
                        <?php foreach ($storageOptions as $storage): ?>
                            <option value="<?= h($storage['code']) ?>" <?= in_array($storage['code'], $selectedStorageCodes, true) ? 'selected' : '' ?>>
                                <?= h($storage['code'] . ' / ' . storage_type_label($storage['location_type']) . ' / ' . $storage['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="note">
                        テキスト入力と同じ内容を選択でも指定できます。保存時に選択場所がすでに使われていれば警告します。
                    </div>
                    <div id="locationUsageWarning" class="note" style="color:#9b1c1c;"></div>
                </div>

                <div>
                    <label>購入先</label>
                    <input type="text" name="supplier" value="<?= h($part['supplier']) ?>">
                </div>

                <div>
                    <label>購入URL</label>
                    <input type="text" name="supplier_url" value="<?= h($part['supplier_url']) ?>">
                </div>

                <div>
                    <label>Footprint</label>
                    <input type="text" name="footprint" value="<?= h($part['footprint']) ?>">
                </div>

                <div>
                    <label>QR payload（空なら自動生成）</label>
                    <input type="text" name="qr_payload" value="<?= h($part['qr_payload']) ?>">
                </div>

                <div class="form-full">
                    <label>備考</label>
                    <textarea name="note" rows="5"><?= h($part['note']) ?></textarea>
                </div>
            </div>

            <div class="submit-row">
                <button type="submit"><?= $isEdit ? '更新する' : '登録する' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
async function fetchPartInfo() {
    const url = document.getElementById('autofill_url').value.trim();
    const msg = document.getElementById('autofill_msg');

    if (!url) {
        msg.textContent = 'URLを入れてください';
        return;
    }

    msg.textContent = '取得中...';

    const form = new FormData();
    form.append('url', url);

    try {
        const res = await fetch('fetch_part_info.php', {
            method: 'POST',
            body: form
        });

        const text = await res.text();

        let json;
        try {
            json = JSON.parse(text);
        } catch (e) {
            msg.textContent = '返り値がJSONではありません: ' + text;
            return;
        }

        if (!json.ok) {
            msg.textContent = '取得失敗: ' + json.error;
            return;
        }

        const d = json.data || {};

        const setValue = (selector, value) => {
            const el = document.querySelector(selector);
            if (el && value !== undefined && value !== null && value !== '') {
                el.value = value;
            }
        };

        setValue('[name="name"]', d.name);
        setValue('[name="manufacturer"]', d.manufacturer);
        setValue('[name="mpn"]', d.mpn);
        setValue('[name="supplier_part_number"]', d.supplier_part_number);
        setValue('[name="supplier"]', d.supplier);
        setValue('[name="supplier_url"]', d.supplier_url);
        setValue('[name="unit_price"]', d.unit_price);
        setValue('[name="part_type"]', d.part_type);
        setValue('[name="category"]', d.category);
        const subcategorySelect = document.getElementById('subcategory');
        if (subcategorySelect && d.subcategory) {
            subcategorySelect.dataset.current = d.subcategory;
        }
        refreshSubcategoryOptions();
        setValue('[name="subcategory"]', d.subcategory);
        setValue('[name="tags"]', d.tags);

        const noteEl = document.querySelector('[name="note"]');
        if (noteEl && d.note && !noteEl.value.trim()) {
            noteEl.value = d.note;
        }

        msg.textContent = '自動入力しました。内容を確認して保存してください。';
    } catch (e) {
        msg.textContent = '通信エラー: ' + e.message;
    }
}

const taxonomyOptions = <?= taxonomy_json() ?>;
const storageUsage = <?= json_encode(get_storage_usage($pdo, array_column($storageOptions, 'code'), $isEdit ? $id : null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function normalizeClassifyText() {
    return [
        document.querySelector('[name="name"]')?.value || '',
        document.querySelector('[name="mpn"]')?.value || '',
        document.querySelector('[name="manufacturer"]')?.value || '',
        document.querySelector('[name="footprint"]')?.value || ''
    ].join(' ').toUpperCase();
}

function detectAutoClassification() {
    const text = normalizeClassifyText();

    const match = pattern => pattern.test(text);
    const pin = (() => {
        const m = text.match(/([12])\s*[×X]\s*(\d+)/);
        return m ? `${m[1]}x${m[2]}` : '';
    })();

    if (match(/ATMEGA/)) return ['マイコン', 'AVR'];
    if (match(/ATTINY/)) return ['マイコン', 'ATtiny'];
    if (match(/RP2040/)) return ['マイコン', 'RP2040'];
    if (match(/ESP-WROOM/)) return ['マイコン', 'ESP'];
    if (match(/AT42QT|191-212/)) return ['マイコン', 'タッチセンサー'];
    if (match(/MCP9600/)) return ['マイコン', '熱電対'];
    if (match(/CH340/)) return ['IC', 'USBシリアル変換'];
    if (match(/HT16K33/)) return ['IC', 'LEDドライバ'];
    if (match(/74HC|TC74/)) return ['IC', 'ロジックIC'];
    if (match(/NJM2391|NJM78L33|レギュレーター/)) return ['IC', '電源IC'];
    if (match(/BSS138|MOSFET/)) return ['IC', 'MOSFET'];
    if (match(/チップ抵抗|RK73|RC0603|MCR03/)) return ['チップ抵抗', match(/1608|0603/) ? '1608' : 'その他'];
    if (match(/カーボン抵抗|炭素皮膜抵抗|CF25|CFS50/)) return ['リード抵抗', 'カーボン抵抗'];
    if (match(/コンデンサ|コンデンサー|MLCC|GRM|RD15|C1608|CC0603/)) {
        if (match(/電解/)) return ['コンデンサ', '電解コンデンサ'];
        if (match(/1608|0603|GRM188|C1608|CC0603/)) return ['コンデンサ', 'チップセラミック 1608'];
        if (match(/3216|GRM31/)) return ['コンデンサ', 'チップセラミック 3216'];
        return ['コンデンサ', 'リードセラミック'];
    }
    if (match(/LED/)) return ['ダイオード', 'LED'];
    if (match(/ツェナー|BZX|UDZV/)) return ['ダイオード', 'ツェナーダイオード'];
    if (match(/1N4148/)) return ['ダイオード', 'スイッチングダイオード'];
    if (match(/ショットキー|CUHS/)) return ['ダイオード', 'ショットキーダイオード'];
    if (match(/ボックスヘッダー/)) return ['ピンソケット', pin === '2x5' ? '2x5' : 'その他'];
    if (match(/ピンヘッダー|ピンヘッダ|PH-/)) return ['ピンヘッダ', ['1x40', '2x40'].includes(pin) ? pin : 'その他'];
    if (match(/ICソケット|2227-|ゼロプレッシャー|ULO-ZS/)) return ['ピンソケット', match(/ゼロプレッシャー|ULO-ZS/) ? 'ゼロプレッシャー' : 'ICソケット'];
    if (match(/ピンソケット|FH-/)) return ['ピンソケット', taxonomyOptions['ピンソケット']?.includes(pin) ? pin : 'その他'];
    if (match(/USB TYPE-C|TYPE-C/)) return ['コネクタ', 'USB Type-C'];
    if (match(/MICROUSB|MICRO USB/)) return ['コネクタ', 'microUSB'];
    if (match(/USBコネクター|USB CONNECTOR|5075BR/)) return ['コネクタ', 'USB-B'];
    if (match(/GROVE/)) return ['コネクタ', 'Grove'];
    if (match(/ターミナルブロック|TB113|APF-102/)) return ['コネクタ', 'ターミナルブロック'];
    if (match(/DCジャック|2DC/)) return ['コネクタ', 'DCジャック'];
    if (match(/SMA/)) return ['コネクタ', 'SMA'];
    if (match(/タクトスイッチ/)) return ['スイッチ', 'タクトスイッチ'];
    if (match(/スライドスイッチ/)) return ['スイッチ', 'スライドスイッチ'];
    if (match(/GATERON|KEY SWITCH|SWITCH SET/)) return ['キーボード部品', 'キースイッチ'];
    if (match(/キーキャップ/)) return ['キーボード部品', 'キーキャップ'];
    if (match(/ジャンパーピン/)) return ['スイッチ', 'ジャンパーピン'];
    if (match(/クリスタル|水晶発振子/)) {
        if (match(/12\s*MHZ/)) return ['水晶発振子', '12MHz'];
        if (match(/16\s*MHZ/)) return ['水晶発振子', '16MHz'];
        if (match(/32\.768\s*KHZ/)) return ['水晶発振子', '32.768kHz'];
        return ['水晶発振子', 'その他'];
    }

    return ['', ''];
}

function applyAutoClassificationToEmptyFields() {
    const categorySelect = document.getElementById('category');
    const subcategorySelect = document.getElementById('subcategory');
    if (!categorySelect || !subcategorySelect || categorySelect.value) return;

    const [category, subcategory] = detectAutoClassification();
    if (!category) return;

    categorySelect.value = category;
    subcategorySelect.dataset.current = subcategory;
    refreshSubcategoryOptions();
}

function refreshSubcategoryOptions() {
    const categorySelect = document.getElementById('category');
    const subcategorySelect = document.getElementById('subcategory');
    if (!categorySelect || !subcategorySelect) return;

    const selectedCategory = categorySelect.value;
    const current = subcategorySelect.dataset.current || subcategorySelect.value;
    const options = taxonomyOptions[selectedCategory] || [];

    subcategorySelect.innerHTML = '';

    const emptyOption = document.createElement('option');
    emptyOption.value = '';
    emptyOption.textContent = selectedCategory ? '選択してください' : '大分類を選択してください';
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
    refreshSubcategoryOptions();
    const categorySelect = document.getElementById('category');
    if (categorySelect) {
        categorySelect.addEventListener('change', () => {
            const subcategorySelect = document.getElementById('subcategory');
            if (subcategorySelect) {
                subcategorySelect.dataset.current = '';
            }
            refreshSubcategoryOptions();
        });
    }

    ['name', 'mpn', 'manufacturer', 'footprint'].forEach(fieldName => {
        const field = document.querySelector(`[name="${fieldName}"]`);
        if (field) {
            field.addEventListener('blur', applyAutoClassificationToEmptyFields);
        }
    });

    const locationsSelect = document.getElementById('locationsSelect');
    const locationInput = document.querySelector('[name="location"]');
    const warningBox = document.getElementById('locationUsageWarning');

    function parseLocationInput(value) {
        return String(value || '')
            .split(/[,\u3001\s\n]+/)
            .map(code => code.trim().toUpperCase())
            .filter(Boolean);
    }

    function getCurrentLocationCodes() {
        const selected = locationsSelect
            ? Array.from(locationsSelect.selectedOptions).map(opt => opt.value)
            : [];
        const typed = locationInput ? parseLocationInput(locationInput.value) : [];
        return [...new Set([...typed, ...selected])];
    }

    function syncSelectFromText() {
        if (!locationsSelect || !locationInput) return;
        const codes = parseLocationInput(locationInput.value);
        Array.from(locationsSelect.options).forEach(option => {
            option.selected = codes.includes(option.value);
        });
    }

    function updateLocationWarning() {
        if (!warningBox) return;
        const selected = getCurrentLocationCodes();
        const warnings = selected
            .filter(code => storageUsage[code] && storageUsage[code].length > 0)
            .map(code => `${code}: ${storageUsage[code].map(p => `${p.part_code || ''} ${p.name || ''}`.trim()).join(', ')}`);

        warningBox.textContent = warnings.length > 0
            ? '警告: 既に登録あり - ' + warnings.join(' / ')
            : '';
    }

    if (locationsSelect) {
        locationsSelect.addEventListener('change', () => {
            const selected = Array.from(locationsSelect.selectedOptions).map(opt => opt.value);
            if (locationInput) {
                locationInput.value = selected.join(',');
            }
            updateLocationWarning();
        });
    }

    if (locationInput) {
        locationInput.addEventListener('input', () => {
            syncSelectFromText();
            updateLocationWarning();
        });
        syncSelectFromText();
    }

    updateLocationWarning();
});
</script>
</body>
</html>
