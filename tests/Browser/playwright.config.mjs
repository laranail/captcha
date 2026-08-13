import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: '.',
    testMatch: '*.spec.mjs',
    // Fails the run if the fixtures no longer match the template they were rendered from.
    // Without it a standalone `npx playwright test` passes against a previous render.
    globalSetup: './global-setup.mjs',
    // A failure here is a real defect in the runtime, never a flaky network — the suite blocks
    // every non-file:// request. Retrying would only hide a race in our own code.
    retries: 0,
    fullyParallel: true,
    reporter: 'list',
    use: {
        ...devices['Desktop Chrome'],
        // The fixtures are file:// pages written by the Pest test that invokes this config.
        baseURL: undefined,
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
