"""
Builds a minimal, valid TrueType font subset containing only the Devanagari
block (+ ZWJ/ZWNJ/space) glyphs needed to print Hindi text in the PDF writer,
from Noto Sans Devanagari (SIL OFL 1.1). Produces both Regular and Bold.

Output per weight: a standalone .ttf plus a JSON metrics/cmap file consumed
by the PHP-side font embedder (App\Core\Fonts\DevanagariFont).
"""
import struct
import json
import math

CODEPOINTS = list(range(0x0900, 0x097F + 1)) + [0x200C, 0x200D, 0x0020]


def u16(data, off): return struct.unpack('>H', data[off:off + 2])[0]
def s16(data, off): return struct.unpack('>h', data[off:off + 2])[0]
def u32(data, off): return struct.unpack('>I', data[off:off + 4])[0]


def parse_tables(data):
    _, num_tables, _, _, _ = struct.unpack('>IHHHH', data[0:12])
    tables = {}
    offset = 12
    for _ in range(num_tables):
        tag, checksum, toff, tlen = struct.unpack('>4sIII', data[offset:offset + 16])
        tables[tag.decode('ascii')] = (toff, tlen)
        offset += 16
    return tables


def build_subset(src_path, out_ttf_path, out_json_path, family_label, style_label):
    with open(src_path, 'rb') as f:
        data = f.read()

    tables = parse_tables(data)

    head_off, head_len = tables['head']
    units_per_em = u16(data, head_off + 18)
    index_to_loc_format = s16(data, head_off + 50)

    # FontDescriptor metrics come from head (bbox) and OS/2 (ascent/descent/capHeight).
    x_min = s16(data, head_off + 36)
    y_min = s16(data, head_off + 38)
    x_max = s16(data, head_off + 40)
    y_max = s16(data, head_off + 42)

    os2_off, _ = tables['OS/2']
    os2_version = u16(data, os2_off + 0)
    s_typo_ascender = s16(data, os2_off + 68)
    s_typo_descender = s16(data, os2_off + 70)
    s_cap_height = s16(data, os2_off + 88) if os2_version >= 2 else int(units_per_em * 0.7)

    maxp_off, maxp_len = tables['maxp']
    num_glyphs = u16(data, maxp_off + 4)
    hhea_off, _ = tables['hhea']
    num_h_metrics = u16(data, hhea_off + 34)
    hmtx_off, _ = tables['hmtx']
    loca_off, _ = tables['loca']
    glyf_off, glyf_len = tables['glyf']

    if index_to_loc_format == 0:
        loca_offsets = [u16(data, loca_off + i * 2) * 2 for i in range(num_glyphs + 1)]
    else:
        loca_offsets = [u32(data, loca_off + i * 4) for i in range(num_glyphs + 1)]

    def glyph_bytes(gid):
        start, end = loca_offsets[gid], loca_offsets[gid + 1]
        return data[glyf_off + start:glyf_off + end]

    def is_composite(gbytes):
        if len(gbytes) < 10:
            return False
        return struct.unpack('>h', gbytes[0:2])[0] < 0

    def composite_component_gids(gbytes):
        gids = []
        p = 10
        while p < len(gbytes):
            flags, glyph_index = struct.unpack('>HH', gbytes[p:p + 4])
            gids.append(glyph_index)
            p += 4
            ARG_WORDS = 0x0001
            SCALE = 0x0008
            XY_SCALE = 0x0040
            TWO_BY_TWO = 0x0080
            MORE = 0x0020
            p += 4 if flags & ARG_WORDS else 2
            if flags & SCALE:
                p += 2
            elif flags & XY_SCALE:
                p += 4
            elif flags & TWO_BY_TWO:
                p += 8
            if not (flags & MORE):
                break
        return gids

    # cmap: platform 3 encoding 1 (Windows Unicode BMP), format 4
    cmap_off, _ = tables['cmap']
    _, num_subtables = struct.unpack('>HH', data[cmap_off:cmap_off + 4])
    sub_off = None
    for i in range(num_subtables):
        rec_off = cmap_off + 4 + i * 8
        platform_id, encoding_id, subtable_offset = struct.unpack('>HHI', data[rec_off:rec_off + 8])
        if platform_id == 3 and encoding_id == 1:
            sub_off = cmap_off + subtable_offset
    assert sub_off is not None
    assert u16(data, sub_off) == 4

    seg_count = u16(data, sub_off + 6) // 2
    end_code_off = sub_off + 14
    start_code_off = end_code_off + seg_count * 2 + 2
    id_delta_off = start_code_off + seg_count * 2
    id_range_offset_off = id_delta_off + seg_count * 2

    end_codes = [u16(data, end_code_off + i * 2) for i in range(seg_count)]
    start_codes = [u16(data, start_code_off + i * 2) for i in range(seg_count)]
    id_deltas = [s16(data, id_delta_off + i * 2) for i in range(seg_count)]
    id_range_offsets = [u16(data, id_range_offset_off + i * 2) for i in range(seg_count)]

    def get_glyph_id(codepoint):
        for i in range(seg_count):
            if start_codes[i] <= codepoint <= end_codes[i]:
                if id_range_offsets[i] == 0:
                    return (codepoint + id_deltas[i]) & 0xFFFF
                addr = id_range_offset_off + i * 2 + id_range_offsets[i] + (codepoint - start_codes[i]) * 2
                gid = u16(data, addr)
                return 0 if gid == 0 else (gid + id_deltas[i]) & 0xFFFF
        return 0

    cp_to_old_gid = {}
    for cp in CODEPOINTS:
        gid = get_glyph_id(cp)
        if gid != 0:
            cp_to_old_gid[cp] = gid

    old_gids_needed = set(cp_to_old_gid.values())
    frontier = list(old_gids_needed)
    while frontier:
        gid = frontier.pop()
        gb = glyph_bytes(gid)
        if is_composite(gb):
            for comp_gid in composite_component_gids(gb):
                if comp_gid not in old_gids_needed:
                    old_gids_needed.add(comp_gid)
                    frontier.append(comp_gid)

    sorted_old_gids = sorted(old_gids_needed)
    old_to_new = {0: 0}
    for i, old_gid in enumerate(sorted_old_gids, start=1):
        old_to_new[old_gid] = i
    new_num_glyphs = len(old_to_new)
    new_to_old = {v: k for k, v in old_to_new.items()}
    cp_to_new_gid = {cp: old_to_new[gid] for cp, gid in cp_to_old_gid.items()}

    def remap_glyph_bytes(gb):
        if not is_composite(gb):
            return gb
        gb = bytearray(gb)
        p = 10
        while p < len(gb):
            flags, glyph_index = struct.unpack('>HH', gb[p:p + 4])
            struct.pack_into('>H', gb, p + 2, old_to_new.get(glyph_index, 0))
            p += 4
            ARG_WORDS = 0x0001
            SCALE = 0x0008
            XY_SCALE = 0x0040
            TWO_BY_TWO = 0x0080
            MORE = 0x0020
            p += 4 if flags & ARG_WORDS else 2
            if flags & SCALE:
                p += 2
            elif flags & XY_SCALE:
                p += 4
            elif flags & TWO_BY_TWO:
                p += 8
            if not (flags & MORE):
                break
        return bytes(gb)

    def pad4(b):
        return b + b'\x00' * ((4 - len(b) % 4) % 4)

    new_glyf_chunks = []
    new_loca = [0]
    running = 0
    for new_gid in range(new_num_glyphs):
        old_gid = new_to_old.get(new_gid, 0)
        gb = b'' if old_gid == 0 else glyph_bytes(old_gid)
        gb = remap_glyph_bytes(gb)
        gb_padded = pad4(gb)
        new_glyf_chunks.append(gb_padded)
        running += len(gb_padded)
        new_loca.append(running)
    new_glyf = b''.join(new_glyf_chunks)
    new_loca_bytes = b''.join(struct.pack('>I', off) for off in new_loca)

    def full_hmetric(old_gid):
        if old_gid < num_h_metrics:
            return struct.unpack('>Hh', data[hmtx_off + old_gid * 4: hmtx_off + old_gid * 4 + 4])
        aw = u16(data, hmtx_off + (num_h_metrics - 1) * 4)
        lsb_off = hmtx_off + num_h_metrics * 4 + (old_gid - num_h_metrics) * 2
        lsb = s16(data, lsb_off)
        return aw, lsb

    new_hmtx = b''.join(
        struct.pack('>Hh', *full_hmetric(new_to_old.get(g, 0))) for g in range(new_num_glyphs)
    )

    new_head = bytearray(data[head_off:head_off + head_len])
    struct.pack_into('>h', new_head, 50, 1)  # indexToLocFormat = long
    struct.pack_into('>I', new_head, 8, 0)  # checkSumAdjustment, fixed later

    hhea_len = tables['hhea'][1]
    new_hhea = bytearray(data[hhea_off:hhea_off + hhea_len])
    struct.pack_into('>H', new_hhea, 34, new_num_glyphs)

    new_maxp = bytearray(data[maxp_off:maxp_off + maxp_len])
    struct.pack_into('>H', new_maxp, 4, new_num_glyphs)

    def build_cmap4(mapping):
        cps = sorted(mapping.keys())
        segments = []
        i = 0
        while i < len(cps):
            start = cps[i]
            start_gid = mapping[start]
            j = i
            while j + 1 < len(cps) and cps[j + 1] == cps[j] + 1:
                j += 1
            segments.append((start, cps[j], start_gid))
            i = j + 1

        seg_count_ = len(segments) + 1
        end_codes_ = [s[1] for s in segments] + [0xFFFF]
        start_codes_ = [s[0] for s in segments] + [0xFFFF]
        id_deltas_ = [(s[2] - s[0]) & 0xFFFF for s in segments] + [1]
        id_range_offsets_ = [0] * seg_count_

        seg_count_x2 = seg_count_ * 2
        entry_selector_ = int(math.log2(seg_count_)) if seg_count_ > 0 else 0
        search_range_ = 2 * (2 ** entry_selector_)
        range_shift_ = seg_count_x2 - search_range_

        def to_signed16(v):
            v &= 0xFFFF
            return v - 0x10000 if v >= 0x8000 else v

        core = struct.pack('>HH', seg_count_x2, search_range_) + struct.pack('>HH', entry_selector_, range_shift_)
        core += b''.join(struct.pack('>H', c) for c in end_codes_)
        core += struct.pack('>H', 0)
        core += b''.join(struct.pack('>H', c) for c in start_codes_)
        core += b''.join(struct.pack('>h', to_signed16(d)) for d in id_deltas_)
        core += b''.join(struct.pack('>H', r) for r in id_range_offsets_)
        total_len = 6 + len(core)
        return struct.pack('>HHH', 4, total_len, 0) + core

    cmap_subtable = build_cmap4(cp_to_new_gid)
    cmap_table = struct.pack('>HH', 0, 1) + struct.pack('>HHI', 3, 1, 12) + cmap_subtable

    new_post = struct.pack('>IiHH', 0x00030000, 0, 0, 0) + struct.pack('>IIIII', 0, 0, 0, 0, 0)

    def build_name_table():
        strings = [
            (1, family_label), (2, style_label),
            (3, f'{family_label}-{style_label}-Subset-1.0'),
            (4, f'{family_label} {style_label}'),
            (6, f'{family_label.replace(" ", "")}-{style_label}'),
        ]
        storage = b''
        records = b''
        for name_id, s in strings:
            s_utf16 = s.encode('utf-16-be')
            records += struct.pack('>HHHHHH', 3, 1, 0x0409, name_id, len(s_utf16), len(storage))
            storage += s_utf16
        header = struct.pack('>HHH', 0, len(strings), 6 + len(strings) * 12)
        return header + records + storage

    new_name = build_name_table()

    final_tables = {
        'cmap': cmap_table, 'glyf': new_glyf, 'head': bytes(new_head),
        'hhea': bytes(new_hhea), 'hmtx': new_hmtx, 'loca': new_loca_bytes,
        'maxp': bytes(new_maxp), 'name': new_name, 'post': new_post,
    }
    tags_sorted = sorted(final_tables.keys())
    num_out_tables = len(tags_sorted)

    def calc_checksum(b):
        bp = b + b'\x00' * ((4 - len(b) % 4) % 4)
        total = 0
        for i in range(0, len(bp), 4):
            total = (total + struct.unpack('>I', bp[i:i + 4])[0]) & 0xFFFFFFFF
        return total

    entry_selector = int(math.log2(num_out_tables)) if num_out_tables > 0 else 0
    search_range = (2 ** entry_selector) * 16
    range_shift = num_out_tables * 16 - search_range
    header = struct.pack('>IHHHH', 0x00010000, num_out_tables, search_range, entry_selector, range_shift)

    dir_size = 12 + num_out_tables * 16
    running_offset = dir_size
    records = []
    blobs = []
    for tag in tags_sorted:
        blob = final_tables[tag]
        records.append((tag, calc_checksum(blob), running_offset, len(blob)))
        padded = blob + b'\x00' * ((4 - len(blob) % 4) % 4)
        blobs.append(padded)
        running_offset += len(padded)

    dir_bytes = b''.join(
        struct.pack('>4sIII', tag.encode('ascii'), cs, off, ln) for tag, cs, off, ln in records
    )
    font_bytes = bytearray(header + dir_bytes + b''.join(blobs))

    whole_checksum = calc_checksum(bytes(font_bytes))
    adjustment = (0xB1B0AFBA - whole_checksum) & 0xFFFFFFFF
    head_toff = next(off for tag, cs, off, ln in records if tag == 'head')
    struct.pack_into('>I', font_bytes, head_toff + 8, adjustment)

    with open(out_ttf_path, 'wb') as f:
        f.write(bytes(font_bytes))

    widths_by_gid = {}
    for g in range(new_num_glyphs):
        aw, _ = full_hmetric(new_to_old.get(g, 0))
        widths_by_gid[g] = aw

    out_map = {
        'unitsPerEm': units_per_em,
        'ascent': s_typo_ascender,
        'descent': s_typo_descender,
        'capHeight': s_cap_height,
        'bbox': [x_min, y_min, x_max, y_max],
        'numGlyphs': new_num_glyphs,
        'cmap': {str(cp): gid for cp, gid in cp_to_new_gid.items()},
        'widths': widths_by_gid,
    }
    with open(out_json_path, 'w') as f:
        json.dump(out_map, f)

    print(f'{out_ttf_path}: {len(font_bytes)} bytes, {new_num_glyphs} glyphs')


build_subset(
    '/projects/sandbox/noto-fonts/archive/hinted/NotoSansDevanagari/NotoSansDevanagari-Regular.ttf',
    '/projects/sandbox/fontbuild/NotoSansDevanagari-Regular.ttf',
    '/projects/sandbox/fontbuild/NotoSansDevanagari-Regular.json',
    'Noto Sans Devanagari', 'Regular',
)
build_subset(
    '/projects/sandbox/noto-fonts/archive/hinted/NotoSansDevanagari/NotoSansDevanagari-Bold.ttf',
    '/projects/sandbox/fontbuild/NotoSansDevanagari-Bold.ttf',
    '/projects/sandbox/fontbuild/NotoSansDevanagari-Bold.json',
    'Noto Sans Devanagari', 'Bold',
)
