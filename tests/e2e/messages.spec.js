// Mesajlar: iki kullanıcı arasında gönderme, okunmamış sayacı, yanıt, HTML kaçışı ve mobil görünüm.
const { test, expect } = require('@playwright/test');
const { createUser, deleteUsers, loginAs, api } = require('./helpers');

let alice;
let bob;
test.beforeAll(() => { alice = createUser('msg_a'); bob = createUser('msg_b'); });
test.afterAll(() => { deleteUsers(alice, bob); });
test.beforeEach(async ({}, testInfo) => { test.skip(testInfo.project.name !== 'chromium', 'Yalnızca varsayılan projede çalışır'); });

test('mesajlaşma: gönder, okunmamış sayacı, yanıt, kaçışlama', async ({ browser }) => {
    test.setTimeout(120000);
    const aliceContext = await browser.newContext();
    const bobContext = await browser.newContext();
    const a = await aliceContext.newPage();
    const b = await bobContext.newPage();
    const dialogs = [];
    a.on('dialog', (dialog) => { dialogs.push(dialog.message()); dialog.dismiss(); });

    await loginAs(a, alice);
    await loginAs(b, bob);
    const bobId = await a.evaluate(async (name) => {
        await app.initPromise;
        const response = await fetch(`/api/search-users.php?q=${encodeURIComponent(name)}`, { credentials: 'include' });
        return (await response.json()).users.find(u => u.username === name).id;
    }, bob.username);

    // Yeni konuşma
    await a.goto(`/messages.html?with=${bobId}`);
    await expect(a.locator('.thread-header .person-name')).toHaveText(bob.username);
    await expect(a.locator('#sendBtn')).toBeDisabled();
    await a.fill('#messageInput', 'Merhaba, bu bir deneme');
    await expect(a.locator('#sendBtn')).toBeEnabled();
    await a.press('#messageInput', 'Enter');
    await expect(a.locator('.msg-mine')).toContainText('Merhaba, bu bir deneme');
    await expect(a.locator('.day-sep')).toHaveText('Bugün');
    await expect(a.locator('.conv-name')).toHaveText(bob.username);

    // Shift+Enter satır ekler, HTML metin olarak görünür
    await a.type('#messageInput', 'satır bir');
    await a.press('#messageInput', 'Shift+Enter');
    await a.type('#messageInput', '<img src=x onerror=alert(1)>');
    await a.press('#messageInput', 'Enter');
    await expect(a.locator('.msg-mine')).toHaveCount(2);
    expect(await a.locator('.msg-mine').nth(1).innerHTML()).toContain('&lt;img');
    await expect(a.locator('.msg-mine img')).toHaveCount(0);
    expect(dialogs).toEqual([]);

    // Bob okunmamış sayacını görür, açınca temizlenir ve yanıtlar
    await b.goto('/messages.html');
    await expect(b.locator('.conv-unread')).toHaveText('2');
    await expect(b.locator('.conv-item.has-unread')).toHaveCount(1);
    await b.click('.conv-item');
    await expect(b.locator('.msg-theirs').first()).toContainText('Merhaba, bu bir deneme');
    await b.fill('#messageInput', 'Selam, mesajın geldi');
    await b.click('#sendBtn');
    await expect(b.locator('.msg-mine')).toHaveCount(1);

    // Alice yanıtı yoklamayla alır
    await expect(a.locator('.msg-theirs', { hasText: 'Selam, mesajın geldi' })).toBeVisible({ timeout: 20000 });

    await b.reload();
    await expect(b.locator('.conv-item')).toHaveCount(1);
    await expect(b.locator('.conv-unread')).toHaveCount(0);

    // Süzgeç
    await a.fill('#convFilter', 'zzz');
    await expect(a.locator('#convList')).toContainText('Eşleşen konuşma yok');
    await a.fill('#convFilter', bob.username.slice(-4));
    await expect(a.locator('.conv-item')).toHaveCount(1);

    await aliceContext.close();
    await bobContext.close();
});

test.describe('mobil görünüm', () => {
    test.use({ viewport: { width: 390, height: 780 } });

    test('liste ve konuşma ayrı ekranlardır, geri düğmesi listeye döner', async ({ page }) => {
        await loginAs(page, alice);
        const bobId = await page.evaluate(async (name) => {
            await app.initPromise;
            const response = await fetch(`/api/search-users.php?q=${encodeURIComponent(name)}`, { credentials: 'include' });
            return (await response.json()).users.find(u => u.username === name).id;
        }, bob.username);
        await api(page, 'POST', '/api/messages.php', { to_user_id: bobId, text: 'mobil deneme' });

        await page.goto('/messages.html');
        await expect(page.locator('.conv-item')).toBeVisible();
        await expect(page.locator('#threadPanel')).toBeHidden();
        await page.click('.conv-item');
        await expect(page.locator('#messageInput')).toBeVisible();
        await expect(page.locator('#convList')).toBeHidden();
        await page.click('.back-to-list');
        await expect(page.locator('#convList')).toBeVisible();
    });
});
