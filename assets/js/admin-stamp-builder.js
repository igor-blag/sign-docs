(function () {
    'use strict';

    var config = window.SignDocsStampBuilder;
    var pageCanvas = document.getElementById('sign-docs-stamp-builder-canvas');

    if (!pageCanvas || !config || !window.SignDocsStampLayout || !window.SignDocsStampUI || !window.qrcode) {
        return;
    }

    var form = pageCanvas.closest('form');
    var zoomWrap = document.getElementById('sign-docs-stamp-zoom');
    var zoomCanvas = document.getElementById('sign-docs-stamp-zoom-canvas');
    var pageContext = pageCanvas.getContext('2d');
    var zoomContext = zoomCanvas ? zoomCanvas.getContext('2d') : null;
    var measureContext = null;
    var iconImage = null;
    var pendingFrame = 0;

    var PT_TO_PX = 96 / 72;
    var PAGE_W = 595.28;
    var PAGE_H = 841.89;
    var MARGIN = 24;

    function settingInput(name) {
        return form ? form.querySelector('input[name="sign_docs_settings[' + name + ']"]') : null;
    }

    function selectValue(name) {
        return form ? form.querySelector('select[name="sign_docs_settings[' + name + ']"]') : null;
    }

    function selectedValue(name, fallback) {
        var select = selectValue(name);

        return select ? select.value.trim() : (fallback || '');
    }

    function settingValue(name) {
        var input = settingInput(name);
        return input ? input.value.trim() : '';
    }

    function settingChecked(name) {
        var input = settingInput(name);
        return !!(input && input.checked);
    }

    function radioValue(name) {
        if (!form) {
            return '';
        }

        var checked = form.querySelector('input[name="sign_docs_settings[' + name + ']"]:checked');

        return checked ? checked.value : '';
    }

    function stampCornerValue() {
        var select = selectValue('stamp_corner');
        return select ? select.value : 'top-left';
    }

    function measurePt(text, sizePt) {
        if (!measureContext) {
            measureContext = document.createElement('canvas').getContext('2d');
        }

        var family = window.SignDocsStampUI.fontLoaded() ? window.SignDocsStampUI.fontFamily() : 'sans-serif';
        measureContext.font = (sizePt * PT_TO_PX) + 'px ' + family;

        return measureContext.measureText(String(text || '')).width / PT_TO_PX;
    }

    function buildStampData() {
        var sample = config.sample || {};
        var now = new Date();
        var day = String(now.getDate()).padStart(2, '0');
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var year = now.getFullYear();
        var hours = String(now.getHours()).padStart(2, '0');
        var minutes = String(now.getMinutes()).padStart(2, '0');
        var seconds = String(now.getSeconds()).padStart(2, '0');

        return {
            stamp_font_size: settingValue('stamp_font_size') || '8.4',
            stamp_padding: settingValue('stamp_padding') || '5',
            stamp_qr_gap: settingValue('stamp_qr_gap') || '5',
            stamp_qr_padding: settingValue('stamp_qr_padding') || '5',
            stamp_line_spacing: settingValue('stamp_line_spacing') || '1.25',
            stamp_color: settingValue('stamp_color') || '#2e7d32',
            stamp_opacity: settingValue('stamp_opacity') || '1',
            stamp_rows: settingValue('stamp_rows') || 'header,meta,signer,org',
            stamp_border_enabled: settingChecked('stamp_border_enabled') ? '1' : '0',
            stamp_qr_enabled: settingChecked('stamp_qr_enabled') ? '1' : '0',
            stamp_qr_position: radioValue('stamp_qr_position') || 'right',
            stamp_qr_size: settingValue('stamp_qr_size') || '54',
            stamp_qr_ec_level: selectedValue('stamp_qr_ec_level', 'h'),
            qr_logo_enabled: settingChecked('qr_logo_enabled') ? '1' : '0',
            local_signed_at: year + '-' + month + '-' + day + ' ' + hours + ':' + minutes + ':' + seconds,
            post_id: sample.post_id || '0000',
            sha256_hash: sample.sha256_hash || '',
            signer_name: settingValue('signer_name'),
            signer_position: settingValue('signer_position'),
            organization: settingValue('signer_organization'),
            verification_url: sample.verification_url || ''
        };
    }

    function cornerOrigin(corner, stampWidth, stampHeight) {
        var isRight = corner === 'top-right' || corner === 'bottom-right';
        var isBottom = corner === 'bottom-left' || corner === 'bottom-right';

        return {
            x: isRight ? PAGE_W - stampWidth - MARGIN : MARGIN,
            top: isBottom ? PAGE_H - stampHeight - MARGIN : MARGIN
        };
    }

    function setupCanvas(canvasElement, canvasContext, cssWidth, cssHeight) {
        var dpr = window.devicePixelRatio > 1 ? window.devicePixelRatio : 1;

        canvasElement.style.width = Math.round(cssWidth) + 'px';
        canvasElement.style.height = Math.round(cssHeight) + 'px';
        canvasElement.width = Math.max(1, Math.round(cssWidth * dpr));
        canvasElement.height = Math.max(1, Math.round(cssHeight * dpr));
        canvasContext.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function drawStamp(canvasContext, data, layout, origin, scale) {
        var color = window.SignDocsStampUI.hexToCss(data.stamp_color);
        var opacity = window.SignDocsStampUI.stampOpacity(data);
        var qrCanvas = null;

        if (layout.qr && data.verification_url) {
            var icon = data.qr_logo_enabled !== '0' ? iconImage : null;
            qrCanvas = window.SignDocsStampUI.qrCanvas(data.verification_url, color, icon, data.stamp_qr_ec_level);
        }

        canvasContext.save();
        canvasContext.globalAlpha = opacity;
        canvasContext.fillStyle = color;
        canvasContext.strokeStyle = color;

        if (layout.hasBorder) {
            canvasContext.lineWidth = Math.max(1, 1.2 * scale);
            canvasContext.strokeRect(
                origin.x * scale,
                origin.top * scale,
                layout.width * scale,
                layout.height * scale
            );
        }

        var family = window.SignDocsStampUI.fontLoaded() ? window.SignDocsStampUI.fontFamily() : 'sans-serif';
        canvasContext.font = (layout.fontSize * scale) + 'px ' + family;
        canvasContext.textBaseline = 'alphabetic';

        layout.lines.forEach(function (line) {
            canvasContext.fillText(
                line.text,
                (origin.x + layout.textX) * scale,
                (origin.top + line.y) * scale
            );
        });

        if (qrCanvas) {
            canvasContext.drawImage(
                qrCanvas,
                (origin.x + layout.qr.left) * scale,
                (origin.top + layout.qr.top) * scale,
                layout.qr.size * scale,
                layout.qr.size * scale
            );
        }

        canvasContext.restore();
    }

    function drawPageCanvas(data, layout) {
        var wrap = pageCanvas.parentNode;
        var wrapWidth = wrap ? wrap.clientWidth : 520;
        var naturalWidth = PAGE_W * PT_TO_PX;
        var fit = Math.min(1, Math.max(0.2, (wrapWidth - 8) / naturalWidth));
        var scale = PT_TO_PX * fit;
        var cssWidth = PAGE_W * scale;
        var cssHeight = PAGE_H * scale;

        setupCanvas(pageCanvas, pageContext, cssWidth, cssHeight);

        pageContext.fillStyle = '#ffffff';
        pageContext.fillRect(0, 0, cssWidth, cssHeight);
        pageContext.strokeStyle = '#d9dbde';
        pageContext.lineWidth = 1;
        pageContext.strokeRect(0.5, 0.5, cssWidth - 1, cssHeight - 1);

        if (layout) {
            var origin = cornerOrigin(stampCornerValue(), layout.width, layout.height);
            drawStamp(pageContext, data, layout, origin, scale);
        }
    }

    function drawZoomCanvas(data, layout) {
        if (!zoomContext || !zoomWrap || !layout) {
            if (zoomWrap) {
                zoomWrap.hidden = true;
            }
            return;
        }

        var inner = zoomCanvas.parentNode;
        var innerWidth = inner ? inner.clientWidth : 480;
        var padding = 12;
        var naturalStampWidth = layout.width * PT_TO_PX;
        var zoomRatio = Math.min(1.5, Math.max(0.7, (innerWidth - padding * 2) / naturalStampWidth));
        var scale = PT_TO_PX * zoomRatio;
        var cssWidth = layout.width * scale + padding * 2;
        var cssHeight = layout.height * scale + padding * 2;

        zoomWrap.hidden = false;
        setupCanvas(zoomCanvas, zoomContext, cssWidth, cssHeight);

        zoomContext.fillStyle = '#f0f0f1';
        zoomContext.fillRect(0, 0, cssWidth, cssHeight);

        drawStamp(
            zoomContext,
            data,
            layout,
            { x: padding, top: padding },
            scale
        );
    }

    function render() {
        if (!pageContext) {
            return;
        }

        var data = buildStampData();
        var maxWidth = Math.max(120, PAGE_W - MARGIN * 2);
        var layout = window.SignDocsStampLayout.compute(data, measurePt, maxWidth);

        drawPageCanvas(data, layout);
        drawZoomCanvas(data, layout);
    }

    function scheduleRender() {
        if (pendingFrame) {
            return;
        }

        pendingFrame = window.requestAnimationFrame(function () {
            pendingFrame = 0;
            render();
        });
    }

    function syncRowsValue() {
        var rowsInput = document.getElementById('sign-docs-stamp-rows-value');
        var list = document.getElementById('sign-docs-stamp-rows');

        if (!rowsInput || !list) {
            return;
        }

        var keys = [];
        Array.prototype.forEach.call(list.querySelectorAll('li'), function (item) {
            var checkbox = item.querySelector('input[type="checkbox"]');
            if (checkbox && checkbox.checked) {
                keys.push(item.getAttribute('data-row-key') || '');
            }
        });

        rowsInput.value = keys.join(',');
    }

    function moveRow(item, direction) {
        var list = document.getElementById('sign-docs-stamp-rows');

        if (!list) {
            return;
        }

        var sibling = direction === -1 ? item.previousElementSibling : item.nextElementSibling;
        if (!sibling || sibling.tagName.toLowerCase() !== 'li') {
            return;
        }

        if (direction === -1) {
            list.insertBefore(item, sibling);
        } else {
            list.insertBefore(sibling, item);
        }

        syncRowsValue();
        scheduleRender();
    }

    function bindRowList() {
        var list = document.getElementById('sign-docs-stamp-rows');

        if (!list) {
            return;
        }

        list.addEventListener('change', function (event) {
            var target = event.target;
            var item = target && target.closest ? target.closest('li') : null;

            if (target && target.type === 'checkbox' && item) {
                item.classList.toggle('is-active', target.checked);
                syncRowsValue();
                scheduleRender();
            }
        });

        list.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('button') : null;
            if (!button) {
                return;
            }

            var item = button.closest('li');
            if (!item) {
                return;
            }

            if (button.classList.contains('sign-docs-stamp-rows__up')) {
                moveRow(item, -1);
            } else if (button.classList.contains('sign-docs-stamp-rows__down')) {
                moveRow(item, 1);
            }
        });
    }

    function syncFooterOptions() {
        var toggle = document.getElementById('sign-docs-stamp-footer-enabled');
        var options = document.getElementById('sign-docs-footer-options');

        if (!toggle || !options) {
            return;
        }

        options.classList.toggle('is-hidden', !toggle.checked);
    }

    function bindInputs() {
        if (!form) {
            return;
        }

        ['input', 'change'].forEach(function (eventName) {
            form.addEventListener(eventName, function (event) {
                var input = event.target;
                if (!input || !input.name || input.name.indexOf('sign_docs_settings[') !== 0) {
                    return;
                }

                if (input.id === 'sign-docs-default-stamp-opacity') {
                    var label = document.getElementById('sign-docs-stamp-opacity-label');
                    if (label) {
                        label.textContent = Math.round(parseFloat(input.value || '1') * 100) + '%';
                    }
                }

                if (input.id === 'sign-docs-stamp-footer-enabled') {
                    syncFooterOptions();
                }

                scheduleRender();
            });
        });

        window.addEventListener('resize', scheduleRender);
        syncFooterOptions();
    }

    window.SignDocsStampUI.loadFont(config.fonts.regular).then(function () {
        scheduleRender();
    });
    if (config.siteIconUrl) {
        window.SignDocsStampUI.loadImage(config.siteIconUrl).then(function (image) {
            iconImage = image;
            scheduleRender();
        });
    }
    bindRowList();
    bindInputs();
    scheduleRender();
}());
