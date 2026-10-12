import { chromium } from '@playwright/test';
import { readFile } from 'node:fs/promises';
const base = process.env.DDEV_PRIMARY_URL;
if (!base) throw new Error('Run inside DDEV.');
const credentials = JSON.parse(
    await readFile('/var/www/html/.bootstrap/credentials.json', 'utf8'),
);
const browser = await chromium.launch();
try {
    const page = await browser.newPage({ ignoreHTTPSErrors: true });
    await page.goto(base + '/login');
    await page.locator('input[name=user]').fill(credentials.member.username);
    await page.locator('input[name=pass]').fill(credentials.member.password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login')),
        page.locator('form:has(input[name=user]) [type=submit]').click(),
    ]);
    await page.goto(base + '/forum');
    await page
        .getByRole('link', { name: 'Development forum', exact: true })
        .first()
        .click();
    const form = page.locator('form').filter({ has: page.locator('button') });
    const candidates = await form.evaluateAll((forms) =>
        forms.map((f) => ({
            action: f.action,
            body: Object.fromEntries(new FormData(f)),
        })),
    );
    const mutation = candidates.find(
        (f) =>
            f.action.includes('subscribe') ||
            Object.values(f.body).includes('subscribe'),
    );
    if (!mutation) throw new Error('Subscription POST form missing.');
    if (!mutation.body.__RequestToken)
        throw new Error('Request token missing.');
    let response = await page.request.get(mutation.action, { maxRedirects: 0 });
    if (
        response.status() !== 200 ||
        !(await response.text()).includes('__RequestToken')
    )
        throw new Error(
            'Subscription GET confirmation missing: ' + response.status(),
        );
    const missing = { ...mutation.body };
    delete missing.__RequestToken;
    response = await page.request.post(mutation.action, {
        form: missing,
        maxRedirects: 0,
    });
    if (response.status() !== 403)
        throw new Error('Missing token not rejected: ' + response.status());
    response = await page.request.post(mutation.action, {
        form: { ...missing, __RequestToken: 'forged' },
        maxRedirects: 0,
    });
    if (response.status() !== 403)
        throw new Error('Invalid token not rejected: ' + response.status());
    response = await page.request.post(mutation.action, {
        form: mutation.body,
        maxRedirects: 0,
    });
    if (response.status() !== 303)
        throw new Error(
            'Valid token rejected: ' +
                response.status() +
                ' ' +
                (await response.text()).slice(0, 200),
        );
    await page.reload();
    const restore = await page.locator('form').evaluateAll((forms) =>
        forms
            .map((f) => ({
                action: f.action,
                body: Object.fromEntries(new FormData(f)),
            }))
            .find((f) => f.action.includes('subscribe')),
    );
    if (!restore) throw new Error('Subscription restore form missing.');
    response = await page.request.post(restore.action, {
        form: restore.body,
        maxRedirects: 0,
    });
    if (response.status() !== 303)
        throw new Error('Subscription restore failed.');
    console.log(
        'Real TYPO3 CSRF check passed: GET confirmation, missing/forged token 403, valid POST 303; subscription restored.',
    );
} finally {
    await browser.close();
}
