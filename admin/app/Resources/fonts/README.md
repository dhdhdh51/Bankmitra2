# NotoSansDevanagari-Regular.ttf / NotoSansDevanagari-Bold.ttf

Subsets of [Noto Sans Devanagari](https://github.com/notofonts/devanagari)
(SIL Open Font License 1.1 - see `OFL.txt` alongside this file), cut down to
the Devanagari Unicode block (U+0900-U+097F) plus ZWJ/ZWNJ/space - 136 glyphs,
about 21 KB each - which is what `App\Core\Pdf` needs to print Hindi labels
and values on the field visit report, customer data sheet and report exports.

Regenerate with `tools/build-devanagari-font-subset.py` if the set of
characters the PDF writer needs ever grows (for example, if Hindi place
names start using a character outside the base Devanagari block).

The subsetting keeps the original glyph outlines, metrics and OpenType
tables (`cmap`, `glyf`, `head`, `hhea`, `hmtx`, `loca`, `maxp`, `name`,
`post`) untouched apart from renumbering glyph IDs to the smaller set - it
does not redraw or modify a single letterform, which is what the license's
"Modified Version" bundling terms require staying compatible with.
