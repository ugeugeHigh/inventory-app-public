<?php

function part_taxonomy_options(): array
{
    return [
        'マイコン' => ['AVR', 'ATtiny', 'RP2040', 'ESP', 'タッチセンサー', '熱電対', 'その他'],
        'IC' => ['USBシリアル変換', 'LEDドライバ', 'ロジックIC', '電源IC', 'MOSFET', 'その他'],
        'チップ抵抗' => ['1608', '3216', 'その他'],
        'リード抵抗' => ['カーボン抵抗', '金属皮膜抵抗', 'その他'],
        'コンデンサ' => ['チップセラミック 1608', 'チップセラミック 3216', 'リードセラミック', '電解コンデンサ', 'その他'],
        'ダイオード' => ['LED', 'ツェナーダイオード', 'スイッチングダイオード', 'ショットキーダイオード', 'その他'],
        'ピンヘッダ' => ['1x40', '2x40', 'その他'],
        'ピンソケット' => ['1x2', '1x4', '1x5', '1x6', '1x7', '1x8', '1x9', '1x14', '1x15', '2x5', '2x15', 'ICソケット', 'ゼロプレッシャー', 'その他'],
        'コネクタ' => ['USB-B', 'microUSB', 'USB Type-C', 'Grove', 'ターミナルブロック', 'DCジャック', 'SMA', 'その他'],
        'スイッチ' => ['タクトスイッチ', 'スライドスイッチ', 'キースイッチ', 'ジャンパーピン', 'その他'],
        '水晶発振子' => ['12MHz', '16MHz', '32.768kHz', 'その他'],
        'キーボード部品' => ['キースイッチ', 'キーキャップ', 'その他'],
        '基板' => ['製品基板', '変換基板', 'その他'],
        'センサー' => ['タッチ', '熱電対', 'その他'],
        'その他' => ['その他'],
    ];
}

function normalize_part_text(array $part): string
{
    return mb_strtoupper(implode(' ', [
        (string)($part['name'] ?? ''),
        (string)($part['mpn'] ?? ''),
        (string)($part['manufacturer'] ?? ''),
        (string)($part['footprint'] ?? ''),
    ]), 'UTF-8');
}

function add_part_tag(array &$tags, string $tag): void
{
    $tag = trim($tag);
    if ($tag === '') {
        return;
    }
    $tags[$tag] = true;
}

function split_part_tags(string $tags): array
{
    $values = preg_split('/\s*,\s*|\s*、\s*|\s*\n\s*/u', $tags);
    $result = [];

    foreach ($values ?: [] as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            $result[$value] = true;
        }
    }

    return $result;
}

function merge_part_tags(string $currentTags, string $autoTags): string
{
    $merged = split_part_tags($currentTags);

    foreach (array_keys(split_part_tags($autoTags)) as $tag) {
        $merged[$tag] = true;
    }

    return implode(',', array_keys($merged));
}

function apply_auto_part_classification(array $part, bool $overwrite = false): array
{
    $classified = classify_part($part);

    if ($overwrite || trim((string)($part['category'] ?? '')) === '') {
        $part['category'] = $classified['category'];
    }

    if ($overwrite || trim((string)($part['subcategory'] ?? '')) === '') {
        $part['subcategory'] = $classified['subcategory'];
    }

    $part['tags'] = merge_part_tags((string)($part['tags'] ?? ''), $classified['tags']);

    return $part;
}

function extract_part_pin_count(string $text): ?string
{
    if (preg_match('/([12])\s*[×X]\s*(\d+)/u', $text, $m)) {
        return $m[1] . 'x' . $m[2];
    }
    if (preg_match('/(\d+)\s*P/u', $text, $m)) {
        return $m[1] . 'P';
    }
    if (preg_match('/(\d+)\s*PIN/u', $text, $m)) {
        return $m[1] . 'pin';
    }
    return null;
}

function extract_part_resistance(string $text): ?string
{
    if (preg_match('/(\d+(?:\.\d+)?)\s*(M|K)?\s*Ω/u', $text, $m)) {
        $unit = $m[2] ?? '';
        return $m[1] . ($unit !== '' ? $unit : '') . 'Ω';
    }
    if (preg_match('/(\d+)K(\d+)/u', $text, $m)) {
        return $m[1] . '.' . $m[2] . 'kΩ';
    }
    if (preg_match('/(\d+)M(\d*)/u', $text, $m)) {
        return $m[1] . ($m[2] !== '' ? '.' . $m[2] : '') . 'MΩ';
    }
    if (preg_match('/(\d{2,4})[RJ]B?\b/u', $text, $m)) {
        return $m[1] . 'Ω';
    }
    return null;
}

function extract_part_capacitance(string $text): ?string
{
    if (preg_match('/(\d+(?:\.\d+)?)\s*([μΜU]F|NF|PF)/u', $text, $m)) {
        $unit = strtoupper($m[2]);
        $unit = str_replace(['UF', 'ΜF'], 'μF', $unit);
        $unit = str_replace(['NF', 'PF'], ['nF', 'pF'], $unit);
        return $m[1] . $unit;
    }
    return null;
}

