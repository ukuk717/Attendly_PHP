const { test, expect } = require('@playwright/test');

const users = {
  employee: {
    email: process.env.TEST_EMPLOYEE_EMAIL || 'test@test.com',
    password: process.env.TEST_EMPLOYEE_PASSWORD || 'testuser_1234',
    dashboardTitle: '勤怠ダッシュボード',
  },
  platform: {
    email: process.env.TEST_PLATFORM_EMAIL || 'plat@form.com',
    password: process.env.TEST_PLATFORM_PASSWORD || 'Platform1234!',
    landingPath: '/platform/tenants',
  },
};

test('health endpoint returns ok', async ({ request }) => {
  const response = await request.get('/health');
  expect(response.ok()).toBeTruthy();
  await expect(response.text()).resolves.toBe('ok');
});

test('employee can login and logout', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('メールアドレス').fill(users.employee.email);
  await page.getByLabel('パスワード').fill(users.employee.password);
  await page.getByRole('button', { name: 'ログイン' }).click();

  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByRole('heading', { name: users.employee.dashboardTitle })).toBeVisible();

  await page.getByRole('button', { name: 'ログアウト' }).click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole('button', { name: 'ログイン' })).toBeVisible();
});

test('platform admin lands on tenant list after login', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('メールアドレス').fill(users.platform.email);
  await page.getByLabel('パスワード').fill(users.platform.password);
  await page.getByRole('button', { name: 'ログイン' }).click();

  await expect(page).toHaveURL(new RegExp(`${users.platform.landingPath}$`));
  await expect(page.getByRole('heading', { name: 'テナント管理' })).toBeVisible();
});
