const http = require('http');
const https = require('https');

const baseUrl = process.env.BASE_URL || 'http://localhost:8000';
const employeeEmail = process.env.TEST_EMPLOYEE_EMAIL || 'test@test.com';
const employeePassword = process.env.TEST_EMPLOYEE_PASSWORD || 'testuser_1234';
const tenantAdminEmail = process.env.TEST_TENANT_ADMIN_EMAIL || 'admin@example.com';
const tenantAdminPassword = process.env.TEST_TENANT_ADMIN_PASSWORD || 'TestPass123!';
const platformEmail = process.env.TEST_PLATFORM_EMAIL || 'plat@form.com';
const platformPassword = process.env.TEST_PLATFORM_PASSWORD || 'Platform1234!';

const jar = () => new Map();

const updateJar = (cookieJar, setCookieHeader) => {
  if (!setCookieHeader) return;
  const headers = Array.isArray(setCookieHeader) ? setCookieHeader : [setCookieHeader];
  headers.forEach((entry) => {
    const part = entry.split(';')[0];
    const eq = part.indexOf('=');
    if (eq > 0) {
      const name = part.slice(0, eq).trim();
      const value = part.slice(eq + 1).trim();
      cookieJar.set(name, value);
    }
  });
};

const buildCookieHeader = (cookieJar) => {
  if (!cookieJar || cookieJar.size === 0) return '';
  return Array.from(cookieJar.entries())
    .map(([key, value]) => `${key}=${value}`)
    .join('; ');
};

const request = (method, path, { body, headers = {}, cookieJar } = {}) =>
  new Promise((resolve, reject) => {
    const url = new URL(path, baseUrl);
    const isHttps = url.protocol === 'https:';
    const client = isHttps ? https : http;
    const cookieHeader = buildCookieHeader(cookieJar);
    if (cookieHeader) {
      headers.Cookie = cookieHeader;
    }
    const options = {
      method,
      hostname: url.hostname,
      port: url.port || (isHttps ? 443 : 80),
      path: url.pathname + url.search,
      headers,
    };

    const req = client.request(options, (res) => {
      let data = '';
      res.setEncoding('utf8');
      res.on('data', (chunk) => {
        data += chunk;
      });
      res.on('end', () => {
        updateJar(cookieJar, res.headers['set-cookie']);
        resolve({
          status: res.statusCode,
          headers: res.headers,
          body: data,
        });
      });
    });

    req.on('error', reject);
    if (body) {
      req.write(body);
    }
    req.end();
  });

const formBody = (data) => {
  const params = new URLSearchParams();
  Object.entries(data).forEach(([key, value]) => {
    params.append(key, value);
  });
  return params.toString();
};

const extractCsrf = (html) => {
  const match = html.match(/name="csrf_token" value="([^"]+)"/);
  return match ? match[1] : '';
};

const extractFirstMatch = (html, pattern) => {
  const match = html.match(pattern);
  return match ? match[1] : null;
};

