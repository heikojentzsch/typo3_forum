import { test, expect } from '@playwright/test';

test('gallery groups by post, deduplicates attachments, navigates and restores focus', async ({
    page,
}) => {
    await page.goto('/gallery');
    const trigger = page
        .getByRole('link')
        .filter({ has: page.getByRole('img', { name: 'First', exact: true }) });
    await trigger.click();
    const gallery = page.getByRole('dialog', { name: 'Bildergalerie' });
    await expect(gallery).toBeVisible();
    await expect(gallery.getByRole('status')).toContainText('1 / 2');
    await gallery.getByRole('button', { name: 'Nächstes Bild' }).click();
    await expect(gallery.getByRole('img')).toHaveAttribute(
        'src',
        /gallery-two/,
    );
    await expect(gallery.getByRole('status')).toContainText('2 / 2');
    await page.keyboard.press('ArrowLeft');
    await expect(gallery.getByRole('status')).toContainText('1 / 2');
    await gallery.locator('.forum-gallery-stage').evaluate((stage) => {
        const touch = (x) =>
            new Touch({
                identifier: 1,
                target: stage,
                clientX: x,
                clientY: 100,
            });
        stage.dispatchEvent(
            new TouchEvent('touchstart', { touches: [touch(200)] }),
        );
        stage.dispatchEvent(
            new TouchEvent('touchend', { changedTouches: [touch(80)] }),
        );
    });
    await expect(gallery.getByRole('status')).toContainText('2 / 2');
    await page.keyboard.press('ArrowLeft');
    await gallery
        .getByRole('button', { name: 'Originalgröße', exact: true })
        .click();
    await expect(gallery.locator('.forum-gallery-stage')).toHaveClass(
        /forum-gallery-original/,
    );
    await gallery.getByRole('button', { name: 'An Fenster anpassen' }).click();
    await expect(gallery.locator('.forum-gallery-stage')).not.toHaveClass(
        /forum-gallery-original/,
    );
    expect(
        await gallery.evaluate(
            (dialog) => dialog.scrollHeight <= dialog.clientHeight,
        ),
    ).toBe(true);
    await expect(
        gallery.getByRole('link', { name: 'Original herunterladen' }),
    ).toHaveAttribute('href', /gallery-one/);
    await page.keyboard.press('Escape');
    await expect(gallery).not.toBeVisible();
    await expect(trigger).toBeFocused();
    expect(
        await page.locator('body').evaluate((body) => body.style.overflow),
    ).toBe('');
});

test('gallery reports missing originals and keeps a single-image post isolated', async ({
    page,
}) => {
    await page.goto('/gallery');
    await page.getByRole('img', { name: 'Missing', exact: true }).click();
    const gallery = page.getByRole('dialog');
    await expect(gallery.getByRole('status')).toHaveText(
        'Bild konnte nicht geladen werden.',
    );
    await expect(
        gallery.getByRole('button', { name: 'Nächstes Bild' }),
    ).toBeDisabled();
    await gallery.getByRole('button', { name: 'Schließen' }).click();
    await expect(gallery).not.toBeVisible();
});
