#!/usr/bin/env python3
import io
import json
import sys
from pathlib import Path

import qrcode
from PIL import Image, ImageDraw, ImageFont


CANVAS_W = 472
CANVAS_H = 354
DPI = 300


def load_font(size, bold=False):
    candidates = [
        "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc" if bold else "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc",
        "/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf" if bold else "/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf" if bold else "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    ]

    for candidate in candidates:
        if Path(candidate).is_file():
            return ImageFont.truetype(candidate, size)

    return ImageFont.load_default()


def text_width(draw, text, font):
    if text == "":
        return 0
    bbox = draw.textbbox((0, 0), text, font=font)
    return bbox[2] - bbox[0]


def wrap_text(draw, text, font, max_width, max_lines):
    text = str(text).strip()
    if not text:
        return [""]

    lines = []
    current = ""

    for char in text:
        candidate = current + char
        if current and text_width(draw, candidate, font) > max_width:
            lines.append(current)
            current = char
            if len(lines) >= max_lines:
                break
        else:
            current = candidate

    if len(lines) < max_lines and current:
        lines.append(current)

    if len(lines) > max_lines:
        lines = lines[:max_lines]

    if len(lines) == max_lines and "".join(lines) != text:
        while lines[-1] and text_width(draw, lines[-1] + "...", font) > max_width:
            lines[-1] = lines[-1][:-1]
        lines[-1] = lines[-1] + "..."

    return lines


def choose_font_for_lines(draw, text, max_width, max_height, max_lines, start_size, min_size, bold=False):
    for size in range(start_size, min_size - 1, -1):
        font = load_font(size, bold=bold)
        lines = wrap_text(draw, text, font, max_width, max_lines)
        line_height = int(size * 1.12)
        text_height = len(lines) * line_height
        if (
            len(lines) <= max_lines
            and text_height <= max_height
            and all(text_width(draw, line, font) <= max_width for line in lines)
        ):
            return font, lines, line_height

    font = load_font(min_size, bold=bold)
    return font, wrap_text(draw, text, font, max_width, max_lines), int(min_size * 1.12)


def make_qr(payload, size=206):
    qr = qrcode.QRCode(
        version=None,
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=8,
        border=2,
    )
    qr.add_data(payload)
    qr.make(fit=True)
    img = qr.make_image(fill_color="black", back_color="white").convert("1")
    return img.resize((size, size), Image.Resampling.NEAREST)


def render_qr(data):
    payload = str(data.get("payload") or "").strip()
    size = int(data.get("size") or 240)

    if not payload:
        raise ValueError("payload is required")

    size = max(80, min(size, 1200))
    img = make_qr(payload, size=size)

    output = io.BytesIO()
    img.save(output, format="PNG", dpi=(DPI, DPI), optimize=True)
    return output.getvalue()


def render_label(data):
    name = str(data.get("name") or "").strip() or "未設定"
    location = str(data.get("location") or "").strip() or "-"
    payload = str(data.get("payload") or "").strip()

    if not payload:
        raise ValueError("payload is required")

    img = Image.new("L", (CANVAS_W, CANVAS_H), 255)
    draw = ImageDraw.Draw(img)

    qr_img = make_qr(payload)
    qr_x = 18
    qr_y = 74
    img.paste(qr_img.convert("L"), (qr_x, qr_y))

    text_x = 244
    text_w = CANVAS_W - text_x - 16

    divider_y = 244
    name_y = 48

    caption_font = load_font(18, bold=True)
    name_font, name_lines, name_lh = choose_font_for_lines(
        draw,
        name,
        text_w,
        max_height=divider_y - name_y - 8,
        max_lines=6,
        start_size=35,
        min_size=14,
        bold=True,
    )

    y = 24
    draw.text((text_x, y), "部品名", font=caption_font, fill=0)
    y = name_y
    for line in name_lines:
        draw.text((text_x, y), line, font=name_font, fill=0)
        y += name_lh

    draw.line((text_x, divider_y, CANVAS_W - 16, divider_y), fill=0, width=2)

    loc_caption_y = divider_y + 12
    draw.text((text_x, loc_caption_y), "保管場所", font=caption_font, fill=0)

    loc_font, loc_lines, loc_lh = choose_font_for_lines(
        draw,
        location,
        text_w,
        max_height=CANVAS_H - (loc_caption_y + 26) - 14,
        max_lines=3,
        start_size=38,
        min_size=14,
        bold=True,
    )

    loc_y = loc_caption_y + 26
    for line in loc_lines:
        draw.text((text_x, loc_y), line, font=loc_font, fill=0)
        loc_y += loc_lh

    img = img.point(lambda p: 0 if p < 180 else 255, mode="1")

    output = io.BytesIO()
    img.save(output, format="PNG", dpi=(DPI, DPI), optimize=True)
    return output.getvalue()


def main():
    try:
        raw = sys.stdin.read()
        data = json.loads(raw)
        if data.get("mode") == "qr":
            sys.stdout.buffer.write(render_qr(data))
        else:
            sys.stdout.buffer.write(render_label(data))
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
