(function () {
    'use strict';

    const form = document.getElementById('sign-docs-upload-form');
    const statusBox = document.getElementById('sign-docs-upload-status');

    if (!form || !statusBox || !window.SignDocsUpload) {
        return;
    }

    const statusText = statusBox.querySelector('p');
    let previewUrl = '';
    let previewCanvas = null;
    let manualStampPicking = false;
    let previewPageSize = null;
    let selectedStampPosition = null;
    let titleManuallyEdited = false;
    let pdfJsModule = null;
    let metadataSuggestionRun = 0;

    function isFirefox() {
        return typeof navigator !== 'undefined' && /Firefox\//.test(navigator.userAgent);
    }

    function pageFitRect(wrapper, pageWidth, pageHeight) {
        const scale = Math.min(wrapper.clientWidth / pageWidth, wrapper.clientHeight / pageHeight);

        return {
            left: Math.max(0, (wrapper.clientWidth - pageWidth * scale) / 2),
            top: Math.max(0, (wrapper.clientHeight - pageHeight * scale) / 2),
            width: pageWidth * scale,
            height: pageHeight * scale
        };
    }

    let measureContext = null;

    function ensureMeasureFont() {
        const url = window.SignDocsUpload && window.SignDocsUpload.fonts && window.SignDocsUpload.fonts.regular ? window.SignDocsUpload.fonts.regular : '';

        return window.SignDocsStampUI.loadFont(url);
    }

    function textWidthPt(text, sizePt) {
        if (!measureContext) {
            measureContext = document.createElement('canvas').getContext('2d');
        }

        const family = window.SignDocsStampUI.fontLoaded() ? window.SignDocsStampUI.fontFamily() : 'sans-serif';
        measureContext.font = (sizePt * 96 / 72) + 'px ' + family;

        return measureContext.measureText(String(text || '')).width;
    }

    function stampPreviewMetrics(pageWidth) {
        const data = {
            stamp_font_size: field('stamp_font_size') || '8.4',
            stamp_padding: field('stamp_padding') || '5',
            stamp_qr_gap: field('stamp_qr_gap') || '5',
            stamp_qr_padding: field('stamp_qr_padding') || '5',
            stamp_line_spacing: field('stamp_line_spacing') || '1.25',
            stamp_rows: field('stamp_rows') || 'header,meta,signer,org',
            stamp_qr_enabled: field('stamp_qr_enabled'),
            stamp_qr_position: field('stamp_qr_position') || 'right',
            stamp_qr_size: field('stamp_qr_size') || '54',
            stamp_qr_ec_level: field('stamp_qr_ec_level') || 'h',
            local_signed_at: localSignedAt(),
            post_id: '',
            sha256_hash: '',
            signer_name: field('signer_name'),
            signer_position: field('signer_position'),
            organization: field('document_institution') || field('signer_organization')
        };
        const measure = function (text, sizePt) {
            return textWidthPt(text, sizePt);
        };
        const layout = window.SignDocsStampLayout.compute(data, measure, Math.max(120, pageWidth - 24 * 2));

        return layout ? { width: layout.width, height: layout.height } : { width: 0, height: 0 };
    }

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
            frame.style.display = '';
        }

        if (preview) {
            preview.style.display = 'none';
        }

        if (previewCanvas) {
            previewCanvas.remove();
            previewCanvas = null;
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

    async function renderCanvasPreview(file) {
        const preview = document.getElementById('sign-docs-pdf-preview');
        const wrapper = document.getElementById('sign-docs-preview-frame-wrap');

        if (!preview || !wrapper) {
            throw new Error('Preview container not found.');
        }

        const pdfjs = await loadPdfJs();
        const pdf = await pdfjs.getDocument({
            data: await file.arrayBuffer(),
            useWorkerFetch: false,
            isEvalSupported: false
        });

        try {
            const page = await pdf.getPage(1);
            const base = page.getViewport({ scale: 1 });

            preview.style.display = 'block';

            const rect = pageFitRect(wrapper, base.width, base.height);
            const dpr = window.devicePixelRatio > 1 ? window.devicePixelRatio : 1;
            const scale = (rect.width / base.width) * dpr;
            const viewport = page.getViewport({ scale });

            if (!previewCanvas || previewCanvas.parentNode !== wrapper) {
                previewCanvas = document.createElement('canvas');
                previewCanvas.setAttribute('aria-hidden', 'true');
                previewCanvas.style.position = 'absolute';
                previewCanvas.style.zIndex = '1';
                previewCanvas.style.pointerEvents = 'none';
                wrapper.insertBefore(previewCanvas, wrapper.firstChild);
            }

            previewCanvas.style.left = rect.left + 'px';
            previewCanvas.style.top = rect.top + 'px';
            previewCanvas.style.width = rect.width + 'px';
            previewCanvas.style.height = rect.height + 'px';
            previewCanvas.width = Math.max(1, Math.round(viewport.width));
            previewCanvas.height = Math.max(1, Math.round(viewport.height));

            const context = previewCanvas.getContext('2d');
            context.clearRect(0, 0, previewCanvas.width, previewCanvas.height);
            await page.render({ canvasContext: context, viewport: viewport }).promise;

            const frame = preview.querySelector('iframe');
            if (frame) {
                frame.style.display = 'none';
            }

            return { width: base.width, height: base.height };
        } catch (error) {
            if (previewCanvas) {
                previewCanvas.remove();
                previewCanvas = null;
            }
            const frame = preview.querySelector('iframe');
            if (frame) {
                frame.style.display = '';
            }
            throw error;
        } finally {
            try {
                if (typeof pdf.destroy === 'function') {
                    await pdf.destroy();
                }
            } catch (ignore) {
                // Ignore destroy errors.
            }
        }
    }

    async function showPdfPreview(file) {
        const preview = document.getElementById('sign-docs-pdf-preview');
        const frame = preview ? preview.querySelector('iframe') : null;

        clearPdfPreview();

        if (!preview || !frame || !file || (file.type !== 'application/pdf' && !/\.pdf$/i.test(file.name || ''))) {
            return;
        }

        if (isFirefox()) {
            try {
                previewPageSize = await renderCanvasPreview(file);
                syncManualStampLayer();
                updateManualStampControls(false);
                ensureMeasureFont().then(function () {
                    syncManualStampLayer();
                    updateManualStampControls(false);
                });
                return;
            } catch (error) {
                // Fall back to the iframe path; Firefox shows no preview there either.
            }
        }

        previewUrl = URL.createObjectURL(file);
        frame.src = previewUrl + '#page=1&toolbar=0&navpanes=0&scrollbar=0&view=Fit';
        preview.style.display = 'block';
        previewPageSize = await readPreviewPageSize(file);
        syncManualStampLayer();
        updateManualStampControls(false);
        ensureMeasureFont().then(function () {
            syncManualStampLayer();
            updateManualStampControls(false);
        });
    }

    function setHiddenField(name, value) {
        const input = form.querySelector('[name="' + name + '"]');

        if (input) {
            input.value = value;
        }
    }

    function setElementHidden(element, hidden) {
        if (!element) {
            return;
        }

        element.hidden = hidden;
        element.style.display = hidden ? 'none' : '';
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
        suggestMetadataFromFile(file);
    }

    async function loadPdfJs() {
        if (pdfJsModule) {
            return pdfJsModule;
        }

        const config = window.SignDocsUpload.pdfJs || {};
        if (!window.SignDocsUpload.hasPdfJs || !config.module || !config.worker) {
            throw new Error('PDF.js не найден в assets/vendor.');
        }

        pdfJsModule = await import(config.module);
        pdfJsModule.GlobalWorkerOptions.workerSrc = config.worker;

        return pdfJsModule;
    }

    function compactExtractedText(text) {
        return String(text || '')
            .replace(/\r\n/g, '\n')
            .replace(/\r/g, '\n')
            .replace(/[ \t]+/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    async function extractFirstPageText(file) {
        const pdfjs = await loadPdfJs();
        const loadingTask = pdfjs.getDocument({
            data: await file.arrayBuffer(),
            useWorkerFetch: false,
            isEvalSupported: false
        });

        const pdf = await loadingTask.promise;
        try {
            const page = await pdf.getPage(1);
            const textContent = await page.getTextContent();
            const parts = [];
            let lastY = null;

            textContent.items.forEach(function (item) {
                const value = String(item.str || '').trim();
                if (!value) {
                    return;
                }

                const y = item.transform && item.transform.length > 5 ? Math.round(item.transform[5]) : null;
                if (lastY !== null && y !== null && Math.abs(lastY - y) > 4) {
                    parts.push('\n');
                }
                parts.push(value);
                lastY = y;
            });

            return compactExtractedText(parts.join(' '));
        } finally {
            if (typeof pdf.destroy === 'function') {
                await pdf.destroy();
            }
        }
    }

    function rememberFieldValues() {
        const values = {};
        [
            'document_category',
            'document_type_label',
            'document_type_term_id',
            'document_institution',
            'document_date',
            'document_number',
            'post_title',
            'document_comment'
        ].forEach(function (name) {
            values[name] = field(name);
        });

        return values;
    }

    function setFieldIfUnchanged(name, value, initialValues) {
        const input = form.querySelector('[name="' + name + '"]');
        const nextValue = String(value || '').trim();

        if (!input || !nextValue) {
            return false;
        }

        const initialValue = Object.prototype.hasOwnProperty.call(initialValues, name) ? initialValues[name] : '';
        const currentValue = input.value.trim();
        if (currentValue && currentValue !== initialValue) {
            return false;
        }

        input.value = nextValue;
        return true;
    }

    function applySuggestedType(suggestion, initialValues) {
        const typeSelect = form.querySelector('[name="document_type_label"]');
        const typeTermInput = form.querySelector('[name="document_type_term_id"]');
        const termId = String(suggestion.document_type_term_id || '');
        const label = String(suggestion.document_type_label || '').trim();

        if (!typeSelect || (!termId && !label)) {
            return;
        }

        const currentValue = typeSelect.value.trim();
        const initialValue = initialValues.document_type_label || '';
        if (currentValue && currentValue !== initialValue) {
            return;
        }

        const matched = Array.prototype.find.call(typeSelect.options, function (option) {
            return (termId && option.dataset.termId === termId) || (label && option.value === label);
        });

        if (!matched) {
            return;
        }

        typeSelect.value = matched.value;
        if (typeTermInput) {
            typeTermInput.value = matched.dataset.termId || '';
        }
    }

    function applyMetadataSuggestion(suggestion, initialValues) {
        if (suggestion.document_category) {
            setFieldIfUnchanged('document_category', suggestion.document_category, initialValues);
            syncDocumentTypeOptions();
        }

        applySuggestedType(suggestion, initialValues);
        syncDocumentTypeOptions();

        setFieldIfUnchanged('document_institution', suggestion.document_institution, initialValues);
        setFieldIfUnchanged('document_date', suggestion.document_date, initialValues);
        setFieldIfUnchanged('document_number', suggestion.document_number, initialValues);

        syncInstitutionMode();
        syncDocumentTitle();

        if (suggestion.post_title && !titleManuallyEdited) {
            setFieldIfUnchanged('post_title', suggestion.post_title, initialValues);
        }
    }

    async function suggestMetadataFromFile(file) {
        const runId = metadataSuggestionRun + 1;
        metadataSuggestionRun = runId;

        if (!window.SignDocsUpload.aiAutofillEnabled || !file || (file.type !== 'application/pdf' && !/\.pdf$/i.test(file.name || ''))) {
            return;
        }

        const initialValues = rememberFieldValues();

        try {
            setStatus('Читаю текст первой страницы для автозаполнения...', 'info');
            const firstPageText = await extractFirstPageText(file);
            if (runId !== metadataSuggestionRun) {
                return;
            }

            if (firstPageText.length < 40) {
                setStatus('В первой странице не нашлось достаточно текстового слоя для AI-автозаполнения.', 'warning');
                return;
            }

            setStatus('Подбираю реквизиты документа через AI...', 'info');
            const data = new FormData();
            data.append('first_page_text', firstPageText.slice(0, 12000));
            data.append('source_filename', file.name || '');

            const suggestion = await postForm(window.SignDocsUpload.suggestMetadataUrl, data);
            if (runId !== metadataSuggestionRun) {
                return;
            }

            applyMetadataSuggestion(suggestion, initialValues);

            const warnings = Array.isArray(suggestion.warnings) ? suggestion.warnings.filter(Boolean) : [];
            const confidence = typeof suggestion.confidence === 'number' ? Math.round(suggestion.confidence * 100) : null;
            const message = confidence !== null
                ? 'AI предложил реквизиты документа. Уверенность: ' + confidence + '%.'
                : 'AI предложил реквизиты документа.';
            setStatus(warnings.length ? message + ' Проверьте: ' + warnings.join('; ') : message, warnings.length ? 'warning' : 'success');
        } catch (error) {
            if (runId === metadataSuggestionRun) {
                setStatus(error.message || 'Не удалось выполнить AI-автозаполнение.', 'warning');
            }
        }
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
            setElementHidden(button, unsignedOnly);
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

    function quoteSubject(value) {
        const subject = String(value || '').trim().replace(/^[«"']+|[»"']+$/g, '').trim();
        return subject ? '«' + subject + '»' : '';
    }

    function plainSubject(value) {
        return String(value || '').trim().replace(/^[«"']+|[»"']+$/g, '').trim();
    }

    function selectedDocumentTypeSlug() {
        const typeSelect = form.querySelector('[name="document_type_label"]');
        if (!typeSelect || !typeSelect.selectedOptions || !typeSelect.selectedOptions[0]) {
            return '';
        }

        return String(typeSelect.selectedOptions[0].getAttribute('data-type-slug') || '').trim();
    }

    function matchingTitleRule() {
        const config = window.SignDocsUpload && window.SignDocsUpload.titleRules ? window.SignDocsUpload.titleRules : null;
        const rules = config && Array.isArray(config.rules) ? config.rules : [];
        const category = inputValue('document_category');
        const typeSlug = selectedDocumentTypeSlug();

        for (let i = 0; i < rules.length; i += 1) {
            const rule = rules[i] || {};
            if (String(rule.enabled || '0') !== '1') {
                continue;
            }
            if (String(rule.type_slug || '') !== typeSlug) {
                continue;
            }
            if (String(rule.category || '') && String(rule.category || '') !== category) {
                continue;
            }

            return rule;
        }

        return null;
    }

    function titleComponentValue(name, rule) {
        if (name === 'document_institution') {
            return '';
        }
        if (name === 'document_number') {
            var num = inputValue(name).replace(/^[№\s]+/, '').trim();
            return num ? '№ ' + num : '';
        }

        return inputValue(name);
    }

    function composeDocumentTitle() {
        const rule = matchingTitleRule();
        if (rule && Array.isArray(rule.parts)) {
            const parts = [];
            rule.parts.forEach(function (part) {
                const value = titleComponentValue(String(part || ''), rule);
                if (value) {
                    parts.push(value);
                }
            });

            if (parts.length) {
                return parts.join(String(rule.separator || ' ')).replace(/\s+/g, ' ').trim();
            }
        }

        const useQuotes = !!(form.querySelector('[name="include_subject_quotes_in_title"]') && form.querySelector('[name="include_subject_quotes_in_title"]').checked);
        const subject = inputValue('document_subject');

        return useQuotes ? quoteSubject(subject) : plainSubject(subject);
    }

    function syncDocumentTitle() {
        const titleInput = form.querySelector('[name="post_title"]');
        const title = composeDocumentTitle();

        if (!title) {
            return;
        }

        if (titleInput && !titleManuallyEdited) {
            titleInput.value = title;
        }
    }

    function signingBaseDate() {
        // The signature date is fixed when the document is submitted, so presets use the current local date.
        return new Date();
    }

    function shortAcademicYear(startYear) {
        return String(startYear) + '/' + String((startYear + 1) % 100).padStart(2, '0');
    }

    function academicYearStart(date) {
        const month = date.getMonth();
        const year = date.getFullYear();
        return month >= 8 ? year : year - 1;
    }

    function yearPresetGroups() {
        const base = signingBaseDate();
        const academicStart = academicYearStart(base);
        const calendarYear = base.getFullYear();

        return [
            {
                label: 'Учебный',
                items: [
                    { label: shortAcademicYear(academicStart - 1), value: 'за ' + shortAcademicYear(academicStart - 1) + ' учебный год' },
                    { label: shortAcademicYear(academicStart), value: 'на ' + shortAcademicYear(academicStart) + ' учебный год' },
                    { label: shortAcademicYear(academicStart + 1), value: 'на ' + shortAcademicYear(academicStart + 1) + ' учебный год' }
                ]
            },
            {
                label: 'Календарный',
                items: [
                    { label: String(calendarYear - 1), value: 'за ' + String(calendarYear - 1) + ' год' },
                    { label: String(calendarYear), value: 'на ' + String(calendarYear) + ' год' },
                    { label: String(calendarYear + 1), value: 'на ' + String(calendarYear + 1) + ' год' }
                ]
            }
        ];
    }

    function renderYearPresetButtons() {
        const container = form.querySelector('.sign-docs-year-actions');
        const titleInput = form.querySelector('[name="post_title"]');
        if (!container || !titleInput) {
            return;
        }

        container.innerHTML = '';
        yearPresetGroups().forEach(function (group) {
            const groupEl = document.createElement('span');
            groupEl.className = 'sign-docs-year-actions__group';
            const label = document.createElement('strong');
            label.textContent = group.label + ':';
            groupEl.appendChild(label);

            group.items.forEach(function (item) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button button-small';
                button.textContent = item.label;
                button.addEventListener('click', function () {
                    var currentTitle = titleInput.value.trim();
                    titleInput.value = currentTitle ? currentTitle + ' ' + item.value : item.value;
                    titleManuallyEdited = true;
                });
                groupEl.appendChild(button);
            });

            container.appendChild(groupEl);
        });
    }

    function applySentenceCase() {
        const titleInput = form.querySelector('[name="post_title"]');
        if (!titleInput) {
            return;
        }

        var source = titleInput.value.trim();
        if (!source) {
            return;
        }

        var lower = source.toLocaleLowerCase('ru-RU');
        titleInput.value = lower ? lower.charAt(0).toLocaleUpperCase('ru-RU') + lower.slice(1) : '';
        titleManuallyEdited = true;
    }

    function formatDateInput(value) {
        var digits = String(value || '').replace(/\D/g, '');
        if (!digits) return '';
        var formatted = digits.substring(0, 2);
        if (digits.length > 2) formatted += '.' + digits.substring(2, 4);
        if (digits.length > 4) formatted += '.' + digits.substring(4, 8);
        return formatted;
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
        const institutionInput = form.querySelector('[name="document_institution"]');
        const defaultInstitutionInput = form.querySelector('[name="default_institution"]');
        const defaultInstitution = defaultInstitutionInput ? defaultInstitutionInput.value.trim() : '';

        if (institutionRow) {
            institutionRow.hidden = isLocalAct();
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
        const metrics = stampPreviewMetrics(pageWidth);
        const scaleX = layer.clientWidth / pageWidth;
        const scaleY = layer.clientHeight / pageHeight;

        return {
            width: Math.max(60, Math.min(layer.clientWidth, metrics.width * scaleX)),
            height: Math.max(40, Math.min(layer.clientHeight, metrics.height * scaleY))
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
        const rect = pageFitRect(wrapper, pageWidth, pageHeight);

        layer.style.left = rect.left + 'px';
        layer.style.top = rect.top + 'px';
        layer.style.width = rect.width + 'px';
        layer.style.height = rect.height + 'px';
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
        let isManual = field('stamp_placement_mode') === 'manual';
        const unsignedOnly = categoryRequiresUnsigned();
        const rect = document.getElementById('sign-docs-stamp-preview-rect');

        manualStampPicking = active;

        if (unsignedOnly) {
            manualStampPicking = false;
            active = false;
            selectedStampPosition = null;
            setHiddenField('stamp_placement_mode', 'corner');
            setHiddenField('stamp_manual_x', '');
            setHiddenField('stamp_manual_y', '');
            isManual = false;
        }

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
            setElementHidden(pickButton, unsignedOnly);
            pickButton.textContent = active ? 'Отменить выбор места' : 'Выбрать место штампа';
        }

        if (resetButton) {
            setElementHidden(resetButton, unsignedOnly || !isManual);
        }

        if (status) {
            setElementHidden(status, unsignedOnly);
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

        if (!titleInput || !file) {
            return;
        }

        titleInput.value = String(file.name || '')
            .replace(/\.[^.]+$/, '')
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        titleManuallyEdited = false;
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
        return window.SignDocsStampUI.hexToRgb(hex);
    }

    function hexToCss(hex) {
        return window.SignDocsStampUI.hexToCss(hex);
    }

    function stampOpacity(data) {
        return window.SignDocsStampUI.stampOpacity(data);
    }

    function stampFontSize(data) {
        const fontSize = Number.parseFloat(data.stamp_font_size || '8.4');
        if (Number.isNaN(fontSize)) {
            return 8.4;
        }

        return Math.min(12, Math.max(6, fontSize));
    }

    function qrLogoEnabled(data) {
        return data.qr_logo_enabled !== '0' && data.qr_logo_enabled !== false;
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
        const regular = fonts.regular;
        const margin = 24;
        const maxWidth = Math.max(120, size.width - margin * 2);
        const layout = window.SignDocsStampLayout.compute(
            data,
            function (text, fontSize) {
                return regular.widthOfTextAtSize(String(text || ''), fontSize);
            },
            maxWidth
        );

        if (!layout) {
            return;
        }

        const origin = manualStampOrigin(size, layout.width, layout.height, data)
            || stampOrigin(size, layout.width, layout.height, data.stamp_corner || 'top-left');
        const x = origin.x;
        const y = origin.y;
        const color = hexToRgb(data.stamp_color);
        const mainColor = PDFLib.rgb(color.r, color.g, color.b);
        const opacity = stampOpacity(data);

        if (layout.hasBorder) {
            page.drawRectangle({
                x: x,
                y: y,
                width: layout.width,
                height: layout.height,
                borderColor: mainColor,
                borderWidth: 1.2,
                borderOpacity: opacity
            });
        }

        layout.lines.forEach(function (line) {
            page.drawText(line.text, {
                x: x + layout.textX,
                y: y + (layout.height - line.y),
                size: layout.fontSize,
                font: regular,
                color: mainColor,
                opacity: opacity,
                maxWidth: line.drawWidth
            });
        });

        if (layout.qr && qrImage) {
            const qrBottom = y + (layout.height - layout.qr.top - layout.qr.size);
            page.drawImage(qrImage, {
                x: x + layout.qr.left,
                y: qrBottom,
                width: layout.qr.size,
                height: layout.qr.size,
                opacity: opacity
            });

            addUriLink(
                pdfDoc,
                page,
                [
                    x + layout.qr.left,
                    qrBottom,
                    x + layout.qr.left + layout.qr.size,
                    qrBottom + layout.qr.size
                ],
                data.verification_url
            );
        }
    }

    function drawFooterStamp(pdfDoc, page, data, fonts) {
        const PDFLib = window.PDFLib;
        const size = page.getSize();
        const margin = Math.min(30, Math.max(16, size.width * 0.04));
        const borderEnabled = !(data.stamp_footer_border_enabled === '0' || data.stamp_footer_border_enabled === false);
        const fontSize = clamp(parseFloat(data.stamp_footer_font_size) || 6.4, 5, 12);
        const footerOpacity = clamp(parseFloat(data.stamp_footer_opacity) || 1, 0.1, 1);
        const top = data.stamp_footer_position === 'top';
        const barHeight = borderEnabled ? 28 : Math.max(12, fontSize + 6);
        const width = size.width - margin * 2;
        const y = top ? (size.height - margin - barHeight) : margin;
        const padX = borderEnabled ? 10 : 0;
        const color = hexToRgb(data.stamp_color);
        const mainColor = PDFLib.rgb(color.r, color.g, color.b);
        const text = 'SHA-256 исходного PDF: ' + data.sha256_hash + '  Проверка: ' + data.verification_url;

        if (borderEnabled) {
            page.drawRectangle({
                x: margin,
                y: y,
                width: width,
                height: barHeight,
                borderColor: mainColor,
                borderWidth: 0.5,
                borderOpacity: footerOpacity
            });
        }

        page.drawText(text, {
            x: margin + padX,
            y: y + (borderEnabled ? 10 : 2),
            size: fontSize,
            font: fonts.regular,
            color: mainColor,
            opacity: footerOpacity,
            maxWidth: width - padX * 2
        });

        addUriLink(pdfDoc, page, [margin, y, margin + width, y + barHeight], data.verification_url);
    }

    async function stampPdf(file, data) {
        const PDFLib = window.PDFLib;
        const bytes = await file.arrayBuffer();
        const pdfDoc = await PDFLib.PDFDocument.load(bytes, {
            ignoreEncryption: true,
            updateMetadata: false
        });
        const fonts = await embedGolosFonts(pdfDoc);
        const stampData = Object.assign({}, data);
        let qrImage = null;

        if (!(data.stamp_qr_enabled === '0' || data.stamp_qr_enabled === false)) {
            const icon = qrLogoEnabled(data) && window.SignDocsUpload.siteIconUrl
                ? await window.SignDocsStampUI.loadImage(window.SignDocsUpload.siteIconUrl)
                : null;
            const qrCanvas = window.SignDocsStampUI.qrCanvas(data.verification_url, hexToCss(data.stamp_color), icon, data.stamp_qr_ec_level);

            if (qrCanvas) {
                qrImage = await pdfDoc.embedPng(dataUrlToBytes(qrCanvas.toDataURL('image/png')));
            }
        }

        const pages = pdfDoc.getPages();
        const last = pages.length - 1;
        const stampMode = data.stamp_page === 'last' ? 'last' : (data.stamp_page === 'both' ? 'both' : 'first');
        const stampFirst = stampMode === 'first' || stampMode === 'both';
        const stampLast = stampMode === 'last' || stampMode === 'both';
        const footerOn = !(data.stamp_footer_enabled === '0' || data.stamp_footer_enabled === false);

        pages.forEach(function (page, index) {
            if (stampFirst && index === 0) {
                drawFirstPageStamp(pdfDoc, page, stampData, fonts, qrImage);
            } else if (stampLast && index === last) {
                drawFirstPageStamp(pdfDoc, page, stampData, fonts, qrImage);
            } else if (footerOn) {
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
            prepareData.append('document_comment', field('document_comment'));
            prepareData.append('document_category', field('document_category'));
            prepareData.append('document_type_label', field('document_type_label'));
            prepareData.append('document_type_term_id', field('document_type_term_id'));
            prepareData.append('document_institution', field('document_institution'));
            prepareData.append('document_date', field('document_date'));
            prepareData.append('document_number', field('document_number'));
            prepareData.append('signer_name', field('signer_name'));
            prepareData.append('signer_position', field('signer_position'));
            prepareData.append('signer_organization', field('signer_organization'));
            prepareData.append('stamp_corner', field('stamp_corner') || 'top-left');
            prepareData.append('stamp_color', field('stamp_color') || '#2e7d32');
            prepareData.append('stamp_opacity', field('stamp_opacity') || '1');
            prepareData.append('stamp_font_size', field('stamp_font_size') || '8.4');
            prepareData.append('stamp_border_enabled', field('stamp_border_enabled') === '0' ? '0' : '1');
            prepareData.append('stamp_padding', field('stamp_padding') || '5');
            prepareData.append('stamp_qr_gap', field('stamp_qr_gap') || '5');
            prepareData.append('stamp_qr_padding', field('stamp_qr_padding') || '5');
            prepareData.append('stamp_line_spacing', field('stamp_line_spacing') || '1.25');
            prepareData.append('stamp_rows', field('stamp_rows') || 'header,meta,signer,org');
            prepareData.append('stamp_qr_enabled', field('stamp_qr_enabled') === '0' ? '0' : '1');
            prepareData.append('stamp_qr_position', field('stamp_qr_position') || 'right');
            prepareData.append('stamp_qr_size', field('stamp_qr_size') || '54');
            prepareData.append('stamp_qr_ec_level', field('stamp_qr_ec_level') || 'h');
            prepareData.append('stamp_page', field('stamp_page') || 'first');
            prepareData.append('stamp_footer_enabled', field('stamp_footer_enabled') === '0' ? '0' : '1');
            prepareData.append('stamp_footer_border_enabled', field('stamp_footer_border_enabled') === '0' ? '0' : '1');
            prepareData.append('stamp_footer_font_size', field('stamp_footer_font_size') || '6.4');
            prepareData.append('stamp_footer_opacity', field('stamp_footer_opacity') || '1');
            prepareData.append('stamp_footer_position', field('stamp_footer_position') || 'bottom');
            prepareData.append('stamp_placement_mode', field('stamp_placement_mode') || 'corner');
            prepareData.append('stamp_manual_x', field('stamp_manual_x'));
            prepareData.append('stamp_manual_y', field('stamp_manual_y'));
            prepareData.append('qr_logo_enabled', field('qr_logo_enabled') === '0' ? '0' : '1');
            prepareData.append('replaces_post_id', field('replaces_post_id'));

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

    var categoryInput = form.querySelector('[name="document_category"]');
    if (categoryInput) {
        var savedCategory = window.localStorage.getItem('sign_docs_last_category');
        if (savedCategory && categoryInput.querySelector('option[value="' + savedCategory.replace(/"/g, '') + '"]')) {
            categoryInput.value = savedCategory;
        }
    }

    ['document_category', 'document_type_label', 'document_institution', 'document_date', 'document_number'].forEach(function (name) {
        const input = form.querySelector('[name="' + name + '"]');
        if (!input) {
            return;
        }

        const handler = function () {
            if (name === 'document_date') {
                renderYearPresetButtons();
            }
            if (name === 'document_category') {
                window.localStorage.setItem('sign_docs_last_category', input.value);
            }
            if (name === 'document_type_label') {
                window.localStorage.setItem('sign_docs_last_type', input.value);
            }
            (name === 'document_category' || name === 'document_type_label' ? syncDocumentTypeOptions : syncDocumentTitle)();
        };

        input.addEventListener('input', handler);
        input.addEventListener('change', handler);
    });

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

    const addInstitutionBtn = document.getElementById('sign-docs-add-institution-to-title');
    if (addInstitutionBtn && institutionInput) {
        addInstitutionBtn.addEventListener('click', function () {
            var institutionValue = institutionInput.value.trim();
            if (!institutionValue) {
                return;
            }

            var titleInput = form.querySelector('[name="post_title"]');
            if (!titleInput) {
                return;
            }

            var currentTitle = titleInput.value.trim();
            titleInput.value = currentTitle ? currentTitle + ' ' + institutionValue : institutionValue;
            titleManuallyEdited = true;
        });
    }

    var dateInput = form.querySelector('[name="document_date"]');
    if (dateInput) {
        dateInput.addEventListener('input', function () {
            dateInput.value = formatDateInput(dateInput.value);
        });
    }

    const titleInput = form.querySelector('[name="post_title"]');
    if (titleInput) {
        titleInput.addEventListener('input', function () {
            titleManuallyEdited = true;
        });
    }

    renderYearPresetButtons();

    var sentenceBtn = document.getElementById('sign-docs-sentence-case');
    if (sentenceBtn) {
        sentenceBtn.addEventListener('click', applySentenceCase);
    }

    syncDocumentTypeOptions();

    var typeSelect = form.querySelector('[name="document_type_label"]');
    var typeTermInput = form.querySelector('[name="document_type_term_id"]');
    var savedType = window.localStorage.getItem('sign_docs_last_type');
    if (typeSelect && savedType) {
        Array.prototype.forEach.call(typeSelect.options, function (option) {
            if (option.value === savedType && !option.disabled) {
                typeSelect.value = savedType;
                if (typeTermInput) {
                    typeTermInput.value = option.dataset.termId || '';
                }
                syncDocumentTitle();
                syncUploadMode();
            }
        });
    }

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
