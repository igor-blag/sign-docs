#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Extract gettext strings from Sign Docs PHP/JS sources and write the
languages/sign-docs.pot template.

Usage:
    python3 tools/i18n/extract_pot.py

Requires Python 3.8+. No third-party packages.
"""
import io
import os
import re
import glob
import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
LANGUAGES = os.path.join(ROOT, 'languages')

PHP_FUNCTIONS = ['__', '_e', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', '_n', '_nx']
JS_FUNCTIONS = ['__', '_n']

CALL = re.compile(r"\b(%s)\s*\(" % '|'.join(PHP_FUNCTIONS + JS_FUNCTIONS))


def split_args(s):
    args, depth, cur, quote = [], 0, [], None
    i = 0
    while i < len(s):
        ch = s[i]
        if quote:
            cur.append(ch)
            if ch == '\\' and i + 1 < len(s):
                cur.append(s[i + 1])
                i += 2
                continue
            if ch == quote:
                quote = None
            i += 1
            continue
        if ch in '"\'':
            quote = ch
            cur.append(ch)
        elif ch in '([{':
            depth += 1
            cur.append(ch)
        elif ch in ')]}':
            depth -= 1
            cur.append(ch)
        elif ch == ',' and depth == 0:
            args.append(''.join(cur))
            cur = []
        else:
            cur.append(ch)
        i += 1
    if ''.join(cur).strip():
        args.append(''.join(cur))
    return [a.strip() for a in args]


def unquote(a):
    if len(a) >= 2 and a[0] == "'" and a[-1] == "'":
        return a[1:-1].replace("\\'", "'").replace('\\\\', '\\')
    if len(a) >= 2 and a[0] == '"' and a[-1] == '"':
        s = a[1:-1]
        s = s.replace('\\"', '"').replace('\\$', '$').replace('\\\\', '\\')
        s = s.replace('\\n', '\n').replace('\\t', '\t').replace('\\r', '\r')
        return s
    return None


def scan_file(path):
    with io.open(path, encoding='utf-8') as fh:
        text = fh.read()
    rel = os.path.relpath(path, ROOT).replace('\\', '/')
    out = []
    pos = 0
    while True:
        m = CALL.search(text, pos)
        if not m:
            break
        fn = m.group(1)
        start = m.end()
        i = start
        depth, quote = 1, None
        while i < len(text):
            c = text[i]
            if quote:
                if c == '\\':
                    i += 2
                    continue
                if c == quote:
                    quote = None
                i += 1
                continue
            if c in '"\'':
                quote = c
            elif c == '(':
                depth += 1
            elif c == ')':
                depth -= 1
                if depth == 0:
                    break
            i += 1
        args = split_args(text[start:i])
        plural = fn in ('_n', '_nx')
        if plural:
            has_domain = len(args) >= 4 and unquote(args[3]) == 'sign-docs'
            target = 0 if has_domain else -1
        else:
            has_domain = len(args) >= 2 and unquote(args[1]) == 'sign-docs'
            target = 0
        if has_domain and target >= 0 and args and args[0][:1] in ('"', "'"):
            singular = unquote(args[0])
            plural_msg = unquote(args[1]) if (plural and len(args) >= 2 and args[1][:1] in ('"', "'")) else None
            lineno = text.count('\n', 0, m.start()) + 1
            out.append((singular, plural_msg, rel, lineno))
        pos = i + 1
    return out


def po_escape(value):
    value = value.replace('\\', '\\\\')
    value = value.replace('"', '\\"')
    value = value.replace('\n', '\\n')
    value = value.replace('\r', '\\r')
    value = value.replace('\t', '\\t')
    return value


def main():
    paths = sorted(glob.glob(os.path.join(ROOT, 'includes', '*.php')))
    paths.append(os.path.join(ROOT, 'sign-docs.php'))
    paths.extend(sorted(glob.glob(os.path.join(ROOT, 'assets', 'js', '*.js'))))

    entries = {}
    order = []
    for path in paths:
        for singular, plural_msg, rel, lineno in scan_file(path):
            key = singular
            if key not in entries:
                entries[key] = {'plural': None, 'refs': []}
                order.append(key)
            entries[key]['plural'] = plural_msg
            ref = '%s:%d' % (rel, lineno)
            if ref not in entries[key]['refs']:
                entries[key]['refs'].append(ref)

    pot = []
    pot.append('msgid ""')
    pot.append('msgstr ""')
    pot.append('"Project-Id-Version: Sign Docs %s\\n"' % _plugin_version())
    pot.append('"Report-Msgid-Bugs-To: https://github.com/igor-blag/sign-docs/issues\\n"')
    pot.append('"POT-Creation-Date: %s\\n"' % datetime.datetime.now().strftime('%Y-%m-%d %H:%M%z'))
    pot.append('"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"')
    pot.append('"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"')
    pot.append('"Language-Team: LANGUAGE <LL@li.org>\\n"')
    pot.append('"MIME-Version: 1.0\\n"')
    pot.append('"Content-Type: text/plain; charset=UTF-8\\n"')
    pot.append('"Content-Transfer-Encoding: 8bit\\n"')
    pot.append('"Plural-Forms: nplurals=2; plural=(n != 1);\\n"')
    pot.append('')

    for key in order:
        entry = entries[key]
        for ref in entry['refs']:
            pot.append('#: %s' % ref)
        pot.append('msgid "%s"' % po_escape(key))
        if entry['plural'] is not None:
            pot.append('msgid_plural "%s"' % po_escape(entry['plural']))
            pot.append('msgstr[0] ""')
            pot.append('msgstr[1] ""')
        else:
            pot.append('msgstr ""')
        pot.append('')

    os.makedirs(LANGUAGES, exist_ok=True)
    pot_path = os.path.join(LANGUAGES, 'sign-docs.pot')
    with io.open(pot_path, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write('\n'.join(pot))
    print('Wrote %s (%d msgids)' % (pot_path, len(order)))


def _plugin_version():
    main = io.open(os.path.join(ROOT, 'sign-docs.php'), encoding='utf-8').read()
    m = re.search(r"Version:\s*([0-9][0-9.]*)", main)
    return m.group(1) if m else '0.0.0'


if __name__ == '__main__':
    main()
