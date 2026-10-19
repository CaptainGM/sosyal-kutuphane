// Statik varlıklar (CSS/JS/ikon) ve herkese açık API yanıtları (TMDB listeleri vb.) "önce önbellek, arkada yenile":
// sayfa hemen açılır, güncel sürüm arka planda alınıp bir sonraki açılışta kullanılır. Kullanıcıya özel API yanıtları
// (oturum, kütüphane, yorumlar) asla önbelleğe girmez. Sayfalar ağ öncelikli, çevrimdışıyken offline.html'e düşer.
const CACHE_NAME = 'sosyal-kutuphane-v11';
const API_CACHE = 'sk-api-v11';
const API_CACHE_LIMIT = 200;
const PUBLIC_API = [
    '/api/tmdb-proxy.php',
    '/api/books-proxy.php',
    '/api/popular-content.php',
    '/api/upcoming.php',
    '/api/content-rating.php',
];
const STATIC_ASSETS = [
    'css/style.css',
    'css/ui.css',
    'css/profile.css',
    'css/support.css',
    'js/app.js',
    'js/support.js',
    'js/escape-html.js',
    'js/comment-view.js',
    'js/motion.js',
    'js/strips.js',
    'js/push.js',
    'js/profile-view.js',
    'js/password-rules.js',
    'manifest.json',
    'img/logo.svg',
    'icons/icon-192.png',
    'icons/icon-512.png',
    'offline.html',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            // "reload": kurulum sırasında HTTP önbelleğini atlayıp sunucudan taze kopya alır.
            .then((cache) => Promise.all(
                STATIC_ASSETS.map((asset) => cache.add(new Request(asset, { cache: 'reload' })))
            ))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME && k !== API_CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

// Önbellekteki kopya varsa hemen döner; ağdan gelen yanıt arka planda önbelleğe yazılır (bir sonraki açılış için).
function staleWhileRevalidate(event, cacheName, limit = Infinity) {
    const { request } = event;
    const cachePromise = caches.open(cacheName);
    const networkPromise = cachePromise.then(async (cache) => {
        try {
            const response = await fetch(request);
            if (response.ok) {
                await cache.put(request, response.clone());
                if (Number.isFinite(limit)) {
                    const keys = await cache.keys();
                    await Promise.all(keys.slice(0, Math.max(0, keys.length - limit)).map((key) => cache.delete(key)));
                }
            }
            return response;
        } catch (error) {
            return null;
        }
    });
    event.waitUntil(networkPromise);
    return cachePromise
        .then((cache) => cache.match(request))
        .then((cached) => cached || networkPromise.then((response) => response || Response.error()));
}

// Tek bir başarısız deneme "çevrimdışı" demek değildir (sunucu o an meşgul ya da yeniden başlıyor olabilir):
// bir kez daha dener, ikisi de olmazsa çevrimdışı sayfasını gösterir.
async function navigate(request) {
    try {
        return await fetch(request);
    } catch (firstError) {
        await new Promise((resolve) => setTimeout(resolve, 600));
        try {
            return await fetch(request);
        } catch (secondError) {
            return (await caches.match('offline.html')) || Response.error();
        }
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }
    if (PUBLIC_API.includes(url.pathname)) {
        event.respondWith(staleWhileRevalidate(event, API_CACHE, API_CACHE_LIMIT));
        return;
    }
    if (url.pathname.startsWith('/api/')) {
        return;
    }

    const isStaticAsset = STATIC_ASSETS.some((asset) => url.pathname === '/' + asset);
    if (isStaticAsset) {
        event.respondWith(staleWhileRevalidate(event, CACHE_NAME));
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(navigate(request));
    }
});

// Tarayıcı bildirimi geldiğinde gösterilir. Metin sunucudan gelir ve mesaj içeriği taşımaz.
self.addEventListener('push', (event) => {
    let data = { title: 'Sosyal Kütüphane', body: 'Yeni bir bildirimin var', url: 'search.html' };
    try {
        data = { ...data, ...(event.data ? event.data.json() : {}) };
    } catch (error) {
        // Bozuk yük varsayılan metinle gösterilir.
    }
    event.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: 'icons/icon-192.png',
        badge: 'icons/icon-192.png',
        data: { url: data.url },
    }));
});

// Bildirime tıklayınca açık sekme öne gelir; yoksa uygulama yeni sekmede açılır.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || 'search.html';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const existing = windows.find((client) => 'focus' in client);
            if (existing) {
                existing.navigate(url).catch(() => { });
                return existing.focus();
            }
            return self.clients.openWindow(url);
        })
    );
});
