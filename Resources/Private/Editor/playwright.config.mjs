import { defineConfig } from '@playwright/test';
export default defineConfig({
    testDir: '.',
    testMatch: '*.spec.mjs',
    workers: 1,
    outputDir: '../../../var/editor-tests',
    use: { baseURL: 'http://127.0.0.1:8791', headless: true },
    webServer: {
        command: 'node test-server.mjs',
        url: 'http://127.0.0.1:8791',
        reuseExistingServer: false,
    },
    projects: [
        { name: 'desktop', use: { viewport: { width: 1280, height: 900 } } },
        {
            name: 'mobile',
            use: {
                viewport: { width: 390, height: 844 },
                isMobile: true,
                hasTouch: true,
            },
        },
    ],
});
