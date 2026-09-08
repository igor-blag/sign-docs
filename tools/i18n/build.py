#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Compile languages/sign-docs-ru_RU.po into:
  - languages/sign-docs-ru_RU.mo           (binary gettext catalog)
  - languages/sign-docs-ru_RU.l10n.php     (PHP catalog, preferred by WP 6.5+)
  - languages/sign-docs-ru_RU-<md5>.json   (WP JS script translations)

The .po file is the canonical source of Russian translations. Edit the .po
directly, then re-run:

    python3 tools/i18n/build.py

Requires Python 3.8+. No third-party packages.
"""
import io
import os
import sys
import json
import hashlib
import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
LANGUAGES = os.path.join(ROOT, 'languages')
DOMAIN = 'sign-docs'
LOCALE = 'ru_RU'
PLURAL_HEADER = 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);'

# Scripts registered through wp_set_script_translations() and their plugin-relative path.
SCRIPT_FILES = (
    'assets/js/public.js',
    'assets/js/document-block.js',
    'assets/js/admin-upload.js',
    'assets/js/stamp-layout.js',
)


class Entry:
    def __init__(self):
        self.msgid = ''
        self.msgid_plural = None
        self.msgstr = []


def parse_po(path):
    text = io.open(path, encoding='utf-8').read()
    entries = []
    current = None
    field = None
    for raw in text.splitlines():
        line = raw.rstrip('\n')
        if line.startswith('msgid '):
            current = Entry()
            entries.append(current)
            field = 'msgid'
            current.msgid = _parse_po_value(line[6:])
        elif line.startswith('msgid_plural '):
            current.msgid_plural = _parse_po_value(line[13:])
            field = 'plural'
        elif line.startswith('msgstr['):
            idx = int(line[7:line.index(']')])
            while len(current.msgstr) <= idx:
                current.msgstr.append('')
            current.msgstr[idx] = _parse_po_value(line[line.index(']') + 2:])
            field = 'str'
        elif line.startswith('msgstr '):
            current.msgstr = [_parse_po_value(line[7:])]
            field = 'str'
        elif line.startswith('"'):
            part = _parse_po_value(line)
            if field == 'msgid':
                current.msgid += part
            elif field == 'plural':
                current.msgid_plural = (current.msgid_plural or '') + part
            elif field == 'str' and current.msgstr:
                current.msgstr[-1] += part
    return entries


def _parse_po_value(s):
    s = s.strip()
    if s.startswith('"') and s.endswith('"'):
        s = s[1:-1]
    s = s.replace('\\n', '\n').replace('\\t', '\t').replace('\\r', '\r')
    s = s.replace('\\"', '"').replace('\\\\', '\\')
    return s


def translations_map(entries):
    result = {}  # msgid -> value (str or list)
    header = ''
    for e in entries:
        if e.msgid == '':
            header = e.msgstr[0] if e.msgstr else ''
            continue
        if e.msgid_plural:
            result[e.msgid] = e.msgstr
        else:
            result[e.msgid] = e.msgstr[0] if e.msgstr else ''
    return result, header


def write_mo(path, pairs, header=''):
    # pairs: list of (key, value) already UTF-8 encoded.
    def to_bytes(s):
        return s.encode('utf-8')

    items = []
    if header:
        items.append((to_bytes(''), to_bytes(header)))
    for k, v in pairs:
        items.append((to_bytes(k), to_bytes(v)))

    n = len(items)
    header_size = 28
    orig_table_size = n * 8
    trans_table_size = n * 8
    blob_offset = header_size + orig_table_size + trans_table_size

    orig_offsets = []
    trans_offsets = []
    blob = bytearray()
    for key, value in items:
        orig_offsets.append((len(key), blob_offset + len(blob)))
        blob.extend(key)
        blob.append(0)  # NUL terminator between entries.
    for key, value in items:
        trans_offsets.append((len(value), blob_offset + len(blob)))
        blob.extend(value)
        blob.append(0)  # NUL terminator between entries.

    orig_table = bytearray()
    for ln, off in orig_offsets:
        orig_table += _int(ln) + _int(off)
    trans_table = bytearray()
    for ln, off in trans_offsets:
        trans_table += _int(ln) + _int(off)

    out = bytearray()
    out += bytes([0xde, 0x12, 0x04, 0x95])  # magic little endian
    out += _int(0)                           # revision
    out += _int(n)                           # number of strings
    out += _int(header_size)                 # offset original table
    out += _int(header_size + orig_table_size)  # offset translation table
    out += _int(0)                           # hash table size
    out += _int(blob_offset)                 # hash table offset must equal the start of the
                                             # string data, so WordPress' MO::import_from_reader()
                                             # computes hash_addr - translations_addr == total * 8.
    out += orig_table
    out += trans_table
    out += blob

    with open(path, 'wb') as fh:
        fh.write(bytes(out))


def _int(value):
    return value.to_bytes(4, byteorder='little', signed=False)


def php_string(value):
    """Emit a PHP double-quoted string, binary-safe (handles NUL, quotes, backslashes)."""
    out = []
    for ch in value:
        if ch == '\\':
            out.append('\\\\')
        elif ch == '"':
            out.append('\\"')
        elif ch == '$':
            out.append('\\$')
        elif ch == '\n':
            out.append('\\n')
        elif ch == '\r':
            out.append('\\r')
        elif ch == '\t':
            out.append('\\t')
        elif ch == '\0':
            out.append('\\0')
        else:
            out.append(ch)
    return '"' + ''.join(out) + '"'


def header_map(header):
    """Split the PO header block into a {Key: value} map."""
    result = {}
    for line in header.split('\n'):
        line = line.strip()
        if not line or ':' not in line:
            continue
        key, value = line.split(':', 1)
        result[key.strip()] = value.strip()
    return result


def write_php_l10n(path, pairs, header=''):
    """Write a WordPress .l10n.php translation file (preferred by WP 6.5+).

    The PHP loader (WP_Translation_File_PHP::parse_file) reads a top-level
    'messages' map of original => translation, where plural entries are keyed
    'singular\\0plural' and their value is the NUL-joined plural translations.
    Every other key is treated as a (case-insensitive) header, so the
    'Plural-Forms' header is required for correct plural selection.
    """
    lines = ['<?php', '']
    lines.append('// This file was generated by Sign Docs tools/i18n/build.py. Do not edit.')
    lines.append('// It is derived from languages/sign-docs-ru_RU.po.')
    lines.append('')

    messages = {}
    for key, value in pairs:
        messages[key] = value

    headers = header_map(header)
    headers['domain'] = DOMAIN
    headers['language'] = LOCALE
    headers['generator'] = 'Sign Docs tools/i18n/build.py'

    lines.append('return array(')
    for key, value in headers.items():
        lines.append('    %s => %s,' % (php_string(str(key)), php_string(str(value))))
    lines.append("    'messages' => array(")
    for key, value in messages.items():
        lines.append('        %s => %s,' % (php_string(key), php_string(value)))
    lines.append('    ),')
    lines.append(');')
    lines.append('')

    with io.open(path, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write('\n'.join(lines))
    print('Wrote', path, '(%d entries)' % len(messages))


def js_translation_files(translations, header):
    # Reuse the source scanner to find which msgids each script file uses.
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import importlib.util
    spec = importlib.util.spec_from_file_location('extract_pot', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'extract_pot.py'))
    ep = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(ep)

    for rel in SCRIPT_FILES:
        path = os.path.join(ROOT, rel)
        used = {}
        for singular, plural_msg, _, _ in ep.scan_file(path):
            if singular in translations and singular not in used:
                value = translations[singular]
                if not isinstance(value, list):
                    # wp.i18n expects every JS translation value to be an array
                    # (singular -> one element, plural -> N elements). A plain
                    # string value makes __() return only its first character.
                    value = [value]
                used[singular] = value
        if not used:
            continue

        locale_data = {
            '': {
                'domain': DOMAIN,
                'lang': LOCALE,
                'plural-forms': PLURAL_HEADER,
            },
        }
        for msgid, value in used.items():
            locale_data[msgid] = value

        payload = {
            'translation-revision-date': datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S+0000'),
            'generator': 'Sign Docs tools/i18n/build.py',
            'domain': DOMAIN,
            'locale_data': {DOMAIN: locale_data},
        }
        md5 = hashlib.md5(rel.encode('utf-8')).hexdigest()
        filename = '%s-%s-%s.json' % (DOMAIN, LOCALE, md5)
        with io.open(os.path.join(LANGUAGES, filename), 'w', encoding='utf-8', newline='\n') as fh:
            json.dump(payload, fh, ensure_ascii=False, indent=2)
        print('Wrote', os.path.join(LANGUAGES, filename))


def coverage_report(translations):
    """Report msgids present in sources but missing translations, and orphans."""
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import importlib.util
    spec = importlib.util.spec_from_file_location('extract_pot', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'extract_pot.py'))
    ep = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(ep)

    source = set()
    for rel in ep.glob.glob(os.path.join(ROOT, 'includes', '*.php')):
        for singular, _, _, _ in ep.scan_file(rel):
            source.add(singular)
    for rel in ep.glob.glob(os.path.join(ROOT, 'assets', 'js', '*.js')):
        for singular, _, _, _ in ep.scan_file(rel):
            source.add(singular)

    missing = sorted(k for k in source if k not in translations or translations[k] in ('', []))
    orphans = sorted(k for k in translations if k not in source and k != '')
    if missing:
        print('WARNING: %d source msgids without a Russian translation:' % len(missing))
        for k in missing:
            print('   -', repr(k))
    if orphans:
        print('NOTE: %d translations not referenced by the source anymore:' % len(orphans))
        for k in orphans:
            print('   -', repr(k))
    if not missing and not orphans:
        print('Coverage OK: all %d source msgids are translated.' % len(source))


def main():
    po_path = os.path.join(LANGUAGES, '%s-%s.po' % (DOMAIN, LOCALE))
    entries = parse_po(po_path)
    translations, header = translations_map(entries)
    coverage_report(translations)

    pairs = []
    for e in entries:
        if e.msgid == '':
            continue
        if e.msgid_plural:
            key = e.msgid + '\0' + e.msgid_plural
            value = '\0'.join(e.msgstr)
        else:
            key = e.msgid
            value = e.msgstr[0] if e.msgstr else ''
        pairs.append((key, value))

    mo_path = os.path.join(LANGUAGES, '%s-%s.mo' % (DOMAIN, LOCALE))
    write_mo(mo_path, pairs, header)
    print('Wrote', mo_path, '(%d entries)' % len(pairs))

    php_path = os.path.join(LANGUAGES, '%s-%s.l10n.php' % (DOMAIN, LOCALE))
    write_php_l10n(php_path, pairs, header)

    js_translation_files(translations, header)


if __name__ == '__main__':
    main()
