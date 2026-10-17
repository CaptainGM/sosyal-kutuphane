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

    // Ok düğmeleri kenarlara yaklaşınca soluklaşır; fareyle sürükleyerek de kaydırılır (dokunmatikte kaydırma zaten yerleşiktir).
    function wire(host) {
        const viewport = host.querySelector('.strip-viewport');
        const prev = host.querySelector('.strip-arrow--prev');
        const next = host.querySelector('.strip-arrow--next');

        function syncArrows() {
            const max = viewport.scrollWidth - viewport.clientWidth;
            prev.disabled = viewport.scrollLeft <= 2;
            next.disabled = viewport.scrollLeft >= max - 2;
        }

        [prev, next].forEach((button) => {
            button.addEventListener('click', () => {
                const direction = button === next ? 1 : -1;
                viewport.scrollBy({ left: direction * Math.max(240, viewport.clientWidth * 0.8), behavior: 'smooth' });
            });
        });
        viewport.addEventListener('scroll', syncArrows, { passive: true });
        window.addEventListener('resize', syncArrows, { passive: true });
        syncArrows();
        // Afişler geç yüklenince genişlik değişir; bir kez daha eşitle.
        requestAnimationFrame(syncArrows);

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
