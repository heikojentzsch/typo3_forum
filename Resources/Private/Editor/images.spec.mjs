import { test, expect } from '@playwright/test';

async function png(page) {
    return Buffer.from(
        await page.evaluate(() => {
            const canvas = document.createElement('canvas');
            canvas.width = 2400;
            canvas.height = 1200;
            canvas.getContext('2d').fillRect(0, 0, 2400, 1200);
            return canvas.toDataURL('image/png').split(',')[1];
        }),
        'base64',
    );
}

test('image picker preserves original photos, preserves the reference and omits removed uploads', async ({
    page,
}) => {
    await page.goto('/');
    await expect(page.getByRole('status')).toHaveText('Ready');
    await page.locator('.forum-rich-editor input[type=file]').setInputFiles({
        name: 'photo.png',
        mimeType: 'image/png',
        buffer: await png(page),
    });
    const image = page.frameLocator('iframe').locator('img[data-forum-image]');
    await expect(image).toHaveCount(1);
    await expect
        .poll(() => image.evaluate((img) => img.naturalWidth))
        .toBe(2400);
    await expect(page.locator('input[data-forum-inline-upload]')).toHaveCount(
        1,
    );
    const uploaded = await page
        .locator('input[data-forum-inline-upload]')
        .evaluate((input) => ({
            name: input.files[0].name,
            size: input.files[0].size,
        }));
    expect(uploaded.name).toMatch(/^forum-image-[a-f0-9]{32}--photo\.png$/);
    expect(uploaded.size).toBe((await png(page)).length);
    const stored = await page
        .locator('input[data-forum-inline-upload]')
        .evaluate(async (input) =>
            Array.from(new Uint8Array(await input.files[0].arrayBuffer())),
        );
    expect(Buffer.from(stored)).toEqual(await png(page));
    await page.locator('#submit').click();
    expect(await page.locator('body').getAttribute('data-submitted')).toContain(
        'data-forum-image=',
    );
    await page.evaluate(() =>
        window.tinymce.activeEditor.setContent('<p>Removed</p>'),
    );
    await page.locator('#submit').click();
    await expect(
        page.locator('input[data-forum-inline-upload]'),
    ).toBeDisabled();
});

for (const eventName of ['paste', 'drop']) {
    test(`${eventName} inserts a local image without uploading it immediately`, async ({
        page,
    }) => {
        await page.goto('/');
        await expect(page.getByRole('status')).toHaveText('Ready');
        const requests = [];
        page.on('request', (req) => {
            if (req.method() === 'POST') requests.push(req.url());
        });
        await page.evaluate(
            ({ eventName, base64 }) => {
                const bytes = Uint8Array.from(atob(base64), (c) =>
                    c.charCodeAt(0),
                );
                const transfer = new DataTransfer();
                transfer.items.add(
                    new File([bytes], 'photo.png', { type: 'image/png' }),
                );
                const event =
                    eventName === 'paste'
                        ? new ClipboardEvent('paste', {
                              clipboardData: transfer,
                              bubbles: true,
                              cancelable: true,
                          })
                        : new DragEvent('drop', {
                              dataTransfer: transfer,
                              bubbles: true,
                              cancelable: true,
                          });
                window.tinymce.activeEditor.getBody().dispatchEvent(event);
            },
            { eventName, base64: (await png(page)).toString('base64') },
        );
        await expect(
            page.frameLocator('iframe').locator('img[data-forum-image]'),
        ).toHaveCount(1);
        expect(requests).toEqual([]);
    });
}

test('unsupported image formats display an error without inserting content', async ({
    page,
}) => {
    await page.goto('/');
    await expect(page.getByRole('status')).toHaveText('Ready');
    await page.locator('.forum-rich-editor input[type=file]').setInputFiles({
        name: 'image.svg',
        mimeType: 'image/svg+xml',
        buffer: Buffer.from('<svg></svg>'),
    });
    await expect(
        page.locator('.forum-rich-editor > [role=alert]'),
    ).toContainText('Picture not inserted');
    await expect(
        page.frameLocator('iframe').locator('img[data-forum-image]'),
    ).toHaveCount(0);
});
