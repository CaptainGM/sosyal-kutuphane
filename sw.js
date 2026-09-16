// Sadece statik varlıklar (CSS/JS/ikon) önbellekten karşılanır.
// /api/* ve GET dışı istekler asla önbelleğe girmez. Sayfalar ağ öncelikli,
// çevrimdışıyken offline.html'e düşer.
const CACHE_NAME = 'sosyal-kutuphane-v1';
const STATIC_ASSETS = [
    'css/style.css',
    'js/app.js',
    'js/escape-html.js',
    'manifest.json',
    'icons/icon-192.png',
    'icons/icon-512.png',
    'offline.html',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_ASSETS))
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
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request))
        );
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('offline.html'))
        );
    }
});