const loginAs = async (email, password) => {
  const cookies = jar();
  const loginPage = await request('GET', '/login', { cookieJar: cookies });
  const csrf = extractCsrf(loginPage.body);
  if (!csrf) {
    throw new Error('CSRF token not found on login page');
  }

  const body = formBody({
    email,
    password,
    csrf_token: csrf,
    'g-recaptcha-response': '',
  });
  const loginRes = await request('POST', '/login', {
    cookieJar: cookies,
    body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  return { cookies, loginRes };
};

test('health endpoint returns ok', async () => {
  const res = await request('GET', '/health');
  expect(res.status).toBe(200);
  expect(res.body).toBe('ok');
});

test('login page includes CSRF token', async () => {
  const res = await request('GET', '/login');
  expect(res.status).toBe(200);
  const csrf = extractCsrf(res.body);
  expect(csrf).not.toBe('');
});

test('csrf guard blocks login without token', async () => {
  const body = formBody({
    email: employeeEmail,
    password: employeePassword,
  });
  const res = await request('POST', '/login', {
    body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  expect(res.status).toBe(400);
  expect(res.body).toContain('invalid_csrf_token');
});

test('whoami returns 401 when unauthenticated', async () => {
  const res = await request('GET', '/whoami');
  expect(res.status).toBe(401);
});

test('static assets respond', async () => {
  const css = await request('GET', '/styles.css');
  expect(css.status).toBe(200);
  const headerJs = await request('GET', '/header.js');
  expect(headerJs.status).toBe(200);
});

test('security headers are present on login', async () => {
  const res = await request('GET', '/login');
  expect(res.status).toBe(200);
  expect(res.headers['x-frame-options']).toBeDefined();
  expect(res.headers['x-content-type-options']).toBeDefined();
  expect(res.headers['referrer-policy']).toBeDefined();
  expect(res.headers['content-security-policy']).toBeDefined();
});

test('employee can login and see dashboard', async () => {
  const { cookies, loginRes } = await loginAs(employeeEmail, employeePassword);
  expect(loginRes.status).toBe(303);
  expect(loginRes.headers.location).toBe('/dashboard');

  const dashboard = await request('GET', '/dashboard', { cookieJar: cookies });
  expect(dashboard.status).toBe(200);
  expect(dashboard.body).toContain('勤怠ダッシュボード');
});

test('employee punch requires CSRF and toggles session', async () => {
  const { cookies } = await loginAs(employeeEmail, employeePassword);
  const dashboard = await request('GET', '/dashboard', { cookieJar: cookies });
  const csrf = extractCsrf(dashboard.body);
  expect(csrf).not.toBe('');

  const body = formBody({ csrf_token: csrf });
  const punch = await request('POST', '/work-sessions/punch', {
    cookieJar: cookies,
    body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  expect(punch.status).toBe(303);
  expect(punch.headers.location).toBe('/dashboard');

  const punchOut = await request('POST', '/work-sessions/punch', {
    cookieJar: cookies,
    body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  expect(punchOut.status).toBe(303);
  expect(punchOut.headers.location).toBe('/dashboard');
});

test('employee cannot access admin pages', async () => {
  const { cookies } = await loginAs(employeeEmail, employeePassword);
  const res = await request('GET', '/admin/role-codes', { cookieJar: cookies });
  expect(res.status).toBe(303);
  expect(res.headers.location).toBe('/dashboard');
});

test('employee can access account and settings pages', async () => {
  const { cookies } = await loginAs(employeeEmail, employeePassword);
  const account = await request('GET', '/account', { cookieJar: cookies });
  expect(account.status).toBe(200);
  expect(account.body).toContain('アカウント設定');
  const mfa = await request('GET', '/settings/mfa', { cookieJar: cookies });
  expect(mfa.status).toBe(200);
  expect(mfa.body).toContain('二段階認証');
  const payrolls = await request('GET', '/payrolls', { cookieJar: cookies });
  expect(payrolls.status).toBe(200);
  expect(payrolls.body).toContain('給与明細');
});

test('login mfa page redirects when no pending mfa', async () => {
  const res = await request('GET', '/login/mfa');
  expect(res.status).toBe(303);
  expect(res.headers.location).toBe('/login');
});

test('mfa setup reset requires CSRF', async () => {
  const { cookies } = await loginAs(employeeEmail, employeePassword);
  const res = await request('POST', '/settings/mfa/totp/setup/reset', {
    cookieJar: cookies,
    body: formBody({}),
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  expect(res.status).toBe(400);
  expect(res.body).toContain('invalid_csrf_token');
});

test('passkey registration requires authentication', async () => {
  const res = await request('POST', '/passkeys/registration/options', {
    body: JSON.stringify({}),
    headers: { 'Content-Type': 'application/json' },
  });
  expect(res.status).toBe(303);
  expect(res.headers.location).toBe('/login');
});

test('tenant admin can access role code page', async () => {
  const { cookies, loginRes } = await loginAs(tenantAdminEmail, tenantAdminPassword);
  expect(loginRes.status).toBe(303);
  const page = await request('GET', '/admin/role-codes', { cookieJar: cookies });
  expect(page.status).toBe(200);
  expect(page.body).toContain('ロールコード');
});

test('tenant admin can open session editor and add session', async () => {
  const { cookies } = await loginAs(tenantAdminEmail, tenantAdminPassword);
  const dashboard = await request('GET', '/dashboard', { cookieJar: cookies });
  expect(dashboard.status).toBe(200);
  const employeeId = extractFirstMatch(dashboard.body, /\/admin\/employees\/(\d+)\/sessions/);
  expect(employeeId).not.toBeNull();

  const sessionsPage = await request('GET', `/admin/employees/${employeeId}/sessions`, { cookieJar: cookies });
  expect(sessionsPage.status).toBe(200);
  const csrf = extractCsrf(sessionsPage.body);
  expect(csrf).not.toBe('');

  const now = new Date();
  const start = new Date(now.getTime() - 60 * 60 * 1000);
  const format = (dt) => {
    const pad = (v) => String(v).padStart(2, '0');
    return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
  };
  const body = formBody({
    csrf_token: csrf,
    startTime: format(start),
    endTime: format(now),
  });
  const addRes = await request('POST', `/admin/employees/${employeeId}/sessions`, {
    cookieJar: cookies,
    body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  expect(addRes.status).toBe(303);
});

test('tenant admin can access timesheet and payslip pages', async () => {
  const { cookies } = await loginAs(tenantAdminEmail, tenantAdminPassword);
  const timesheets = await request('GET', '/admin/timesheets/export', { cookieJar: cookies });
  expect(timesheets.status).toBe(200);
  expect(timesheets.body).toContain('勤怠エクスポート');
  const payslips = await request('GET', '/admin/payslips', { cookieJar: cookies });
  expect(payslips.status).toBe(200);
  expect(payslips.body).toContain('給与明細');
  const payslipsSend = await request('GET', '/admin/payslips/send', { cookieJar: cookies });
  expect(payslipsSend.status).toBe(200);
  expect(payslipsSend.body).toContain('給与明細送信');
});

test('platform admin lands on tenant list after login', async () => {
  const { cookies, loginRes } = await loginAs(platformEmail, platformPassword);
  expect(loginRes.status).toBe(303);
  expect(loginRes.headers.location).toBe('/platform/tenants');

  const tenants = await request('GET', '/platform/tenants', { cookieJar: cookies });
  expect(tenants.status).toBe(200);
  expect(tenants.body).toContain('テナント管理');
});

test('employee cannot access platform admin pages', async () => {
  const { cookies } = await loginAs(employeeEmail, employeePassword);
  const res = await request('GET', '/platform/tenants', { cookieJar: cookies });
  expect(res.status).toBe(303);
  expect(res.headers.location).toBe('/login');
});

test('platform admin can create announcement and employee can see it', async () => {
  const { cookies: adminCookies, loginRes: adminLoginRes } = await loginAs(platformEmail, platformPassword);
  expect(adminLoginRes.status).toBe(303);

  const newPage = await request('GET', '/platform/announcements/new', { cookieJar: adminCookies });
  expect(newPage.status).toBe(200);
  const createCsrf = extractCsrf(newPage.body);
  expect(createCsrf).not.toBe('');

  const uniqueTitle = `自動テストお知らせ-${Date.now()}`;
  const createBody = formBody({
    csrf_token: createCsrf,
    title: uniqueTitle,
    body: '**太字**\n- リスト\n[リンク](https://example.com)',
    type: 'maintenance',
    status: 'published',
    show_on_login: '0',
    is_pinned: '0',
    publish_start_at: '',
    publish_end_at: '',
  });
  const createRes = await request('POST', '/platform/announcements', {
    cookieJar: adminCookies,
    body: createBody,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  expect(createRes.status).toBe(303);

  const { cookies: employeeCookies, loginRes: employeeLoginRes } = await loginAs(employeeEmail, employeePassword);
  expect(employeeLoginRes.status).toBe(303);

  const announcements = await request('GET', '/announcements', { cookieJar: employeeCookies });
  expect(announcements.status).toBe(200);
  expect(announcements.body).toContain(uniqueTitle);
});

test('employee can mark announcement as read', async () => {
  const { cookies } = await loginAs(employeeEmail, employeePassword);
  const page = await request('GET', '/announcements', { cookieJar: cookies });
  expect(page.status).toBe(200);
  const csrf = extractCsrf(page.body);
  expect(csrf).not.toBe('');

  const match = page.body.match(/\\/announcements\\/(\\d+)\\/read/);
  expect(match).not.toBeNull();
  const announcementId = match ? match[1] : null;
  expect(announcementId).not.toBeNull();

  const body = formBody({
    csrf_token: csrf,
    redirect: '/announcements',
  });
  const res = await request('POST', `/announcements/${announcementId}/read`, {
    cookieJar: cookies,
    body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  expect(res.status).toBe(303);
  expect(res.headers.location).toBe('/announcements');
});

test('account pages require authentication', async () => {
  const res = await request('GET', '/account');
  expect(res.status).toBe(303);
  expect(res.headers.location).toBe('/login');
});

test('password reset page is reachable', async () => {
  const res = await request('GET', '/password/reset');
  expect(res.status).toBe(200);
  expect(res.body).toContain('パスワードリセット');
});

test('password reset request sends mail and redirects', async () => {
  const page = await request('GET', '/password/reset');
  const csrf = extractCsrf(page.body);
  expect(csrf).not.toBe('');
  const body = formBody({
    email: employeeEmail,
    csrf_token: csrf,
  });
  const res = await request('POST', '/password/reset', {
    body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  expect(res.status).toBe(303);
  expect(res.headers.location).toBe('/password/reset');

  const fs = require('fs');
  const path = require('path');
  const logPath = path.join(__dirname, '..', 'php', 'storage', 'mail.log');
  if (fs.existsSync(logPath)) {
    const logContent = fs.readFileSync(logPath, 'utf8');
    expect(logContent).toContain('MAIL');
  }
});

test('register page is reachable', async () => {
  const res = await request('GET', '/register');
  expect(res.status).toBe(200);
  expect(res.body).toContain('従業員');
});
