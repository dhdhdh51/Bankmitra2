#!/usr/bin/env python3
"""
Renders PNG previews of the launch screen and the launcher icon.

There is no emulator or device in this environment, so nothing about the app's
appearance can be confirmed by running it. This reads the real Android vector
drawables - Android's pathData is SVG path syntax, so the same path strings can be
rendered directly - and produces images that show what the artwork looks like.

It is a preview of the ARTWORK, not a screenshot of the app: fonts and the exact
splash icon scaling come from the device. Layout geometry mirrors
res/layout/activity_splash.xml.

    python3 tools/render-brand-preview.py [output-dir]

Default output: docs/previews/
"""

from __future__ import annotations

import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

import cairosvg
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
RES = ROOT / "android/app/src/main/res"
ANDROID_NS = "{http://schemas.android.com/apk/res/android}"

NAVY = "#0B2A5B"
GOLD = "#F2B21C"


def read_colour(name: str, default: str) -> str:
    """Pulls a colour literal out of values/*.xml so nothing is hardcoded twice."""
    for path in (RES / "values").glob("*.xml"):
        m = re.search(rf'<color name="{re.escape(name)}">(#[0-9A-Fa-f]{{6,8}})</color>', path.read_text())
        if m:
            return m.group(1)
    return default


def vector_to_svg_body(path: Path) -> tuple[str, float, float]:
    """
    Converts an Android vector drawable into SVG path elements.

    Handles the <group> transform used by ic_splash_logo.xml; anything more exotic
    would need a real converter, and the assertion below makes that loud rather
    than silently dropping artwork.
    """
    tree = ET.parse(path)
    root = tree.getroot()
    vw = float(root.get(f"{ANDROID_NS}viewportWidth"))
    vh = float(root.get(f"{ANDROID_NS}viewportHeight"))

    def emit(node, indent: str = "") -> str:
        out = []
        for child in node:
            tag = child.tag
            if tag == "path":
                d = child.get(f"{ANDROID_NS}pathData")
                fill = child.get(f"{ANDROID_NS}fillColor", "#000000")
                out.append(f'{indent}<path d="{d}" fill="{fill}"/>')
            elif tag == "group":
                tx = child.get(f"{ANDROID_NS}translateX", "0")
                ty = child.get(f"{ANDROID_NS}translateY", "0")
                sx = child.get(f"{ANDROID_NS}scaleX", "1")
                sy = child.get(f"{ANDROID_NS}scaleY", "1")
                out.append(f'{indent}<g transform="translate({tx},{ty}) scale({sx},{sy})">')
                out.append(emit(child, indent + "  "))
                out.append(f"{indent}</g>")
            else:
                raise SystemExit(f"!! {path.name}: unsupported element <{tag}>")
        return "\n".join(out)

    return emit(root), vw, vh


def render_icon(out_dir: Path) -> None:
    """The launcher icon: navy field, artwork in the adaptive safe zone."""
    body, vw, vh = vector_to_svg_body(RES / "drawable/ic_launcher_foreground.xml")
    bg = read_colour("ic_launcher_background", NAVY)

    # Android masks adaptive icons; a squircle is the common shape. The full
    # square is drawn too so the safe-zone margin is visible.
    svg = f"""<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 {vw} {vh}">
  <defs>
    <clipPath id="squircle">
      <rect x="0" y="0" width="{vw}" height="{vh}" rx="24" ry="24"/>
    </clipPath>
  </defs>
  <g clip-path="url(#squircle)">
    <rect width="{vw}" height="{vh}" fill="{bg}"/>
{body}
  </g>
</svg>"""
    target = out_dir / "launcher-icon.png"
    cairosvg.svg2png(bytestring=svg.encode(), write_to=str(target), output_width=512, output_height=512)
    print(f"  wrote {target.relative_to(ROOT)}  (512x512, masked as a squircle)")


def render_splash(out_dir: Path) -> None:
    """
    The launch screen, laid out like activity_splash.xml on a 1080x2280 phone.

    This composites the real shipped WebP, not a redraw, so what comes out is what
    the phone shows apart from the system font in the slow-network hint.
    """
    navy = read_colour("lrms_brand_navy", NAVY)
    gold = read_colour("lrms_brand_gold", GOLD)

    lockup_path = RES / "drawable-nodpi/brand_lockup.webp"
    if not lockup_path.is_file():
        raise SystemExit(f"!! missing {lockup_path} - run tools/prepare-brand-assets.py")

    # dp -> px at 3x (a 1080x2280 phone is xxhdpi).
    s = 3
    w, h = 360 * s, 760 * s

    canvas = Image.new("RGB", (w, h), navy)
    lockup = Image.open(lockup_path).convert("RGB")

    # match_parent minus a 26dp inset each side, capped at 420dp, centred.
    target_w = min(w - 2 * 26 * s, 420 * s)
    target_h = round(lockup.size[1] * target_w / lockup.size[0])
    plate = lockup.resize((target_w, target_h), Image.LANCZOS)

    # Rounded corners, matching ShapeAppearance.LRMS.BrandLarge (22dp).
    mask = Image.new("L", (target_w, target_h), 0)
    ImageDraw.Draw(mask).rounded_rectangle(
        [0, 0, target_w - 1, target_h - 1], radius=22 * s, fill=255
    )
    canvas.paste(plate, ((w - target_w) // 2, (h - target_h) // 2), mask)

    # The gold progress ring near the bottom.
    draw = ImageDraw.Draw(canvas)
    cx, cy, r = w // 2, h - 48 * s - 13 * s, 13 * s
    draw.arc([cx - r, cy - r, cx + r, cy + r], 0, 360, fill="#33FFFFFF"[:7], width=3 * s)
    draw.arc([cx - r, cy - r, cx + r, cy + r], -90, 20, fill=gold, width=3 * s)

    target = out_dir / "splash-screen.png"
    canvas.resize((540, 1140), Image.LANCZOS).save(target)
    print(f"  wrote {target.relative_to(ROOT)}  (540x1140, laid out like activity_splash.xml)")


def main() -> None:
    out_dir = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "docs/previews"
    out_dir.mkdir(parents=True, exist_ok=True)
    print("==> rendering brand previews from the real vector drawables")
    render_icon(out_dir)
    render_splash(out_dir)
    print("\nThese show the artwork, not a device screenshot: system fonts and the")
    print("exact splash icon scale are decided by the phone.")


if __name__ == "__main__":
    main()
