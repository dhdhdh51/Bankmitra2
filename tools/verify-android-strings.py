#!/usr/bin/env python3
"""
Checks the app's translations against the default strings, and fails the build if they
disagree in a way that would crash on a phone.

    python3 tools/verify-android-strings.py

WHY THIS EXISTS RATHER THAN TRUSTING LINT

A translation is not prose, it is code with words in it. `getString(R.string.x, a, b)`
formats the string for the CURRENT locale, so a Hindi value that drops a `%1$s`, or swaps
`%1$d` for `%d`, or gains an argument the caller does not pass, throws
IllegalFormatException the moment that screen opens - in Hindi only, on somebody else's
phone, in a village, with no stack trace coming back to anyone. It cannot be caught by
opening the app in English, which is how it would be tested.

Android lint has StringFormatMatches and ExtraTranslation, and they are worth having, but
lint on this project runs as part of a release assemble and its findings are warnings by
default. This is a hard gate, it runs in a second, and it says exactly which key is wrong.

WHAT IT PROVES

  1. Every translatable default string has a translation in every shipped locale. A
     partly-translated app is worse than an untranslated one: the agent cannot tell
     whether a screen is in their language until they are halfway down it.

  2. No translation exists for a key the default file does not have. Those are dead
     weight that looks like work and can never be displayed.

  3. Format specifiers match the default exactly, as a sorted multiset, including their
     positional indices. This is the one that prevents the crash.

  4. Nothing translatable="false" has been translated anyway - if a brand name or a
     currency symbol has been rendered into Devanagari, one of the two decisions is wrong
     and somebody should say which.

  5. Every locale carries the same count, printed, so a translation quietly dropped in a
     merge shows up as a number that moved.
"""

from __future__ import annotations

import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RES = ROOT / 'android' / 'app' / 'src' / 'main' / 'res'
DEFAULT = RES / 'values' / 'strings.xml'

# Positional (%1$s) and plain (%s) specifiers. `%%` is a literal percent and is not one.
SPECIFIER = re.compile(r'%(?!%)\d*\$?[a-zA-Z]')

passed = 0
failed = 0


def ok(label: str) -> None:
    global passed
    passed += 1
    print(f'  PASS  {label}')


def bad(label: str, detail: str = '') -> None:
    global failed
    failed += 1
    print(f'  FAIL  {label}' + (f' -> {detail}' if detail else ''))


def read(path: Path) -> tuple[dict[str, str], set[str]]:
    """Returns (name -> value, names marked translatable="false")."""
    root = ET.parse(path).getroot()
    values: dict[str, str] = {}
    fixed: set[str] = set()

    for el in root.findall('string'):
        name = el.get('name')
        if name is None:
            continue
        # itertext() so a value carrying <b> or <xliff:g> is read whole rather than
        # truncated at the first child - which would hide a specifier inside it.
        values[name] = ''.join(el.itertext())
        if el.get('translatable') == 'false':
            fixed.add(name)

    return values, fixed


def specifiers(text: str) -> list[str]:
    return sorted(SPECIFIER.findall(text))


def main() -> int:
    if not DEFAULT.is_file():
        print(f'!! no default strings file at {DEFAULT}')
        return 1

    base, untranslatable = read(DEFAULT)
    translatable = {k: v for k, v in base.items() if k not in untranslatable}

    print(f'==> default locale: {len(base)} strings '
          f'({len(translatable)} translatable, {len(untranslatable)} fixed)')
    ok(f'the default locale has strings ({len(base)})')

    locales = sorted(
        p for p in RES.glob('values-*/strings.xml')
        # values-night and friends are configuration qualifiers, not languages.
        if re.fullmatch(r'values-[a-z]{2}(-r[A-Z]{2})?', p.parent.name)
    )

    if not locales:
        bad('at least one translation ships', 'found no values-<lang>/strings.xml')
        return 1

    ok(f'translations ship for {len(locales)} locale(s): '
       + ', '.join(p.parent.name.split('-', 1)[1] for p in locales))

    for path in locales:
        code = path.parent.name.split('-', 1)[1]
        try:
            values, _ = read(path)
        except ET.ParseError as exc:
            bad(f'[{code}] the file is valid XML', str(exc))
            continue

        ok(f'[{code}] the file is valid XML ({len(values)} strings)')

        missing = sorted(set(translatable) - set(values))
        # A partly-translated app is worse than one that is not translated at all.
        if missing:
            bad(f'[{code}] every translatable string is translated',
                f'{len(missing)} missing, e.g. ' + ', '.join(missing[:8]))
        else:
            ok(f'[{code}] every translatable string is translated')

        orphans = sorted(set(values) - set(base))
        if orphans:
            bad(f'[{code}] no translation for a string that does not exist',
                ', '.join(orphans[:8]))
        else:
            ok(f'[{code}] no translation for a string that does not exist')

        translated_fixed = sorted(set(values) & untranslatable)
        if translated_fixed:
            bad(f'[{code}] nothing marked translatable="false" has been translated',
                ', '.join(translated_fixed[:8]))
        else:
            ok(f'[{code}] nothing marked translatable="false" has been translated')

        # THE ONE THAT PREVENTS A CRASH.
        drift = []
        for key in sorted(set(translatable) & set(values)):
            want = specifiers(translatable[key])
            got = specifiers(values[key])
            if want != got:
                drift.append(f'{key}: default {want} vs {code} {got}')

        if drift:
            bad(f'[{code}] every format specifier matches the default exactly',
                '; '.join(drift[:4]))
        else:
            formatted = sum(1 for k in translatable if specifiers(translatable[k]))
            ok(f'[{code}] every format specifier matches the default exactly '
               f'({formatted} formatted string(s))')

        # A translation that is character-for-character the English is usually a key
        # somebody pasted and never came back to. Not a failure - an acronym, a brand or a
        # scheme name legitimately stays - but worth counting out loud.
        identical = [
            k for k in sorted(set(translatable) & set(values))
            if values[k] == translatable[k]
        ]
        print(f'        {len(identical)} string(s) are identical to the default '
              f'(acronyms and names, mostly)')

    print()
    print('=' * 60)
    print(f'  ANDROID STRINGS: {passed} passed, {failed} failed')
    print('=' * 60)

    if failed:
        return 1

    print()
    print('ANDROID STRINGS OK')
    return 0


if __name__ == '__main__':
    sys.exit(main())
