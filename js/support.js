// Destek düğmesi ve paneli (sol alt). js/app.js oturum açmış, demo olmayan kullanıcılar için yükler (app.loadSupportWidget).
// Sunucu tarafı: api/support-tickets.php. Talepler kullanıcıdan geldiği için görünen her metin app.escapeHtml'den geçer.
(function () {
    if (window.skSupportLoaded) return;
    window.skSupportLoaded = true;

    const ENDPOINT = '/api/support-tickets.php';
    const DRAFT_KEY = 'sk-support-draft';
    const esc = (value) => app.escapeHtml(value);

    const svg = (body) => `<svg class="support-i" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${body}</svg>`;
    const ICON = {
        lifebuoy: svg('<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="m4.93 4.93 4.24 4.24M14.83 9.17l4.24-4.24M14.83 14.83l4.24 4.24M9.17 14.83l-4.24 4.24"/>'),
        check: svg('<path d="M20 6 9 17l-5-5"/>'),
    };

    // Sunucu listesi (GET) gelene kadar form bununla çizilir; gelince değişmişse konular yenilenir.
    const state = {
        open: false,
        tab: 'new',
        sending: false,
        categories: [
            { key: 'bug', label: 'Hata bildirimi' },
            { key: 'account', label: 'Hesap veya giriş sorunu' },
            { key: 'content', label: 'İçerik ya da yorum şikâyeti' },
            { key: 'suggestion', label: 'Öneri veya istek' },
            { key: 'other', label: 'Diğer' },
        ],
        limits: { message_min: 10, message_max: 2000 },
        tickets: null,
    };

    const root = document.createElement('div');
    root.className = 'support';
    root.innerHTML = `
        <button type="button" class="support-fab" aria-expanded="false" aria-controls="supportPanel" aria-label="Destek talebi oluştur">
            <span class="support-fab-icon">${ICON.lifebuoy}</span>
            <span class="support-fab-label">Destek</span>
        </button>
        <section class="support-panel" id="supportPanel" role="dialog" aria-labelledby="supportTitle" hidden>
            <header class="support-head">
                <div>
                    <h2 id="supportTitle">Destek</h2>
                    <p>Bir sorun mu var? Yöneticilere yaz, bakalım.</p>
                </div>
                <button type="button" class="support-close" aria-label="Destek panelini kapat">&times;</button>
            </header>
            <div class="support-tabs" role="tablist" aria-label="Destek bölümleri">
                <button type="button" role="tab" id="supportTabNew" data-tab="new" aria-selected="true" aria-controls="supportPaneNew">Yeni talep</button>
                <button type="button" role="tab" id="supportTabList" data-tab="list" aria-selected="false" aria-controls="supportPaneList" tabindex="-1">Taleplerim <span class="support-count" hidden></span></button>
            </div>
            <div class="support-pane" id="supportPaneNew" role="tabpanel" aria-labelledby="supportTabNew">
                <form class="support-form" novalidate>
                    <fieldset class="support-topics">
                        <legend>Konu</legend>
                        <div class="support-topic-grid"></div>
                    </fieldset>
                    <div class="support-field">
                        <label for="supportMessage">Ne oldu?</label>
                        <textarea id="supportMessage" class="textarea" name="message" rows="5" maxlength="2000" placeholder="Hangi sayfadaydın, ne yapmaya çalışıyordun, ne oldu? Ne kadar ayrıntı, o kadar hızlı çözüm."></textarea>
                        <div class="support-meta"><span>Şifreni ve kart bilgilerini yazma.</span><span class="support-counter">0 / 2000</span></div>
                    </div>
                    <p class="support-error" role="alert" hidden></p>
                    <button type="submit" class="btn btn-primary support-submit">Talebi gönder</button>
                </form>
                <div class="support-done" hidden>
                    <span class="support-done-icon">${ICON.check}</span>
                    <h3>Talebin alındı</h3>
                    <p>Yöneticiler inceleyecek. Çözülünce bildirim alırsın; durumu "Taleplerim" sekmesinden de görebilirsin.</p>
                    <div class="support-done-actions">
                        <button type="button" class="btn btn-primary" data-go="list">Taleplerim</button>
                        <button type="button" class="btn btn-secondary" data-go="new">Yeni talep</button>
                    </div>
                </div>
            </div>
            <div class="support-pane" id="supportPaneList" role="tabpanel" aria-labelledby="supportTabList" hidden></div>
        </section>`;
    document.body.appendChild(root);

    const fab = root.querySelector('.support-fab');
    const panel = root.querySelector('.support-panel');
    const tabs = [...root.querySelectorAll('[role="tab"]')];
    const paneNew = root.querySelector('#supportPaneNew');
    const paneList = root.querySelector('#supportPaneList');
    const form = root.querySelector('.support-form');
    const done = root.querySelector('.support-done');
    const topicGrid = root.querySelector('.support-topic-grid');
    const textarea = root.querySelector('#supportMessage');
    const counter = root.querySelector('.support-counter');
    const errorBox = root.querySelector('.support-error');
    const submitBtn = root.querySelector('.support-submit');
    const countBadge = root.querySelector('.support-count');

    // --- Taslak: sayfa değiştirince yazılan metin kaybolmasın (yalnızca bu sekme, gönderilince silinir) ---
    function readDraft() {
        try { return JSON.parse(sessionStorage.getItem(DRAFT_KEY)) || {}; } catch (e) { return {}; }
    }
    function saveDraft() {
        try {
            const message = textarea.value;
            if (message.trim() === '') sessionStorage.removeItem(DRAFT_KEY);
            else sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ category: selectedCategory(), message }));
        } catch (e) { /* özel pencere / depolama kapalı: sorun değil */ }
    }
    function clearDraft() {
        try { sessionStorage.removeItem(DRAFT_KEY); } catch (e) { }
    }

    // --- Form ---
    function selectedCategory() {
        const checked = form.querySelector('input[name="category"]:checked');
        return checked ? checked.value : state.categories[0].key;
    }

    function renderTopics(selected) {
        const current = state.categories.some((c) => c.key === selected) ? selected : state.categories[0].key;
        topicGrid.innerHTML = state.categories.map((c) => `
            <label class="support-topic">
                <input type="radio" name="category" value="${esc(c.key)}"${c.key === current ? ' checked' : ''}>
                <span>${esc(c.label)}</span>
            </label>`).join('');
    }

    function updateCounter() {
        counter.textContent = `${textarea.value.length} / ${state.limits.message_max}`;
    }

    function showError(message) {
        errorBox.textContent = message || '';
        errorBox.hidden = !message;
    }

    function showForm() {
        done.hidden = true;
        form.hidden = false;
        showError('');
    }

    async function submit(event) {
        event.preventDefault();
        if (state.sending) return;
        const message = textarea.value.trim();
        if (message.length < state.limits.message_min) {
            showError(`Mesajın en az ${state.limits.message_min} karakter olmalı. Sorunu biraz daha anlat.`);
            textarea.focus();
            return;
        }
        showError('');
        state.sending = true;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Gönderiliyor...';
        try {
            const response = await fetch(ENDPOINT, {
                method: 'POST',
                headers: app.authHeaders(),
                credentials: 'include',
                body: JSON.stringify({ category: selectedCategory(), message, page: location.pathname + location.search }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                showError(data.message || 'Talep gönderilemedi. Birazdan tekrar dene.');
                return;
            }
            clearDraft();
            textarea.value = '';
            updateCounter();
            state.tickets = null; // liste bir sonraki açılışta yeniden alınır
            form.hidden = true;
            done.hidden = false;
            done.querySelector('button').focus();
            loadTickets();
        } catch (error) {
            showError('Bağlantı hatası. İnternetini kontrol edip tekrar dene.');
        } finally {
            state.sending = false;
            submitBtn.disabled = false;
            submitBtn.textContent = 'Talebi gönder';
        }
    }

    // --- Taleplerim ---
    function updateCount() {
        const openCount = (state.tickets || []).filter((t) => t.status === 'open').length;
        countBadge.textContent = String(openCount);
        countBadge.hidden = openCount === 0;
    }

    function renderList(message) {
        if (message) {
            paneList.innerHTML = `<p class="support-empty">${esc(message)}</p>`;
            return;
        }
        if (state.tickets === null) {
            paneList.innerHTML = '<p class="support-empty">Yükleniyor...</p>';
            return;
        }
        if (!state.tickets.length) {
            paneList.innerHTML = `<div class="support-empty"><p>Henüz talep göndermedin.</p><button type="button" class="btn btn-secondary btn-sm" data-go="new">Yeni talep oluştur</button></div>`;
            return;
        }
        paneList.innerHTML = '<ul class="support-list">' + state.tickets.map((t) => {
            const resolved = t.status === 'resolved';
            return `
            <li class="support-ticket">
                <div class="support-ticket-head">
                    <span class="chip ${resolved ? 'chip--success' : 'chip--warm'}">${resolved ? 'Çözüldü' : 'Çözülmedi'}</span>
                    <span class="support-ticket-cat">${esc(t.category_label)}</span>
                    <time datetime="${esc(t.created_at)}">${esc(app.getTimeAgo(t.created_at))}</time>
                </div>
                <p class="support-ticket-msg">${esc(t.message)}</p>
                ${t.admin_note ? `<p class="support-ticket-note"><strong>Yönetici notu:</strong> ${esc(t.admin_note)}</p>` : ''}
            </li>`;
        }).join('') + '</ul>';
    }

    async function loadTickets() {
        try {
            const response = await fetch(ENDPOINT, { credentials: 'include' });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'liste alınamadı');
            state.tickets = data.tickets || [];
            if (Array.isArray(data.categories) && data.categories.length && JSON.stringify(data.categories) !== JSON.stringify(state.categories)) {
                state.categories = data.categories;
                renderTopics(selectedCategory());
            }
            if (data.limits) state.limits = data.limits;
            updateCount();
            renderList();
        } catch (error) {
            renderList('Talepler şu an alınamadı. Birazdan tekrar dene.');
        }
    }

    // --- Sekmeler, aç / kapat ---
    function showTab(name, moveFocus) {
        state.tab = name === 'list' ? 'list' : 'new';
        tabs.forEach((tab) => {
            const active = tab.dataset.tab === state.tab;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            if (active && moveFocus) tab.focus();
        });
        paneNew.hidden = state.tab !== 'new';
        paneList.hidden = state.tab !== 'list';
        if (state.tab === 'list') {
            renderList();
            loadTickets();
        }
    }

    function open(tab) {
        state.open = true;
        panel.hidden = false;
        fab.setAttribute('aria-expanded', 'true');
        root.dataset.open = 'true';
        const wanted = tab || state.tab;
        if (wanted === 'new') showForm(); // gönderim sonrası "Talebin alındı" ekranı bir sonraki açılışta temiz forma döner
        showTab(wanted);
        if (state.tab === 'new') {
            (form.querySelector('input[name="category"]:checked') || textarea).focus();
            if (state.tickets === null) loadTickets(); // konu listesi ve açık talep sayısı için
        } else {
            tabs.find((t) => t.dataset.tab === state.tab).focus();
        }
    }

    function close() {
        if (!state.open) return;
        state.open = false;
        panel.hidden = true;
        fab.setAttribute('aria-expanded', 'false');
        root.dataset.open = 'false';
        fab.focus();
    }

    fab.addEventListener('click', () => (state.open ? close() : open()));
    root.querySelector('.support-close').addEventListener('click', close);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && state.open && !document.querySelector('.modal-overlay, .confirm-overlay')) close();
    });

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
            if (tab.dataset.tab === 'new') showForm();
            showTab(tab.dataset.tab);
        });
        tab.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
            event.preventDefault();
            const next = tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            showTab(next.dataset.tab, true);
        });
    });

    // "Taleplerim" / "Yeni talep" kısayol düğmeleri (teşekkür ekranı ve boş liste)
    root.addEventListener('click', (event) => {
        const go = event.target.closest('[data-go]');
        if (!go) return;
        if (go.dataset.go === 'new') showForm();
        showTab(go.dataset.go);
        if (go.dataset.go === 'new') textarea.focus();
    });

    textarea.addEventListener('input', () => { updateCounter(); saveDraft(); });
    topicGrid.addEventListener('change', saveDraft);
    form.addEventListener('submit', submit);

    // Dışarıdan açma: bildirime tıklayınca (app.openSupport) ya da bağlantıda #destek varken.
    window.addEventListener('support:open', (event) => open((event.detail && event.detail.tab) || 'list'));

    const draft = readDraft();
    renderTopics(draft.category);
    if (typeof draft.message === 'string') textarea.value = draft.message.slice(0, state.limits.message_max);
    updateCounter();
    if (location.hash === '#destek') open('list');
})();
