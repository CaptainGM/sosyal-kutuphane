// Tarayıcı bildirimleri: kullanıcı izin verirse bu cihazın aboneliği sunucuya kaydedilir.
// Profil sayfasındaki "Tarayıcı bildirimleri" bölümünü bağlar. app (app.js) genel kapsamdan okunur.
(function () {
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    function base64ToBytes(base64) {
        const padded = base64.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(base64.length / 4) * 4, '=');
        const raw = atob(padded);
        return Uint8Array.from(raw, (char) => char.charCodeAt(0));
    }

    async function currentSubscription() {
        const registration = await navigator.serviceWorker.ready;
        return registration.pushManager.getSubscription();
    }

    async function enable(publicKey) {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            throw new Error('Bildirim izni verilmedi.');
        }
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64ToBytes(publicKey),
        });
        const response = await fetch('/api/push-subscribe.php', {
            method: 'POST',
            headers: app.authHeaders(),
            credentials: 'include',
            body: JSON.stringify({ subscription: subscription.toJSON() }),
        });
        const data = await response.json();
        if (!data.success) {
            await subscription.unsubscribe();
            throw new Error(data.message || 'Abonelik kaydedilemedi.');
        }
    }

    async function disable() {
        const subscription = await currentSubscription();
        if (!subscription) return;
        await fetch('/api/push-subscribe.php', {
            method: 'DELETE',
            headers: app.authHeaders(),
            credentials: 'include',
            body: JSON.stringify({ endpoint: subscription.endpoint }),
        });
        await subscription.unsubscribe();
    }

    async function wireSettings() {
        const button = document.getElementById('pushToggleBtn');
        const status = document.getElementById('pushStatus');
        if (!button || !status) return;
        await app.initPromise;
        if (!app.currentUser) return;

        if (!supported) {
            status.textContent = 'Bu tarayıcı bildirimleri desteklemiyor. iPhone\'da önce Safari\'den "Ana Ekrana Ekle" ile uygulamayı kur.';
            button.hidden = true;
            return;
        }

        let config;
        try {
            config = await (await fetch('/api/push-subscribe.php', { credentials: 'include' })).json();
        } catch (error) {
            status.textContent = 'Bildirim ayarları yüklenemedi.';
            button.hidden = true;
            return;
        }
        if (!config.configured) {
            status.textContent = 'Tarayıcı bildirimleri bu sunucuda henüz açık değil.';
            button.hidden = true;
            return;
        }

        const render = async () => {
            const subscribed = !!(await currentSubscription());
            button.textContent = subscribed ? 'Bu cihazda bildirimleri kapat' : 'Bu cihazda bildirimleri aç';
            button.setAttribute('aria-pressed', String(subscribed));
            status.textContent = subscribed
                ? 'Bu cihaza yeni bölüm, takip ve mesaj bildirimleri gelir.'
                : 'Bildirimler kapalı. Açmak için düğmeye bas.';
        };
        await render();

        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                if (await currentSubscription()) {
                    await disable();
                } else {
                    await enable(config.public_key);
                }
            } catch (error) {
                status.textContent = error.message || 'Bir sorun oluştu.';
            } finally {
                button.disabled = false;
                await render();
            }
        });
    }

    window.SKPush = { supported };
    document.addEventListener('DOMContentLoaded', wireSettings);
})();
