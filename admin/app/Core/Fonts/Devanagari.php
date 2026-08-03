<?php

declare(strict_types=1);

namespace App\Core\Fonts;

/**
 * Devanagari script classification and the one reordering rule this writer
 * implements: moving VOWEL SIGN I (U+093F, "ि") before the consonant cluster
 * it attaches to.
 *
 * WHAT THIS DOES NOT DO
 *
 * Real Devanagari rendering is shaped by an engine such as HarfBuzz: a
 * consonant cluster joined by a virama (क् + ष -> क्ष) forms a single
 * ligature glyph pulled from the font's GSUB table, and a few other vowel
 * signs and the "reph" (a leading र् that moves above a following syllable)
 * also reorder. None of that GSUB/GPOS processing is implemented here - this
 * writer has no font-shaping engine and does not take on one as a dependency
 * (see the class doc on `Pdf`: "no FPDF, no Composer").
 *
 * What it draws instead, for a conjunct like क्ष, is three separate glyphs in
 * logical order - KA, VIRAMA, SSA - each shown at full size rather than
 * combined into the compact ligature a properly shaped renderer would show.
 * This is legible (a Hindi reader reads क् ष as two full letters joined by a
 * visible halant stroke, which is how Devanagari printed without an OpenType
 * shaping engine has always looked) but is not the typography a native
 * Devanagari font is capable of.
 *
 * The one rule implemented - moving the "ि" matra before its consonant - is
 * applied because leaving it in logical (encoded) order is not merely
 * unpolished, it is wrong: "ि" is one of the commonest vowel signs in Hindi,
 * Unicode always encodes it after the consonant it sounds with, and every
 * rendering of it puts the mark before that consonant. Skipping this one
 * deterministic, well-documented reordering would print words with their
 * vowel sign visually attached to the wrong letter.
 */
final class Devanagari
{
    private const BLOCK_START = 0x0900;
    private const BLOCK_END = 0x097F;
    public const ZWNJ = 0x200C;
    public const ZWJ = 0x200D;

    private const VIRAMA = 0x094D;
    private const NUKTA = 0x093C;
    private const VOWEL_SIGN_I = 0x093F;

    public static function isDevanagariCodepoint(int $codepoint): bool
    {
        return ($codepoint >= self::BLOCK_START && $codepoint <= self::BLOCK_END)
            || $codepoint === self::ZWNJ
            || $codepoint === self::ZWJ;
    }

    /** True when the string carries at least one Devanagari-block or ZWJ/ZWNJ character. */
    public static function containsDevanagari(string $utf8): bool
    {
        foreach (self::codepoints($utf8) as $codepoint) {
            if (self::isDevanagariCodepoint($codepoint)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<int> Unicode codepoints, in order. */
    public static function codepoints(string $utf8): array
    {
        if ($utf8 === '') {
            return [];
        }
        $chars = preg_split('//u', $utf8, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return [];
        }
        $points = [];
        foreach ($chars as $char) {
            $ord = mb_ord($char, 'UTF-8');
            if ($ord !== false) {
                $points[] = $ord;
            }
        }
        return $points;
    }

    /** Rebuilds a UTF-8 string from codepoints. */
    public static function fromCodepoints(array $codepoints): string
    {
        $out = '';
        foreach ($codepoints as $codepoint) {
            $char = mb_chr($codepoint, 'UTF-8');
            if ($char !== false) {
                $out .= $char;
            }
        }
        return $out;
    }

    /**
     * Devanagari consonants: the block a consonant cluster is built from
     * (क-ह), the nukta-formed consonants used for borrowed sounds (क़-य़),
     * and the extension block's two additional consonants used in a few
     * place names and personal names.
     */
    public static function isConsonant(int $codepoint): bool
    {
        return ($codepoint >= 0x0915 && $codepoint <= 0x0939)
            || ($codepoint >= 0x0958 && $codepoint <= 0x095F)
            || ($codepoint >= 0x0978 && $codepoint <= 0x097F);
    }

    /**
     * Moves each VOWEL SIGN I to just before the consonant cluster - one
     * consonant, or several joined by a visible virama - that it sounds with.
     *
     * @param list<int> $codepoints
     * @return list<int>
     */
    public static function reorderMatraI(array $codepoints): array
    {
        $output = [];
        $count = count($codepoints);
        $i = 0;

        while ($i < $count) {
            if (!self::isConsonant($codepoints[$i])) {
                $output[] = $codepoints[$i];
                $i++;
                continue;
            }

            $clusterStart = $i;
            $j = $i + 1;

            // Extend across a nukta, and across virama + another consonant - a
            // conjunct such as क्ष is one cluster for this purpose, because a
            // following "ि" belongs to the whole cluster's sound, not just to
            // its last consonant.
            while (true) {
                if ($j < $count && $codepoints[$j] === self::NUKTA) {
                    $j++;
                }
                if ($j < $count && $codepoints[$j] === self::VIRAMA
                    && $j + 1 < $count && self::isConsonant($codepoints[$j + 1])
                ) {
                    $j += 2;
                    continue;
                }
                break;
            }

            $clusterEnd = $j;

            if ($clusterEnd < $count && $codepoints[$clusterEnd] === self::VOWEL_SIGN_I) {
                $output[] = self::VOWEL_SIGN_I;
                for ($k = $clusterStart; $k < $clusterEnd; $k++) {
                    $output[] = $codepoints[$k];
                }
                $i = $clusterEnd + 1;
                continue;
            }

            for ($k = $clusterStart; $k < $clusterEnd; $k++) {
                $output[] = $codepoints[$k];
            }
            $i = $clusterEnd;
        }

        return $output;
    }
}
