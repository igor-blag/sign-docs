(function () {
    'use strict';

    const __ = window.wp && window.wp.i18n && window.wp.i18n.__ ? window.wp.i18n.__ : function (text) { return text; };

    document.addEventListener('click', function (event) {
        document.querySelectorAll('.sign-docs-document-link__details[open]').forEach(function (details) {
            if (!details.contains(event.target)) {
                details.removeAttribute('open');
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('.sign-docs-document-link__details[open]').forEach(function (details) {
            details.removeAttribute('open');
        });
    });

    function bytesToHex(buffer) {
        return Array.prototype.map.call(new Uint8Array(buffer), function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
    }

    function setCheckerResult(checker, message, status) {
        const result = checker.querySelector('[data-sign-docs-checker-result]');

        if (!result) {
            return;
        }

        result.textContent = message;
        result.dataset.status = status || '';
    }

    async function hashFile(file) {
        const buffer = await file.arrayBuffer();
        const digest = await window.crypto.subtle.digest('SHA-256', buffer);

        return bytesToHex(digest);
    }

    document.addEventListener('change', async function (event) {
        const input = event.target.closest('[data-sign-docs-checker-input]');

        if (!input) {
            return;
        }

        const checker = input.closest('[data-sign-docs-checker]');
        const file = input.files && input.files[0] ? input.files[0] : null;

        if (!checker || !file) {
            return;
        }

        if (!window.crypto || !window.crypto.subtle) {
            setCheckerResult(checker, __('В этом браузере недоступен расчет SHA-256.', 'sign-docs'), 'error');
            return;
        }

        setCheckerResult(checker, __('Считаю SHA-256 выбранного файла...', 'sign-docs'), 'pending');

        try {
            const hash = await hashFile(file);
            const originalHash = (checker.dataset.originalHash || '').toLowerCase();
            const stampedHash = (checker.dataset.stampedHash || '').toLowerCase();

            if (originalHash && hash === originalHash) {
                setCheckerResult(checker, __('Файл совпадает с контрольной исходной копией.', 'sign-docs'), 'success');
                return;
            }

            if (stampedHash && hash === stampedHash) {
                setCheckerResult(checker, __('Файл совпадает с публичной PDF-копией с отметкой.', 'sign-docs'), 'success');
                return;
            }

            setCheckerResult(checker, __('Файл не совпадает с контрольной исходной копией или публичной копией с отметкой.', 'sign-docs'), 'error');
        } catch (error) {
            setCheckerResult(checker, __('Не удалось рассчитать SHA-256 выбранного файла.', 'sign-docs'), 'error');
        }
    });

    function setPreviewMessage(block, message, isError) {
        const body = block.querySelector('[data-sign-docs-preview-body]');

        if (!body) {
            return;
        }

        body.textContent = '';
        const p = document.createElement('p');
        p.className = 'sign-docs-verification__preview-loading';
        if (isError) {
            p.dataset.status = 'error';
        }
        p.textContent = message;
        body.appendChild(p);
    }

    function renderPdfPreview(block, url) {
        const config = window.SignDocsPreview || {};

        if (!config.hasPdfJs || !config.module) {
            block.remove();
            return;
        }

        const body = block.querySelector('[data-sign-docs-preview-body]');

        if (!body) {
            return;
        }

        block.hidden = false;

        setPreviewMessage(block, __('Загружаю предпросмотр…', 'sign-docs'));

        (async function () {
            try {
                const pdfjs = await import(/* webpackIgnore: true */ config.module);
                pdfjs.GlobalWorkerOptions.workerSrc = config.worker;

                const buffer = await fetch(url).then(function (response) {
                    return response.arrayBuffer();
                });
                const doc = await pdfjs.getDocument({ data: buffer }).promise;

                body.textContent = '';
                body.classList.add('is-loaded');

                const wrap = document.createElement('div');
                wrap.className = 'sign-docs-verification__preview-scroll';
                body.appendChild(wrap);

                const defaultScale = 1.0;
                for (let i = 0; i < doc.numPages; i += 1) {
                    const page = await doc.getPage(i + 1);
                    const viewport = page.getViewport({ scale: defaultScale });
                    const canvas = document.createElement('canvas');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    canvas.className = 'sign-docs-verification__preview-page';
                    canvas.setAttribute('aria-label', (i + 1) + ' / ' + doc.numPages);
                    wrap.appendChild(canvas);
                    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                }
            } catch (error) {
                setPreviewMessage(block, __('Не удалось показать предпросмотр документа.', 'sign-docs'), true);
            }
        }());
    }

    document.querySelectorAll('[data-sign-docs-preview]').forEach(function (block) {
        const url = block.getAttribute('data-pdf-url');

        if (url) {
            renderPdfPreview(block, url);
        }
    });
}());
