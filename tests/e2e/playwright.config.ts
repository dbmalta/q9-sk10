import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './specs',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: process.env.CI ? 'github' : 'html',
  timeout: 30_000,

  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'mobile',
      // Use Chromium with iPhone-like viewport/userAgent to verify responsive CSS
      // without requiring WebKit (which needs extra Linux system deps in WSL).
      use: {
        ...devices['Pixel 5'],
      },
    },
  ],

  webServer: process.env.CI
    ? undefined
    : {
        command: 'php -S localhost:8080 -t .',
        port: 8080,
        cwd: '../../',
        reuseExistingServer: true,
        timeout: 10_000,
      },
});
