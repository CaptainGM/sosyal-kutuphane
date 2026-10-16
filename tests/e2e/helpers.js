// Tarayıcı testlerinin ortak yardımcıları: geçici kullanıcı oluşturma/silme ve giriş.
// Kullanıcılar scripts/e2e-user.php ile (yalnızca "e2e_" önekli) doğrudan veritabanına yazılır; böylece
// e-posta doğrulaması beklenmez ve testler birbirinin verisini kirletmez.
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const PASSWORD = 'Flow1234!';

function php(...args) {
    return execFileSync('php', [path.join(ROOT, 'scripts', 'e2e-user.php'), ...args], { cwd: ROOT, encoding: 'utf8', env: process.env });
}

function uniqueName(prefix) {
    return `e2e_${prefix}_${Math.random().toString(36).slice(2, 8)}`;
}

function createUser(prefix, { admin = false } = {}) {
    const username = uniqueName(prefix);
    php('create', username, ...(admin ? ['admin'] : []));
    return { username, email: `${username}@test.com`, password: PASSWORD };
}

function deleteUsers(...users) {
    for (const user of users) {
        try {
            php('delete', user.username);
        } catch (error) {
            console.warn(`Kullanıcı silinemedi: ${user.username} (${error.message.split('\n')[0]})`);
        }
    }
}

async function loginAs(page, user) {
    await page.goto('/index.html');
    await page.fill('#loginEmail', user.email);
    await page.fill('#loginPassword', user.password);
    await page.click('#loginSubmitBtn');
    await page.waitForURL('**/search.html');
}

// Sayfa içi API çağrısı: oturum çerezi ve CSRF başlığıyla (app.authHeaders) gider.
function api(page, method, url, body) {
    return page.evaluate(async ([m, u, b]) => {
        await app.initPromise;
        const response = await fetch(u, { method: m, headers: app.authHeaders(), credentials: 'include', body: b ? JSON.stringify(b) : undefined });
        return response.json();
    }, [method, url, body]);
}

module.exports = { createUser, deleteUsers, loginAs, api, PASSWORD };
