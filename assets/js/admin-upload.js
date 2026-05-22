(function () {
    'use strict';

    const form = document.getElementById('sign-docs-upload-form');
    const statusBox = document.getElementById('sign-docs-upload-status');

    if (!form || !statusBox || !window.SignDocsUpload) {
        return;
    }

    const statusText = statusBox.querySelector('p');
    let previewUrl = '';
    let manualStampPicking = false;
    let previewPageSize = null;
    let selectedStampPosition = null;
    let titleManuallyEdited = false;

    function setStatus(message, type) {
        statusBox.hidden = false;
        statusBox.className = 'notice notice-' + (type || 'info');
        statusText.textContent = message;
    }

    function setStatusLink(message, url, label, type) {
        statusBox.hidden = false;
        statusBox.className = 'notice notice-' + (type || 'info');
        statusText.textContent = '';
        statusText.appendChild(document.createTextNode(message + ' '));

        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = label;
        statusText.appendChild(link);
    }

    function clearPdfPreview() {
        const preview = document.getElementById('sign-docs-pdf-preview');
        const frame = preview ? preview.querySelector('iframe') : null;

        if (frame) {
            frame.removeAttribute('src');
        }

        if (preview) {
            preview.style.display = 'none';
        }

        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = '';
        }

        previewPageSize = null;
        selectedStampPosition = null;
        resetManualStampPosition();
    }

    async function readPreviewPageSize(file) {
        if (!window.PDFLib || !file) {
            return null;
        }

        try {
            const pdfDoc = await window.PDFLib.PDFDocument.load(await file.arrayBuffer(), {
                ignoreEncryption: true,
                updateMetadata: false
            });
            const firstPage = pdfDoc.getPages()[0];

            return firstPage ? firstPage.getSize() : null;
        } catch (error) {
            return null;
        }
    }

    async function showPdfPreview(file) {
        const preview = document.getElementById('sign-docs-pdf-preview');
        const frame = preview ? preview.querySelector('iframe') : null;

        clearPdfPreview();

        if (!preview || !frame || !file || (file.type !== 'application/pdf' && !/\.pdf$/i.test(file.name || ''))) {
            return;
        }

        previewUrl = URL.createObjectURL(file);
        frame.src = previewUrl + '#page=1&toolbar=0&navpanes=0&scrollbar=0&view=Fit';
        preview.style.display = 'block';
        previewPageSize = await readPreviewPageSize(file);
        syncManualStampLayer();
        updateManualStampControls(false);
    }

    function setHiddenField(name, value) {
        const input = form.querySelector('[name="' + name + '"]');

        if (input) {
            input.value = value;
        }
    }

    function inputValue(name) {
        const input = form.querySelector('[name="' + name + '"]');
        return input ? input.value.trim() : '';
    }

    function categoryRequiresUnsigned() {
        return inputValue('document_category') === 'external-regulation';
    }

    function isLocalAct() {
        return inputValue('document_category') === 'local-act';
    }

    function includeInstitutionInTitle() {
        const checkbox = form.querySelector('[name="include_institution_in_title"]');

        return !isLocalAct() || (checkbox && checkbox.checked);
    }

    function currentFile() {
        const fileInput = form.querySelector('[name="sign_docs_pdf"]');
        return fileInput && fileInput.files ? fileInput.files[0] : null;
    }

    function syncFileDropzone(file) {
        const title = document.getElementById('sign-docs-file-dropzone-title');
        const text = document.getElementById('sign-docs-file-dropzone-text');

        if (!title || !text) {
            return;
        }

        if (!file) {
            title.textContent = 'Перетащите PDF сюда';
            text.textContent = 'или щелкните, чтобы выбрать файл';
            return;
        }

        title.textContent = file.name || 'PDF выбран';
        text.textContent = 'Файл будет загружен после отправки формы';
    }

    function handleSelectedFile(file) {
        syncFileDropzone(file);
        fillTitleFromFile(file);
        showPdfPreview(file);
    }

    function defaultStampPosition() {
        const corner = field('stamp_corner') || 'top-left';

        return {
            x: corner === 'top-right' || corner === 'bottom-right' ? 1 : 0,
            y: corner === 'bottom-left' || corner === 'bottom-right' ? 1 : 0
        };
    }

    function syncUploadMode() {
        const unsignedOnly = categoryRequiresUnsigned();
        const signedButtons = form.querySelectorAll('.sign-docs-save-signed');
        const unsignedButtons = form.querySelectorAll('.sign-docs-save-unsigned');

        Array.prototype.forEach.call(signedButtons, function (button) {
            button.hidden = unsignedOnly;
        });

        Array.prototype.forEach.call(unsignedButtons, function (button) {
            button.classList.toggle('button-primary', unsignedOnly);
            button.classList.toggle('button-secondary', !unsignedOnly);
        });

        syncInstitutionMode();

        if (!previewUrl && currentFile()) {
            showPdfPreview(currentFile());
            return;
        }

        updateManualStampControls(false);
    }

    function normalizeNumber(value) {
        const number = String(value || '').trim().replace(/^№\s*/, '');
        return number ? '№ ' + number : '';
    }

    function quoteSubject(value) {
        const subject = String(value || '').trim().replace(/^[«"']+|[»"']+$/g, '').trim();
        return subject ? '«' + subject + '»' : '';
    }

    function composeDocumentTitle() {
        return [
            inputValue('document_type_label'),
            includeInstitutionInTitle() ? inputValue('document_institution') : '',
            inputValue('document_date'),
            normalizeNumber(inputValue('document_number')),
            quoteSubject(inputValue('document_subject'))
        ].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
    }

    function syncDocumentTitle() {
        const titleInput = form.querySelector('[name="post_title"]');
        const fullTitleInput = form.querySelector('[name="full_title"]');
        const title = composeDocumentTitle();

        if (!title) {
            return;
        }

        if (titleInput && !titleManuallyEdited) {
            titleInput.value = title;
        }

        if (fullTitleInput && (!fullTitleInput.value.trim() || fullTitleInput.dataset.signDocsAuto === '1')) {
            fullTitleInput.value = title;
            fullTitleInput.dataset.signDocsAuto = '1';
        }
    }

    function applySubjectCase(mode) {
        const subjectInput = form.querySelector('[name="document_subject"]');
        if (!subjectInput) {
            return;
        }

        const source = subjectInput.value.trim();
        if (mode === 'upper') {
            subjectInput.value = source.toLocaleUpperCase('ru-RU');
        } else if (mode === 'lower') {
            subjectInput.value = source.toLocaleLowerCase('ru-RU');
        } else {
            const lower = source.toLocaleLowerCase('ru-RU');
            subjectInput.value = lower ? lower.charAt(0).toLocaleUpperCase('ru-RU') + lower.slice(1) : '';
        }

        syncDocumentTitle();
    }

    function syncDocumentTypeOptions() {
        const category = inputValue('document_category');
        const typeSelect = form.querySelector('[name="document_type_label"]');
        const typeTermInput = form.querySelector('[name="document_type_term_id"]');

        if (!typeSelect) {
            return;
        }

        Array.prototype.forEach.call(typeSelect.options, function (option) {
            const visible = !option.dataset.category || option.dataset.category === category;
            option.hidden = !visible;
            option.disabled = !visible;
        });

        if (typeSelect.selectedOptions[0] && typeSelect.selectedOptions[0].disabled) {
            const firstVisible = Array.prototype.find.call(typeSelect.options, function (option) {
                return !option.disabled;
            });
            if (firstVisible) {
                typeSelect.value = firstVisible.value;
            }
        }

        if (typeTermInput && typeSelect.selectedOptions[0]) {
            typeTermInput.value = typeSelect.selectedOptions[0].dataset.termId || '';
        }

        syncDocumentTitle();
        syncUploadMode();
    }

    function syncInstitutionMode() {
        const institutionRow = document.getElementById('sign-docs-institution-row');
        const includeInstitutionRow = document.getElementById('sign-docs-include-institution-row');
        const institutionInput = form.querySelector('[name="document_institution"]');
        const defaultInstitutionInput = form.querySelector('[name="default_institution"]');
        const defaultInstitution = defaultInstitutionInput ? defaultInstitutionInput.value.trim() : '';

        if (institutionRow) {
            institutionRow.hidden = isLocalAct();
        }

        if (includeInstitutionRow) {
            includeInstitutionRow.hidden = !isLocalAct();
        }

        if (isLocalAct() && institutionInput) {
            institutionInput.value = defaultInstitution;
        }

        if (!isLocalAct() && institutionInput && institutionInput.value.trim() === defaultInstitution) {
            institutionInput.value = '';
        }

        syncDocumentTitle();
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function stampPreviewSize(layer) {
        const pageWidth = previewPageSize && previewPageSize.width ? previewPageSize.width : 595.28;
        const pageHeight = previewPageSize && previewPageSize.height ? previewPageSize.height : 841.89;
        const data = {
            stamp_width_mm: field('stamp_width_mm') || '100',
            stamp_font_size: field('stamp_font_size') || '8.4'
        };
        const widthRatio = stampWidthPoints(data, pageWidth) / pageWidth;
        const fontSize = stampFontSize(data);
        const lineHeight = fontSize * 1.25;
        const heightRatio = Math.max(82, 36 + lineHeight * 4) / pageHeight;

        return {
            width: Math.max(120, Math.min(layer.clientWidth * 0.75, layer.clientWidth * widthRatio)),
            height: Math.max(58, Math.min(layer.clientHeight * 0.35, layer.clientHeight * heightRatio))
        };
    }

    function syncManualStampLayer() {
        const wrapper = document.getElementById('sign-docs-preview-frame-wrap');
        const layer = document.getElementById('sign-docs-stamp-pick-layer');

        if (!wrapper || !layer) {
            return;
        }

        const pageWidth = previewPageSize && previewPageSize.width ? previewPageSize.width : 595.28;
        const pageHeight = previewPageSize && previewPageSize.height ? previewPageSize.height : 841.89;
        const scale = Math.min(wrapper.clientWidth / pageWidth, wrapper.clientHeight / pageHeight);
        const width = pageWidth * scale;
        const height = pageHeight * scale;

        layer.style.left = Math.max(0, (wrapper.clientWidth - width) / 2) + 'px';
        layer.style.top = Math.max(0, (wrapper.clientHeight - height) / 2) + 'px';
        layer.style.width = width + 'px';
        layer.style.height = height + 'px';
        layer.style.right = 'auto';
        layer.style.bottom = 'auto';

        if (selectedStampPosition) {
            placeManualStampRect(selectedStampPosition);
            placeSelectedStampRect(selectedStampPosition);
        }
    }

    function applyStampRectStyle(rect) {
        const color = hexToCss(field('stamp_color') || '#2e7d32');
        const opacity = stampOpacity({ stamp_opacity: field('stamp_opacity') || '1' });

        rect.style.borderColor = color;
        rect.style.background = color + Math.round(opacity * 28).toString(16).padStart(2, '0');
        rect.style.opacity = '1';
    }

    function placeSelectedStampRect(position) {
        const layer = document.getElementById('sign-docs-stamp-pick-layer');
        const rect = document.getElementById('sign-docs-stamp-selected-rect');

        if (!layer || !rect || !position) {
            return;
        }

        const size = stampPreviewSize(layer);
        const left = layer.offsetLeft + clamp(position.x, 0, 1) * Math.max(0, layer.clientWidth - size.width);
        const top = layer.offsetTop + clamp(position.y, 0, 1) * Math.max(0, layer.clientHeight - size.height);

        rect.style.width = size.width + 'px';
        rect.style.height = size.height + 'px';
        rect.style.left = left + 'px';
        rect.style.top = top + 'px';
        rect.style.display = 'block';
        rect.style.zIndex = '4';
        applyStampRectStyle(rect);
    }

    function hideSelectedStampRect() {
        const rect = document.getElementById('sign-docs-stamp-selected-rect');

        if (rect) {
            rect.style.display = 'none';
        }
    }

    function placeManualStampRect(position) {
        const layer = document.getElementById('sign-docs-stamp-pick-layer');
        const rect = document.getElementById('sign-docs-stamp-preview-rect');

        if (!layer || !rect || !position) {
            return;
        }

        const size = stampPreviewSize(layer);
        const left = clamp(position.x, 0, 1) * Math.max(0, layer.clientWidth - size.width);
        const top = clamp(position.y, 0, 1) * Math.max(0, layer.clientHeight - size.height);

        rect.style.width = size.width + 'px';
        rect.style.height = size.height + 'px';
        rect.style.left = left + 'px';
        rect.style.top = top + 'px';
        rect.style.display = 'block';
        applyStampRectStyle(rect);
    }

    function moveManualStampRect(event) {
        const layer = document.getElementById('sign-docs-stamp-pick-layer');
        const rect = document.getElementById('sign-docs-stamp-preview-rect');

        if (!layer || !rect) {
            return null;
        }

        const bounds = layer.getBoundingClientRect();
        const size = stampPreviewSize(layer);
        const left = clamp(event.clientX - bounds.left - size.width / 2, 0, Math.max(0, bounds.width - size.width));
        const top = clamp(event.clientY - bounds.top - size.height / 2, 0, Math.max(0, bounds.height - size.height));

        rect.style.width = size.width + 'px';
        rect.style.height = size.height + 'px';
        rect.style.left = left + 'px';
        rect.style.top = top + 'px';
        rect.style.display = 'block';
        applyStampRectStyle(rect);

        return {
            x: bounds.width > size.width ? left / (bounds.width - size.width) : 0,
            y: bounds.height > size.height ? top / (bounds.height - size.height) : 0
        };
    }

    function updateManualStampControls(active) {
        const layer = document.getElementById('sign-docs-stamp-pick-layer');
        const pickButton = document.getElementById('sign-docs-stamp-pick');
        const resetButton = document.getElementById('sign-docs-stamp-reset');
        const status = document.getElementById('sign-docs-stamp-placement-status');
        const isManual = field('stamp_placement_mode') === 'manual';
        const unsignedOnly = categoryRequiresUnsigned();
        const rect = document.getElementById('sign-docs-stamp-preview-rect');

        manualStampPicking = active;
        syncManualStampLayer();

        if (layer) {
            layer.style.display = active || isManual || !unsignedOnly ? 'block' : 'none';
            layer.style.pointerEvents = active ? 'auto' : 'none';
            layer.style.zIndex = '2';
        }

        if (active || unsignedOnly) {
            hideSelectedStampRect();
        }

        if (rect && !active && !isManual) {
            rect.style.display = 'none';
            if (!unsignedOnly) {
                placeSelectedStampRect(defaultStampPosition());
            }
        }

        if (!active && isManual && selectedStampPosition) {
            placeSelectedStampRect(selectedStampPosition);
            if (rect) {
                rect.style.display = 'none';
                rect.style.zIndex = '3';
            }
        }

        if (pickButton) {
            pickButton.hidden = unsignedOnly;
            pickButton.textContent = active ? 'Отменить выбор места' : 'Выбрать место штампа';
        }

        if (resetButton) {
            resetButton.hidden = unsignedOnly || !isManual;
        }

        if (status) {
            status.hidden = unsignedOnly;
            status.textContent = isManual
                ? 'Место выбрано вручную. Можно выбрать заново.'
                : (active ? 'Наведите прямоугольник на нужное место и щелкните.' : 'Используется угол из настроек.');
        }
    }

    function resetManualStampPosition() {
        setHiddenField('stamp_placement_mode', 'corner');
        setHiddenField('stamp_manual_x', '');
        setHiddenField('stamp_manual_y', '');
        selectedStampPosition = null;
        hideSelectedStampRect();
        updateManualStampControls(false);
    }

    function saveManualStampPosition(event) {
        const position = moveManualStampRect(event);

        if (!position) {
            return;
        }

        setHiddenField('stamp_placement_mode', 'manual');
        setHiddenField('stamp_manual_x', position.x.toFixed(6));
        setHiddenField('stamp_manual_y', position.y.toFixed(6));
        selectedStampPosition = position;
        hideSelectedStampRect();
        placeSelectedStampRect(position);
        updateManualStampControls(false);
    }

    function fillTitleFromFile(file) {
        const titleInput = form.querySelector('[name="post_title"]');

        if (!titleInput || !file || titleInput.value.trim() !== '' || composeDocumentTitle()) {
            return;
        }

        titleInput.value = String(file.name || '')
            .replace(/\.[^.]+$/, '')
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function field(name) {
        const input = form.querySelector('[name="' + name + '"]');
        return input ? input.value.trim() : '';
    }

    function uploadReturnUrl(postId) {
        const returnTo = field('return_to');

        if (!returnTo || !postId) {
            return '';
        }

        const url = new URL(returnTo, window.location.origin);
        url.searchParams.set('sign_docs_document_id', String(postId));

        return url.toString().replace('__SIGN_DOCS_CREATED_ID__', String(postId));
    }

    async function postForm(url, data) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': window.SignDocsUpload.nonce },
            body: data
        });
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message || 'Request failed.');
        }

        return payload;
    }

    async function fetchBytes(url) {
        const response = await fetch(url, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error('Не удалось загрузить файл шрифта.');
        }

        return await response.arrayBuffer();
    }

    function loadCanvasImage(url) {
        return new Promise(function (resolve, reject) {
            const image = new Image();

            image.onload = function () {
                resolve(image);
            };
            image.onerror = reject;
            image.crossOrigin = 'anonymous';
            image.src = url;
        });
    }

    async function makeQrCanvas(text, color, useIconTexture) {
        const qr = window.qrcode(0, useIconTexture ? 'M' : 'H');
        qr.addData(text);
        qr.make();

        const count = qr.getModuleCount();
        const scale = 4;
        const quiet = 4;
        const canvas = document.createElement('canvas');
        canvas.width = (count + quiet * 2) * scale;
        canvas.height = canvas.width;

        const context = canvas.getContext('2d');
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.fillStyle = color || '#2e7d32';

        for (let row = 0; row < count; row += 1) {
            for (let col = 0; col < count; col += 1) {
                if (qr.isDark(row, col)) {
                    context.fillRect((col + quiet) * scale, (row + quiet) * scale, scale, scale);
                }
            }
        }

        if (useIconTexture && window.SignDocsUpload.siteIconUrl) {
            try {
                const image = await loadCanvasImage(window.SignDocsUpload.siteIconUrl);
                const imageWidth = image.naturalWidth || image.width;
                const imageHeight = image.naturalHeight || image.height;
                const imageSize = Math.min(imageWidth, imageHeight);
                const sourceX = (imageWidth - imageSize) / 2;
                const sourceY = (imageHeight - imageSize) / 2;

                context.save();
                context.globalAlpha = 0.36;
                context.globalCompositeOperation = 'source-atop';
                context.filter = 'grayscale(1) contrast(1.35) brightness(0.7)';
                context.drawImage(
                    image,
                    sourceX,
                    sourceY,
                    imageSize,
                    imageSize,
                    quiet * scale,
                    quiet * scale,
                    count * scale,
                    count * scale
                );
                context.restore();
            } catch (error) {
                context.globalCompositeOperation = 'source-over';
                context.filter = 'none';
                context.globalAlpha = 1;
            }
        }

        return canvas;
    }

    function dataUrlToBytes(dataUrl) {
        const base64 = dataUrl.split(',')[1];
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);

        for (let i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }

        return bytes;
    }

    function imageBlobToPngBytes(blob) {
        return new Promise(function (resolve, reject) {
            const image = new Image();
            const objectUrl = URL.createObjectURL(blob);

            image.onload = function () {
                const canvas = document.createElement('canvas');
                canvas.width = image.naturalWidth || image.width;
                canvas.height = image.naturalHeight || image.height;
                canvas.getContext('2d').drawImage(image, 0, 0);
                URL.revokeObjectURL(objectUrl);
                resolve(dataUrlToBytes(canvas.toDataURL('image/png')));
            };
            image.onerror = function () {
                URL.revokeObjectURL(objectUrl);
                reject(new Error('Could not decode site icon.'));
            };
            image.src = objectUrl;
        });
    }

    function hexToRgb(hex) {
        const normalized = String(hex || '#2e7d32').replace('#', '');
        const value = normalized.length === 3
            ? normalized.split('').map(function (item) { return item + item; }).join('')
            : normalized;

        return {
            r: parseInt(value.slice(0, 2), 16) / 255,
            g: parseInt(value.slice(2, 4), 16) / 255,
            b: parseInt(value.slice(4, 6), 16) / 255
        };
    }

    function hexToCss(hex) {
        const normalized = String(hex || '#2e7d32').trim();

        return /^#[0-9a-f]{6}$/i.test(normalized) ? normalized : '#2e7d32';
    }

    function stampOpacity(data) {
        const opacity = Number.parseFloat(data.stamp_opacity || '1');
        if (Number.isNaN(opacity)) {
            return 1;
        }

        return Math.min(1, Math.max(0.1, opacity));
    }

    function stampFontSize(data) {
        const fontSize = Number.parseFloat(data.stamp_font_size || '8.4');
        if (Number.isNaN(fontSize)) {
            return 8.4;
        }

        return Math.min(12, Math.max(6, fontSize));
    }

    function stampWidthPoints(data, pageWidth) {
        const widthMm = Number.parseFloat(data.stamp_width_mm || '100');
        const points = (Number.isNaN(widthMm) ? 100 : Math.min(160, Math.max(70, widthMm))) * 72 / 25.4;

        return Math.min(points, pageWidth - 48);
    }

    function qrLogoEnabled(data) {
        return data.qr_logo_enabled !== '0' && data.qr_logo_enabled !== false;
    }

    function stampBorderEnabled(data) {
        return data.stamp_border_enabled !== '0' && data.stamp_border_enabled !== false;
    }

    function compactSignedAt(value) {
        const source = String(value || '').trim();
        const match = source.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (match) {
            return match[3] + '.' + match[2] + '.' + match[1] + ', ' + match[4] + ':' + match[5];
        }

        return source.replace(/(\d{1,2}:\d{2}):\d{2}(?=\s|$)/, '$1');
    }

    function localSignedAt() {
        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        }).format(new Date());
    }

    function fittedFontSize(font, text, size, maxWidth, minSize) {
        let nextSize = size;

        while (nextSize > minSize && font.widthOfTextAtSize(text, nextSize) > maxWidth) {
            nextSize -= 0.2;
        }

        return Math.max(minSize, nextSize);
    }

    function wrapText(font, text, size, maxWidth, maxLines) {
        const source = String(text || '').replace(/\s+/g, ' ').trim();
        const words = source ? source.split(' ') : [];
        const lines = [];
        let line = '';

        words.forEach(function (word) {
            const next = line ? line + ' ' + word : word;

            if (font.widthOfTextAtSize(next, size) <= maxWidth) {
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
            const limited = lines.slice(0, maxLines);
            let last = limited[limited.length - 1] || '';

            while (last.length > 1 && font.widthOfTextAtSize(last + '...', size) > maxWidth) {
                last = last.slice(0, -1).trim();
            }

            limited[limited.length - 1] = last + '...';
            return limited;
        }

        return lines;
    }

    function stampTextRows(data, fonts, fontSize, textWidth) {
        const signedText = 'Документ подписан: ' + compactSignedAt(data.local_signed_at || data.signed_at);
        const signedFontSize = fittedFontSize(fonts.regular, signedText, fontSize * 0.83, textWidth, 5.6);
        const rows = [];

        wrapText(fonts.medium, data.signer_name || data.signer || '', fontSize, textWidth, 2).forEach(function (line) {
            rows.push({ text: line, size: fontSize, font: fonts.medium });
        });
        wrapText(fonts.regular, data.signer_position || '', fontSize * 0.93, textWidth, 2).forEach(function (line) {
            rows.push({ text: line, size: fontSize * 0.93, font: fonts.regular });
        });
        wrapText(fonts.regular, data.organization || '', fontSize * 0.89, textWidth, 2).forEach(function (line) {
            rows.push({ text: line, size: fontSize * 0.89, font: fonts.regular });
        });
        rows.push({ text: signedText, size: signedFontSize, font: fonts.regular });

        return rows;
    }

    function addUriLink(pdfDoc, page, rect, url) {
        const PDFLib = window.PDFLib;
        const annotation = pdfDoc.context.register(
            pdfDoc.context.obj({
                Type: PDFLib.PDFName.of('Annot'),
                Subtype: PDFLib.PDFName.of('Link'),
                Rect: rect,
                Border: [0, 0, 0],
                A: {
                    Type: PDFLib.PDFName.of('Action'),
                    S: PDFLib.PDFName.of('URI'),
                    URI: PDFLib.PDFString.of(url)
                }
            })
        );

        page.node.addAnnot(annotation);
    }

    async function embedGolosFonts(pdfDoc) {
        const fontkit = window.fontkit || window.Fontkit;
        if (!fontkit) {
            throw new Error('Не загружен fontkit для встраивания шрифта.');
        }

        pdfDoc.registerFontkit(fontkit);

        return {
            regular: await pdfDoc.embedFont(await fetchBytes(window.SignDocsUpload.fonts.regular), { subset: true }),
            medium: await pdfDoc.embedFont(await fetchBytes(window.SignDocsUpload.fonts.medium), { subset: true })
        };
    }

    async function embedSiteIcon(pdfDoc) {
        const iconUrl = window.SignDocsUpload.siteIconUrl;
        if (!iconUrl) {
            return null;
        }

        try {
            const response = await fetch(iconUrl, { credentials: 'same-origin' });
            if (!response.ok) {
                return null;
            }

            const contentType = response.headers.get('content-type') || '';
            const blob = await response.blob();
            const bytes = await blob.arrayBuffer();

            if (contentType.includes('jpeg') || contentType.includes('jpg')) {
                return await pdfDoc.embedJpg(bytes);
            }

            if (contentType.includes('png')) {
                return await pdfDoc.embedPng(bytes);
            }

            return await pdfDoc.embedPng(await imageBlobToPngBytes(blob));
        } catch (error) {
            return null;
        }
    }

    function stampOrigin(pageSize, stampWidth, stampHeight, corner) {
        const margin = 24;
        const isRight = corner === 'top-right' || corner === 'bottom-right';
        const isBottom = corner === 'bottom-left' || corner === 'bottom-right';

        return {
            x: isRight ? pageSize.width - stampWidth - margin : margin,
            y: isBottom ? margin : pageSize.height - stampHeight - margin
        };
    }

    function manualStampOrigin(pageSize, stampWidth, stampHeight, data) {
        if (data.stamp_placement_mode !== 'manual') {
            return null;
        }

        const manualX = Number.parseFloat(data.stamp_manual_x || '');
        const manualY = Number.parseFloat(data.stamp_manual_y || '');

        if (Number.isNaN(manualX) || Number.isNaN(manualY)) {
            return null;
        }

        return {
            x: clamp(manualX, 0, 1) * Math.max(0, pageSize.width - stampWidth),
            y: (1 - clamp(manualY, 0, 1)) * Math.max(0, pageSize.height - stampHeight)
        };
    }

    function drawFirstPageStamp(pdfDoc, page, data, fonts, qrImage) {
        const PDFLib = window.PDFLib;
        const size = page.getSize();
        const stampWidth = stampWidthPoints(data, size.width);
        const fontSize = stampFontSize(data);
        const lineHeight = fontSize * 1.25;
        const qrSize = 54;
        const innerPadding = 8;
        const textWidth = stampWidth - qrSize - innerPadding * 3;
        const rows = stampTextRows(data, fonts, fontSize, textWidth);
        const stampHeight = Math.max(82, innerPadding * 2 + rows.length * lineHeight + 4);
        const origin = manualStampOrigin(size, stampWidth, stampHeight, data) || stampOrigin(size, stampWidth, stampHeight, data.stamp_corner || 'top-left');
        const x = origin.x;
        const y = origin.y;
        const color = hexToRgb(data.stamp_color);
        const mainColor = PDFLib.rgb(color.r, color.g, color.b);
        const opacity = stampOpacity(data);
        const qrX = x + stampWidth - qrSize - innerPadding;
        const qrY = y + 24;
        const textX = x + innerPadding;
        let textY = y + stampHeight - innerPadding - fontSize;

        if (stampBorderEnabled(data)) {
            page.drawRectangle({
                x: x,
                y: y,
                width: stampWidth,
                height: stampHeight,
                borderColor: mainColor,
                borderWidth: 1.2,
                borderOpacity: opacity
            });
        }

        rows.forEach(function (row) {
            page.drawText(row.text, {
                x: textX,
                y: textY,
                size: row.size,
                font: row.font,
                color: mainColor,
                opacity: opacity,
                maxWidth: textWidth
            });
            textY -= lineHeight;
        });

        page.drawImage(qrImage, {
            x: qrX,
            y: qrY,
            width: qrSize,
            height: qrSize,
            opacity: opacity
        });

        const docId = 'ID ' + data.post_id;
        const docIdSize = 7.2;
        const docIdWidth = fonts.medium.widthOfTextAtSize(docId, docIdSize);
        const docIdX = qrX - 5;
        const docIdY = qrY + qrSize / 2 + docIdWidth / 2;
        page.drawText(docId, {
            x: docIdX,
            y: docIdY,
            size: docIdSize,
            font: fonts.medium,
            color: mainColor,
            opacity: opacity,
            rotate: PDFLib.degrees(-90)
        });

        addUriLink(pdfDoc, page, [qrX, qrY, qrX + qrSize, qrY + qrSize], data.verification_url);
    }

    function drawFooterStamp(pdfDoc, page, data, fonts) {
        const PDFLib = window.PDFLib;
        const size = page.getSize();
        const margin = Math.min(30, Math.max(16, size.width * 0.04));
        const y = 18;
        const stampWidth = size.width - margin * 2;
        const color = hexToRgb(data.stamp_color);
        const mainColor = PDFLib.rgb(color.r, color.g, color.b);
        const opacity = stampOpacity(data);
        const text = 'SHA-256 исходного PDF: ' + data.sha256_hash + '  Проверка: ' + data.verification_url;

        page.drawRectangle({
            x: margin,
            y: y,
            width: stampWidth,
            height: 28,
            borderColor: mainColor,
            borderWidth: 0.5,
            borderOpacity: opacity
        });
        page.drawText(text, {
            x: margin + 10,
            y: y + 10,
            size: 6.4,
            font: fonts.regular,
            color: mainColor,
            opacity: opacity,
            maxWidth: stampWidth - 20
        });

        addUriLink(pdfDoc, page, [margin, y, margin + stampWidth, y + 28], data.verification_url);
    }

    async function stampPdf(file, data) {
        const PDFLib = window.PDFLib;
        const bytes = await file.arrayBuffer();
        const pdfDoc = await PDFLib.PDFDocument.load(bytes, {
            ignoreEncryption: true,
            updateMetadata: false
        });
        const fonts = await embedGolosFonts(pdfDoc);
        const qrCanvas = await makeQrCanvas(data.verification_url, hexToCss(data.stamp_color), qrLogoEnabled(data));
        const qrImage = await pdfDoc.embedPng(dataUrlToBytes(qrCanvas.toDataURL('image/png')));
        const pages = pdfDoc.getPages();
        const stampData = Object.assign({}, data);

        pages.forEach(function (page, index) {
            if (index === 0) {
                drawFirstPageStamp(pdfDoc, page, stampData, fonts, qrImage);
            } else {
                drawFooterStamp(pdfDoc, page, stampData, fonts);
            }
        });

        pdfDoc.setProducer('Sign Docs');
        pdfDoc.setCreator('Sign Docs WordPress plugin');

        return await pdfDoc.save({
            useObjectStreams: true,
            updateFieldAppearances: false
        });
    }

    form.addEventListener('submit', async function (event) {
        const file = currentFile();
        const saveMode = event.submitter && event.submitter.value ? event.submitter.value : 'signed';

        if (!file) {
            return;
        }

        if (saveMode === 'unsigned' || categoryRequiresUnsigned()) {
            return;
        }

        if (!window.SignDocsUpload.hasVendor || !window.PDFLib || !window.qrcode || !(window.fontkit || window.Fontkit)) {
            event.preventDefault();
            setStatus('Не загружены локальные JS-библиотеки или шрифт Golos Text. Проверьте assets/vendor.', 'error');
            return;
        }

        event.preventDefault();

        try {
            const submitButton = form.querySelector('[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
            }

            setStatus('Загружаю исходный PDF и считаю серверный SHA-256...', 'info');

            const prepareData = new FormData();
            prepareData.append('original_pdf', file, file.name);
            prepareData.append('post_title', field('post_title'));
            prepareData.append('full_title', field('full_title'));
            prepareData.append('document_category', field('document_category'));
            prepareData.append('document_type_label', field('document_type_label'));
            prepareData.append('document_type_term_id', field('document_type_term_id'));
            prepareData.append('document_institution', field('document_institution'));
            prepareData.append('document_date', field('document_date'));
            prepareData.append('document_number', field('document_number'));
            prepareData.append('document_subject', field('document_subject'));
            prepareData.append('signer_name', field('signer_name'));
            prepareData.append('signer_position', field('signer_position'));
            prepareData.append('signer_organization', field('signer_organization'));
            prepareData.append('stamp_corner', field('stamp_corner') || 'top-left');
            prepareData.append('stamp_color', field('stamp_color') || '#2e7d32');
            prepareData.append('stamp_opacity', field('stamp_opacity') || '1');
            prepareData.append('stamp_font_size', field('stamp_font_size') || '8.4');
            prepareData.append('stamp_width_mm', field('stamp_width_mm') || '100');
            prepareData.append('stamp_border_enabled', field('stamp_border_enabled') === '0' ? '0' : '1');
            prepareData.append('stamp_placement_mode', field('stamp_placement_mode') || 'corner');
            prepareData.append('stamp_manual_x', field('stamp_manual_x'));
            prepareData.append('stamp_manual_y', field('stamp_manual_y'));
            prepareData.append('qr_logo_enabled', field('qr_logo_enabled') === '0' ? '0' : '1');

            const prepared = await postForm(window.SignDocsUpload.prepareUrl, prepareData);
            prepared.local_signed_at = localSignedAt();

            setStatus('Встраиваю Golos Text, накладываю штамп и QR-код...', 'info');
            const stampedBytes = await stampPdf(file, prepared);
            const stampedBlob = new Blob([stampedBytes], { type: 'application/pdf' });

            setStatus('Сохраняю публичную PDF-копию с отметкой...', 'info');
            const completeData = new FormData();
            completeData.append('post_id', prepared.post_id);
            completeData.append('stamped_pdf', stampedBlob, 'stamped.pdf');

            const completed = await postForm(window.SignDocsUpload.completeUrl, completeData);
            const returnUrl = uploadReturnUrl(completed.post_id || prepared.post_id);
            if (returnUrl) {
                window.location.assign(returnUrl);
                return;
            }
            setStatusLink('Документ подписан и зарегистрирован.', completed.verification_url, 'Открыть страницу проверки', 'success');
            form.reset();
            titleManuallyEdited = false;
            clearPdfPreview();
            if (submitButton) {
                submitButton.disabled = false;
            }
        } catch (error) {
            setStatus(error.message || 'Не удалось подписать документ.', 'error');
            const submitButton = form.querySelector('[type="submit"]');
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    });

    const fileInput = form.querySelector('[name="sign_docs_pdf"]');
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
            handleSelectedFile(file);
        });
    }

    const dropzone = form.querySelector('.sign-docs-file-dropzone');
    if (dropzone && fileInput) {
        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'drop'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.remove('is-dragover');
            });
        });

        dropzone.addEventListener('drop', function (event) {
            const file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0] ? event.dataTransfer.files[0] : null;
            if (!file) {
                return;
            }

            if (typeof DataTransfer !== 'undefined') {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                fileInput.files = transfer.files;
            }
            handleSelectedFile(file);
        });
    }

    ['document_category', 'document_type_label', 'document_institution', 'document_date', 'document_number', 'document_subject'].forEach(function (name) {
        const input = form.querySelector('[name="' + name + '"]');
        if (!input) {
            return;
        }

        input.addEventListener('input', name === 'document_category' || name === 'document_type_label' ? syncDocumentTypeOptions : syncDocumentTitle);
        input.addEventListener('change', name === 'document_category' || name === 'document_type_label' ? syncDocumentTypeOptions : syncDocumentTitle);
    });

    const includeInstitutionInput = form.querySelector('[name="include_institution_in_title"]');
    if (includeInstitutionInput) {
        includeInstitutionInput.addEventListener('change', syncDocumentTitle);
    }

    const institutionSelect = document.getElementById('sign-docs-institution-select');
    const institutionInput = form.querySelector('[name="document_institution"]');
    if (institutionSelect && institutionInput) {
        institutionSelect.addEventListener('change', function () {
            if (!institutionSelect.value) {
                return;
            }

            institutionInput.value = institutionSelect.value;
            syncDocumentTitle();
        });
    }

    const titleInput = form.querySelector('[name="post_title"]');
    if (titleInput) {
        titleInput.addEventListener('input', function () {
            titleManuallyEdited = true;
        });
    }

    const fullTitleInput = form.querySelector('[name="full_title"]');
    if (fullTitleInput) {
        fullTitleInput.addEventListener('input', function () {
            fullTitleInput.dataset.signDocsAuto = '0';
        });
    }

    Array.prototype.forEach.call(form.querySelectorAll('[data-sign-docs-case]'), function (button) {
        button.addEventListener('click', function () {
            applySubjectCase(button.getAttribute('data-sign-docs-case') || 'sentence');
        });
    });

    syncDocumentTypeOptions();
    syncUploadMode();

    const pickButton = document.getElementById('sign-docs-stamp-pick');
    if (pickButton) {
        pickButton.addEventListener('click', function () {
            updateManualStampControls(!manualStampPicking);
        });
    }

    const resetButton = document.getElementById('sign-docs-stamp-reset');
    if (resetButton) {
        resetButton.addEventListener('click', resetManualStampPosition);
    }

    const pickLayer = document.getElementById('sign-docs-stamp-pick-layer');
    if (pickLayer) {
        pickLayer.addEventListener('mousemove', moveManualStampRect);
        pickLayer.addEventListener('click', saveManualStampPosition);
    }

    window.addEventListener('resize', syncManualStampLayer);
    window.addEventListener('beforeunload', clearPdfPreview);
}());
