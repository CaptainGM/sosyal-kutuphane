// Destek düğmesi ve paneli (sol alt). js/app.js her sayfada yükler (app.loadSupportWidget); demo hesaplarda ve yönetim sayfasında yoktur.
//  - Giriş yapmış kullanıcı: yeni talep, "Taleplerim" (yönetici yanıtları ve kendi yanıtları bir konuşma olarak görünür, yanıt yazılabilir).
//  - Giriş yapmamış ziyaretçi (ör. doğrulama kodu gelmeyen): e-posta + mesaj formu; yanıt e-postayla gelir.
// Sunucu tarafı: api/support-tickets.php (kullanıcı) ve api/support-guest.php (misafir). Talepler kullanıcıdan geldiği için
// görünen her metin app.escapeHtml'den geçer.
(function () {
    if (window.skSupportLoaded) return;
    window.skSupportLoaded = true;

    const guest = !app.currentUser;
    const ENDPOINT = guest ? '/api/support-guest.php' : '/api/support-tickets.php';
    const DRAFT_KEY = guest ? 'sk-support-guest-draft' : 'sk-support-draft';
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
        guestToken: null,
    };

    const root = document.createElement('div');
    root.className = 'support' + (guest ? ' support--guest' : '');
    root.innerHTML = `
        <button type="button" class="support-fab" aria-expanded="false" aria-controls="supportPanel" aria-label="${guest ? 'Destek talebi gönder: giriş yapamıyor musun?' : 'Destek talebi oluştur'}">
            <span class="support-fab-icon">${ICON.lifebuoy}</span>
            <span class="support-fab-label">Destek</span>
        </button>
        <section class="support-panel" id="supportPanel" role="dialog" aria-labelledby="supportTitle" hidden>
            <header class="support-head">
                <div>
                    <h2 id="supportTitle">Destek</h2>
                    <p>${guest ? 'Giriş yapamıyor ya da kod mu gelmiyor? Bize yaz.' : 'Bir sorun mu var? Yöneticilere yaz, bakalım.'}</p>
                </div>
                <button type="button" class="support-close" aria-label="Destek panelini kapat">&times;</button>
            </header>
            <div class="support-tabs" role="tablist" aria-label="Destek bölümleri"${guest ? ' hidden' : ''}>
                <button type="button" role="tab" id="supportTabNew" data-tab="new" aria-selected="true" aria-controls="supportPaneNew">Yeni talep</button>
                <button type="button" role="tab" id="supportTabList" data-tab="list" aria-selected="false" aria-controls="supportPaneList" tabindex="-1">Taleplerim <span class="support-count" hidden></span></button>
            </div>
            <div class="support-pane" id="supportPaneNew" role="tabpanel" aria-labelledby="supportTabNew">
                <form class="support-form" novalidate>
                    ${guest ? `
                    <div class="support-field">
                        <label for="supportEmail">E-posta adresin</label>
                        <input id="supportEmail" class="input" name="email" type="email" autocomplete="email" maxlength="120" placeholder="Yanıtı bu adrese göndereceğiz">
                    </div>
                    <div class="support-hp" aria-hidden="true"><label>Web sitesi <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>` : ''}
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
                    <p class="support-privacy">Gönderdiğin bilgiler yalnızca destek için kullanılır. <a href="gizlilik.html">Gizlilik ve veri kullanımı</a></p>
                </form>
                <div class="support-done" hidden>
                    <span class="support-done-icon">${ICON.check}</span>
                    <h3>Talebin alındı</h3>
                    <p>${guest
                        ? 'Yöneticiler inceleyecek ve yanıtı yazdığın e-posta adresine gönderecek. Birkaç saat içinde gelmezse Spam / Gereksiz klasörüne de bak.'
                        : 'Yöneticiler inceleyecek. Yanıt gelince ve çözülünce bildirim alırsın; konuşmayı "Taleplerim" sekmesinden de görebilirsin.'}</p>
                    <div class="support-done-actions">
                        ${guest ? '<button type="button" class="btn btn-primary" data-close>Tamam</button>' : '<button type="button" class="btn btn-primary" data-go="list">Taleplerim</button>'}
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
    const emailInput = root.querySelector('#supportEmail');
    const honeypot = root.querySelector('input[name="website"]');
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
            if (message.trim() === '' && !(emailInput && emailInput.value.trim())) sessionStorage.removeItem(DRAFT_KEY);
            else sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ category: selectedCategory(), message, email: emailInput ? emailInput.value : undefined }));
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

    // Misafir formu: açılışta imzalı bir belirteç alınır (sunucu en az 3 sn doldurma süresi bekler); eskiyse yenilenir.
    async function ensureGuestToken() {
        if (!guest) return;
        if (state.guestToken && Date.now() - state.guestToken.at < 90 * 60 * 1000) return;
        try {
            const response = await fetch(ENDPOINT, { credentials: 'omit' });
            const data = await response.json();
            if (data.success) {
                state.guestToken = { value: data.token, at: Date.now() };
                if (Array.isArray(data.categories) && data.categories.length) {
                    state.categories = data.categories;
                    renderTopics(selectedCategory());
                }
                if (data.limits) state.limits = data.limits;
            }
        } catch (error) { /* gönderirken yeniden denenir */ }
    }

    async function submit(event) {
        event.preventDefault();
        if (state.sending) return;
        const message = textarea.value.trim();
        if (guest && !/^\S+@\S+\.\S+$/.test(emailInput.value.trim())) {
            showError('Yanıt gönderebilmemiz için geçerli bir e-posta adresi yaz.');
            emailInput.focus();
            return;
        }
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
            if (guest) await ensureGuestToken();
            const payload = { category: selectedCategory(), message, page: location.pathname + location.search };
            if (guest) Object.assign(payload, { email: emailInput.value.trim(), website: honeypot.value, token: state.guestToken && state.guestToken.value });
            const response = await fetch(ENDPOINT, {
                method: 'POST',
                headers: guest ? { 'Content-Type': 'application/json' } : app.authHeaders(),
                credentials: guest ? 'omit' : 'include',
                body: JSON.stringify(payload),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                if (guest) state.guestToken = null; // süresi dolmuş olabilir: bir sonraki denemede yenilenir
                showError(data.message || 'Talep gönderilemedi. Birazdan tekrar dene.');
                return;
            }
            clearDraft();
            textarea.value = '';
            updateCounter();
            state.tickets = null; // liste bir sonraki açılışta yeniden alınır
            state.guestToken = null; // bir belirteçle tek talep
            form.hidden = true;
            done.hidden = false;
            done.querySelector('button').focus();
            if (!guest) loadTickets();
        } catch (error) {
            showError('Bağlantı hatası. İnternetini kontrol edip tekrar dene.');
        } finally {
            state.sending = false;
            submitBtn.disabled = false;
            submitBtn.textContent = 'Talebi gönder';
        }
    }

    // --- Taleplerim (yalnızca giriş yapmış kullanıcı) ---
    function updateCount() {
        const openCount = (state.tickets || []).filter((t) => t.status === 'open').length;
        countBadge.textContent = String(openCount);
        countBadge.hidden = openCount === 0;
    }

    function threadHtml(t) {
        if (t.replies && t.replies.length) {
            return '<ol class="support-thread">' + t.replies.map((r) =>
                `<li class="support-bubble support-bubble--${r.by === 'admin' ? 'admin' : 'user'}"><span class="support-bubble-who">${r.by === 'admin' ? 'Destek ekibi' : 'Sen'} · ${esc(app.getTimeAgo(r.at))}</span><span class="support-bubble-text">${esc(r.text)}</span></li>`
            ).join('') + '</ol>';
        }
        // Konuşma özelliğinden önceki kayıtlar: yalnızca tek not
        return t.admin_note ? `<p class="support-ticket-note"><strong>Yönetici notu:</strong> ${esc(t.admin_note)}</p>` : '';
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
        const openReplyIds = new Set([...paneList.querySelectorAll('.support-reply:not([hidden])')].map((box) => box.dataset.id));
        paneList.innerHTML = '<ul class="support-list">' + state.tickets.map((t) => {
            const resolved = t.status === 'resolved';
            const replyOpen = openReplyIds.has(String(t.id));
            return `
            <li class="support-ticket" data-id="${Number(t.id)}">
                <div class="support-ticket-head">
                    <span class="chip ${resolved ? 'chip--success' : 'chip--warm'}">${resolved ? 'Çözüldü' : 'Çözülmedi'}</span>
                    <span class="support-ticket-cat">${esc(t.category_label)}</span>
                    <time datetime="${esc(t.created_at)}">${esc(app.getTimeAgo(t.created_at))}</time>
                </div>
                <p class="support-ticket-msg">${esc(t.message)}</p>
                ${threadHtml(t)}
                <div class="support-reply" data-id="${Number(t.id)}"${replyOpen ? '' : ' hidden'}>
                    <label class="sr-only-label" for="supportReply${Number(t.id)}">Yanıtın</label>
                    <textarea id="supportReply${Number(t.id)}" class="textarea" rows="2" maxlength="2000" placeholder="${resolved ? 'Sorun devam ediyorsa yaz; talep yeniden açılır.' : 'Yanıtını yaz'}"></textarea>
                    <p class="support-error" role="alert" hidden></p>
                    <button type="button" class="btn btn-primary btn-sm" data-send-reply="${Number(t.id)}">Gönder</button>
                </div>
                <button type="button" class="btn-glass btn-sm support-reply-toggle" data-toggle-reply="${Number(t.id)}" aria-expanded="${replyOpen}">${resolved ? 'Yeniden yaz' : 'Yanıt yaz'}</button>
            </li>`;
        }).join('') + '</ul>';
    }

    async function sendReply(id, button) {
        const box = paneList.querySelector(`.support-reply[data-id="${id}"]`);
        const field = box.querySelector('textarea');
        const err = box.querySelector('.support-error');
        const message = field.value.trim();
        err.hidden = true;
        if (message.length < 2) {
            err.textContent = 'Yanıtın boş olamaz.';
            err.hidden = false;
            field.focus();
            return;
        }
        button.disabled = true;
        try {
            const response = await fetch(ENDPOINT, { method: 'POST', headers: app.authHeaders(), credentials: 'include', body: JSON.stringify({ action: 'reply', ticket_id: id, message }) });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                err.textContent = data.message || 'Yanıt gönderilemedi. Birazdan tekrar dene.';
                err.hidden = false;
                return;
            }
            const index = state.tickets.findIndex((t) => t.id === id);
            if (index >= 0) state.tickets[index] = data.ticket;
            updateCount();
            renderList();
        } catch (error) {
            err.textContent = 'Bağlantı hatası. Tekrar dene.';
            err.hidden = false;
        } finally {
            button.disabled = false;
        }
    }

    async function loadTickets() {
        if (guest) return;
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
        state.tab = name === 'list' && !guest ? 'list' : 'new';
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
        if (guest) ensureGuestToken();
        const touchOnly = window.matchMedia('(pointer: coarse)').matches;
        if (touchOnly) {
            panel.tabIndex = -1;
            panel.focus({ preventScroll: true });
        } else if (state.tab === 'new') {
            (guest ? emailInput : form.querySelector('input[name="category"]:checked') || textarea).focus();
        } else {
            tabs.find((t) => t.dataset.tab === state.tab).focus();
        }
        if (state.tab === 'new' && !guest && state.tickets === null) loadTickets(); // konu listesi ve açık talep sayısı için
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

    // Kısayol düğmeleri: teşekkür ekranı, boş liste, yanıt aç/kapat ve gönder
    root.addEventListener('click', (event) => {
        const go = event.target.closest('[data-go]');
        if (go) {
            if (go.dataset.go === 'new') showForm();
            showTab(go.dataset.go);
            if (go.dataset.go === 'new') (guest ? emailInput : textarea).focus();
            return;
        }
        if (event.target.closest('[data-close]')) {
            close();
            return;
        }
        const toggle = event.target.closest('[data-toggle-reply]');
        if (toggle) {
            const box = paneList.querySelector(`.support-reply[data-id="${toggle.dataset.toggleReply}"]`);
            box.hidden = !box.hidden;
            toggle.setAttribute('aria-expanded', String(!box.hidden));
            if (!box.hidden) box.querySelector('textarea').focus();
            return;
        }
        const send = event.target.closest('[data-send-reply]');
        if (send) sendReply(Number(send.dataset.sendReply), send);
    });

    textarea.addEventListener('input', () => { updateCounter(); saveDraft(); });
    if (emailInput) emailInput.addEventListener('input', saveDraft);
    topicGrid.addEventListener('change', saveDraft);
    form.addEventListener('submit', submit);

    // Dışarıdan açma: bildirime tıklayınca (app.openSupport) ya da bağlantıda #destek varken.
    window.addEventListener('support:open', (event) => open((event.detail && event.detail.tab) || 'list'));

    const draft = readDraft();
    renderTopics(draft.category);
    if (typeof draft.message === 'string') textarea.value = draft.message.slice(0, state.limits.message_max);
    if (emailInput && typeof draft.email === 'string') emailInput.value = draft.email.slice(0, 120);
    updateCounter();
    if (location.hash === '#destek') open(guest ? 'new' : 'list');
})();
