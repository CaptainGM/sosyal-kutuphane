// Statik varlıklar (CSS/JS/ikon) ağ öncelikli: çevrimiçiyken her zaman en güncel sürüm gelir,
// ağ yoksa önbellekteki kopya kullanılır. /api/* ve GET dışı istekler asla önbelleğe girmez.
// Sayfalar ağ öncelikli, çevrimdışıyken offline.html'e düşer.
const CACHE_NAME = 'sosyal-kutuphane-v8';
const STATIC_ASSETS = [
    'css/style.css',
    'css/ui.css',
    'css/profile.css',
    'js/app.js',
    'js/escape-html.js',
    'js/comment-view.js',
    'js/motion.js',
    'js/strips.js',
    'js/profile-view.js',
    'js/password-rules.js',
    'manifest.json',
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
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

async function networkFirst(request) {
    const cache = await caches.open(CACHE_NAME);
    try {
        const response = await fetch(request, { cache: 'no-cache' });
        if (response.ok) {
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }
        throw error;
    }
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
    if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
        return;
    }

    const isStaticAsset = STATIC_ASSETS.some((asset) => url.pathname === '/' + asset);
    if (isStaticAsset) {
        event.respondWith(networkFirst(request));
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(navigate(request));
    }
});
