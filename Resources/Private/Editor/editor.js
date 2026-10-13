import { mountInlineImages } from './images.js';
import { mountEmojiPicker } from './emojis.js';
const base = new URL('.', import.meta.url);
const scripts = new Map();
function script(url) {
    if (!scripts.has(url))
        scripts.set(
            url,
            new Promise((resolve, reject) => {
                const tag = document.createElement('script');
                tag.src = url;
                tag.onload = resolve;
                tag.onerror = () => {
                    scripts.delete(url);
                    tag.remove();
                    reject(new Error('Editor could not be loaded'));
                };
                document.head.append(tag);
            }),
        );
    return scripts.get(url);
}
async function create(host, html, wrapper) {
    await script(new URL('tinymce/tinymce.min.js', base).href);
    const field = document.createElement('textarea');
    field.value = html;
    host.append(field);
    const [editor] = await window.tinymce.init({
        target: field,
        license_key: 'gpl',
        base_url: new URL('tinymce', base).href,
        suffix: '.min',
        menubar: false,
        promotion: false,
        branding: false,
        height: 320,
        resize: true,
        plugins: 'lists link codesample',
        automatic_uploads: false,
        paste_data_images: false,
        setup: (editor) => mountInlineImages(editor, wrapper),
        link_default_target: '_blank',
        link_target_list: false,
        link_assume_external_targets: 'https',
        toolbar:
            'undo redo | bold italic underline strikethrough | forumImages link bullist numlist blockquote codesample',
        toolbar_mode: 'sliding',
        formats: {
            underline: { inline: 'u', exact: true },
            strikethrough: { inline: 's', exact: true },
        },
        valid_elements:
            'p,br,strong/b,em/i,u,s,ul,ol,li,blockquote[data-post],pre,code[class],a[href|rel|target],img[src|alt|data-forum-image]',
        convert_urls: false,
        entity_encoding: 'raw',
        content_style:
            'body {font:16px/1.5 system-ui,sans-serif} img {max-width:100%} pre {white-space:pre-wrap} blockquote {margin:1rem 0;padding:1rem 1.25rem;border:1px solid #bdc9d6;border-left:4px solid #607d9d;border-radius:6px;background:#f5f7fa;color:#334155} blockquote > :first-child {margin-top:0} blockquote > :last-child {margin-bottom:0}',
    });
    if (!editor) throw new Error('Editor initialization failed');
    return {
        getHtml: () => editor.getContent(),
        captureSelection: () => {
            const bookmark = editor.selection.getBookmark(2, true);
            return (text) => {
                editor.focus();
                editor.selection.moveToBookmark(bookmark);
                editor.insertContent(text);
            };
        },
        onChange: (callback) => editor.on('input change undo redo', callback),
    };
}

async function mount(wrapper) {
    if (wrapper.dataset.mounted) return;
    wrapper.dataset.mounted = '1';
    const textarea = wrapper.querySelector('textarea');
    const status = wrapper.querySelector('[role=status]');
    const host = wrapper.querySelector('.forum-editor-host');
    let editor = null,
        loading = true;
    const sync = () => {
        if (editor) textarea.value = editor.getHtml();
    };
    mountEmojiPicker(
        wrapper.querySelector('.forum-editor-controls'),
        new URL('emojis.json', base),
        () => {
            if (loading) return null;
            const start = textarea.selectionStart,
                end = textarea.selectionEnd;
            const insert = editor
                ? editor.captureSelection()
                : (text) => {
                      textarea.setRangeText(text, start, end, 'end');
                      textarea.focus();
                  };
            return (text) => {
                insert(text);
                sync();
            };
        },
    );
    textarea.form?.addEventListener('submit', (event) => {
        if (loading) {
            event.preventDefault();
            status.textContent = wrapper.dataset.loading;
            return;
        }
        sync();
    });
    textarea.readOnly = true;
    status.textContent = wrapper.dataset.loading;
    try {
        editor = await create(host, textarea.value, wrapper);
        editor.onChange(sync);
        textarea.hidden = true;
        status.textContent = wrapper.dataset.ready;
    } catch (error) {
        host.replaceChildren();
        status.textContent = wrapper.dataset.error;
        console.error(error);
    } finally {
        loading = false;
        textarea.readOnly = false;
    }
}
function start() {
    document.querySelectorAll('.forum-rich-editor').forEach(mount);
}
if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', start);
else start();
