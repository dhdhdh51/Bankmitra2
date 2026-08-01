#!/usr/bin/env python3
"""
Derives the shipped brand assets from the one master artwork.

The master is docs/brand/d2-recovery-lockup.jpg - the logo as supplied. Keeping it
in the repo and generating everything from it means the app and the panel can
never drift apart, and a new master only has to be dropped in once.

Two assets come out of it:

  lockup  the full square lockup - mark, wordmark, tagline and the five badges.
          Used large: the app's launch screen and both login pages. Never use it
          small; the tagline and badge captions stop being legible.

  mark    the monogram alone, cropped out of the master. Used small: the panel
          header and the mobile login header, where the full lockup would be an
          unreadable smudge.

    python3 tools/prepare-brand-assets.py

WebP because it is a photographic image with a gradient: the same quality as JPEG
at roughly half the bytes, and supported by every browser and by Android since
API 14 - well below this project's minSdk 24.
"""

from __future__ import annotations

import pathlib
import sys

from PIL import Image

ROOT = pathlib.Path(__file__).resolve().parent.parent
MASTER = ROOT / "docs/brand/d2-recovery-lockup.jpg"

# Bounding box of the monogram inside the 1024x1024 master: the gold crescent on
# the left, the shield on the right, the D2 between them. Measured against the
# artwork - a tighter box clips the shield and the foot of the crescent, and a
# taller one catches the top of the badge row underneath.
MARK_BOX = (200, 36, 872, 528)

# The app only ever shows the lockup - its launch and login screens both have room
# for it, and its toolbar shows the screen title rather than a logo. The monogram
# is a panel asset, for the sidebar and 404 headers. Shipping it to Android too
# would be a drawable nothing references; the release build's resource shrinker
# proved the point by dropping it.
TARGETS = [
    # (destination, kind, width in px, webp quality)
    (ROOT / "android/app/src/main/res/drawable-nodpi/brand_lockup.webp", "lockup", 900, 82),
    (ROOT / "admin/assets/img/d2-lockup.webp", "lockup", 900, 82),
    (ROOT / "admin/assets/img/d2-mark.webp", "mark", 420, 88),
]


def main() -> None:
    if not MASTER.is_file():
        sys.exit(f"!! master artwork missing: {MASTER.relative_to(ROOT)}")

    master = Image.open(MASTER).convert("RGB")
    if master.size != (1024, 1024):
        sys.exit(
            f"!! the master is {master.size[0]}x{master.size[1]}; MARK_BOX was measured "
            "against 1024x1024, so re-measure it before replacing the artwork"
        )

    mark = master.crop(MARK_BOX)
    print(f"==> master {master.size[0]}x{master.size[1]}, mark crop {mark.size[0]}x{mark.size[1]}")

    for dest, kind, width, quality in TARGETS:
        source = master if kind == "lockup" else mark
        height = round(source.size[1] * width / source.size[0])
        resized = source.resize((width, height), Image.LANCZOS)

        dest.parent.mkdir(parents=True, exist_ok=True)
        resized.save(dest, "WEBP", quality=quality, method=6)
        print(
            f"  {dest.relative_to(ROOT)}  {width}x{height}  "
            f"{dest.stat().st_size / 1024:.0f} KB"
        )


if __name__ == "__main__":
    main()
