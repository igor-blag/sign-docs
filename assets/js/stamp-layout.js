(function (global) {
    'use strict';

    var ROW_KEYS = ['header', 'meta', 'signer', 'org'];
    var DEFAULT_ROWS = ['header', 'meta', 'signer', 'org'];
    var ROW_MAX_LINES = { header: 2, meta: 2, signer: 2, org: 3 };
    var HEADER_TEXT = 'ДОКУМЕНТ ПОДПИСАН ПРОСТОЙ ЭЛЕКТРОННОЙ ПОДПИСЬЮ';

    function clampNumber(value, min, max, fallback) {
        if (typeof value !== 'number' || Number.isNaN(value)) {
            return fallback;
        }

        return Math.min(max, Math.max(min, value));
    }

    function parseFloatOr(value, fallback) {
        var number = parseFloat(String(value || ''));
        return Number.isNaN(number) ? fallback : number;
    }

    function fontSizeOf(data) {
        return clampNumber(parseFloatOr(data.stamp_font_size, 8.4), 6, 12, 8.4);
    }

    function paddingOf(data) {
        return clampNumber(parseFloatOr(data.stamp_padding, 5), 2, 16, 5);
    }

    function qrGapOf(data) {
        return clampNumber(parseFloatOr(data.stamp_qr_gap, 5), 0, 20, 5);
    }

    function qrMarginOf(data) {
        return clampNumber(parseFloatOr(data.stamp_qr_padding, 5), 0, 12, 5);
    }

    function lineSpacingOf(data) {
        return clampNumber(parseFloatOr(data.stamp_line_spacing, 1.25), 1, 2, 1.25);
    }

    function flagOn(data, key, fallback) {
        var value = data[key];

        if (value === undefined || value === null) {
            return fallback;
        }

        return !(value === '0' || value === false);
    }

    function parseRowKeys(value) {
        var items = Array.isArray(value) ? value : String(value || '').split(',');
        var seen = {};
        var result = [];

        items.forEach(function (item) {
            var key = String(item || '').trim();
            if (ROW_KEYS.indexOf(key) < 0 || seen[key]) {
                return;
            }
            seen[key] = true;
            result.push(key);
        });

        return result;
    }

    function enabledRows(data) {
        var rows = parseRowKeys(data.stamp_rows);

        return rows.length ? rows : DEFAULT_ROWS.slice();
    }

    function compactSignedAt(value) {
        var source = String(value || '').trim();
        if (!source) {
            return '';
        }

        var date = new Date(source.replace(' ', 'T'));
        if (isNaN(date.getTime())) {
            return source.replace(/(\d{1,2}:\d{2}):\d{2}(?=\s|$)/, '$1');
        }

        var day = String(date.getDate()).padStart(2, '0');
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var year = date.getFullYear();
        var hours = String(date.getHours()).padStart(2, '0');
        var minutes = String(date.getMinutes()).padStart(2, '0');
        var seconds = String(date.getSeconds()).padStart(2, '0');

        return day + '.' + month + '.' + year + ' ' + hours + ':' + minutes + ':' + seconds;
    }

    function shortHash(hash) {
        var source = String(hash || '');

        return source.length > 8 ? source.slice(0, 4) + '...' + source.slice(-4) : source;
    }

    function composeContent(data) {
        var dateText = compactSignedAt(data.local_signed_at || data.signed_at || '');
        var name = String(data.signer_name || data.signer || '').trim();
        var position = String(data.signer_position || '').trim();

        return {
            header: HEADER_TEXT,
            meta: dateText
                + ' (UTC)  |  ID: ' + String(data.post_id || '')
                + '  |  SHA-256: ' + shortHash(data.sha256_hash),
            signer: position && name ? position + ': ' + name : (position || name),
            org: String(data.organization || data.signer_organization || '').trim()
        };
    }

    function wrapLine(text, measure, fontSize, maxWidth, maxLines) {
        var source = String(text || '').replace(/\s+/g, ' ').trim();
        var words = source ? source.split(' ') : [];
        var lines = [];
        var line = '';

        words.forEach(function (word) {
            var next = line ? line + ' ' + word : word;

            if (measure(next, fontSize) <= maxWidth) {
                line = next;
                return;
            }

            if (line) {
                lines.push(line);
            }

            line = word;
        });

        if (line) {
            lines.push(line);
        }

        if (lines.length > maxLines) {
            var limited = lines.slice(0, maxLines);
            var last = limited[limited.length - 1] || '';

            while (last.length > 1 && measure(last + '...', fontSize) > maxWidth) {
                last = last.slice(0, -1).trim();
            }

            limited[limited.length - 1] = last + '...';

            return limited;
        }

        return lines;
    }

    function compute(data, measure, maxWidthUnit) {
        var fontSize = fontSizeOf(data);
        var lineHeight = fontSize * lineSpacingOf(data);
        var textPad = paddingOf(data);
        var gap = qrGapOf(data);
        var qrMargin = qrMarginOf(data);
        var qrSize = 54;
        var qrEnabled = flagOn(data, 'stamp_qr_enabled', true);
        var qrBelow = qrEnabled && String(data.stamp_qr_position || 'right') === 'below';
        var content = composeContent(data);
        var rows = enabledRows(data).filter(function (key) {
            return content[key];
        });
        var maxWidth = Math.max(80, Number(maxWidthUnit) || 0);
        var natural = function (key) {
            return measure(content[key], fontSize);
        };
        var lines = [];

        if (!rows.length && !qrEnabled) {
            return null;
        }

        var width;
        var height;
        var qr = null;

        if (qrEnabled && !qrBelow) {
            var candidates = rows.map(function (key, index) {
                return natural(key) + (index === 0 ? qrSize + qrMargin + gap + textPad : textPad * 2);
            });
            candidates.push(qrSize + qrMargin * 2);
            width = Math.min(maxWidth, Math.max.apply(null, candidates));
            var textWidth = Math.max(8, width - qrSize - qrMargin - gap - textPad);
            var fullWidth = Math.max(8, width - textPad * 2);

            rows.forEach(function (key) {
                var wrapWidth = key === 'org' ? fullWidth : textWidth;
                wrapLine(content[key], measure, fontSize, wrapWidth, ROW_MAX_LINES[key]).forEach(function (line) {
                    lines.push(line);
                });
            });

            var count = lines.length;
            height = Math.max(textPad * 2 + count * lineHeight, qrMargin * 2 + qrSize);
            qr = {
                left: width - qrMargin - qrSize,
                top: qrMargin,
                size: qrSize
            };

            var overlapLimit = Math.max(0, Math.ceil((qrMargin - textPad + qrSize - 0.2 * fontSize) / lineHeight));

            lines = lines.map(function (text, index) {
                return {
                    text: text,
                    y: textPad + fontSize + index * lineHeight,
                    drawWidth: index < overlapLimit ? textWidth : fullWidth
                };
            });
        } else {
            var sizeCandidates = rows.map(function (key) {
                return natural(key) + textPad * 2;
            });
            var minimum = qrEnabled ? qrSize + qrMargin * 2 : 24;
            sizeCandidates.push(minimum);
            width = Math.min(maxWidth, Math.max.apply(null, sizeCandidates));
            var contentWidth = Math.max(8, width - textPad * 2);

            rows.forEach(function (key) {
                wrapLine(content[key], measure, fontSize, contentWidth, ROW_MAX_LINES[key]).forEach(function (line) {
                    lines.push(line);
                });
            });

            if (qrEnabled) {
                var textSpan = lines.length * lineHeight;
                height = lines.length ? textPad + textSpan + gap + qrSize + qrMargin : qrMargin * 2 + qrSize;
                qr = {
                    left: (width - qrSize) / 2,
                    top: height - qrMargin - qrSize,
                    size: qrSize
                };
            } else {
                height = textPad * 2 + lines.length * lineHeight;
            }

            lines = lines.map(function (text, index) {
                return {
                    text: text,
                    y: textPad + fontSize + index * lineHeight,
                    drawWidth: contentWidth
                };
            });
        }

        return {
            width: width,
            height: height,
            fontSize: fontSize,
            lineHeight: lineHeight,
            pad: textPad,
            textX: textPad,
            lines: lines,
            qr: qr,
            hasBorder: flagOn(data, 'stamp_border_enabled', true)
        };
    }

    global.SignDocsStampLayout = {
        ROW_KEYS: ROW_KEYS,
        DEFAULT_ROWS: DEFAULT_ROWS,
        enabledRows: enabledRows,
        composeContent: composeContent,
        compute: compute,
        compactSignedAt: compactSignedAt
    };
})(window);
