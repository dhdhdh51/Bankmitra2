#!/usr/bin/env python3
"""
Measures whether the adaptive-icon artwork survives a circular mask.

An adaptive icon is drawn on a 108dp canvas, but launchers crop it: the largest
shape guaranteed to be fully visible is a 66dp-diameter circle in the middle, i.e.
radius 33 around (54, 54). Anything outside that gets cut on the launchers that
use a circle - which is most of them.

This flattens the vector's paths (applying <group> transforms), samples the points
and reports the ones that fall outside. Rendering alone does not catch it, because
the preview mask is a squircle and hides the problem.

    python3 tools/check-icon-safezone.py [--radius 33] [file ...]
"""

from __future__ import annotations

import math
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RES = ROOT / "android/app/src/main/res"
NS = "{http://schemas.android.com/apk/res/android}"

CENTRE = 54.0
NUMBER = re.compile(r"[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?")


def points(d: str) -> list[tuple[float, float]]:
    """
    Every on-curve point and control point of an SVG path, in absolute coords.

    Control points are included on purpose: a Bezier bulges towards them, so a
    control point well outside the circle means the curve is close to the edge.
    """
    out: list[tuple[float, float]] = []
    x = y = 0.0
    start = (0.0, 0.0)
    i = 0
    cmd = ""
    tokens = re.findall(r"[A-Za-z]|[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?", d)

    def nxt() -> float:
        nonlocal i
        v = float(tokens[i])
        i += 1
        return v

    while i < len(tokens):
        t = tokens[i]
        if re.match(r"[A-Za-z]", t):
            cmd = t
            i += 1
            if cmd in "Zz":
                x, y = start
                continue
        # Implicit repeat of the previous command.
        rel = cmd.islower()
        c = cmd.upper()

        if c == "M":
            dx, dy = nxt(), nxt()
            x, y = (x + dx, y + dy) if rel else (dx, dy)
            start = (x, y)
            out.append((x, y))
        elif c == "L":
            dx, dy = nxt(), nxt()
            x, y = (x + dx, y + dy) if rel else (dx, dy)
            out.append((x, y))
        elif c == "H":
            dx = nxt()
            x = x + dx if rel else dx
            out.append((x, y))
        elif c == "V":
            dy = nxt()
            y = y + dy if rel else dy
            out.append((x, y))
        elif c == "C":
            vals = [nxt() for _ in range(6)]
            if rel:
                pts = [(x + vals[0], y + vals[1]), (x + vals[2], y + vals[3]), (x + vals[4], y + vals[5])]
            else:
                pts = [(vals[0], vals[1]), (vals[2], vals[3]), (vals[4], vals[5])]
            out.extend(pts)
            x, y = pts[2]
        elif c in "SQ":
            vals = [nxt() for _ in range(4)]
            if rel:
                pts = [(x + vals[0], y + vals[1]), (x + vals[2], y + vals[3])]
            else:
                pts = [(vals[0], vals[1]), (vals[2], vals[3])]
            out.extend(pts)
            x, y = pts[1]
        elif c == "T":
            vals = [nxt() for _ in range(2)]
            x, y = (x + vals[0], y + vals[1]) if rel else (vals[0], vals[1])
            out.append((x, y))
        elif c == "A":
            vals = [nxt() for _ in range(7)]
            x, y = (x + vals[5], y + vals[6]) if rel else (vals[5], vals[6])
            out.append((x, y))
        else:
            raise SystemExit(f"!! unsupported path command '{cmd}'")
    return out


def walk(node, tx: float, ty: float, sx: float, sy: float, acc: list) -> None:
    for child in node:
        if child.tag == "path":
            d = child.get(f"{NS}pathData")
            for px, py in points(d):
                acc.append((px * sx + tx, py * sy + ty))
        elif child.tag == "group":
            gtx = float(child.get(f"{NS}translateX", 0))
            gty = float(child.get(f"{NS}translateY", 0))
            gsx = float(child.get(f"{NS}scaleX", 1))
            gsy = float(child.get(f"{NS}scaleY", 1))
            walk(child, tx + gtx * sx, ty + gty * sy, sx * gsx, sy * gsy, acc)


def check(path: Path, radius: float) -> int:
    root = ET.parse(path).getroot()
    vw = float(root.get(f"{NS}viewportWidth"))
    acc: list[tuple[float, float]] = []
    walk(root, 0.0, 0.0, 1.0, 1.0, acc)

    scale = 108.0 / vw  # normalise to the 108dp adaptive canvas
    outside = []
    xs = [p[0] * scale for p in acc]
    ys = [p[1] * scale for p in acc]
    for px, py in acc:
        cx, cy = px * scale, py * scale
        r = math.hypot(cx - CENTRE, cy - CENTRE)
        if r > radius:
            outside.append((round(cx, 1), round(cy, 1), round(r, 1)))

    try:
        label = path.resolve().relative_to(ROOT)
    except ValueError:
        label = path
    print(f"\n{label}")
    print(f"  bounding box   x {min(xs):.1f}..{max(xs):.1f}   y {min(ys):.1f}..{max(ys):.1f}")
    worst = max((math.hypot(p[0] * scale - CENTRE, p[1] * scale - CENTRE) for p in acc), default=0)
    print(f"  furthest point {worst:.1f} from centre  (limit {radius})")

    if outside:
        print(f"  !! {len(outside)} point(s) outside the {radius * 2:.0f}dp safe circle:")
        for cx, cy, r in sorted(outside, key=lambda p: -p[2])[:8]:
            print(f"       ({cx}, {cy})  r={r}")
        return 1
    print("  ok  every point survives a circular mask")
    return 0


def main() -> None:
    args = sys.argv[1:]
    radius = 33.0
    if "--radius" in args:
        idx = args.index("--radius")
        radius = float(args[idx + 1])
        del args[idx : idx + 2]

    files = [Path(a) for a in args] or [
        RES / "drawable/ic_launcher_foreground.xml",
        RES / "drawable/ic_launcher_monochrome.xml",
    ]
    bad = sum(check(f, radius) for f in files)
    print()
    raise SystemExit(1 if bad else 0)


if __name__ == "__main__":
    main()
