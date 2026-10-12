// Optional read-only acceptance check against the local DDEV fixture.
import { chromium } from '@playwright/test';
import { readFile } from 'node:fs/promises';
const base = process.env.DDEV_PRIMARY_URL;
if (!base) throw new Error('Run inside the DDEV web container.');
const credentials = JSON.parse(await readFile('/var/www/html/.bootstrap/credentials.json', 'utf8'));
const browser = await chromium.launch();
try {
    const page = await browser.newPage({ ignoreHTTPSErrors: true });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/login');
    await page.locator('input[name=user]').fill(credentials.member.username);
    await page.locator('input[name=pass]').fill(credentials.member.password);
    await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('form:has(input[name=user]) [type=submit]').click()]);
    await page.goto(base + '/forum');
    const forumLink = await page.getByRole('link', { name: 'Development forum', exact: true }).first().getAttribute('href');
    if (!forumLink) throw new Error('Managed forum link not found.');
    await page.goto(new URL(forumLink, base).href);
    const newLink = await page.locator('a[href$="/new"]').first().getAttribute('href');
    if (!newLink) throw new Error('New topic link not found.');
    const response = await page.goto(new URL(newLink, base).href);
    if (response.status() !== 200) throw new Error('Topic form failed: ' + response.status());
    await page.locator('.tox-tinymce').waitFor({ state: 'visible' });
    if (await page.locator('.forum-rich-editor > textarea').isVisible()) throw new Error('Fallback textarea is visible while the rich editor is active.');
    await page.locator('.forum-emoji-picker > button').click();
    await page.locator('.forum-emoji-grid button').first().waitFor({ state: 'visible' });
    for (let i = 0; i < 4; i++) await page.locator('.forum-emoji-actions button').first().click();
    const picker = page.locator('.forum-emoji-panel');
    if (!(await picker.evaluate(element => element.scrollHeight <= element.clientHeight))) throw new Error('Emoji popup has an outer scrollbar.');
    if ((await picker.boundingBox()).height > 480) throw new Error('Emoji popup is too tall.');
    await page.screenshot({ path: '../../../var/emoji-typo3-compact.png', fullPage: false });
    await page.locator('.forum-emoji-panel input').fill('Rakete');
    await page.locator('.forum-emoji-grid button').waitFor({ state: 'visible' });
    await page.locator('.forum-emoji-grid button').click();
    if (!(await page.locator('.forum-rich-editor > textarea').inputValue()).includes('🚀')) throw new Error('Emoji insertion failed.');
    if (errors.length) throw new Error(errors.join('\n'));
    await page.screenshot({ path: '../../../var/editor-typo3-form.png', fullPage: true });
    const preview = await page.request.post(base + '/forum?type=43568275', { form: {
        'tx_typo3forum_ajax[action]': 'preview', 'tx_typo3forum_ajax[controller]': 'Ajax',
        'tx_typo3forum_ajax[text]': '<blockquote data-post="1"><p>Quoted content 🙂</p></blockquote><p><a href="https://example.org">Link</a></p>'
    } });
    if (preview.status() !== 200) throw new Error('Quote preview failed: ' + preview.status());
    const previewHtml = await preview.text();
    if (!previewHtml.includes('forum-quote-source')) throw new Error('Quote source link missing in TYPO3 rendering.');
    await page.setContent(previewHtml);
    const sourceLink = page.locator('.forum-quote-source a');
    const sourceUrl = await sourceLink.getAttribute('href');
    if (!sourceUrl) throw new Error('Original post URL missing.');
    const border = await page.locator('blockquote').evaluate(element => getComputedStyle(element).borderTopWidth);
    if (border === '0px') throw new Error('Quote frame is missing.');
    await page.screenshot({ path: '../../../var/quote-preview.png', fullPage: true });
    const original = await page.goto(new URL(sourceUrl, base).href);
    if (original.status() !== 200 || !new URL(page.url()).hash.startsWith('#post-')) throw new Error('Original-post link did not resolve to the post anchor.');
    console.log('TYPO3 form, emoji insertion, quote frame and original-post link passed; no post submitted.');
} finally { await browser.close(); }
