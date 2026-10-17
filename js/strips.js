// Afiş şeridi (.strip): ekranın iki kenarına kadar uzanan, elle kaydırılan afiş satırı. Fareyle sürüklenir, dokunmatikte
// parmakla kayar, iki yandaki ok düğmeleri sayfa sayfa ilerletir. Kendi kendine hareket etmez (hareket ayarından bağımsızdır).
// escapeHtml (escape-html.js) genel kapsamdan okunur.
(function (global) {
    const ARROW_ATTRS = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
    const ARROW_PREV = `<svg ${ARROW_ATTRS}><path d="m15 18-6-6 6-6"/></svg>`;
    const ARROW_NEXT = `<svg ${ARROW_ATTRS}><path d="m9 18 6-6-6-6"/></svg>`;

    function cardHtml(item) {
        return `<a class="strip-card" draggable="false" href="${escapeHtml(item.href)}" aria-label="${escapeHtml(item.title)} sayfasına git">
            <img src="${escapeHtml(item.image)}" alt="" draggable="false" loading="lazy" decoding="async" onerror="this.src='img/no-poster.svg'">
            <span class="strip-card-meta"><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.subtitle || '')}</small></span>
        </a>`;
    }

    const CARD_GAP = 18;
    const CARD_MIN_WIDTH = 150;

    // Ok düğmeleri sayfa sayfa ilerletir: her sayfada tam kartlar görünür (yarım afiş kalmaz), bir sonraki sayfa
    // kaldığı yerden başlar. Fareyle sürüklenir, dokunmatikte kaydırılır; bırakınca en yakın karta oturur.
    function wire(host) {
        const viewport = host.querySelector('.strip-viewport');
        const prev = host.querySelector('.strip-arrow--prev');
        const next = host.querySelector('.strip-arrow--next');
        let perPage = 2;
        let step = CARD_MIN_WIDTH + CARD_GAP;

        // Görünür genişliğe sığan tam kart sayısı; kartlar bu sayıyı tam dolduracak şekilde genişletilir.
        function layout() {
            const inner = viewport.clientWidth;
            if (!inner) return;
            const oldIndex = Math.round(viewport.scrollLeft / step);
            perPage = Math.max(2, Math.floor((inner + CARD_GAP) / (CARD_MIN_WIDTH + CARD_GAP)));
            const width = (inner - (perPage - 1) * CARD_GAP) / perPage;
            step = width + CARD_GAP;
            host.style.setProperty('--strip-card-w', `${width}px`);
            viewport.scrollLeft = oldIndex * step;
            syncArrows();
        }

        function syncArrows() {
            const max = viewport.scrollWidth - viewport.clientWidth;
            prev.disabled = viewport.scrollLeft <= 2;
            next.disabled = viewport.scrollLeft >= max - 2;
        }

        function goPage(direction) {
            const max = viewport.scrollWidth - viewport.clientWidth;
            const index = Math.round(viewport.scrollLeft / step) + direction * perPage;
            viewport.scrollTo({ left: Math.max(0, Math.min(max, index * step)), behavior: 'smooth' });
        }

        prev.addEventListener('click', () => goPage(-1));
        next.addEventListener('click', () => goPage(1));
        viewport.addEventListener('scroll', syncArrows, { passive: true });
        if (typeof ResizeObserver === 'function') {
            new ResizeObserver(layout).observe(viewport);
        } else {
            window.addEventListener('resize', layout, { passive: true });
        }
        layout();

        let startX = 0;
        let startScroll = 0;
        let pointerId = null;
        let dragged = false;

        viewport.addEventListener('pointerdown', (event) => {
            if (event.pointerType !== 'mouse' || event.button !== 0) return;
            pointerId = event.pointerId;
            startX = event.clientX;
            startScroll = viewport.scrollLeft;
            dragged = false;
        });
        viewport.addEventListener('pointermove', (event) => {
            if (pointerId !== event.pointerId) return;
            const delta = event.clientX - startX;
            if (!dragged && Math.abs(delta) > 5) {
                dragged = true;
                viewport.classList.add('is-dragging');
                viewport.setPointerCapture(pointerId);
            }
            if (dragged) viewport.scrollLeft = startScroll - delta;
        });
        const endDrag = (event) => {
            if (pointerId !== event.pointerId) return;
            pointerId = null;
            viewport.classList.remove('is-dragging');
        };
        viewport.addEventListener('pointerup', endDrag);
        viewport.addEventListener('pointercancel', endDrag);
        // Sürükleme sırasında bırakılan fare, altındaki afişe tıklama sayılmasın.
        viewport.addEventListener('click', (event) => {
            if (dragged) {
                event.preventDefault();
                event.stopPropagation();
                dragged = false;
            }
        }, true);
    }

    // items: [{ href, image, title, subtitle }]. options: label, emptyText.
    function render(container, items, options = {}) {
        const { label = 'Liste', emptyText = 'Sonuç bulunamadı' } = options;
        if (!items.length) {
            container.className = '';
            container.innerHTML = `<div class="strip-loading">${escapeHtml(emptyText)}</div>`;
            return;
        }

        container.className = 'strip';
        container.innerHTML = `
            <div class="strip-viewport" role="region" aria-label="${escapeHtml(label)}" tabindex="0">
                <div class="strip-track">${items.map(cardHtml).join('')}</div>
            </div>
            <button type="button" class="strip-arrow strip-arrow--prev" aria-label="Geri kaydır">${ARROW_PREV}</button>
            <button type="button" class="strip-arrow strip-arrow--next" aria-label="İleri kaydır">${ARROW_NEXT}</button>`;
        wire(container);
    }

    global.SKStrip = { render };
})(window);
