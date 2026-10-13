const de = document.documentElement.lang.startsWith('de');
const labels = de
    ? {
          title: 'Bildergalerie',
          close: 'Schließen',
          previous: 'Vorheriges Bild',
          next: 'Nächstes Bild',
          zoom: 'Originalgröße',
          fit: 'An Fenster anpassen',
          download: 'Original herunterladen',
          loading: 'Bild wird geladen …',
          error: 'Bild konnte nicht geladen werden.',
      }
    : {
          title: 'Picture gallery',
          close: 'Close',
          previous: 'Previous picture',
          next: 'Next picture',
          zoom: 'Original size',
          fit: 'Fit to window',
          download: 'Download original',
          loading: 'Loading picture …',
          error: 'Picture could not be loaded.',
      };
const dialog = document.createElement('dialog');
dialog.className = 'forum-gallery';
dialog.setAttribute('aria-label', labels.title);
const toolbar = document.createElement('div');
toolbar.className = 'forum-gallery-toolbar';
function button(label, action) {
    const element = document.createElement('button');
    element.type = 'button';
    element.textContent = label;
    element.addEventListener('click', action);
    toolbar.append(element);
    return element;
}
const previous = button(labels.previous, () => show(index - 1));
const next = button(labels.next, () => show(index + 1));
const zoom = button(labels.zoom, () => {
    const enabled = stage.classList.toggle('forum-gallery-original');
    zoom.setAttribute('aria-pressed', String(enabled));
    zoom.textContent = enabled ? labels.fit : labels.zoom;
});
zoom.setAttribute('aria-pressed', 'false');
const download = document.createElement('a');
download.textContent = labels.download;
download.download = '';
toolbar.append(download);
button(labels.close, () => dialog.close()).autofocus = true;
const status = document.createElement('p');
status.setAttribute('role', 'status');
const stage = document.createElement('div');
stage.className = 'forum-gallery-stage';
dialog.append(toolbar, status, stage);
let items = [],
    index = 0,
    trigger,
    previousOverflow;
function show(nextIndex) {
    index = (nextIndex + items.length) % items.length;
    const item = items[index];
    const image = document.createElement('img');
    image.alt = item.querySelector('img')?.alt || labels.title;
    stage.classList.remove('forum-gallery-original');
    zoom.setAttribute('aria-pressed', 'false');
    zoom.textContent = labels.zoom;
    previous.disabled = next.disabled = items.length < 2;
    status.textContent = `${index + 1} / ${items.length} — ${labels.loading}`;
    image.onload = () => {
        if (stage.firstChild === image)
            status.textContent = `${index + 1} / ${items.length} — ${image.naturalWidth} × ${image.naturalHeight}`;
    };
    image.onerror = () => {
        if (stage.firstChild === image) status.textContent = labels.error;
    };
    stage.replaceChildren(image);
    stage.scrollTo(0, 0);
    download.href = item.href;
    image.src = item.href;
}
document.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[data-forum-gallery]');
    if (
        !link ||
        event.button !== 0 ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey ||
        event.altKey
    )
        return;
    const scope =
        link.closest('.tx-typo3forum-post') || link.closest('.tx-typo3forum');
    if (!scope) return;
    event.preventDefault();
    const unique = new Map(
        Array.from(scope.querySelectorAll('a[data-forum-gallery]'), (item) => [
            item.href,
            item,
        ]),
    );
    items = Array.from(unique.values());
    trigger = link;
    if (!dialog.isConnected) document.body.append(dialog);
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    dialog.showModal();
    show(items.findIndex((item) => item.href === link.href));
});
dialog.addEventListener('close', () => {
    stage.replaceChildren();
    document.body.style.overflow = previousOverflow;
    trigger?.focus();
});
dialog.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
        event.preventDefault();
        show(index + (event.key === 'ArrowRight' ? 1 : -1));
    }
});
let startX = null,
    startY = 0;
stage.addEventListener(
    'touchstart',
    (event) => {
        if (event.touches.length !== 1) {
            startX = null;
            return;
        }
        startX = event.touches[0].clientX;
        startY = event.touches[0].clientY;
    },
    { passive: true },
);
stage.addEventListener(
    'touchend',
    (event) => {
        if (
            startX === null ||
            stage.classList.contains('forum-gallery-original')
        )
            return;
        const dx = event.changedTouches[0].clientX - startX;
        const dy = event.changedTouches[0].clientY - startY;
        if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy))
            show(index + (dx < 0 ? 1 : -1));
    },
    { passive: true },
);
