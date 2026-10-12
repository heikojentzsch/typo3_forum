let catalog;
const normalize = (value) =>
    value.toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');
const groups = {
    en: [
        'Smileys & emotion',
        'People & body',
        'Components',
        'Animals & nature',
        'Food & drink',
        'Travel & places',
        'Activities',
        'Objects',
        'Symbols',
        'Flags',
    ],
    de: [
        'Gesichter & Gefühle',
        'Menschen & Körper',
        'Bestandteile',
        'Tiere & Natur',
        'Essen & Trinken',
        'Reisen & Orte',
        'Aktivitäten',
        'Objekte',
        'Symbole',
        'Flaggen',
    ],
};
let nextId = 0;
export function mountEmojiPicker(controls, url, capture) {
    const de = document.documentElement.lang.toLowerCase().startsWith('de');
    const locale = de ? 'de' : 'en';
    const labels = de
        ? {
              open: 'Emojis auswählen',
              search: 'Emojis suchen (Deutsch / Englisch)',
              all: 'Alle Kategorien',
              category: 'Kategorie',
              more: 'Weitere anzeigen',
              loading: 'Emojis werden geladen …',
              error: 'Emojis konnten nicht geladen werden. Bitte erneut öffnen.',
              empty: 'Keine Emojis gefunden.',
              results: 'Treffer',
              close: 'Schließen',
          }
        : {
              open: 'Choose emojis',
              search: 'Search emojis (German / English)',
              all: 'All categories',
              category: 'Category',
              more: 'Show more',
              loading: 'Loading emojis …',
              error: 'Could not load emojis. Please reopen to retry.',
              empty: 'No emojis found.',
              results: 'results',
              close: 'Close',
          };
    const container = document.createElement('div');
    container.className = 'forum-emoji-picker';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.textContent = '🙂 ' + labels.open;
    toggle.setAttribute('aria-expanded', 'false');
    const panel = document.createElement('div');
    panel.hidden = true;
    panel.className = 'forum-emoji-panel';
    panel.id = 'forum-emojis-' + ++nextId;
    toggle.setAttribute('aria-controls', panel.id);
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', labels.open);
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = labels.search;
    search.setAttribute('aria-label', labels.search);
    const category = document.createElement('select');
    category.setAttribute('aria-label', labels.category);
    category.add(new Option(labels.all, ''));
    groups[locale].forEach((label, index) =>
        category.add(new Option(label, String(index))),
    );
    const message = document.createElement('p');
    message.setAttribute('aria-live', 'polite');
    const grid = document.createElement('div');
    grid.className = 'forum-emoji-grid';
    const more = document.createElement('button');
    more.type = 'button';
    more.textContent = labels.more;
    more.hidden = true;
    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.textContent = labels.close;
    const actions = document.createElement('div');
    actions.className = 'forum-emoji-actions';
    actions.append(more, dismiss);
    panel.append(search, category, message, grid, actions);
    container.append(toggle, panel);
    controls.append(container);
    let rows = [],
        matches = [],
        shown = 0,
        insert;
    function position() {
        if (panel.hidden) return;
        const viewport = window.visualViewport;
        const left = viewport?.offsetLeft || 0,
            top = viewport?.offsetTop || 0;
        const width = viewport?.width || window.innerWidth,
            height = viewport?.height || window.innerHeight;
        const anchor = toggle.getBoundingClientRect();
        const bottom = top + height - 12;
        const below = Math.max(0, bottom - anchor.bottom - 6);
        const above = Math.max(0, anchor.top - top - 18);
        const upwards = below < 280 && above > below;
        const available = Math.min(480, height - 24, upwards ? above : below);
        panel.style.width = Math.max(0, Math.min(384, width - 24)) + 'px';
        panel.style.maxHeight = Math.max(0, available) + 'px';
        panel.style.left =
            Math.max(
                left + 12,
                Math.min(anchor.left, left + width - panel.offsetWidth - 12),
            ) + 'px';
        panel.style.top =
            Math.max(
                top + 12,
                Math.min(
                    upwards
                        ? anchor.top - panel.offsetHeight - 6
                        : anchor.bottom + 6,
                    bottom - panel.offsetHeight,
                ),
            ) + 'px';
    }
    window.addEventListener('resize', position);
    window.addEventListener('scroll', position);
    window.visualViewport?.addEventListener('resize', position);
    window.visualViewport?.addEventListener('scroll', position);
    const close = (focus = false) => {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        if (focus) toggle.focus();
    };
    function append() {
        const fragment = document.createDocumentFragment();
        for (const row of matches.slice(shown, shown + 80)) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = row.emoji;
            button.title = row[locale];
            button.setAttribute('aria-label', row[locale]);
            button.addEventListener('click', () => {
                close();
                insert?.(row.emoji);
            });
            fragment.append(button);
        }
        grid.append(fragment);
        shown = Math.min(shown + 80, matches.length);
        more.hidden = shown >= matches.length;
    }
    function filter() {
        const terms = normalize(search.value.trim())
            .split(/\s+/)
            .filter(Boolean);
        matches = rows.filter(
            (row) =>
                (category.value === '' ||
                    String(row.group) === category.value) &&
                terms.every((term) => row.search.includes(term)),
        );
        shown = 0;
        grid.replaceChildren();
        grid.scrollTop = 0;
        message.textContent = matches.length
            ? `${matches.length} ${labels.results}`
            : labels.empty;
        append();
        position();
    }
    toggle.addEventListener('click', async () => {
        if (!panel.hidden) {
            close();
            return;
        }
        insert = capture();
        if (!insert) return;
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        search.focus();
        message.textContent = labels.loading;
        position();
        try {
            catalog ||= fetch(url)
                .then((response) => {
                    if (!response.ok)
                        throw new Error('Emoji catalog unavailable');
                    return response.json();
                })
                .then((data) =>
                    data.map((row) => ({
                        ...row,
                        search: normalize(row.search),
                    })),
                )
                .catch((error) => {
                    catalog = null;
                    throw error;
                });
            rows = await catalog;
            filter();
        } catch {
            message.textContent = labels.error;
            position();
        }
    });
    search.addEventListener('input', filter);
    category.addEventListener('change', filter);
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            grid.querySelector('button')?.focus();
        }
    });
    more.addEventListener('click', () => {
        const previous = grid.children.length;
        append();
        position();
        grid.children[previous]?.focus({ preventScroll: true });
        grid.scrollTop = grid.children[previous]?.offsetTop - grid.offsetTop;
    });
    dismiss.addEventListener('click', () => close(true));
    container.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        }
    });
    container.addEventListener('focusout', (event) => {
        if (event.relatedTarget && !container.contains(event.relatedTarget))
            close();
    });
    document.addEventListener('pointerdown', (event) => {
        if (!container.contains(event.target)) close();
    });
    return close;
}
