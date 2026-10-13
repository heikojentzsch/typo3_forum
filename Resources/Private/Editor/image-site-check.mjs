// Mutating acceptance check for the managed synthetic DDEV fixture only.
// Creates a temporary moderator topic and deletes it after successful checks.
import { chromium, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
const base = process.env.DDEV_PRIMARY_URL;
if (!base || !new URL(base).hostname.endsWith('.ddev.site'))
    throw new Error('Run inside the managed DDEV fixture.');
const credentials = JSON.parse(
    await readFile('/var/www/html/.bootstrap/credentials.json', 'utf8'),
);
const format = process.env.FORUM_IMAGE_TEST_FORMAT || 'png';
if (!['png', 'jpeg', 'webp'].includes(format))
    throw new Error('Unsupported test format.');
const browser = await chromium.launch();
async function login(role) {
    const page = await browser.newPage({ ignoreHTTPSErrors: true });
    await page.goto(base + '/login');
    await page.locator('input[name=user]').fill(credentials[role].username);
    await page.locator('input[name=pass]').fill(credentials[role].password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login')),
        page.locator('form:has(input[name=user]) [type=submit]').click(),
    ]);
    return page;
}
async function ready(page) {
    await expect
        .poll(() =>
            page
                .locator('.forum-rich-editor')
                .evaluate(
                    (wrapper) =>
                        wrapper.querySelector('[role=status]').textContent ===
                        wrapper.dataset.ready,
                ),
        )
        .toBe(true);
}
try {
    const page = await login('moderator');
    await page.goto(base + '/forum/moderator-forum');
    await page.locator('a[href$="/new"]').first().click();
    await ready(page);
    await page
        .locator('#subject')
        .fill('Inline image acceptance ' + Date.now());
    await page.evaluate(() => window.tinymce.activeEditor.setContent(''));
    const buffer = Buffer.from(
        await page.evaluate((format) => {
            const canvas = document.createElement('canvas');
            canvas.width = 2400;
            canvas.height = 1200;
            canvas.getContext('2d').fillRect(0, 0, 2400, 1200);
            return canvas.toDataURL('image/' + format).split(',')[1];
        }, format),
        'base64',
    );
    await page.locator('.forum-rich-editor input[type=file]').setInputFiles({
        name: 'photo.' + format,
        mimeType: 'image/' + format,
        buffer,
    });
    await expect(
        page.frameLocator('iframe').locator('img[data-forum-image]'),
    ).toHaveCount(1);
    await expect(
        page.locator('.forum-rich-editor > [role=alert]'),
    ).toBeHidden();
    await Promise.all([
        page.waitForNavigation(),
        page.locator('#topic input[type=submit]').click(),
    ]);
    const topicUrl = page.url();
    const image = page.locator(
        '.tx-typo3forum-parsed-text img[data-forum-image]',
    );
    await expect
        .poll(() => image.evaluate((img) => img.naturalWidth))
        .toBe(800);
    const imageUrl = new URL(await image.getAttribute('src'), base).href;
    const allowed = await page.request.get(imageUrl);
    expect(allowed.status()).toBe(200);
    expect(allowed.headers()['content-type']).toBe('image/png');
    expect(allowed.headers()['cache-control']).toContain('no-store');
    const originalUrl = new URL(
        await image.locator('..').getAttribute('href'),
        base,
    ).href;
    const original = await page.request.get(originalUrl);
    expect(original.headers()['content-type']).toBe('image/' + format);
    expect(await original.body()).toEqual(buffer);
    await image.click();
    const gallery = page.getByRole('dialog', { name: 'Bildergalerie' });
    await expect(gallery).toBeVisible();
    await expect
        .poll(() => gallery.locator('img').evaluate((img) => img.naturalWidth))
        .toBe(2400);
    await expect(gallery.getByRole('status')).toContainText('1 / 1');
    await gallery
        .getByRole('button', { name: 'Originalgröße', exact: true })
        .click();
    await expect(gallery.locator('.forum-gallery-stage')).toHaveClass(
        /forum-gallery-original/,
    );
    await page.screenshot({ path: '../../../var/gallery-original.png' });
    await page.keyboard.press('Escape');
    await expect(gallery).not.toBeVisible();
    const anonymous = await browser.newContext({ ignoreHTTPSErrors: true });
    expect((await anonymous.request.get(imageUrl)).status()).toBe(403);
    expect((await anonymous.request.get(originalUrl)).status()).toBe(403);
    const member = await login('member');
    expect((await member.request.get(imageUrl)).status()).toBe(403);
    expect((await member.request.get(originalUrl)).status()).toBe(403);
    await page.getByRole('link', { name: 'Zitieren', exact: true }).click();
    await ready(page);
    await expect
        .poll(() =>
            page
                .frameLocator('iframe')
                .locator('img[data-forum-image]')
                .evaluate((img) => img.naturalWidth),
        )
        .toBe(800);
    await page.goto(topicUrl);
    await page.getByRole('link', { name: 'Bearbeiten', exact: true }).click();
    await ready(page);
    await expect
        .poll(() =>
            page
                .frameLocator('iframe')
                .locator('img[data-forum-image]')
                .evaluate((img) => img.naturalWidth),
        )
        .toBe(800);
    await page.evaluate(() => {
        const editor = window.tinymce.activeEditor;
        editor.setContent(editor.getContent() + '<p>Edited caption</p>');
    });
    await Promise.all([
        page.waitForNavigation(),
        page.locator('#post input[type=submit]').click(),
    ]);
    await expect(page.locator('.tx-typo3forum-parsed-text')).toContainText(
        'Edited caption',
    );
    expect((await page.request.get(imageUrl)).status()).toBe(200);
    await page.getByRole('link', { name: 'Bearbeiten', exact: true }).click();
    await ready(page);
    await page.evaluate(() =>
        window.tinymce.activeEditor.setContent('<p>Photo removed</p>'),
    );
    await Promise.all([
        page.waitForNavigation(),
        page.locator('#post input[type=submit]').click(),
    ]);
    await expect(
        page.locator('.tx-typo3forum-parsed-text img[data-forum-image]'),
    ).toHaveCount(0);
    expect((await page.request.get(imageUrl)).status()).toBe(404);
    expect((await page.request.get(originalUrl)).status()).toBe(404);
    await page.getByRole('link', { name: 'Löschen', exact: true }).click();
    await Promise.all([
        page.waitForNavigation(),
        page.locator('input[form=delete][type=submit]').click(),
    ]);
    expect(page.url()).toContain('/forum/moderator-forum');
    console.log(
        'Inline images: image-only create, original byte equality, private thumbnails, gallery, quote/edit, anonymous/member denial, removal and topic cleanup passed.',
    );
} finally {
    await browser.close();
}
