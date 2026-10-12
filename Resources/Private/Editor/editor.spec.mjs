import { test, expect } from '@playwright/test';
test('link dialog adds HTTPS without prompting and fixes the target to a new window', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('status')).toHaveText('Ready');
    await page.evaluate(() => { const editor = window.tinymce.activeEditor; editor.focus(); editor.execCommand('mceLink'); });
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Open link in...', { exact: true })).toHaveCount(0);
    await expect(dialog.getByRole('combobox', { name: /Open link in/ })).toHaveCount(0);
    await dialog.getByRole('combobox', { name: 'URL', exact: true }).fill('example.org/path');
    await dialog.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    await page.locator('#submit').click();
    const html = await page.locator('body').getAttribute('data-submitted');
    expect(html).toContain('href="https://example.org/path"');
    expect(html).toContain('target="_blank"');
    expect(html).toContain('noopener');
});
    test('searchable emoji catalog preserves selection and works without external requests', async ({ page }, info) => {
        const external = [];
        page.on('request', request => { if (!request.url().startsWith('http://127.0.0.1:8791/')) external.push(request.url()); });
        await page.goto('/');
        await expect(page.getByRole('status')).toHaveText('Ready');
            await expect(page.locator('.tox-tinymce')).toBeVisible();
            await page.evaluate(() => {
                const editor = window.tinymce.activeEditor;
                const text = editor.getBody().querySelector('strong').firstChild;
                editor.focus();
                const range = editor.getDoc().createRange(); range.setStart(text, 1); range.setEnd(text, 3); editor.selection.setRng(range);
            });
            await expect(page.locator('.tox-statusbar__branding')).toHaveCount(0);
        await page.getByRole('button', { name: 'Choose emojis', exact: false }).click();
        const panel = page.locator('.forum-emoji-panel');
        await expect(panel).toBeVisible();
        const search = panel.getByRole('searchbox');
        await search.fill('Rakete');
        await expect(panel.getByRole('button', { name: 'rocket', exact: true })).toBeVisible();
        await page.screenshot({ path: `../../../var/emoji-tinymce-${info.project.name}.png`, fullPage: true });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await panel.getByRole('button', { name: 'rocket', exact: true }).click();
        await page.locator('#submit').click();
        expect(await page.locator('body').getAttribute('data-submitted')).toContain('<strong>B🚀d</strong>');
        await page.getByRole('button', { name: 'Choose emojis', exact: false }).click();
        await search.fill('thumbs up medium skin');
        await panel.getByRole('button', { name: 'thumbs up: medium skin tone', exact: true }).click();
        await page.locator('#submit').click();
        expect(await page.locator('body').getAttribute('data-submitted')).toContain('👍🏽');
        await page.getByRole('button', { name: 'Choose emojis', exact: false }).click();
        await search.fill('');
        await expect(panel.locator('.forum-emoji-grid button')).toHaveCount(80);
        await panel.getByRole('button', { name: 'Show more', exact: true }).click();
        await expect(panel.locator('.forum-emoji-grid button')).toHaveCount(160);
        await panel.getByRole('combobox').selectOption('9');
        await search.fill('Germany');
        await expect(panel.getByRole('button', { name: 'flag: Germany', exact: true })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(panel).toBeHidden();
        expect(external).toEqual([]);
    });
test('German picker retries a failed local catalog load and does not submit on search Enter', async ({ page }) => {
    await page.route('**/emojis.json', route => route.abort(), { times: 1 });
    await page.goto('/');
    await expect(page.getByRole('status')).toHaveText('Ready');
    await page.getByRole('button', { name: 'Choose emojis', exact: false }).click();
    await expect(page.locator('.forum-emoji-panel')).toContainText('Could not load emojis');
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Choose emojis', exact: false }).click();
    await page.getByRole('searchbox').fill('rocket');
    await page.getByRole('searchbox').press('Enter');
    await expect(page.locator('body')).not.toHaveAttribute('data-submitted');
    await expect(page.locator('.forum-emoji-grid').getByRole('button', { name: 'rocket', exact: true })).toBeVisible();
    // Mounting follows the document language, as TYPO3 renders it before scripts run.
    await page.route('http://127.0.0.1:8791/', async route => {
        const response = await route.fetch();
        await route.fulfill({ response, body: (await response.text()).replace('<html>', '<html lang="de">') });
    });
    await page.reload();
    await expect(page.getByRole('status')).toHaveText('Ready');
    await page.getByRole('button', { name: 'Emojis auswählen', exact: false }).click();
    await page.getByRole('searchbox').fill('Rakete');
    await expect(page.locator('.forum-emoji-grid').getByRole('button', { name: 'Rakete', exact: true })).toBeVisible();
});
test('TinyMCE preserves formatting, quote references, Unicode and submission without an editor selector', async ({ page }, info) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.goto('/');
    await expect(page.getByRole('status')).toHaveText('Ready');
    await expect(page.locator('.forum-editor-controls > select')).toHaveCount(0);
    await expect(page.locator('.tox-tinymce')).toBeVisible();
    await expect(page.locator('#editor')).toBeHidden();
    await page.locator('#submit').click();
    const html = await page.locator('body').getAttribute('data-submitted');
    for (const text of ['<strong>Bold</strong>', '<em>Italic</em>', '<u>Underline</u>', '🙂', '👨‍👩‍👧‍👦', 'data-post="42"', 'Item</li>', 'echo 42;']) expect(html).toContain(text);
    await page.screenshot({ path: `../../../var/editor-${info.project.name}.png`, fullPage: true });
    await page.reload();
    await expect(page.getByRole('status')).toHaveText('Ready');
    await expect(page.locator('.tox-tinymce')).toBeVisible();
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('loading failure preserves the original text in an editable, submittable fallback', async ({ page }) => {
    await page.route('**/tinymce.min.js', route => route.abort());
    await page.goto('/');
    await expect(page.getByRole('status')).toHaveText('Load failed');
    await expect(page.locator('#editor')).toBeVisible();
    await expect(page.locator('#editor')).toBeEditable();
    await page.locator('#submit').click();
    expect(await page.locator('body').getAttribute('data-submitted')).toContain('👨‍👩‍👧‍👦');
    await page.locator('#editor').fill('<p>Fallback 🙂</p>');
    await page.locator('#submit').click();
    expect(await page.locator('body').getAttribute('data-submitted')).toBe('<p>Fallback 🙂</p>');
});

test('emoji dropdown stays inside the visible viewport near the bottom and after resize', async ({ page }, info) => {
    await page.goto('/');
    await expect(page.getByRole('status')).toHaveText('Ready');
    await page.evaluate(() => {
        const controls = document.querySelector('.forum-editor-controls');
        controls.style.position = 'fixed'; controls.style.bottom = '12px'; controls.style.left = '12px'; controls.style.zIndex = '1200';
    });
    await page.getByRole('button', { name: 'Choose emojis', exact: false }).click();
    const panel = page.locator('.forum-emoji-panel');
    await expect(panel.locator('.forum-emoji-grid button')).toHaveCount(80);
    async function checkBounds() {
        const box = await panel.boundingBox();
        const viewport = page.viewportSize();
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
        expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);
    }
    await checkBounds();
    const initialHeight = (await panel.boundingBox()).height;
    for (let i = 0; i < 4; i++) await panel.getByRole('button', { name: 'Show more', exact: true }).click();
    expect((await panel.boundingBox()).height).toBeLessThanOrEqual(480);
    expect((await panel.boundingBox()).height).toBeLessThanOrEqual(initialHeight + 1);
    expect(await panel.evaluate(element => element.scrollHeight <= element.clientHeight)).toBe(true);
    expect(await panel.locator('.forum-emoji-grid').evaluate(element => element.scrollHeight > element.clientHeight)).toBe(true);
    await page.setViewportSize({ width: 390, height: 360 });
    await page.waitForFunction(() => {
        const rect = document.querySelector('.forum-emoji-panel').getBoundingClientRect();
        return rect.bottom <= window.innerHeight && rect.right <= window.innerWidth;
    });
    await checkBounds();
    await page.screenshot({ path: `../../../var/emoji-bounded-${info.project.name}.png`, fullPage: false });
    await panel.getByRole('searchbox').fill('Rakete');
    await panel.getByRole('button', { name: 'rocket', exact: true }).click();
    await expect(panel).toBeHidden();
});
