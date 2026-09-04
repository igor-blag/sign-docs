(function (global) {
    'use strict';

    var FONT_FAMILY = '"SignDocsStamp", sans-serif';
    var fontState = {
        loading: null,
        loaded: false
    };
    var iconCache = {};

    function hexToCss(hex) {
        var normalized = String(hex || '#2e7d32').trim();

        return /^#[0-9a-f]{6}$/i.test(normalized) ? normalized : '#2e7d32';
    }

    function hexToRgb(hex) {
        var normalized = String(hex || '#2e7d32').replace('#', '');
        var value = normalized.length === 3
            ? normalized.split('').map(function (item) { return item + item; }).join('')
            : normalized;

        return {
            r: parseInt(value.slice(0, 2), 16) / 255,
            g: parseInt(value.slice(2, 4), 16) / 255,
            b: parseInt(value.slice(4, 6), 16) / 255
        };
    }

    function stampOpacity(data) {
        var opacity = parseFloat(data && data.stamp_opacity !== undefined ? data.stamp_opacity : '1');
        if (isNaN(opacity)) {
            return 1;
        }

        return Math.min(1, Math.max(0.1, opacity));
    }

    function fontFamily() {
        return FONT_FAMILY;
    }

    function fontLoaded() {
        return fontState.loaded;
    }

    function loadFont(url) {
        if (fontState.loaded || typeof FontFace === 'undefined' || !url) {
            return Promise.resolve(fontState.loaded);
        }

        if (!fontState.loading) {
            fontState.loading = new FontFace('SignDocsStamp', 'url(' + url + ')')
                .load()
                .then(function (face) {
                    document.fonts.add(face);
                    fontState.loaded = true;
                })
                .then(function () {
                    return true;
                })
                .catch(function () {
                    fontState.loaded = false;
                    return false;
                });
        }

        return fontState.loading;
    }

    function loadImage(url) {
        if (!url || iconCache[url]) {
            return Promise.resolve(iconCache[url] || null);
        }

        iconCache[url] = new Promise(function (resolve) {
            var image = new Image();
            image.crossOrigin = 'anonymous';
            image.onload = function () {
                resolve(image);
            };
            image.onerror = function () {
                resolve(null);
            };
            image.src = url;
        });

        return iconCache[url];
    }

    function applyIconTexture(canvasContext, image, canvasSize) {
        var imageWidth = image.naturalWidth || image.width;
        var imageHeight = image.naturalHeight || image.height;
        var sourceSize = Math.min(imageWidth, imageHeight);
        var sourceX = (imageWidth - sourceSize) / 2;
        var sourceY = (imageHeight - sourceSize) / 2;

        canvasContext.save();
        canvasContext.globalAlpha = 0.36;
        canvasContext.globalCompositeOperation = 'source-atop';
        canvasContext.filter = 'grayscale(1) contrast(1.35) brightness(0.7)';
        canvasContext.drawImage(image, sourceX, sourceY, sourceSize, sourceSize, 0, 0, canvasSize, canvasSize);
        canvasContext.restore();
    }

    var EC_ORDER = { l: 1, m: 2, q: 3, h: 4 };
    var EC_LEVELS = { 1: 'l', 2: 'm', 3: 'q', 4: 'h' };

    function pickEcLevel(chosen, withIcon) {
        var rank = EC_ORDER[String(chosen || 'h').toLowerCase()] || EC_ORDER.h;
        var minimum = withIcon ? EC_ORDER.m : EC_ORDER.l;

        return EC_LEVELS[Math.max(rank, minimum)];
    }

    function qrCanvas(text, color, icon, ecLevel) {
        if (typeof global.qrcode !== 'function') {
            return null;
        }

        var level = pickEcLevel(ecLevel, icon).toUpperCase();
        var qr = global.qrcode(0, level);
        qr.addData(String(text || ''));
        qr.make();

        var count = qr.getModuleCount();
        var scale = 4;
        var quiet = 4;
        var size = (count + quiet * 2) * scale;
        var canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;

        var canvasContext = canvas.getContext('2d');
        canvasContext.fillStyle = hexToCss(color);

        for (var row = 0; row < count; row += 1) {
            for (var col = 0; col < count; col += 1) {
                if (qr.isDark(row, col)) {
                    canvasContext.fillRect((col + quiet) * scale, (row + quiet) * scale, scale, scale);
                }
            }
        }

        if (icon) {
            applyIconTexture(canvasContext, icon, count * scale);
        }

        return canvas;
    }

    global.SignDocsStampUI = {
        hexToCss: hexToCss,
        hexToRgb: hexToRgb,
        stampOpacity: stampOpacity,
        fontFamily: fontFamily,
        fontLoaded: fontLoaded,
        loadFont: loadFont,
        loadImage: loadImage,
        qrCanvas: qrCanvas
    };
})(window);