function extract_part_frequency(string $text): ?string
{
    if (preg_match('/(\d+(?:\.\d+)?)\s*MHZ/u', $text, $m)) {
        return $m[1] . 'MHz';
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*KHZ/u', $text, $m)) {
        return $m[1] . 'kHz';
    }
    return null;
}

function classify_part(array $part): array
{
    $text = normalize_part_text($part);
    $tags = [];
    $category = 'その他';
    $subcategory = 'その他';

    $pinCount = extract_part_pin_count($text);
    $resistance = extract_part_resistance($text);
    $capacitance = extract_part_capacitance($text);
    $frequency = extract_part_frequency($text);

    if ($capacitance !== null) {
        add_part_tag($tags, $capacitance);
    }
    if ($frequency !== null) {
        add_part_tag($tags, $frequency);
    }

    if (preg_match('/ATMEGA|ATTINY|RP2040|ESP-WROOM|AT42QT|MCP9600|191-212/u', $text)) {
        $category = 'マイコン';
        if (preg_match('/ATMEGA/u', $text)) {
            $subcategory = 'AVR';
            add_part_tag($tags, 'AVR');
        } elseif (preg_match('/ATTINY/u', $text)) {
            $subcategory = 'ATtiny';
            add_part_tag($tags, 'AVR');
            add_part_tag($tags, 'tinyAVR');
        } elseif (preg_match('/RP2040/u', $text)) {
            $subcategory = 'RP2040';
        } elseif (preg_match('/ESP-WROOM/u', $text)) {
            $subcategory = 'ESP';
            add_part_tag($tags, 'Wi-Fi');
        } elseif (preg_match('/AT42QT|191-212/u', $text)) {
            $subcategory = 'タッチセンサー';
            add_part_tag($tags, 'タッチ');
        } elseif (preg_match('/MCP9600/u', $text)) {
            $subcategory = '熱電対';
            add_part_tag($tags, '温度');
        }
    } elseif (preg_match('/CH340/u', $text)) {
        $category = 'IC';
        $subcategory = 'USBシリアル変換';
        add_part_tag($tags, 'USB');
        add_part_tag($tags, 'UART');
    } elseif (preg_match('/HT16K33/u', $text)) {
        $category = 'IC';
        $subcategory = 'LEDドライバ';
        add_part_tag($tags, 'I2C');
        add_part_tag($tags, 'キースキャン');
    } elseif (preg_match('/74HC|TC74/u', $text)) {
        $category = 'IC';
        $subcategory = 'ロジックIC';
    } elseif (preg_match('/NJM2391|NJM78L33|レギュレーター/u', $text)) {
        $category = 'IC';
        $subcategory = '電源IC';
        add_part_tag($tags, '3.3V');
    } elseif (preg_match('/BSS138|MOSFET/u', $text)) {
        $category = 'IC';
        $subcategory = 'MOSFET';
        add_part_tag($tags, 'Nch');
    } elseif (preg_match('/チップ抵抗|RK73|RC0603|MCR03/u', $text)) {
        $category = 'チップ抵抗';
        $subcategory = preg_match('/1608|0603/u', $text) ? '1608' : 'その他';
        add_part_tag($tags, 'SMD');
        if ($resistance !== null) {
            add_part_tag($tags, $resistance);
        }
    } elseif (preg_match('/カーボン抵抗|炭素皮膜抵抗|CF25|CFS50/u', $text)) {
        $category = 'リード抵抗';
        $subcategory = 'カーボン抵抗';
        add_part_tag($tags, 'リード');
        if ($resistance !== null) {
            add_part_tag($tags, $resistance);
        }
    } elseif (preg_match('/金属皮膜/u', $text)) {
        $category = 'リード抵抗';
        $subcategory = '金属皮膜抵抗';
        if ($resistance !== null) {
            add_part_tag($tags, $resistance);
        }
    } elseif (preg_match('/コンデンサ|コンデンサー|MLCC|GRM|RD15|C1608|CC0603/u', $text)) {
        $category = 'コンデンサ';
        if (preg_match('/電解/u', $text)) {
            $subcategory = '電解コンデンサ';
        } elseif (preg_match('/1608|0603|GRM188|C1608|CC0603/u', $text)) {
            $subcategory = 'チップセラミック 1608';
            add_part_tag($tags, 'SMD');
        } elseif (preg_match('/3216|GRM31/u', $text)) {
            $subcategory = 'チップセラミック 3216';
            add_part_tag($tags, 'SMD');
        } else {
            $subcategory = 'リードセラミック';
        }
    } elseif (preg_match('/LED/u', $text)) {
        $category = 'ダイオード';
        $subcategory = 'LED';
        if (preg_match('/3216/u', $text)) {
            add_part_tag($tags, '3216');
            add_part_tag($tags, 'SMD');
        }
    } elseif (preg_match('/ツェナー|BZX|UDZV/u', $text)) {
        $category = 'ダイオード';
        $subcategory = 'ツェナーダイオード';
    } elseif (preg_match('/1N4148/u', $text)) {
        $category = 'ダイオード';
        $subcategory = 'スイッチングダイオード';
    } elseif (preg_match('/ショットキー|CUHS/u', $text)) {
        $category = 'ダイオード';
        $subcategory = 'ショットキーダイオード';
    } elseif (preg_match('/ボックスヘッダー/u', $text)) {
        $category = 'ピンソケット';
        $subcategory = $pinCount === '2x5' ? '2x5' : 'その他';
        add_part_tag($tags, 'ボックスヘッダー');
        if ($pinCount !== null) {
            add_part_tag($tags, $pinCount);
        }
    } elseif (preg_match('/ピンヘッダー|ピンヘッダ|PH-/u', $text)) {
        $category = 'ピンヘッダ';
        $subcategory = in_array($pinCount, ['1x40', '2x40'], true) ? $pinCount : 'その他';
        add_part_tag($tags, '2.54mm');
        if ($pinCount !== null) {
            add_part_tag($tags, $pinCount);
        }
    } elseif (preg_match('/ICソケット|2227-|ゼロプレッシャー|ULO-ZS/u', $text)) {
        $category = 'ピンソケット';
        $subcategory = preg_match('/ゼロプレッシャー|ULO-ZS/u', $text) ? 'ゼロプレッシャー' : 'ICソケット';
        if ($pinCount !== null) {
            add_part_tag($tags, $pinCount);
        }
    } elseif (preg_match('/ピンソケット|FH-/u', $text)) {
        $category = 'ピンソケット';
        $subcategory = in_array($pinCount, part_taxonomy_options()['ピンソケット'], true) ? $pinCount : 'その他';
        add_part_tag($tags, '2.54mm');
        if ($pinCount !== null) {
            add_part_tag($tags, $pinCount);
        }
    } elseif (preg_match('/USB TYPE-C|TYPE-C/u', $text)) {
        $category = 'コネクタ';
        $subcategory = 'USB Type-C';
        add_part_tag($tags, 'USB');
    } elseif (preg_match('/MICROUSB|MICRO USB/u', $text)) {
        $category = 'コネクタ';
        $subcategory = 'microUSB';
        add_part_tag($tags, 'USB');
    } elseif (preg_match('/USBコネクター|USB CONNECTOR|5075BR/u', $text)) {
        $category = 'コネクタ';
        $subcategory = 'USB-B';
        add_part_tag($tags, 'USB');
    } elseif (preg_match('/GROVE/u', $text)) {
        $category = 'コネクタ';
        $subcategory = 'Grove';
    } elseif (preg_match('/ターミナルブロック|TB113|APF-102/u', $text)) {
        $category = 'コネクタ';
        $subcategory = 'ターミナルブロック';
        if ($pinCount !== null) {
            add_part_tag($tags, $pinCount);
        }
    } elseif (preg_match('/DCジャック|2DC/u', $text)) {
        $category = 'コネクタ';
        $subcategory = 'DCジャック';
    } elseif (preg_match('/SMA/u', $text)) {
        $category = 'コネクタ';
        $subcategory = 'SMA';
    } elseif (preg_match('/タクトスイッチ/u', $text)) {
        $category = 'スイッチ';
        $subcategory = 'タクトスイッチ';
    } elseif (preg_match('/スライドスイッチ/u', $text)) {
        $category = 'スイッチ';
        $subcategory = 'スライドスイッチ';
    } elseif (preg_match('/GATERON|KEY SWITCH|SWITCH SET/u', $text)) {
        $category = 'キーボード部品';
        $subcategory = 'キースイッチ';
        add_part_tag($tags, 'キーボード');
    } elseif (preg_match('/キーキャップ/u', $text)) {
        $category = 'キーボード部品';
        $subcategory = 'キーキャップ';
        add_part_tag($tags, 'キーボード');
    } elseif (preg_match('/ジャンパーピン/u', $text)) {
        $category = 'スイッチ';
        $subcategory = 'ジャンパーピン';
        add_part_tag($tags, '2.54mm');
    } elseif (preg_match('/クリスタル|水晶発振子/u', $text)) {
        $category = '水晶発振子';
        $subcategory = $frequency ?? 'その他';
    }

    if (($part['part_type'] ?? '') === 'board' && $category === 'その他') {
        $category = '基板';
        $subcategory = '製品基板';
    }

    if ($category === 'ピンソケット' || $category === 'ピンヘッダ') {
        add_part_tag($tags, 'ピン数:' . $subcategory);
    }
    if ($category === 'チップ抵抗' && $resistance !== null) {
        add_part_tag($tags, '抵抗値:' . $resistance);
    }

    return [
        'category' => $category,
        'subcategory' => $subcategory,
        'tags' => implode(',', array_keys($tags)),
    ];
}

function taxonomy_json(): string
{
    return json_encode(part_taxonomy_options(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
