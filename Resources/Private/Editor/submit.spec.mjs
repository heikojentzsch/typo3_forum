import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    await page.goto('/submit-fixture');
    await page.waitForFunction(() => window.submitGuardReady);
});

test('valid submissions lock once, animate, preserve action and reset on return', async ({
    page,
}) => {
    const requests = [];
    page.on('request', (request) => {
        if (request.url().endsWith('/sent')) requests.push(request);
    });
    await page.locator('[name=subject]').fill('Example');
    const state = await page.evaluate(() => {
        const form = document.querySelector('form');
        const button = document.querySelector('#primary');
        form.requestSubmit(button);
        form.requestSubmit(button);
        return {
            disabled: button.disabled,
            externalDisabled: document.querySelector('#external').disabled,
            spinner: !!button.querySelector('.forum-submit-spinner'),
            label: button.textContent.trim(),
        };
    });
    expect(state).toEqual({
        disabled: true,
        externalDisabled: true,
        spinner: true,
        label: 'Wird gesendet …',
    });
    await page.waitForURL('**/sent');
    expect(requests).toHaveLength(1);
    expect(new URLSearchParams(requests[0].postData()).get('action')).toBe(
        'save',
    );
    await page.goBack();
    await expect(page.locator('#primary')).toBeEnabled();
    await expect(page.locator('#primary')).toHaveText('Beitrag erstellen');
    await expect(page.locator('.forum-submit-spinner')).toHaveCount(0);
});

test('invalid and cancelled forms remain editable', async ({ page }) => {
    await page.locator('#primary').click();
    await expect(page.locator('#primary')).toBeEnabled();
    await expect(page.locator('.forum-submit-spinner')).toHaveCount(0);
    await page.locator('[name=subject]').fill('Example');
    await page.evaluate(() =>
        document
            .querySelector('form')
            .addEventListener('submit', (event) => event.preventDefault()),
    );
    await page.locator('#primary').click();
    await expect(page.locator('#primary')).toBeEnabled();
    await expect(page.locator('form')).not.toHaveAttribute('aria-busy');
});

test('external input submitters show loading without losing their value', async ({
    page,
}) => {
    await page.locator('[name=subject]').fill('Example');
    const request = page.waitForRequest('**/sent');
    const state = await page.evaluate(() => {
        const input = document.querySelector('#external');
        input.click();
        return {
            hidden: getComputedStyle(input).display === 'none',
            spinner: !!document.querySelector('.forum-submit-spinner'),
        };
    });
    expect(state).toEqual({ hidden: true, spinner: true });
    expect(new URLSearchParams((await request).postData()).get('action')).toBe(
        'external',
    );
    await page.waitForURL('**/sent');
});
