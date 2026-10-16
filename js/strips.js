// Afiş şeridi (.strip): tam harekette ekranın iki kenarına kadar uzanan sonsuz kayan şerit; azaltılmış harekette
// aynı kartlar oklu, tam görünen bir kaydırma satırı olur. Kartlar iki kez yazılır, ikinci kopya ekran okuyucudan
// ve klavyeden gizlenir. Seçki yardımcıları saat dilimine göre sabit (herkese aynı) ya da anlık karıştırma sunar.
// escapeHtml (escape-html.js) genel kapsamdan okunur.
(function (global) {
    const ARROW_ATTRS = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
    const ARROW_PREV = `<svg ${ARROW_ATTRS}><path d="m15 18-6-6 6-6"/></svg>`;
    const ARROW_NEXT = `<svg ${ARROW_ATTRS}><path d="m9 18 6-6-6-6"/></svg>`;

    function isReduced() {
        return document.documentElement.getAttribute('data-motion') === 'reduced';
    }

    // Küçük, tohumlanabilir rastgele sayı üreteci (mulberry32).
    function mulberry32(seed) {
        let state = seed | 0;
        return function () {
            state = (state + 0x6D2B79F5) | 0;
            let t = Math.imul(state ^ (state >>> 15), 1 | state);
            t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
    }

    function hashString(text) {
        let hash = 2166136261;
        for (let i = 0; i < text.length; i++) {
            hash ^= text.charCodeAt(i);
            hash = Math.imul(hash, 16777619);
        }
        return hash >>> 0;
    }

    function seededShuffle(items, seed) {
        const random = mulberry32(seed);
        const copy = [...items];
        for (let i = copy.length - 1; i > 0; i--) {
            const j = Math.floor(random() * (i + 1));
            [copy[i], copy[j]] = [copy[j], copy[i]];
        }
        return copy;
    }

    // Aynı zaman diliminde (varsayılan 3 saat) herkese aynı tohum: hesap değiştirince seçki değişmez, saat değişince değişir.
    function windowSeed(key, hours = 3) {
        const slot = Math.floor(Date.now() / (hours * 3600 * 1000));
        return hashString(`${key}:${slot}`);
    }

    function cardHtml(item) {
        return `<a class="strip-card" href="${escapeHtml(item.href)}" aria-label="${escapeHtml(item.title)} sayfasına git">
            <img src="${escapeHtml(item.image)}" alt="" loading="lazy" decoding="async" onerror="this.src='img/no-poster.svg'">
            <span class="strip-card-meta"><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.subtitle || '')}</small></span>
        </a>`;
    }

    function wireArrows(host) {
        const viewport = host.querySelector('.strip-viewport');
        host.querySelectorAll('.strip-arrow').forEach((button) => {
            button.addEventListener('click', () => {
                const direction = button.classList.contains('strip-arrow--next') ? 1 : -1;
                viewport.scrollBy({ left: direction * viewport.clientWidth * 0.8, behavior: 'smooth' });
            });
        });
    }

    // items: [{ href, image, title, subtitle }]. options: reverse, label, duration, emptyText.
    function render(container, items, options = {}) {
        const { reverse = false, label = 'Liste', emptyText = 'Sonuç bulunamadı' } = options;
        if (!items.length) {
            container.className = '';
            container.innerHTML = `<div class="strip-loading">${escapeHtml(emptyText)}</div>`;
            return;
        }

        const reduced = isReduced();
        const cards = items.map(cardHtml).join('');
        const duration = options.duration || Math.max(40, items.length * 5);
        container.className = `strip${reverse ? ' strip--reverse' : ''}`;
        container.style.setProperty('--strip-duration', `${duration}s`);
        container.innerHTML = `
            <div class="strip-viewport" role="region" aria-label="${escapeHtml(label)}"${reduced ? ' tabindex="0"' : ''}>
                <div class="strip-track">
                    <div class="strip-set">${cards}</div>
                    ${reduced ? '' : `<div class="strip-set" aria-hidden="true" inert>${cards}</div>`}
                </div>
            </div>
            <button type="button" class="strip-arrow strip-arrow--prev" aria-label="Geri kaydır">${ARROW_PREV}</button>
            <button type="button" class="strip-arrow strip-arrow--next" aria-label="İleri kaydır">${ARROW_NEXT}</button>`;
        wireArrows(container);
    }

    global.SKStrip = { render, seededShuffle, windowSeed };
})(window);
