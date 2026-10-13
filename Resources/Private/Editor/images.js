const types = new Map([
    ['image/jpeg', 'jpg'],
    ['image/png', 'png'],
    ['image/webp', 'webp'],
]);

async function validateImage(file, maxBytes) {
    if (!types.has(file.type) || file.size > maxBytes)
        throw new Error('format');
    const bitmap = await createImageBitmap(file);
    try {
        if (bitmap.width * bitmap.height > 16000000)
            throw new Error('dimensions');
    } finally {
        bitmap.close();
    }
}

export function mountInlineImages(editor, wrapper) {
    const form = wrapper.querySelector('textarea').form;
    const upload = form?.querySelector(
        'input[type=file][name*="newAttachments"]',
    );
    if (!upload) return;
    const de = document.documentElement.lang.startsWith('de');
    const label = de ? 'Bilder einfügen' : 'Insert pictures';
    const errorText = de
        ? 'Bild nicht eingefügt. Bitte JPEG, PNG oder WebP innerhalb des Uploadlimits wählen (maximal 16 Megapixel und 10 Bilder).'
        : 'Picture not inserted. Choose JPEG, PNG or WebP within the upload limit (at most 16 megapixels and 10 pictures).';
    const picker = document.createElement('input');
    picker.type = 'file';
    picker.accept = 'image/jpeg,image/png,image/webp';
    picker.multiple = true;
    picker.hidden = true;
    picker.setAttribute('aria-label', label);
    wrapper.append(picker);
    const message = document.createElement('p');
    message.setAttribute('role', 'alert');
    message.hidden = true;
    wrapper.append(message);
    const pending = new Map();
    let processing = false;
    const maxBytes = Number(wrapper.dataset.imageMaxBytes) || 4194304;
    const insert = async (files) => {
        if (processing) return;
        processing = true;
        message.hidden = false;
        message.textContent = de
            ? 'Bilder werden vorbereitet …'
            : 'Preparing pictures …';
        const bookmark = editor.selection.getBookmark(2, true);
        editor.setProgressState(true);
        try {
            const activeNew = new Set(
                Array.from(
                    editor.getBody().querySelectorAll('img[data-forum-image]'),
                    (img) => img.dataset.forumImage,
                ).filter((key) => pending.has(key)),
            );
            if (activeNew.size + files.length > 10) throw new Error('count');
            editor.selection.moveToBookmark(bookmark);
            for (const file of files) {
                await validateImage(file, maxBytes);
                const key = crypto.randomUUID().replaceAll('-', '');
                const input = document.createElement('input');
                input.type = 'file';
                input.name = upload.name;
                input.hidden = true;
                input.dataset.forumInlineUpload = key;
                const transfer = new DataTransfer();
                transfer.items.add(
                    new File([file], `forum-image-${key}--${file.name}`, {
                        type: file.type,
                    }),
                );
                input.files = transfer.files;
                form.append(input);
                const url = URL.createObjectURL(file);
                pending.set(key, { input, url });
                editor.insertContent(
                    `<img data-forum-image="${key}" src="${url}" alt="${de ? 'Bild' : 'Picture'}"><p></p>`,
                );
            }
            editor.fire('change');
            message.hidden = true;
        } catch (error) {
            message.textContent = errorText;
        } finally {
            processing = false;
            editor.setProgressState(false);
        }
    };
    editor.ui.registry.addButton('forumImages', {
        icon: 'image',
        tooltip: label,
        onAction: () => picker.click(),
    });
    picker.addEventListener('change', () => {
        void insert(Array.from(picker.files));
        picker.value = '';
    });
    editor.on('paste drop', (event) => {
        const files = Array.from(
            (event.clipboardData || event.dataTransfer)?.files || [],
        );
        if (!files.length) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void insert(files);
    });
    form.addEventListener('submit', (event) => {
        if (processing) {
            event.preventDefault();
            message.hidden = false;
            message.textContent = de
                ? 'Bitte warten, bis die Bilder vorbereitet sind.'
                : 'Please wait until the pictures are ready.';
            return;
        }
        const active = new Set(
            Array.from(
                editor.getBody().querySelectorAll('img[data-forum-image]'),
                (img) => img.dataset.forumImage,
            ),
        );
        for (const [key, { input }] of pending)
            input.disabled = !active.has(key);
    });
    editor.on('init', () => {
        editor.getBody().addEventListener(
            'error',
            (event) => {
                if (!event.target.matches?.('img[data-forum-image]')) return;
                message.hidden = false;
                message.textContent = de
                    ? 'Ein Bild konnte nicht geladen werden. Bitte erneut versuchen oder das Bild entfernen und neu auswählen. Nach einem fehlgeschlagenen Absenden müssen neue Bilder erneut ausgewählt werden.'
                    : 'A picture could not be loaded. Please retry or remove it and select it again. After a rejected submission, new pictures must be selected again.';
            },
            true,
        );
    });
    editor.on('remove', () => {
        for (const { input, url } of pending.values()) {
            input.remove();
            URL.revokeObjectURL(url);
        }
        picker.remove();
        message.remove();
    });
}
