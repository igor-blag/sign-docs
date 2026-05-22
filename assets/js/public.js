(function () {
    'use strict';

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
}());
