#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
PWA icon / screenshot generator for the Hesabdari accounting app.

Reads a master square-ish source image and produces every icon size the
manifest needs, plus maskable icons, Apple touch icon, favicons and
store screenshots.

Requirements: Pillow (PIL).  Run from the project root:
    python tools/generate-pwa-icons.py

Re-run whenever the source artwork (assets/icon-source.png) changes.
"""
import os
from PIL import Image, ImageDraw

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "assets", "icon-source.png")
ICON_DIR = os.path.join(ROOT, "assets", "icons")
SHOT_DIR = os.path.join(ROOT, "assets", "screenshots")

# Brand colors — default theme. The live manifest is manifest.php; its theme_color
# can be overridden per-install via the `pwa_theme_color` setting.
THEME = (79, 70, 229)        # #4f46e5 indigo
THEME_LIGHT = (99, 102, 241)  # #6366f1

SIZES = [72, 96, 128, 144, 152, 192, 384, 512]


def ensure_dirs():
    os.makedirs(ICON_DIR, exist_ok=True)
    os.makedirs(SHOT_DIR, exist_ok=True)


def center_square(img):
    """Crop the largest centered square from an image."""
    w, h = img.size
    side = min(w, h)
    left = (w - side) // 2
    top = (h - side) // 2
    return img.crop((left, top, left + side, top + side))


def vertical_gradient(size, top_color, bottom_color):
    """Create a smooth diagonal-ish vertical gradient canvas."""
    w, h = size
    base = Image.new("RGB", size, top_color)
    top = Image.new("RGB", size, bottom_color)
    mask = Image.new("L", size)
    md = mask.load()
    for y in range(h):
        for x in range(w):
            # diagonal blend
            t = (x / w + y / h) / 2
            md[x, y] = int(255 * t)
    base.paste(top, (0, 0), mask)
    return base


def main():
    ensure_dirs()
    if not os.path.exists(SRC):
        raise SystemExit(f"Source image not found: {SRC}")

    master = Image.open(SRC).convert("RGBA")
    sq = center_square(master)

    # Standard ("any") icons -- full-bleed artwork.
    for s in SIZES:
        out = sq.resize((s, s), Image.LANCZOS)
        out.save(os.path.join(ICON_DIR, f"icon-{s}.png"), "PNG")
        print("wrote", f"icon-{s}.png")

    # Maskable icons -- artwork inset on solid brand bg so it survives
    # aggressive OS masking (safe zone = inner 80%).
    for s in (192, 512):
        canvas = Image.new("RGBA", (s, s), THEME + (255,))
        inner = int(s * 0.78)
        art = sq.resize((inner, inner), Image.LANCZOS)
        off = (s - inner) // 2
        canvas.alpha_composite(art, (off, off))
        canvas.convert("RGB").save(
            os.path.join(ICON_DIR, f"maskable-{s}.png"), "PNG")
        print("wrote", f"maskable-{s}.png")

    # Apple touch icon (no transparency, 180x180).
    apple = sq.resize((180, 180), Image.LANCZOS).convert("RGB")
    apple.save(os.path.join(ICON_DIR, "apple-touch-icon.png"), "PNG")
    print("wrote apple-touch-icon.png")

    # Microsoft tile.
    mstile = sq.resize((150, 150), Image.LANCZOS).convert("RGB")
    mstile.save(os.path.join(ICON_DIR, "mstile-150.png"), "PNG")
    print("wrote mstile-150.png")

    # Favicons.
    fav32 = sq.resize((32, 32), Image.LANCZOS)
    fav16 = sq.resize((16, 16), Image.LANCZOS)
    fav32.convert("RGB").save(os.path.join(ICON_DIR, "favicon-32.png"), "PNG")
    fav16.convert("RGB").save(os.path.join(ICON_DIR, "favicon-16.png"), "PNG")
    # Multi-resolution .ico at project root.
    sq.resize((64, 64), Image.LANCZOS).save(
        os.path.join(ROOT, "favicon.ico"),
        sizes=[(16, 16), (32, 32), (48, 48), (64, 64)])
    print("wrote favicon-32.png, favicon-16.png, favicon.ico")

    # Store screenshots (form_factor narrow + wide).
    icon_big = sq.resize((220, 220), Image.LANCZOS)

    narrow = vertical_gradient((720, 1280), THEME_LIGHT, THEME)
    narrow.paste(icon_big, ((720 - 220) // 2, 360), icon_big)
    narrow.save(os.path.join(SHOT_DIR, "screenshot-narrow.png"), "PNG")
    print("wrote screenshot-narrow.png")

    wide = vertical_gradient((1280, 720), THEME_LIGHT, THEME)
    wide.paste(icon_big, ((1280 - 220) // 2, (720 - 220) // 2), icon_big)
    wide.save(os.path.join(SHOT_DIR, "screenshot-wide.png"), "PNG")
    print("wrote screenshot-wide.png")

    print("DONE")


if __name__ == "__main__":
    main()
