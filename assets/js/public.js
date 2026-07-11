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
}());
