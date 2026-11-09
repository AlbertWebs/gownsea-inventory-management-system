import { defineConfig, devices } from '@playwright/test';

const databasePath = '/tmp/gownsea-playwright-e2e.sqlite';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    // The PHP development server and temporary SQLite database are single-process fixtures.
    workers: 1,
    reporter: process.env.CI ? 'github' : 'list',
    globalSetup: './tests/e2e/setup.ts',
    use: {
        baseURL: 'http://127.0.0.1:8017',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{
        name: 'chromium',
        use: {
            ...devices['Desktop Chrome'],
            ...(process.env.CHROME_BIN ? { launchOptions: { executablePath: process.env.CHROME_BIN } } : {}),
        },
    }],
    webServer: {
        command: `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=${databasePath} DB_URL='' SESSION_DRIVER=file CACHE_STORE=array QUEUE_CONNECTION=sync php -S 127.0.0.1:8017 -t public tests/e2e/router.php`,
        url: 'http://127.0.0.1:8017/login',
        // Never reuse a local server: its environment may point at the developer database.
        reuseExistingServer: false,
        timeout: 30_000,
        env: {
            APP_ENV: 'testing',
            APP_DEBUG: 'false',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: databasePath,
            DB_URL: '',
            SESSION_DRIVER: 'file',
            CACHE_STORE: 'array',
            QUEUE_CONNECTION: 'sync',
        },
    },
});
