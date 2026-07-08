(function (blocks, blockEditor, components, element, i18n, apiFetch) {
    'use strict';

    const el = element.createElement;
    const __ = i18n.__;
    const useEffect = element.useEffect;
    const useRef = element.useRef;
    const useState = element.useState;
    const useBlockProps = blockEditor.useBlockProps;
    const InspectorControls = blockEditor.InspectorControls;
    const BlockControls = blockEditor.BlockControls;
    const RichText = blockEditor.RichText;
    const config = window.SignDocsBlock || {};
    const defaults = config.defaults || {};
    const filters = config.filters || {};

    const statusOptions = [
        { label: __('Any status', 'sign-docs'), value: '' },
        { label: __('Active', 'sign-docs'), value: 'active' },
        { label: __('Unsigned', 'sign-docs'), value: 'unsigned' },
        { label: __('Needs public copy', 'sign-docs'), value: 'needs_public_copy' },
        { label: __('Archived', 'sign-docs'), value: 'archived' },
        { label: __('Replaced', 'sign-docs'), value: 'replaced' }
    ];

    const displayModeOptions = [
        { label: __('Text link', 'sign-docs'), value: 'link' },
        { label: __('Button', 'sign-docs'), value: 'button' },
        { label: __('Card', 'sign-docs'), value: 'card' }
    ];

    const interactionModeOptions = [
        { label: __('Title opens document details', 'sign-docs'), value: 'title-details' },
        { label: __('Title with download and details buttons', 'sign-docs'), value: 'buttons' }
    ];

    function text(value) {
        return String(value || '').trim();
    }

    function documentTitle(document) {
        return text(document.fullTitle) || text(document.title) || __('Signed document', 'sign-docs');
    }

    function optionList(label, items, valueKey) {
        return [{ label: label, value: '' }].concat((items || []).map(function (item) {
            return { label: item.name, value: String(item[valueKey || 'id']) };
        }));
    }

    function documentTypesForCategory(category) {
        return (filters.types || []).filter(function (item) {
            return !item.category || item.category === category;
        });
    }

    function queryString(params) {
        const query = new URLSearchParams();

        Object.keys(params).forEach(function (key) {
            if (params[key] !== undefined && params[key] !== null && String(params[key]) !== '') {
                query.set(key, params[key]);
            }
        });

        return query.toString();
    }

    function fetchDocuments(params) {
        return apiFetch({ path: (config.documentsPath || '/sign-docs/v1/documents') + '?' + queryString(params || {}) });
    }

    async function postForm(url, data) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': config.nonce },
            body: data
        });
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message || __('Request failed.', 'sign-docs'));
        }

        return payload;
    }

    async function fetchBytes(url) {
        const response = await fetch(url, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(__('Could not load a local asset.', 'sign-docs'));
        }

        return await response.arrayBuffer();
    }

    function dataUrlToBytes(dataUrl) {
        const binary = atob(dataUrl.split(',')[1]);
        const bytes = new Uint8Array(binary.length);

        for (let i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }

        return bytes;
    }

    function hexToCss(hex) {
        const normalized = String(hex || '#2e7d32').trim();

        return /^#[0-9a-f]{6}$/i.test(normalized) ? normalized : '#2e7d32';
    }

    function hexToRgb(hex) {
        const value = hexToCss(hex).replace('#', '');

        return {
            r: parseInt(value.slice(0, 2), 16) / 255,
            g: parseInt(value.slice(2, 4), 16) / 255,
            b: parseInt(value.slice(4, 6), 16) / 255
        };
    }

    function stampOpacity(value) {
        const parsed = Number.parseFloat(value || '1');

        return Number.isNaN(parsed) ? 1 : Math.min(1, Math.max(0.1, parsed));
    }

    function stampWidthPoints(data, pageWidth) {
        const widthMm = Number.parseFloat(data.stamp_width_mm || '100');
        const points = (Number.isNaN(widthMm) ? 100 : Math.min(160, Math.max(70, widthMm))) * 72 / 25.4;

        return Math.min(points, pageWidth - 48);
    }

    function stampFontSize(data) {
        const fontSize = Number.parseFloat(data.stamp_font_size || '8.4');

        return Number.isNaN(fontSize) ? 8.4 : Math.min(12, Math.max(6, fontSize));
    }

    function stampBorderEnabled(data) {
        return data.stamp_border_enabled !== '0' && data.stamp_border_enabled !== false;
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function normalizeNumber(value) {
        const number = String(value || '').trim().replace(/^№\s*/, '');
        return number ? '№ ' + number : '';
    }

    function quoteSubject(value) {
        const subject = String(value || '').trim().replace(/^[«"']+|[»"']+$/g, '').trim();
        return subject ? '«' + subject + '»' : '';
    }

    function subjectCase(value, mode) {
        const source = String(value || '').trim();
        if (mode === 'upper') {
            return source.toLocaleUpperCase('ru-RU');
        }
        if (mode === 'lower') {
            return source.toLocaleLowerCase('ru-RU');
        }

        const lower = source.toLocaleLowerCase('ru-RU');
        return lower ? lower.charAt(0).toLocaleUpperCase('ru-RU') + lower.slice(1) : '';
    }

    function compactSignedAt(value) {
        const source = String(value || '').trim();
        const match = source.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);

        return match ? match[3] + '.' + match[2] + '.' + match[1] + ', ' + match[4] + ':' + match[5] : source;
    }

    function wrapText(font, source, size, maxWidth, maxLines) {
        const words = String(source || '').replace(/\s+/g, ' ').trim().split(' ').filter(Boolean);
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

        if (lines.length <= maxLines) {
            return lines;
        }

        const limited = lines.slice(0, maxLines);
        let last = limited[limited.length - 1] || '';
        while (last.length > 1 && font.widthOfTextAtSize(last + '...', size) > maxWidth) {
            last = last.slice(0, -1).trim();
        }
        limited[limited.length - 1] = last + '...';

        return limited;
    }

    async function makeQrCanvas(value, color) {
        const qr = window.qrcode(0, 'H');
        const modulesScale = 4;
        const quiet = 4;

        qr.addData(value);
        qr.make();

        const modules = qr.getModuleCount();
        const canvas = document.createElement('canvas');
        const size = (modules + quiet * 2) * modulesScale;
        const context = canvas.getContext('2d');

        canvas.width = size;
        canvas.height = size;
        context.clearRect(0, 0, size, size);
        context.fillStyle = color || '#2e7d32';

        for (let row = 0; row < modules; row += 1) {
            for (let col = 0; col < modules; col += 1) {
                if (qr.isDark(row, col)) {
                    context.fillRect((col + quiet) * modulesScale, (row + quiet) * modulesScale, modulesScale, modulesScale);
                }
            }
        }

        return canvas;
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

    async function embedFonts(pdfDoc) {
        const fontkit = window.fontkit || window.Fontkit;
        pdfDoc.registerFontkit(fontkit);

        return {
            regular: await pdfDoc.embedFont(await fetchBytes(config.fonts.regular), { subset: true }),
            medium: await pdfDoc.embedFont(await fetchBytes(config.fonts.medium), { subset: true })
        };
    }

    function drawFirstPageStamp(pdfDoc, page, data, fonts, qrImage) {
        const PDFLib = window.PDFLib;
        const pageSize = page.getSize();
        const stampWidth = stampWidthPoints(data, pageSize.width);
        const fontSize = stampFontSize(data);
        const lineHeight = fontSize * 1.25;
        const qrSize = 54;
        const padding = 8;
        const textWidth = stampWidth - qrSize - padding * 3;
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
        rows.push({ text: 'Signed: ' + compactSignedAt(data.signed_at), size: fontSize * 0.83, font: fonts.regular });

        const stampHeight = Math.max(82, padding * 2 + rows.length * lineHeight + 4);
        const corner = data.stamp_corner || 'top-left';
        const right = corner === 'top-right' || corner === 'bottom-right';
        const bottom = corner === 'bottom-left' || corner === 'bottom-right';
        const manualX = Number.parseFloat(data.stamp_manual_x || '');
        const manualY = Number.parseFloat(data.stamp_manual_y || '');
        const hasManual = data.stamp_placement_mode === 'manual' && !Number.isNaN(manualX) && !Number.isNaN(manualY);
        const x = hasManual ? clamp(manualX, 0, 1) * Math.max(0, pageSize.width - stampWidth) : (right ? pageSize.width - stampWidth - 24 : 24);
        const y = hasManual ? (1 - clamp(manualY, 0, 1)) * Math.max(0, pageSize.height - stampHeight) : (bottom ? 24 : pageSize.height - stampHeight - 24);
        const color = hexToRgb(data.stamp_color);
        const mainColor = PDFLib.rgb(color.r, color.g, color.b);
        const alpha = stampOpacity(data.stamp_opacity);
        const qrX = x + stampWidth - qrSize - padding;
        const qrY = y + 24;
        let textY = y + stampHeight - padding - fontSize;

        if (stampBorderEnabled(data)) {
            page.drawRectangle({
                x: x,
                y: y,
                width: stampWidth,
                height: stampHeight,
                borderColor: mainColor,
                borderWidth: 1.2,
                borderOpacity: alpha
            });
        }

        rows.forEach(function (row) {
            page.drawText(row.text, { x: x + padding, y: textY, size: row.size, font: row.font, color: mainColor, opacity: alpha, maxWidth: textWidth });
            textY -= lineHeight;
        });

        page.drawImage(qrImage, { x: qrX, y: qrY, width: qrSize, height: qrSize, opacity: alpha });
        addUriLink(pdfDoc, page, [qrX, qrY, qrX + qrSize, qrY + qrSize], data.verification_url);
    }

    function drawFooterStamp(pdfDoc, page, data, fonts) {
        const PDFLib = window.PDFLib;
        const pageSize = page.getSize();
        const margin = Math.min(30, Math.max(16, pageSize.width * 0.04));
        const width = pageSize.width - margin * 2;
        const color = hexToRgb(data.stamp_color);
        const mainColor = PDFLib.rgb(color.r, color.g, color.b);
        const alpha = stampOpacity(data.stamp_opacity);
        const line = 'SHA-256: ' + data.sha256_hash + '  URL: ' + data.verification_url;

        page.drawRectangle({ x: margin, y: 18, width: width, height: 28, borderColor: mainColor, borderWidth: 0.5, borderOpacity: alpha });
        page.drawText(line, { x: margin + 10, y: 28, size: 6.4, font: fonts.regular, color: mainColor, opacity: alpha, maxWidth: width - 20 });
        addUriLink(pdfDoc, page, [margin, 18, margin + width, 46], data.verification_url);
    }

    async function stampPdf(file, data) {
        const PDFLib = window.PDFLib;
        const pdfDoc = await PDFLib.PDFDocument.load(await file.arrayBuffer(), { ignoreEncryption: true, updateMetadata: false });
        const fonts = await embedFonts(pdfDoc);
        const qrCanvas = await makeQrCanvas(data.verification_url, hexToCss(data.stamp_color));
        const qrImage = await pdfDoc.embedPng(dataUrlToBytes(qrCanvas.toDataURL('image/png')));

        pdfDoc.getPages().forEach(function (page, index) {
            if (index === 0) {
                drawFirstPageStamp(pdfDoc, page, data, fonts, qrImage);
            } else {
                drawFooterStamp(pdfDoc, page, data, fonts);
            }
        });

        pdfDoc.setProducer('Sign Docs');
        pdfDoc.setCreator('Sign Docs WordPress plugin');

        return await pdfDoc.save({ useObjectStreams: true, updateFieldAppearances: false });
    }

    function DocumentPicker(props) {
        const onClose = props.onClose;
        const onSelect = props.onSelect;
        const [search, setSearch] = useState('');
        const [status, setStatus] = useState('');
        const [category, setCategory] = useState('');
        const [type, setType] = useState('');
        const [department, setDepartment] = useState('');
        const [page, setPage] = useState(1);
        const [documents, setDocuments] = useState([]);
        const [totalPages, setTotalPages] = useState(1);
        const [loading, setLoading] = useState(false);
        const [error, setError] = useState('');

        useEffect(function () {
            const timer = window.setTimeout(function () {
                setLoading(true);
                setError('');
                fetchDocuments({ search: search, status: status, category: category, type: type, department: department, page: page, per_page: 12 })
                    .then(function (payload) {
                        setDocuments(payload.items || []);
                        setTotalPages(Math.max(1, Number(payload.totalPages || 1)));
                    })
                    .catch(function (caught) {
                        setError(caught.message || __('Could not load documents.', 'sign-docs'));
                    })
                    .finally(function () {
                        setLoading(false);
                    });
            }, 250);

            return function () {
                window.clearTimeout(timer);
            };
        }, [search, status, category, type, department, page]);

        function resetPage(setter) {
            return function (value) {
                setPage(1);
                setter(value);
            };
        }

        return el(
            components.Modal,
            { title: __('Select signed document', 'sign-docs'), onRequestClose: onClose, className: 'sign-docs-document-picker' },
            el(
                'div',
                { style: { minWidth: 'min(760px, calc(100vw - 64px))' } },
                el(
                    'div',
                    { style: { display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: '12px', marginBottom: '16px' } },
                    el(components.TextControl, { label: __('Search', 'sign-docs'), value: search, onChange: resetPage(setSearch), __next40pxDefaultSize: true }),
                    el(components.SelectControl, { label: __('Status', 'sign-docs'), value: status, options: statusOptions, onChange: resetPage(setStatus), __next40pxDefaultSize: true }),
                    el(components.SelectControl, { label: __('Category', 'sign-docs'), value: category, options: optionList(__('Any category', 'sign-docs'), filters.categories), onChange: resetPage(setCategory), __next40pxDefaultSize: true }),
                    el(components.SelectControl, { label: __('Document type', 'sign-docs'), value: type, options: optionList(__('Any type', 'sign-docs'), filters.types), onChange: resetPage(setType), __next40pxDefaultSize: true }),
                    el(components.SelectControl, { label: __('Department', 'sign-docs'), value: department, options: optionList(__('Any department', 'sign-docs'), filters.departments), onChange: resetPage(setDepartment), __next40pxDefaultSize: true })
                ),
                error ? el(components.Notice, { status: 'error', isDismissible: false }, error) : null,
                loading ? el('div', { style: { padding: '24px 0' } }, el(components.Spinner, null)) : null,
                !loading && documents.length === 0 ? el('p', null, __('No documents found.', 'sign-docs')) : null,
                !loading && documents.length > 0 ? el(
                    'div',
                    { style: { borderTop: '1px solid #ddd' } },
                    documents.map(function (document) {
                        return el(
                            'button',
                            {
                                key: document.id,
                                type: 'button',
                                onClick: function () { onSelect(document); },
                                style: { background: 'transparent', border: '0', borderBottom: '1px solid #ddd', cursor: 'pointer', display: 'block', padding: '12px 0', textAlign: 'left', width: '100%' }
                            },
                            el('strong', null, documentTitle(document)),
                            el('span', { style: { color: '#646970', display: 'block', marginTop: '4px' } }, ['#' + document.id, document.statusLabel || '', document.signedAt || '', document.type || '', document.department || ''].filter(Boolean).join(' - '))
                        );
                    })
                ) : null,
                totalPages > 1 ? el(
                    'div',
                    { style: { alignItems: 'center', display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' } },
                    el(components.Button, { variant: 'secondary', disabled: page <= 1 || loading, onClick: function () { setPage(page - 1); } }, __('Previous', 'sign-docs')),
                    el('span', null, page + ' / ' + totalPages),
                    el(components.Button, { variant: 'secondary', disabled: page >= totalPages || loading, onClick: function () { setPage(page + 1); } }, __('Next', 'sign-docs'))
                ) : null
            )
        );
    }

    function UploadDocumentModal(props) {
        const onClose = props.onClose;
        const onComplete = props.onComplete;
        const replacesDocument = props.replacesDocument || null;
        const fileRef = useRef(null);
        const previewFrameRef = useRef(null);
        const previewLayerRef = useRef(null);
        const fileInputRef = useRef(null);
        const [fileName, setFileName] = useState('');
        const [postTitle, setPostTitle] = useState('');
        const [fullTitle, setFullTitle] = useState('');
        const [titleManual, setTitleManual] = useState(false);
        const [fullTitleManual, setFullTitleManual] = useState(false);
        const [category, setCategory] = useState((filters.categories && filters.categories[0] && filters.categories[0].slug) || '');
        const [typeId, setTypeId] = useState('');
        const [institution, setInstitution] = useState('');
        const [includeInstitution, setIncludeInstitution] = useState(false);
        const [dragOver, setDragOver] = useState(false);
        const [documentDate, setDocumentDate] = useState('');
        const [documentNumber, setDocumentNumber] = useState('');
        const [documentSubject, setDocumentSubject] = useState('');
        const [previewUrl, setPreviewUrl] = useState('');
        const [previewPageSize, setPreviewPageSize] = useState(null);
        const [manualPicking, setManualPicking] = useState(false);
        const [stampPosition, setStampPosition] = useState(null);
        const [busy, setBusy] = useState(false);
        const [status, setStatus] = useState('');
        const [error, setError] = useState('');
        const unsignedOnly = category === 'external-regulation';

        useEffect(function () {
            return function () {
                if (previewUrl) {
                    URL.revokeObjectURL(previewUrl);
                }
            };
        }, [previewUrl]);

        useEffect(function () {
            const useInstitution = category !== 'local-act' || includeInstitution;
            const title = [
                selectedType() ? selectedType().name : '',
                useInstitution ? institution : '',
                documentDate,
                normalizeNumber(documentNumber),
                quoteSubject(documentSubject)
            ].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();

            if (!title) {
                return;
            }

            if (!titleManual) {
                setPostTitle(title);
            }
            if (!fullTitleManual) {
                setFullTitle(title);
            }
        }, [typeId, institution, includeInstitution, category, documentDate, documentNumber, documentSubject]);

        useEffect(function () {
            if (category === 'local-act') {
                setInstitution(text(defaults.signer_organization));
                return;
            }

            if (institution === text(defaults.signer_organization)) {
                setInstitution('');
            }
        }, [category]);

        useEffect(function () {
            const available = documentTypesForCategory(category);
            const selectedIsAvailable = available.some(function (item) {
                return String(item.id) === String(typeId);
            });

            if (!selectedIsAvailable) {
                setTypeId(available[0] ? String(available[0].id) : '');
            }
        }, [category]);

        useEffect(function () {
            if (unsignedOnly) {
                setManualPicking(false);
                setStampPosition(null);
            }
        }, [unsignedOnly]);

        async function readPreviewPageSize(file) {
            if (!window.PDFLib || !file) {
                return null;
            }

            try {
                const pdfDoc = await window.PDFLib.PDFDocument.load(await file.arrayBuffer(), { ignoreEncryption: true, updateMetadata: false });
                const firstPage = pdfDoc.getPages()[0];
                return firstPage ? firstPage.getSize() : null;
            } catch (caught) {
                return null;
            }
        }

        function handleFile(file) {
            fileRef.current = file;
            setFileName(file ? file.name : '');
            setPreviewPageSize(null);
            setStampPosition(null);
            setManualPicking(false);

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                setPreviewUrl('');
            }

            if (file && !postTitle) {
                const title = String(file.name || '').replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
                setPostTitle(title);
                setFullTitle(title);
            }

            if (!file || (file.type !== 'application/pdf' && !/\.pdf$/i.test(file.name || ''))) {
                return;
            }

            const url = URL.createObjectURL(file);
            setPreviewUrl(url);
            readPreviewPageSize(file).then(setPreviewPageSize);
        }

        function onFileChange(event) {
            handleFile(event.target.files && event.target.files[0] ? event.target.files[0] : null);
        }

        function dropFile(event) {
            const file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0] ? event.dataTransfer.files[0] : null;
            event.preventDefault();
            setDragOver(false);

            if (!file) {
                return;
            }

            if (fileInputRef.current && typeof DataTransfer !== 'undefined') {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                fileInputRef.current.files = transfer.files;
            }

            handleFile(file);
        }

        function openFileDialog() {
            if (fileInputRef.current) {
                fileInputRef.current.click();
            }
        }

        function selectedType() {
            return (filters.types || []).filter(function (item) {
                return String(item.id) === String(typeId);
            })[0] || null;
        }

        function selectInstitution(value) {
            if (!value) {
                return;
            }

            setInstitution(value);
        }

        function fieldStyle() {
            return { marginBottom: '16px' };
        }

        function inputStyle() {
            return { boxSizing: 'border-box', width: '100%' };
        }

        function textLinkStyle() {
            return {
                background: 'transparent',
                border: '0',
                color: '#2271b1',
                cursor: 'pointer',
                padding: '0',
                textDecoration: 'underline'
            };
        }

        function dropzoneStyle() {
            return {
                alignItems: 'center',
                background: dragOver ? '#fff' : '#f6f7f7',
                border: '2px dashed ' + (dragOver ? '#2271b1' : '#c3c4c7'),
                borderRadius: '4px',
                boxSizing: 'border-box',
                cursor: 'pointer',
                display: 'flex',
                justifyContent: 'center',
                minHeight: '116px',
                marginBottom: '12px',
                padding: '18px',
                textAlign: 'center',
                width: '100%'
            };
        }

        function actionButtons() {
            return el(
                'div',
                { style: { display: 'flex', gap: '8px', justifyContent: 'flex-start', margin: '0 0 16px' } },
                el(components.Button, { variant: 'secondary', disabled: busy, onClick: onClose }, __('Cancel', 'sign-docs')),
                unsignedOnly ? null : el(components.Button, { variant: 'primary', isBusy: busy, disabled: busy || !fileName, onClick: function () { saveDocument(false); } }, busy ? __('Signing...', 'sign-docs') : __('Save and sign document', 'sign-docs')),
                el(components.Button, { variant: unsignedOnly ? 'primary' : 'secondary', isBusy: busy && unsignedOnly, disabled: busy || !fileName, onClick: function () { saveDocument(true); } }, busy && unsignedOnly ? __('Saving...', 'sign-docs') : __('Save without signature', 'sign-docs'))
            );
        }

        function pickStampPosition(event) {
            if (!manualPicking || !previewLayerRef.current) {
                return;
            }

            const bounds = previewLayerRef.current.getBoundingClientRect();
            const x = clamp((event.clientX - bounds.left) / Math.max(1, bounds.width), 0, 1);
            const y = clamp((event.clientY - bounds.top) / Math.max(1, bounds.height), 0, 1);
            setStampPosition({ x: x, y: y });
            setManualPicking(false);
        }

        function stampRectStyle() {
            if (!stampPosition) {
                return { display: 'none' };
            }

            return {
                display: 'block',
                position: 'absolute',
                width: '180px',
                height: '78px',
                left: 'calc(' + (stampPosition.x * 100).toFixed(3) + '% - 90px)',
                top: 'calc(' + (stampPosition.y * 100).toFixed(3) + '% - 39px)',
                border: '2px solid ' + hexToCss(defaults.stamp_color),
                background: hexToCss(defaults.stamp_color) + '1f',
                boxSizing: 'border-box',
                pointerEvents: 'none'
            };
        }

        function previewLayerStyle() {
            return {
                cursor: manualPicking ? 'crosshair' : 'default',
                display: previewUrl ? 'block' : 'none',
                inset: 0,
                position: 'absolute',
                zIndex: 2
            };
        }

        async function saveDocument(saveUnsigned) {
            const file = fileRef.current;
            const type = selectedType();
            const unsigned = saveUnsigned || unsignedOnly;

            if (!file) {
                setError(__('Choose a PDF file.', 'sign-docs'));
                return;
            }

            if (!unsigned && (!config.hasVendor || !window.PDFLib || !window.qrcode || !(window.fontkit || window.Fontkit))) {
                setError(__('Local PDF libraries or fonts are missing.', 'sign-docs'));
                return;
            }

            setBusy(true);
            setError('');

            try {
                setStatus(__('Saving original PDF and calculating server SHA-256...', 'sign-docs'));
                const prepareData = new FormData();
                prepareData.append('original_pdf', file, file.name);
                prepareData.append('post_title', text(postTitle) || file.name);
                prepareData.append('full_title', text(fullTitle) || text(postTitle) || file.name);
                prepareData.append('document_category', category);
                prepareData.append('document_type_label', type ? type.name : '');
                prepareData.append('document_type_term_id', type ? type.id : '');
                prepareData.append('document_institution', category === 'local-act' && !institution ? text(defaults.signer_organization) : institution);
                prepareData.append('document_date', documentDate);
                prepareData.append('document_number', documentNumber);
                prepareData.append('document_subject', documentSubject);
                prepareData.append('save_mode', unsigned ? 'unsigned' : 'signed');
                prepareData.append('signer_name', unsigned ? '' : text(defaults.signer_name));
                prepareData.append('signer_position', unsigned ? '' : text(defaults.signer_position));
                prepareData.append('signer_organization', unsigned ? '' : text(defaults.signer_organization));
                prepareData.append('stamp_corner', text(defaults.stamp_corner) || 'top-left');
                prepareData.append('stamp_color', text(defaults.stamp_color) || '#2e7d32');
                prepareData.append('stamp_opacity', String(defaults.stamp_opacity || '1'));
                prepareData.append('stamp_font_size', String(defaults.stamp_font_size || '8.4'));
                prepareData.append('stamp_width_mm', String(defaults.stamp_width_mm || '100'));
                prepareData.append('stamp_border_enabled', defaults.stamp_border_enabled === '0' ? '0' : '1');
                prepareData.append('stamp_placement_mode', !unsigned && stampPosition ? 'manual' : 'corner');
                prepareData.append('stamp_manual_x', !unsigned && stampPosition ? stampPosition.x.toFixed(6) : '');
                prepareData.append('stamp_manual_y', !unsigned && stampPosition ? stampPosition.y.toFixed(6) : '');
                prepareData.append('qr_logo_enabled', defaults.qr_logo_enabled === '0' ? '0' : '1');
                prepareData.append('replaces_post_id', replacesDocument && replacesDocument.id ? String(replacesDocument.id) : '');

                const prepared = await postForm(config.prepareUrl, prepareData);
                if (unsigned) {
                    onComplete({
                        id: prepared.post_id,
                        title: prepared.title || text(postTitle) || file.name,
                        fullTitle: prepared.title || text(fullTitle) || text(postTitle) || file.name,
                        verificationUrl: prepared.verification_url || '',
                        stampedFileUrl: '',
                        originalFileUrl: prepared.original_file_url || '',
                        sha256Hash: prepared.sha256_hash || '',
                        statusLabel: prepared.statusLabel || __('Unsigned', 'sign-docs'),
                        signedAt: prepared.signed_at || '',
                        documentVersion: prepared.version || '1'
                    });
                    return;
                }

                prepared.stamp_placement_mode = stampPosition ? 'manual' : 'corner';
                prepared.stamp_manual_x = stampPosition ? stampPosition.x.toFixed(6) : '';
                prepared.stamp_manual_y = stampPosition ? stampPosition.y.toFixed(6) : '';
                setStatus(__('Adding stamp and QR code...', 'sign-docs'));
                const stampedBytes = await stampPdf(file, prepared);
                const stampedBlob = new Blob([stampedBytes], { type: 'application/pdf' });
                const completeData = new FormData();
                completeData.append('post_id', prepared.post_id);
                completeData.append('stamped_pdf', stampedBlob, 'stamped.pdf');

                setStatus(__('Saving public PDF copy...', 'sign-docs'));
                const completed = await postForm(config.completeUrl, completeData);

                onComplete({
                    id: prepared.post_id,
                    title: prepared.title || text(postTitle) || file.name,
                    fullTitle: prepared.title || text(fullTitle) || text(postTitle) || file.name,
                    verificationUrl: completed.verification_url || prepared.verification_url,
                    stampedFileUrl: completed.stamped_file_url || '',
                    originalFileUrl: prepared.original_file_url || '',
                    sha256Hash: prepared.sha256_hash || '',
                    statusLabel: __('Active', 'sign-docs'),
                    signedAt: prepared.signed_at || '',
                    documentVersion: prepared.version || '1'
                });
            } catch (caught) {
                setError(caught.message || __('Could not sign the document.', 'sign-docs'));
                setBusy(false);
            }
        }

        return el(
            components.Modal,
            { title: replacesDocument ? __('Replace document', 'sign-docs') : __('Add document', 'sign-docs'), onRequestClose: busy ? undefined : onClose, className: 'sign-docs-document-upload-modal', isFullScreen: true },
            el(
                'div',
                { style: { maxWidth: '1180px', margin: '0 auto', padding: '24px' } },
                error ? el(components.Notice, { status: 'error', isDismissible: false }, error) : null,
                status ? el(components.Notice, { status: 'info', isDismissible: false }, status) : null,
                replacesDocument ? el(components.Notice, { status: 'warning', isDismissible: false }, __('The new document will mark the selected document as replaced after signing completes.', 'sign-docs')) : null,
                actionButtons(),
                el(
                    'div',
                    { style: { display: 'grid', gridTemplateColumns: 'minmax(420px, 1fr) minmax(420px, 1fr)', gap: '24px', alignItems: 'start' } },
                    el(
                        'div',
                        { style: { background: '#fff', border: '1px solid #dcdcde', boxSizing: 'border-box', padding: '20px' } },
                        el('div', {
                            onClick: openFileDialog,
                            onDragEnter: function (event) { event.preventDefault(); setDragOver(true); },
                            onDragOver: function (event) { event.preventDefault(); setDragOver(true); },
                            onDragLeave: function (event) { event.preventDefault(); setDragOver(false); },
                            onDrop: dropFile,
                            style: dropzoneStyle()
                        },
                            el('input', { ref: fileInputRef, type: 'file', accept: 'application/pdf,.pdf', onChange: onFileChange, style: { display: 'none' } }),
                            el('span', null,
                                el('strong', { style: { display: 'block', marginBottom: '4px' } }, fileName || __('Drop PDF here', 'sign-docs')),
                                el('span', { style: { color: '#646970', display: 'block' } }, fileName ? __('The file will be uploaded after submitting the form.', 'sign-docs') : __('or click to choose a file', 'sign-docs'))
                            )
                        ),
                        el('div', { style: fieldStyle() }, el(components.SelectControl, { label: __('Category', 'sign-docs'), value: category, options: optionList(__('Choose category', 'sign-docs'), filters.categories, 'slug'), onChange: setCategory, __next40pxDefaultSize: true })),
                        el('div', { style: fieldStyle() }, el(components.SelectControl, { label: __('Document type', 'sign-docs'), value: typeId, options: optionList(__('Choose type', 'sign-docs'), documentTypesForCategory(category)), onChange: setTypeId, __next40pxDefaultSize: true })),
                        category === 'local-act' ? el(
                            'div',
                            { style: fieldStyle() },
                            el(components.CheckboxControl, {
                                label: __('Add issuing authority name to the generated title', 'sign-docs'),
                                checked: includeInstitution,
                                onChange: setIncludeInstitution
                            })
                        ) : el(
                            'div',
                            { style: fieldStyle() },
                            el(components.SelectControl, {
                                label: __('Issuing authority directory', 'sign-docs'),
                                value: '',
                                options: optionList(__('Choose from directory', 'sign-docs'), filters.institutions || []),
                                onChange: selectInstitution,
                                __next40pxDefaultSize: true
                            }),
                            el('label', { style: { display: 'block', margin: '12px 0 4px' } }, __('Or enter a new short issuing authority name', 'sign-docs')),
                            el('input', {
                                type: 'text',
                                value: institution,
                                onChange: function (event) { setInstitution(event.target.value); },
                                placeholder: __('Вводите в родительном падеже', 'sign-docs'),
                                style: inputStyle()
                            })
                        ),
                        el(
                            'div',
                            { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', marginBottom: '16px' } },
                            el('label', null, el('span', { style: { display: 'block', marginBottom: '4px' } }, __('Document date', 'sign-docs')), el('input', { type: 'text', value: documentDate, onChange: function (event) { setDocumentDate(event.target.value); }, placeholder: '20.05.2026', style: inputStyle() })),
                            el('label', null, el('span', { style: { display: 'block', marginBottom: '4px' } }, __('Document number', 'sign-docs')), el('input', { type: 'text', value: documentNumber, onChange: function (event) { setDocumentNumber(event.target.value); }, placeholder: __('183-р', 'sign-docs'), style: inputStyle() }))
                        ),
                        el('div', { style: fieldStyle() }, el(components.TextareaControl, { label: __('Document subject', 'sign-docs'), value: documentSubject, onChange: setDocumentSubject, rows: 2, __next40pxDefaultSize: true })),
                        el(
                            'div',
                            { style: { display: 'flex', flexWrap: 'wrap', gap: '12px', margin: '-8px 0 16px' } },
                            el('button', { type: 'button', style: textLinkStyle(), onClick: function () { setDocumentSubject(subjectCase(documentSubject, 'sentence')); } }, __('Sentence case', 'sign-docs')),
                            el('button', { type: 'button', style: textLinkStyle(), onClick: function () { setDocumentSubject(subjectCase(documentSubject, 'lower')); } }, __('Lowercase', 'sign-docs')),
                            el('button', { type: 'button', style: textLinkStyle(), onClick: function () { setDocumentSubject(subjectCase(documentSubject, 'upper')); } }, __('Uppercase', 'sign-docs'))
                        ),
                        el('div', { style: fieldStyle() }, el(components.TextControl, { label: __('Short title', 'sign-docs'), value: postTitle, onChange: function (value) { setTitleManual(true); setPostTitle(value); }, __next40pxDefaultSize: true })),
                        el(components.TextareaControl, { label: __('Full title', 'sign-docs'), value: fullTitle, onChange: function (value) { setFullTitleManual(true); setFullTitle(value); }, rows: 4, __next40pxDefaultSize: true })
                    ),
                    el(
                        'div',
                        null,
                        el(
                            'div',
                            { style: { alignItems: 'center', display: 'flex', gap: '8px', justifyContent: 'space-between', marginBottom: '10px' } },
                            el('h2', { style: { margin: '0' } }, __('Preview', 'sign-docs')),
                            unsignedOnly ? null : el(
                                'div',
                                { style: { display: 'flex', gap: '8px' } },
                                el(components.Button, { variant: manualPicking ? 'primary' : 'secondary', disabled: !previewUrl || busy, onClick: function () { setManualPicking(!manualPicking); } }, manualPicking ? __('Cancel picking', 'sign-docs') : __('Pick stamp position', 'sign-docs')),
                                el(components.Button, { variant: 'tertiary', disabled: !stampPosition || busy, onClick: function () { setStampPosition(null); setManualPicking(false); } }, __('Reset', 'sign-docs'))
                            )
                        ),
                        el(
                            'div',
                            { style: { position: 'relative', width: '100%', height: '620px', border: '1px solid #c3c4c7', background: '#fff', overflow: 'hidden' } },
                            previewUrl ? el('iframe', {
                                ref: previewFrameRef,
                                title: __('Selected PDF preview', 'sign-docs'),
                                src: previewUrl + '#page=1&toolbar=0&navpanes=0&scrollbar=0&view=Fit',
                                style: { border: 0, height: '100%', position: 'relative', width: '100%', zIndex: 1 }
                            }) : el('div', { style: { color: '#646970', padding: '24px' } }, __('Choose a PDF to preview the first page.', 'sign-docs')),
                            el('div', { ref: previewLayerRef, onClick: pickStampPosition, style: previewLayerStyle() },
                                el('div', { style: stampRectStyle() })
                            )
                        ),
                        unsignedOnly ? null : el(
                            'div',
                            { style: { background: '#f6f7f7', border: '1px solid #dcdcde', borderRadius: '4px', marginTop: '12px', padding: '12px' } },
                            el('strong', null, __('Signing parameters', 'sign-docs')),
                            el('p', { style: { marginBottom: '4px' } }, text(defaults.signer_position) + ' ' + text(defaults.signer_name)),
                            el('p', { style: { marginTop: '0' } }, text(defaults.signer_organization)),
                            previewPageSize ? el('p', { style: { color: '#646970', marginBottom: '0' } }, __('First page size:', 'sign-docs') + ' ' + Math.round(previewPageSize.width) + ' x ' + Math.round(previewPageSize.height) + ' pt') : null,
                            stampPosition ? el('p', { style: { color: '#646970', marginBottom: '0' } }, __('Manual stamp position is selected.', 'sign-docs')) : el('p', { style: { color: '#646970', marginBottom: '0' } }, __('Default stamp corner from settings is used.', 'sign-docs'))
                        )
                    )
                ),
                el('div', { style: { marginTop: '24px' } }, actionButtons())
            )
        );
    }

    blocks.registerBlockType('sign-docs/document', {
        apiVersion: 3,
        title: __('Document', 'sign-docs'),
        icon: 'media-document',
        category: 'media',
        attributes: {
            postId: { type: 'number' },
            title: { type: 'string', default: '' },
            fullTitle: { type: 'string', default: '' },
            verificationUrl: { type: 'string', default: '' },
            stampedFileUrl: { type: 'string', default: '' },
            originalFileUrl: { type: 'string', default: '' },
            sha256Hash: { type: 'string', default: '' },
            linkText: { type: 'string', default: '' },
            openInNewTab: { type: 'boolean', default: false },
            showIcon: { type: 'boolean', default: true },
            showMeta: { type: 'boolean', default: false },
            showDownloadButton: { type: 'boolean', default: false },
            showEmbeddedPdf: { type: 'boolean', default: false },
            displayMode: { type: 'string', default: 'link' },
            interactionMode: { type: 'string', default: 'title-details' },
            statusLabel: { type: 'string', default: '' },
            signedAt: { type: 'string', default: '' },
            documentVersion: { type: 'string', default: '' }
        },
        supports: {
            align: ['left', 'center', 'right', 'wide', 'full'],
            className: true
        },
        edit: function (props) {
            const attributes = props.attributes;
            const setAttributes = props.setAttributes;
            const [pickerOpen, setPickerOpen] = useState(false);
            const [uploadOpen, setUploadOpen] = useState(false);
            const [replacementUploadOpen, setReplacementUploadOpen] = useState(false);
            const [loadingSelected, setLoadingSelected] = useState(false);
            const [selectedError, setSelectedError] = useState('');
            const blockProps = useBlockProps({ className: 'sign-docs-document-block sign-docs-document-block--' + (attributes.displayMode || 'link') });
            const selectedTitle = text(attributes.fullTitle) || text(attributes.title);
            const linkText = text(attributes.linkText) || selectedTitle || __('Signed document', 'sign-docs');
            const hasDocument = Number(attributes.postId || 0) > 0;
            const metaText = [attributes.statusLabel || '', attributes.signedAt || '', attributes.documentVersion ? 'v' + attributes.documentVersion : ''].filter(Boolean).join(' · ');

            useEffect(function () {
                if (!hasDocument || selectedTitle) {
                    return;
                }

                setLoadingSelected(true);
                fetchDocuments({ include: attributes.postId, per_page: 1 }).then(function (payload) {
                    const document = payload.items && payload.items[0] ? payload.items[0] : null;

                    if (!document) {
                        return;
                    }

                    setAttributes({
                        title: document.title || '',
                        fullTitle: document.fullTitle || '',
                        verificationUrl: document.verificationUrl || '',
                        stampedFileUrl: document.stampedFileUrl || '',
                        originalFileUrl: document.originalFileUrl || '',
                        sha256Hash: document.sha256Hash || '',
                        statusLabel: document.statusLabel || '',
                        signedAt: document.signedAt || '',
                        documentVersion: document.documentVersion || ''
                    });
                }).catch(function (caught) {
                    setSelectedError(caught.message || __('Could not load selected document.', 'sign-docs'));
                }).finally(function () {
                    setLoadingSelected(false);
                });
            }, [attributes.postId]);

            function selectDocument(document) {
                setAttributes({
                    postId: Number(document.id),
                    title: document.title || '',
                    fullTitle: document.fullTitle || '',
                    verificationUrl: document.verificationUrl || '',
                    stampedFileUrl: document.stampedFileUrl || '',
                    originalFileUrl: document.originalFileUrl || '',
                    sha256Hash: document.sha256Hash || '',
                    statusLabel: document.statusLabel || '',
                    signedAt: document.signedAt || '',
                    documentVersion: document.documentVersion || ''
                });
                setPickerOpen(false);
                setUploadOpen(false);
                setSelectedError('');
            }

            function clearDocument() {
                setAttributes({ postId: undefined, title: '', fullTitle: '', verificationUrl: '', stampedFileUrl: '', originalFileUrl: '', sha256Hash: '', linkText: '', statusLabel: '', signedAt: '', documentVersion: '' });
                setSelectedError('');
            }

            function resetLinkText() {
                setAttributes({ linkText: '' });
            }

            return el(
                'div',
                blockProps,
                hasDocument ? el(
                    element.Fragment,
                    null,
                    el(
                        BlockControls,
                        null,
                        el(
                            components.ToolbarGroup,
                            null,
                            el(components.ToolbarButton, { icon: 'edit', label: __('Replace document', 'sign-docs'), onClick: function () { setPickerOpen(true); } }),
                            el(components.ToolbarButton, { icon: 'upload', label: __('Upload replacement PDF', 'sign-docs'), onClick: function () { setReplacementUploadOpen(true); } }),
                            el(components.ToolbarButton, { icon: 'no', label: __('Remove document', 'sign-docs'), onClick: clearDocument })
                        )
                    ),
                    el(
                        InspectorControls,
                        null,
                        el(
                            components.PanelBody,
                            { title: __('Document', 'sign-docs') },
                            el(components.Button, { variant: 'secondary', onClick: function () { setPickerOpen(true); } }, __('Replace document', 'sign-docs')),
                            el(components.Button, { variant: 'secondary', onClick: function () { setReplacementUploadOpen(true); } }, __('Upload replacement PDF', 'sign-docs')),
                            el(components.Button, { variant: 'tertiary', isDestructive: true, onClick: clearDocument }, __('Remove document', 'sign-docs'))
                        ),
                        el(
                            components.PanelBody,
                            { title: __('Display', 'sign-docs'), initialOpen: true },
                            el(components.SelectControl, {
                                label: __('Style', 'sign-docs'),
                                value: attributes.displayMode || 'link',
                                options: displayModeOptions,
                                onChange: function (value) { setAttributes({ displayMode: value }); },
                                __next40pxDefaultSize: true
                            }),
                            el(components.SelectControl, {
                                label: __('Click behavior', 'sign-docs'),
                                value: attributes.interactionMode || 'title-details',
                                options: interactionModeOptions,
                                onChange: function (value) { setAttributes({ interactionMode: value }); },
                                __next40pxDefaultSize: true
                            }),
                            el(components.ToggleControl, {
                                label: __('Show document icon', 'sign-docs'),
                                checked: attributes.showIcon !== false,
                                onChange: function (value) { setAttributes({ showIcon: value }); }
                            }),
                            el(components.ToggleControl, {
                                label: __('Show status, date and version', 'sign-docs'),
                                checked: !!attributes.showMeta,
                                onChange: function (value) { setAttributes({ showMeta: value }); }
                            }),
                            el(components.ToggleControl, {
                                label: __('Show download button', 'sign-docs'),
                                checked: !!attributes.showDownloadButton,
                                onChange: function (value) { setAttributes({ showDownloadButton: value }); }
                            }),
                            el(components.ToggleControl, {
                                label: __('Embed stamped PDF', 'sign-docs'),
                                checked: !!attributes.showEmbeddedPdf,
                                onChange: function (value) { setAttributes({ showEmbeddedPdf: value }); }
                            }),
                            el(components.ToggleControl, {
                                label: __('Open verification page in a new tab', 'sign-docs'),
                                checked: !!attributes.openInNewTab,
                                onChange: function (value) { setAttributes({ openInNewTab: value }); }
                            }),
                            el(components.Button, { variant: 'secondary', onClick: resetLinkText, disabled: !text(attributes.linkText) }, __('Use document title', 'sign-docs'))
                        )
                    ),
                    selectedError ? el(components.Notice, { status: 'error', isDismissible: false }, selectedError) : null,
                    loadingSelected ? el(components.Spinner, null) : null,
                    el(
                        'p',
                        { className: 'sign-docs-document-link sign-docs-document-link--' + (attributes.displayMode || 'link') },
                        el('span', { className: 'sign-docs-document-link__row' },
                            el(
                                'span',
                                { className: 'sign-docs-document-link__anchor' + ((attributes.interactionMode || 'title-details') === 'buttons' ? ' sign-docs-document-link__anchor--static' : '') },
                                attributes.showIcon !== false ? el('span', { className: 'sign-docs-document-link__icon', 'aria-hidden': true }) : null,
                                el(
                                    'span',
                                    { className: 'sign-docs-document-link__body' },
                                    el(RichText, {
                                        tagName: 'span',
                                        className: 'sign-docs-document-link__text',
                                        value: linkText,
                                        allowedFormats: [],
                                        placeholder: selectedTitle || __('Signed document', 'sign-docs'),
                                        onChange: function (value) { setAttributes({ linkText: value }); }
                                    }),
                                    attributes.showMeta && metaText ? el('span', { className: 'sign-docs-document-link__meta' }, metaText) : null
                                )
                            ),
                            (attributes.interactionMode || 'title-details') === 'buttons' && attributes.stampedFileUrl ? el('span', { className: 'sign-docs-document-link__download' }, __('Download', 'sign-docs')) : null,
                            (attributes.interactionMode || 'title-details') === 'buttons' ? el('span', { className: 'sign-docs-document-link__summary-button' }, __('Details', 'sign-docs')) : null
                        ),
                        attributes.showEmbeddedPdf && attributes.stampedFileUrl ? el('div', { className: 'sign-docs-document-link__embed' }, el('iframe', { title: selectedTitle || __('Stamped PDF', 'sign-docs'), src: attributes.stampedFileUrl })) : null
                    )
                ) : el(
                    components.Placeholder,
                    { icon: 'media-document', label: __('Document', 'sign-docs'), instructions: __('Add a signed document or choose an existing Sign Docs record.', 'sign-docs') },
                    selectedError ? el(components.Notice, { status: 'error', isDismissible: false }, selectedError) : null,
                    el(
                        'div',
                        { style: { alignItems: 'flex-start', display: 'flex', flexWrap: 'wrap', gap: '8px' } },
                        el(components.Button, { variant: 'primary', onClick: function () { setUploadOpen(true); } }, __('Add document', 'sign-docs')),
                        el(components.Button, { variant: 'secondary', onClick: function () { setPickerOpen(true); } }, __('Choose uploaded', 'sign-docs'))
                    )
                ),
                pickerOpen ? el(DocumentPicker, { onClose: function () { setPickerOpen(false); }, onSelect: selectDocument }) : null,
                uploadOpen ? el(UploadDocumentModal, { onClose: function () { setUploadOpen(false); }, onComplete: selectDocument }) : null,
                replacementUploadOpen ? el(UploadDocumentModal, {
                    onClose: function () { setReplacementUploadOpen(false); },
                    onComplete: function (document) {
                        setReplacementUploadOpen(false);
                        selectDocument(document);
                    },
                    replacesDocument: { id: attributes.postId, title: selectedTitle }
                }) : null
            );
        },
        save: function () {
            return null;
        }
    });
}(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n, window.wp.apiFetch));
