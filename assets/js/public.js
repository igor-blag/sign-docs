(function () {
    'use strict';

    const __ = window.wp && window.wp.i18n && window.wp.i18n.__ ? window.wp.i18n.__ : function (text) { return text; };
    const sprintf = window.wp && window.wp.i18n && window.wp.i18n.sprintf ? window.wp.i18n.sprintf : function (format) {
        const args = Array.prototype.slice.call(arguments, 1);
        let index = 0;
        return String(format).replace(/%%|%(\d+)\$([sd])|%([sd])/g, function (match, pos, kind, plain) {
            if (match === '%%') {
                return '%';
            }
            const argIndex = pos ? parseInt(pos, 10) - 1 : index++;
            const value = args[argIndex];
            return value === undefined || value === null ? '' : String(value);
        });
    };

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
            setCheckerResult(checker, __('SHA-256 calculation is not available in this browser.', 'sign-docs'), 'error');
            return;
        }

        setCheckerResult(checker, __('Calculating SHA-256 of the selected file...', 'sign-docs'), 'pending');

        try {
            const hash = await hashFile(file);
            const originalHash = (checker.dataset.originalHash || '').toLowerCase();
            const stampedHash = (checker.dataset.stampedHash || '').toLowerCase();

            if (originalHash && hash === originalHash) {
                setCheckerResult(checker, __('The file matches the control original copy.', 'sign-docs'), 'success');
                return;
            }

            if (stampedHash && hash === stampedHash) {
                setCheckerResult(checker, __('The file matches the public PDF copy with the stamp.', 'sign-docs'), 'success');
                return;
            }

            setCheckerResult(checker, __('The file does not match the control original copy or the public stamped copy.', 'sign-docs'), 'error');
        } catch (error) {
            setCheckerResult(checker, __('Could not calculate the SHA-256 of the selected file.', 'sign-docs'), 'error');
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

        const pageLimit = parseInt(block.getAttribute('data-preview-pages') || '0', 10);
        const limit = Number.isNaN(pageLimit) ? 0 : Math.max(0, pageLimit);

        block.hidden = false;

        setPreviewMessage(block, __('Loading preview…', 'sign-docs'));

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

                const renderCount = limit > 0 ? Math.min(limit, doc.numPages) : doc.numPages;

                for (let i = 0; i < renderCount; i += 1) {
                    const page = await doc.getPage(i + 1);
                    const viewport = page.getViewport({ scale: 1.0 });
                    const canvas = document.createElement('canvas');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    canvas.className = 'sign-docs-verification__preview-page';
                    canvas.setAttribute('aria-label', (i + 1) + ' / ' + doc.numPages);
                    wrap.appendChild(canvas);
                    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                }

                if (renderCount < doc.numPages) {
                    const more = document.createElement('p');
                    more.className = 'sign-docs-verification__preview-more';
                    more.textContent = sprintf(__('Showing the first %1$d of %2$d pages.', 'sign-docs'), renderCount, doc.numPages);
                    wrap.appendChild(more);
                }
            } catch (error) {
                setPreviewMessage(block, __('Could not display the document preview.', 'sign-docs'), true);
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
