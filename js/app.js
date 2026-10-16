
const API_BASE_URL = '/api';
// TMDB/Google Books istekleri kendi proxy uçlarımızdan geçer (ortak önbellek + gizli API anahtarı).
const TMDB_PROXY_URL = `${API_BASE_URL}/tmdb-proxy.php`;
const BOOKS_PROXY_URL = `${API_BASE_URL}/books-proxy.php`;
const fetchOptions = {
    credentials: 'include'
};

// Hareket tercihi js/motion.js tarafından <html data-motion> olarak yazılır; betikler yalnızca bunu okur.
function motionReduced() {
    return document.documentElement.getAttribute('data-motion') === 'reduced';
}

// Tema, beyaz flaş olmasın diye DOMContentLoaded'dan önce uygulanır.
(function applyStoredTheme() {
    const saved = localStorage.getItem('theme');
    if (saved === 'dark' || saved === 'light') {
        document.documentElement.setAttribute('data-theme', saved);
    }
})();

class SocialLibraryApp {
    constructor() {
        this.currentUser = null;
        this.realtimeEnabled = false;
        this.initPromise = this.init();
    }

    async init() {
        try {
            const response = await fetch(`${API_BASE_URL}/auth-status.php`, {
                credentials: 'include'
            });
            const data = await response.json();
            this.realtimeEnabled = data.realtime === true;

            if (data.success && data.user) {
                this.currentUser = data.user;
                this.csrfToken = data.csrf_token || null;
            } else {
                this.currentUser = null;
                this.csrfToken = null;
            }
        } catch (error) {
            console.error('Auth check failed:', error);
            this.currentUser = null;
            this.csrfToken = null;
        }
    }

    // Durum değiştiren (POST/PUT/DELETE) isteklere eklenecek ortak başlıklar.
    authHeaders(extra = {}) {
        return { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken || '', ...extra };
    }

    // Kullanıcı metnini innerHTML'e basmadan önce kaçış yap. Mantık js/escape-html.js'te.
    escapeHtml(str) {
        return escapeHtml(str);
    }

    // --- Tema (koyu/açık mod) ---
    toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme')
            || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        const next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
        window.dispatchEvent(new CustomEvent('themechange', { detail: { theme: next } }));
        document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
            btn.classList.toggle('is-dark', next === 'dark');
        });
    }

    isDarkMode() {
        const attr = document.documentElement.getAttribute('data-theme');
        if (attr === 'dark') return true;
        if (attr === 'light') return false;
        return window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    // --- Toast bildirimleri (alert() yerine) ---
    toast(message, type = 'info', duration = 3500) {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            document.body.appendChild(container);
        }
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.textContent = message;
        container.appendChild(el);
        setTimeout(() => {
            el.classList.add('toast-leaving');
            setTimeout(() => el.remove(), 200);
        }, duration);
    }

    // --- Onay modalı (confirm() yerine), Promise<boolean> döner ---
    confirmDialog(message, { confirmText = 'Onayla', cancelText = 'İptal' } = {}) {
        return new Promise(resolve => {
            const overlay = document.createElement('div');
            overlay.className = 'confirm-overlay';

            const box = document.createElement('div');
            box.className = 'confirm-box';

            const p = document.createElement('p');
            p.textContent = message;

            const actions = document.createElement('div');
            actions.className = 'confirm-actions';

            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn btn-secondary';
            cancelBtn.textContent = cancelText;

            const confirmBtn = document.createElement('button');
            confirmBtn.className = 'btn btn-danger';
            confirmBtn.textContent = confirmText;

            const finish = (result) => {
                overlay.remove();
                resolve(result);
            };
            cancelBtn.onclick = () => finish(false);
            confirmBtn.onclick = () => finish(true);
            overlay.onclick = (e) => { if (e.target === overlay) finish(false); };

            actions.appendChild(cancelBtn);
            actions.appendChild(confirmBtn);
            box.appendChild(p);
            box.appendChild(actions);
            overlay.appendChild(box);
            document.body.appendChild(overlay);
            confirmBtn.focus();
        });
    }

    // --- Avatar: yüklenmiş fotoğraf (yol/URL), emoji ya da kullanıcı adının baş harfi ---
    // size: '' | 'sm' | 'lg' | 'xl' (css/ui.css .avatar--*). Dönen değer güvenlidir: her şey kaçışlanır.
    avatarMarkup(avatar, username, size = '') {
        const cls = 'avatar' + (size ? ' avatar--' + size : '');
        const value = typeof avatar === 'string' ? avatar.trim() : '';
        const name = this.escapeHtml(username || '');
        if (value && /^(?:https?:\/\/|\/(?!\/))/i.test(value)) {
            return `<span class="${cls}"><img src="${this.escapeHtml(value)}" alt="" loading="lazy" decoding="async"></span>`;
        }
        if (value) {
            return `<span class="${cls} avatar--emoji" role="img" aria-label="${name}">${this.escapeHtml(value)}</span>`;
        }
        const initial = this.escapeHtml((username || '?').trim().charAt(0).toUpperCase() || '?');
        return `<span class="${cls}" role="img" aria-label="${name}">${initial}</span>`;
    }

    // --- Küçük satır içi ikon seti (JS ile üretilen kartlar için; stil css/ui.css .ui-i) ---
    icon(name) {
        const paths = {
            film: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18M17 3v18M3 8h4M3 12h18M3 16h4M17 8h4M17 16h4"/>',
            tv: '<rect width="20" height="15" x="2" y="7" rx="2"/><path d="m17 2-5 5-5-5"/>',
            book: '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M8 7h8M8 11h6"/>',
            star: '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9Z"/>',
            message: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            check: '<path d="M20 6 9 17l-5-5"/>',
            list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            calendar: '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            trash: '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
            shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
            flag: '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1zM4 22v-7"/>',
            user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            pencil: '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
            clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            heart: '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
            alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/>',
            sparkles: '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/>',
            lock: '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/>',
            search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            restore: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
            ban: '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
            volumeX: '<path d="M11 5 6 9H2v6h4l5 4zM22 9l-6 6M16 9l6 6"/>'
        };
        const body = paths[name] || paths.star;
        return `<svg class="ui-i" viewBox="0 0 24 24" aria-hidden="true">${body}</svg>`;
    }

    // --- Form modalı (prompt() yerine): Promise<{alan: değer}|null> ---
    // fields: [{ name, label, type: 'text'|'textarea'|'select'|'checkbox', value, placeholder, required, maxlength, hint, options: [{value, label}] }]
    formDialog({ title, description = '', fields = [], submitText = 'Kaydet', cancelText = 'Vazgeç', danger = false } = {}) {
        return new Promise(resolve => {
            const previouslyFocused = document.activeElement;
            const overlay = document.createElement('div');
            overlay.className = 'confirm-overlay sk-dialog-overlay';

            const form = document.createElement('form');
            form.className = 'sk-dialog';
            form.setAttribute('role', 'dialog');
            form.setAttribute('aria-modal', 'true');
            form.setAttribute('aria-labelledby', 'skDialogTitle');
            form.noValidate = true;

            const fieldHtml = fields.map((field, index) => {
                const id = 'skDialogField' + index;
                const label = this.escapeHtml(field.label || '');
                const hint = field.hint ? `<div class="sk-dialog-hint">${this.escapeHtml(field.hint)}</div>` : '';
                const required = field.required ? ' required' : '';
                const max = field.maxlength ? ` maxlength="${Number(field.maxlength)}"` : '';
                const placeholder = field.placeholder ? ` placeholder="${this.escapeHtml(field.placeholder)}"` : '';
                const value = field.value == null ? '' : String(field.value);
                if (field.type === 'checkbox') {
                    return `<label class="sk-dialog-check" for="${id}"><input type="checkbox" id="${id}" name="${this.escapeHtml(field.name)}"${field.value ? ' checked' : ''}> <span>${label}</span></label>${hint}`;
                }
                if (field.type === 'select') {
                    const options = (field.options || []).map(o => `<option value="${this.escapeHtml(o.value)}"${String(o.value) === value ? ' selected' : ''}>${this.escapeHtml(o.label)}</option>`).join('');
                    return `<div class="field"><label for="${id}">${label}</label><select id="${id}" name="${this.escapeHtml(field.name)}" class="select">${options}</select>${hint}</div>`;
                }
                if (field.type === 'textarea') {
                    return `<div class="field"><label for="${id}">${label}</label><textarea id="${id}" name="${this.escapeHtml(field.name)}" class="textarea"${required}${max}${placeholder}>${this.escapeHtml(value)}</textarea>${hint}</div>`;
                }
                return `<div class="field"><label for="${id}">${label}</label><input id="${id}" name="${this.escapeHtml(field.name)}" type="text" class="input" value="${this.escapeHtml(value)}"${required}${max}${placeholder}>${hint}</div>`;
            }).join('');

            form.innerHTML = `
                <h2 id="skDialogTitle" class="sk-dialog-title">${this.escapeHtml(title || '')}</h2>
                ${description ? `<p class="sk-dialog-desc">${this.escapeHtml(description)}</p>` : ''}
                <div class="sk-dialog-fields">${fieldHtml}</div>
                <div class="sk-dialog-error" role="alert" hidden></div>
                <div class="confirm-actions">
                    <button type="button" class="btn btn-secondary" data-dialog-cancel>${this.escapeHtml(cancelText)}</button>
                    <button type="submit" class="btn ${danger ? 'btn-danger' : 'btn-primary'}">${this.escapeHtml(submitText)}</button>
                </div>`;

            const finish = (result) => {
                document.removeEventListener('keydown', onKey, true);
                overlay.remove();
                if (previouslyFocused && typeof previouslyFocused.focus === 'function') previouslyFocused.focus();
                resolve(result);
            };
            const onKey = (event) => {
                if (event.key === 'Escape') {
                    event.stopPropagation();
                    finish(null);
                }
            };
            document.addEventListener('keydown', onKey, true);

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const values = {};
                const errorBox = form.querySelector('.sk-dialog-error');
                for (let i = 0; i < fields.length; i++) {
                    const field = fields[i];
                    const el = form.querySelector('#skDialogField' + i);
                    const value = field.type === 'checkbox' ? el.checked : el.value.trim();
                    if (field.required && !value) {
                        errorBox.textContent = `"${field.label}" alanı boş bırakılamaz.`;
                        errorBox.hidden = false;
                        el.focus();
                        return;
                    }
                    values[field.name] = value;
                }
                finish(values);
            });
            form.querySelector('[data-dialog-cancel]').addEventListener('click', () => finish(null));
            overlay.addEventListener('click', (event) => { if (event.target === overlay) finish(null); });

            overlay.appendChild(form);
            document.body.appendChild(overlay);
            const first = form.querySelector('input:not([type=checkbox]), textarea, select') || form.querySelector('button[type=submit]');
            first.focus();
        });
    }

    // --- Genel modal: Escape ile kapanır, odağı geri verir, üst üste açılanlarda yalnızca en üsttekini kapatır ---
    // html güvenilir olmalıdır (çağıran taraf kullanıcı metnini kaçışlar). Dönen: { el, body, close }
    openModal({ title = '', html = '', width = 560 } = {}) {
        const previouslyFocused = document.activeElement;
        const titleId = 'skModalTitle' + Math.random().toString(36).slice(2, 8);
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="${titleId}" style="max-width: ${Number(width)}px;">
                <div class="modal-box-header">
                    <h2 id="${titleId}">${this.escapeHtml(title)}</h2>
                    <button type="button" class="modal-close-btn" aria-label="Kapat">&times;</button>
                </div>
                <div class="modal-body">${html}</div>
            </div>`;

        this._modalStack = this._modalStack || [];
        const handle = { el: overlay, body: overlay.querySelector('.modal-body'), close: null };
        const onKey = (event) => {
            if (event.key === 'Escape' && this._modalStack[this._modalStack.length - 1] === handle) {
                event.stopImmediatePropagation();
                handle.close();
            }
        };
        handle.close = () => {
            if (!overlay.isConnected) return;
            document.removeEventListener('keydown', onKey, true);
            this._modalStack = this._modalStack.filter(item => item !== handle);
            overlay.remove();
            if (previouslyFocused && typeof previouslyFocused.focus === 'function' && previouslyFocused.isConnected) {
                previouslyFocused.focus();
            }
        };
        document.addEventListener('keydown', onKey, true);
        overlay.addEventListener('click', (event) => { if (event.target === overlay) handle.close(); });
        overlay.querySelector('.modal-close-btn').addEventListener('click', handle.close);

        this._modalStack.push(handle);
        document.body.appendChild(overlay);
        const focusTarget = overlay.querySelector('.modal-body input, .modal-body textarea, .modal-body button, .modal-body a') || overlay.querySelector('.modal-close-btn');
        focusTarget.focus();
        return handle;
    }

    // --- Şifre göster/gizle (Kayma yapmayan SVG ikonlu yapı) ---
    wirePasswordToggle(input) {
        if (!input || input.dataset.toggleWired) return;
        input.dataset.toggleWired = '1';

        const wrap = document.createElement('div');
        wrap.className = 'password-field-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        const eyeOpenSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>`;
        const eyeClosedSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>`;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'password-toggle-btn';
        btn.setAttribute('aria-label', 'Şifreyi göster veya gizle');
        btn.tabIndex = -1;
        btn.innerHTML = eyeOpenSvg;
        btn.onclick = (e) => {
            e.preventDefault();
            e.stopPropagation();
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.innerHTML = showing ? eyeOpenSvg : eyeClosedSvg;
            btn.setAttribute('aria-label', showing ? 'Şifreyi göster' : 'Şifreyi gizle');
        };
        wrap.appendChild(btn);
    }

    wireAllPasswordToggles(root = document) {
        root.querySelectorAll('input[type="password"]').forEach(input => this.wirePasswordToggle(input));
    }

    // --- Bildirim zili ---
    async initNotifications() {
        const bellBtn = document.getElementById('notifBellBtn');
        const badge = document.getElementById('notifBadge');
        const panel = document.getElementById('notifPanel');
        if (!bellBtn || !badge || !panel) return;

        await this.initPromise;
        if (!this.currentUser) return;

        document.addEventListener('click', (e) => {
            if (panel.classList.contains('open') && !panel.contains(e.target) && !bellBtn.contains(e.target)) {
                panel.classList.remove('open');
            }
        });

        bellBtn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const willOpen = !panel.classList.contains('open');
            panel.classList.toggle('open', willOpen);
            if (willOpen) await this._refreshNotifications(panel, badge, true);
        });

        await this._refreshNotifications(panel, badge, false);
        // Akış açıksa sayaç değişince yenilenir; kapalıysa 20 saniyede bir yoklanır.
        const refresh = () => this._refreshNotifications(panel, badge, panel.classList.contains('open'));
        this.openRealtime({
            onCounts: refresh,
            onFallback: () => setInterval(refresh, 20000),
        });
    }

    // Canlı akış (SSE). Sayfa başına tek bağlantı açılır; birden fazla dinleyici aynı akışı paylaşır.
    // Kanal kapalıysa (404, ENABLE_SSE kapalı) ya da kalıcı hatada onFallback çağrılır ve yoklamaya geçilir.
    // Normal kapanışta (25 sn) tarayıcı kendiliğinden yeniden bağlanır; bu yüzden yalnızca CLOSED'da vazgeçilir.
    // conversationId verilmezse (undefined) mevcut konuşma değiştirilmez.
    openRealtime(handlers = {}) {
        if (!this.realtimeEnabled || !('EventSource' in window) || this._realtimeFailed) {
            if (handlers.onFallback) handlers.onFallback();
            return () => { };
        }

        const rt = this._realtime || (this._realtime = { source: null, conversationId: undefined, listeners: new Set() });
        rt.listeners.add(handlers);

        const wanted = handlers.conversationId;
        const needsNewSource = !rt.source || (wanted !== undefined && wanted !== rt.conversationId);
        if (needsNewSource) {
            if (rt.source) rt.source.close();
            if (wanted !== undefined) rt.conversationId = wanted;
            const query = rt.conversationId ? `?conversation_id=${encodeURIComponent(rt.conversationId)}` : '';
            const source = new EventSource(`${API_BASE_URL}/stream.php${query}`, { withCredentials: true });
            rt.source = source;

            source.addEventListener('counts', (event) => {
                const counts = JSON.parse(event.data);
                rt.listeners.forEach(listener => listener.onCounts && listener.onCounts(counts));
            });
            source.addEventListener('typing', (event) => {
                const typing = JSON.parse(event.data).typing === true;
                rt.listeners.forEach(listener => listener.onTyping && listener.onTyping(typing));
            });
            source.addEventListener('error', () => {
                if (source.readyState !== EventSource.CLOSED) return;
                this._realtimeFailed = true;
                rt.source = null;
                rt.listeners.forEach(listener => listener.onFallback && listener.onFallback());
            });
        }

        return () => {
            rt.listeners.delete(handlers);
            if (rt.listeners.size === 0 && rt.source) {
                rt.source.close();
                rt.source = null;
            }
        };
    }

    // Rozet ızgarası. userId verilmezse oturum açmış kullanıcının rozetlerini gösterir.
    async renderBadges(container, userId = null) {
        if (!container) return;
        const query = userId ? `?user_id=${encodeURIComponent(userId)}` : '';
        const lockedIcon = '<svg class="ui-i" viewBox="0 0 24 24" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
        const unlockedIcon = '<svg class="ui-i" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 15-3.5 2 .9-4-3-2.7 4.1-.4L12 6l1.5 3.9 4.1.4-3 2.7.9 4Z"/></svg>';
        try {
            const res = await fetch(`${API_BASE_URL}/badges.php${query}`, { credentials: 'include' });
            const data = await res.json();
            if (!data.success) {
                container.innerHTML = '';
                return;
            }
            container.innerHTML = data.badges.map(badge => {
                const pct = Math.min(100, Math.round((badge.progress / badge.goal) * 100));
                const title = this.escapeHtml(badge.title);
                return `<article class="badge-card${badge.unlocked ? ' is-unlocked' : ''}">
                    <span class="badge-icon">${badge.unlocked ? unlockedIcon : lockedIcon}</span>
                    <div class="badge-text">
                        <h3 class="badge-title">${title}</h3>
                        <p class="badge-desc">${this.escapeHtml(badge.description)}</p>
                        <div class="badge-bar" role="progressbar" aria-label="${title} ilerlemesi" aria-valuemin="0" aria-valuemax="${badge.goal}" aria-valuenow="${badge.progress}"><span style="width: ${pct}%"></span></div>
                        <p class="badge-count">${badge.progress}/${badge.goal}${badge.unlocked ? ' · kazanıldı' : ''}</p>
                    </div>
                </article>`;
            }).join('');
        } catch (error) {
            container.innerHTML = '';
        }
    }

    // Letterboxd / Goodreads CSV içe aktarma (profil ayarları). Dosya önce ayrıştırılır,
    // sonra 40'lık parçalar halinde içe aktarılır; ilerleme durum satırında gösterilir.
    wireCsvImport() {
        const fileInput = document.getElementById('csvFileInput');
        const button = document.getElementById('csvImportBtn');
        const status = document.getElementById('csvImportStatus');
        if (!fileInput || !button || !status) return;

        button.addEventListener('click', async () => {
            const file = fileInput.files[0];
            if (!file) {
                status.textContent = 'Önce bir CSV dosyası seç.';
                return;
            }
            if (file.size > 3 * 1024 * 1024) {
                status.textContent = 'Dosya 3 MB\'tan büyük.';
                return;
            }

            const source = document.getElementById('csvSource').value;
            const list = document.getElementById('csvList').value;
            button.disabled = true;
            status.textContent = 'Dosya okunuyor...';

            try {
                const csv = await file.text();
                const parseRes = await fetch(`${API_BASE_URL}/csv-import.php`, {
                    method: 'POST',
                    headers: this.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({ action: 'parse', source, list, csv })
                });
                const parsed = await parseRes.json();
                if (!parsed.success) {
                    status.textContent = parsed.message || 'Dosya ayrıştırılamadı.';
                    return;
                }

                const rows = parsed.rows;
                let imported = 0;
                const notFound = [];
                for (let start = 0; start < rows.length; start += 40) {
                    const chunk = rows.slice(start, start + 40);
                    status.textContent = `İçe aktarılıyor: ${Math.min(start + 40, rows.length)} / ${rows.length}`;
                    const res = await fetch(`${API_BASE_URL}/csv-import.php`, {
                        method: 'POST',
                        headers: this.authHeaders(),
                        credentials: 'include',
                        body: JSON.stringify({ action: 'import', rows: chunk })
                    });
                    const data = await res.json();
                    if (!data.success) {
                        status.textContent = data.message || 'İçe aktarma durdu.';
                        return;
                    }
                    imported += data.imported;
                    notFound.push(...data.not_found);
                }

                const missed = notFound.length
                    ? `, ${notFound.length} yapım eşleşmedi (${notFound.slice(0, 3).map(t => this.escapeHtml(t)).join(', ')}${notFound.length > 3 ? '…' : ''})`
                    : '';
                status.textContent = `Tamamlandı: ${imported} kayıt eklendi${missed}.`;
                fileInput.value = '';
            } catch (error) {
                status.textContent = 'Bağlantı hatası: ' + error.message;
            } finally {
                button.disabled = false;
            }
        });
    }

    async _refreshNotifications(panel, badge, renderList) {
        try {
            const res = await fetch(`${API_BASE_URL}/notifications.php`, { credentials: 'include' });
            const data = await res.json();
            if (!data.success) return;
            const items = data.notifications || [];
            const unreadCount = items.filter(n => !n.is_read).length;

            if (unreadCount > 0) {
                badge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
                badge.classList.add('visible');
            } else {
                badge.classList.remove('visible');
            }

            // Sayaç arttıysa zile pop animasyonu ver.
            if (this._lastUnreadCount !== undefined && unreadCount > this._lastUnreadCount) {
                badge.classList.remove('nav-badge-pulse');
                void badge.offsetWidth;
                badge.classList.add('nav-badge-pulse');
            }
            this._lastUnreadCount = unreadCount;

            if (renderList) this._renderNotifications(panel, items);
        } catch (error) {
            console.error('Bildirim yükleme hatası:', error);
        }
    }

    _notificationText(n) {
        const username = this.escapeHtml(n.username || 'Bir kullanıcı');
        switch (n.type) {
            case 'follow':
                return `<strong>${username}</strong> seni takip etmeye başladı`;
            case 'message':
                return `<strong>${username}</strong> sana mesaj gönderdi`;
            case 'release':
                return `<strong>${this.escapeHtml(n.content_title || 'Bir yapım')}</strong> vizyona girdi`;
            default:
                return `<strong>${username}</strong> bir bildirim gönderdi`;
        }
    }

    _renderNotifications(panel, items) {
        const header = '<div class="nav-panel-header">Bildirimler</div>';
        if (items.length === 0) {
            panel.innerHTML = header + '<div class="nav-panel-empty">Henüz bildirim yok</div>';
            return;
        }
        const rows = items.slice(0, 20).map(n => {
            const text = this._notificationText(n);
            const timeAgo = this.getTimeAgo(n.created_at);
            const unreadClass = n.is_read ? '' : 'nav-panel-item-unread';
            return `<div class="nav-panel-item ${unreadClass}" data-id="${n.id}" data-type="${this.escapeHtml(n.type)}" data-actor="${n.actor_id ?? ''}" data-content-id="${n.content_id ?? ''}">
                <div>${text}</div>
                <div class="nav-panel-item-time">${timeAgo}</div>
            </div>`;
        }).join('');
        panel.innerHTML = header + `<div>${rows}</div>`;

        panel.querySelectorAll('.nav-panel-item').forEach(el => {
            el.addEventListener('click', () => {
                const { id, type, actor, contentId } = el.dataset;
                fetch(`${API_BASE_URL}/notifications.php`, {
                    method: 'PUT',
                    headers: this.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({ id: parseInt(id, 10) })
                }).catch(() => { });

                if (type === 'follow' && actor) {
                    window.location.href = `user-profile.html?id=${actor}`;
                } else if (type === 'message' && contentId) {
                    window.location.href = `messages.html?conversation_id=${contentId}`;
                } else if (type === 'release' && contentId) {
                    window.location.href = `detail.php?type=movie&id=${contentId}`;
                }
            });
        });
    }

    async searchMovies(query, page = 1) {
        try {
            const response = await fetch(
                `${TMDB_PROXY_URL}?endpoint=search&type=movie&query=${encodeURIComponent(query)}&page=${page}`
            );
            return await response.json();
        } catch (error) {
            console.error('Film arama hatası:', error);
            return { results: [] };
        }
    }

    async getMovieDetail(movieId) {
        try {
            const response = await fetch(`${TMDB_PROXY_URL}?endpoint=detail&type=movie&id=${movieId}`);
            return await response.json();
        } catch (error) {
            console.error('Film detay hatası:', error);
            return null;
        }
    }

    // Film ve dizi atmosferi: her sayfanın arkasında, filmlerden ve dizilerden karelerin zıt yönlerde kaydığı satırlar.
    // Kareler TMDB'nin popüler ve en yüksek puanlı listelerinden gelir. Hareket tercihine göre kayar ya da durağan kolaj olur.
    async initAtmosphere() {
        if (document.querySelector('.atmo')) return;
        const stills = await this.getAtmosphereStills();
        if (stills.length < 8) return;

        const rowCount = window.matchMedia('(max-width: 720px)').matches ? 3 : 4;
        const rows = Array.from({ length: rowCount }, (_, rowIndex) => {
            const set = stills
                .filter((_, i) => i % rowCount === rowIndex)
                .slice(0, 10)
                .map(path => `<img class="atmo-still" src="https://image.tmdb.org/t/p/w300${this.escapeHtml(path)}" alt="" loading="lazy" decoding="async">`)
                .join('');
            const style = `--atmo-duration:${110 + rowIndex * 14}s; --atmo-delay:-${rowIndex * 23}s; --atmo-i:${rowIndex}`;
            return `<div class="atmo-row"><div class="atmo-track" style="${style}"><div class="atmo-set">${set}</div><div class="atmo-set" aria-hidden="true">${set}</div></div></div>`;
        });

        const layer = document.createElement('div');
        layer.className = 'atmo';
        layer.setAttribute('aria-hidden', 'true');
        layer.innerHTML = `<div class="atmo-rows">${rows.join('')}</div>`;
        document.body.prepend(layer);
    }

    // Film ve dizi kareleri sırayla karışır (aynı kare iki kez gelmez). Liste 6 saat oturum belleğinde tutulur,
    // böylece her sayfa geçişinde TMDB listeleri yeniden istenmez.
    async getAtmosphereStills() {
        const CACHE_KEY = 'sk-atmo-stills-v2';
        try {
            const cached = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null');
            if (cached && Array.isArray(cached.paths) && Date.now() - cached.at < 6 * 3600 * 1000) return cached.paths;
        } catch (error) {
            // Oturum belleği kapalı olabilir; liste her seferinde alınır.
        }

        const pick = (type, list) => fetch(`${TMDB_PROXY_URL}?endpoint=list&type=${type}&list=${list}&page=1`)
            .then(response => (response.ok ? response.json() : { results: [] }))
            .catch(() => ({ results: [] }))
            .then(data => (data.results || []).map(item => item.backdrop_path).filter(Boolean));
        const [moviePopular, seriesPopular, movieTop, seriesTop] = await Promise.all([
            pick('movie', 'popular'), pick('series', 'popular'), pick('movie', 'top_rated'), pick('series', 'top_rated'),
        ]);

        const movies = [...moviePopular, ...movieTop];
        const series = [...seriesPopular, ...seriesTop];
        const seen = new Set();
        const mixed = [];
        for (let i = 0; i < Math.max(movies.length, series.length); i++) {
            for (const path of [movies[i], series[i]]) {
                if (path && !seen.has(path)) {
                    seen.add(path);
                    mixed.push(path);
                }
            }
        }
        const paths = mixed.slice(0, 48);
        try {
            sessionStorage.setItem(CACHE_KEY, JSON.stringify({ at: Date.now(), paths }));
        } catch (error) {
            // Önbelleğe yazılamasa da arka plan çalışır.
        }
        return paths;
    }

    // Hareket düğmesi: navbar'daki tema düğmesinin yanına eklenir. Sistem hareketi azaltıyorsa ve kullanıcı henüz seçim
    // yapmadıysa, nedenini açıklayan bir ipucu gösterilir (Windows'ta "Animasyon efektleri" kapalıyken tarayıcı bunu bildirir).
    initMotionToggle() {
        const motion = window.SKMotion;
        if (!motion) return;
        const reduced = motion.isReduced();
        const label = reduced ? 'Animasyonları aç' : 'Animasyonları durdur';
        document.querySelectorAll('.theme-toggle-btn').forEach((themeBtn) => {
            if (themeBtn.parentElement.querySelector('.motion-toggle-btn')) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'nav-icon-btn motion-toggle-btn';
            button.setAttribute('aria-label', label);
            button.setAttribute('aria-pressed', String(!reduced));
            button.title = label;
            const sparkle = '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/><path d="M19 3v4"/><path d="M21 5h-4"/>';
            const attrs = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
            button.innerHTML = `<svg class="icon-motion-on" ${attrs}>${sparkle}</svg><svg class="icon-motion-off" ${attrs}>${sparkle}<line x1="3" y1="3" x2="21" y2="21"/></svg>`;
            button.addEventListener('click', () => motion.toggle());
            themeBtn.before(button);
        });

        if (reduced && motion.systemReduced && !motion.userChoice) {
            this.showMotionHint();
        }
    }

    showMotionHint() {
        try {
            if (Number(localStorage.getItem('sk-motion-hint-until') || 0) > Date.now()) return;
        } catch (error) {
            // Depolama kapalıysa ipucu her seferinde gösterilir.
        }
        if (!document.querySelector('.motion-toggle-btn')) return;

        const hint = document.createElement('div');
        hint.className = 'motion-hint';
        hint.setAttribute('role', 'status');
        hint.innerHTML = `<span><strong>Animasyonlar kapalı.</strong> Windows'ta "Animasyon efektleri" kapalı olduğu için sayfa durağan gösteriliyor.</span>
            <div class="motion-hint-actions">
                <button type="button" data-action="enable">Animasyonları aç</button>
                <button type="button" data-action="dismiss">Şimdi değil</button>
            </div>`;
        hint.addEventListener('click', (event) => {
            const action = event.target.dataset ? event.target.dataset.action : null;
            if (action === 'enable') {
                window.SKMotion.set('full');
            } else if (action === 'dismiss') {
                try {
                    localStorage.setItem('sk-motion-hint-until', String(Date.now() + 7 * 24 * 3600 * 1000));
                } catch (error) {
                    // Kalıcı olmasa da bu sayfada kapanır.
                }
                hint.remove();
            }
        });
        document.body.appendChild(hint);
    }

    async getPopularMovies() {
        try {
            const response = await fetch(`${TMDB_PROXY_URL}?endpoint=list&type=movie&list=popular&page=1`);
            const data = await response.json();
            return (data.results || []).slice(0, 12);
        } catch (error) {
            console.error('Popüler filmler hatası:', error);
            return [];
        }
    }

    // --- Diziler (TMDB /tv) — filmlerle aynı desen ---
    async searchSeries(query, page = 1) {
        try {
            const response = await fetch(
                `${TMDB_PROXY_URL}?endpoint=search&type=series&query=${encodeURIComponent(query)}&page=${page}`
            );
            return await response.json();
        } catch (error) {
            console.error('Dizi arama hatası:', error);
            return { results: [] };
        }
    }

    async getSeriesDetail(seriesId) {
        try {
            const response = await fetch(`${TMDB_PROXY_URL}?endpoint=detail&type=series&id=${seriesId}`);
            return await response.json();
        } catch (error) {
            console.error('Dizi detay hatası:', error);
            return null;
        }
    }

    async discoverMovies(filters = {}, page = 1) {
        try {
            const qs = new URLSearchParams({
                endpoint: 'discover',
                type: 'movie',
                page: page,
                year: filters.year || '',
                genre: filters.genre || '',
                min_rating: filters.min_rating || '',
                sort_by: filters.sort_by || 'popularity'
            }).toString();
            const response = await fetch(`${TMDB_PROXY_URL}?${qs}`);
            return await response.json();
        } catch (error) {
            console.error('Film keşif hatası:', error);
            return { results: [], total_pages: 0, total_results: 0 };
        }
    }

    async discoverSeries(filters = {}, page = 1) {
        try {
            const qs = new URLSearchParams({
                endpoint: 'discover',
                type: 'series',
                page: page,
                year: filters.year || '',
                genre: filters.genre || '',
                min_rating: filters.min_rating || '',
                sort_by: filters.sort_by || 'popularity'
            }).toString();
            const response = await fetch(`${TMDB_PROXY_URL}?${qs}`);
            return await response.json();
        } catch (error) {
            console.error('Dizi keşif hatası:', error);
            return { results: [], total_pages: 0, total_results: 0 };
        }
    }

    async searchBooks(query) {
        try {
            const url = `${BOOKS_PROXY_URL}?action=search&q=${encodeURIComponent('intitle:' + query)}&maxResults=40&orderBy=relevance`;
            const response = await fetch(url);
            if (!response.ok) throw new Error(`Google Books API error: ${response.status}`);
            const data = await response.json();
            let items = data.items || [];
            const queryLower = query.toLowerCase();

            items.sort((a, b) => {
                const titleA = (a.volumeInfo?.title || '').toLowerCase();
                const titleB = (b.volumeInfo?.title || '').toLowerCase();
                if (titleA === queryLower && titleB !== queryLower) return -1;
                if (titleB === queryLower && titleA !== queryLower) return 1;
                const containsA = titleA.includes(queryLower);
                const containsB = titleB.includes(queryLower);
                if (containsA && !containsB) return -1;
                if (containsB && !containsA) return 1;

                return 0;
            });

            return { items };
        } catch (error) {
            console.error('Kitap arama hatası:', error);
            return { items: [] };
        }
    }

    async getBookDetail(bookId) {
        try {
            const url = `${BOOKS_PROXY_URL}?action=detail&id=${encodeURIComponent(bookId)}`;
            const response = await fetch(url);
            if (!response.ok) throw new Error(`Google Books detail error: ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error('Kitap detay hatası:', error);
            return null;
        }
    }
    async saveContentStatus(contentType, contentId, rating = 0, notes = '') {
        if (!this.currentUser) {
            alert('Lütfen giriş yapın');
            return false;
        }

        try {
            const response = await fetch(`${API_BASE_URL}/save-content-status.php`, {
                method: 'POST',
                headers: this.authHeaders(),
                credentials: 'include',
                body: JSON.stringify({
                    user_id: this.currentUser.id,
                    content_type: contentType,
                    content_id: contentId,
                    rating: rating,
                    notes: notes
                })
            });

            const data = await response.json();
            return data.success === true;
        } catch (error) {
            console.error('İçerik kaydetme hatası:', error);
            return false;
        }
    }
    async addComment(contentType, contentId, commentText, contentTitle = '') {
        if (!this.currentUser) {
            alert('Lütfen giriş yapın');
            return false;
        }

        try {
            const response = await fetch(`${API_BASE_URL}/comments.php`, {
                method: 'POST',
                headers: this.authHeaders(),
                credentials: 'include',
                body: JSON.stringify({
                    action: 'add',
                    user_id: this.currentUser.id,
                    content_type: contentType,
                    content_id: contentId,
                    comment_text: commentText,
                    content_title: contentTitle
                })
            });

            const data = await response.json();
            return data.success === true;
        } catch (error) {
            console.error('Yorum ekleme hatası:', error);
            return false;
        }
    }
    async deleteComment(commentId) {
        if (!this.currentUser) return false;

        try {
            const response = await fetch(`${API_BASE_URL}/comments.php`, {
                method: 'POST',
                headers: this.authHeaders(),
                credentials: 'include',
                body: JSON.stringify({
                    action: 'delete',
                    comment_id: commentId,
                    user_id: this.currentUser.id
                })
            });

            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Yorum silme hatası:', error);
            return false;
        }
    }
    async likeComment(commentId) {
        if (!this.currentUser) {
            alert('Lütfen giriş yapın');
            return false;
        }

        try {
            const response = await fetch(`${API_BASE_URL}/like-comment.php`, {
                method: 'POST',
                headers: this.authHeaders(),
                credentials: 'include',
                body: JSON.stringify({
                    comment_id: commentId,
                    user_id: this.currentUser.id
                })
            });

            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Beğen hatası:', error);
            return false;
        }
    }
    async getComments(contentType, contentId) {
        try {
            const response = await fetch(
                `${API_BASE_URL}/comments.php?action=get&content_type=${contentType}&content_id=${contentId}`,
                { credentials: 'include' }
            );
            const data = await response.json();
            return data.comments || [];
        } catch (error) {
            console.error('Yorum getirme hatası:', error);
            return [];
        }
    }
    async followUser(targetUserId) {
        if (!this.currentUser) {
            alert('Lütfen giriş yapın');
            return false;
        }

        try {
            const response = await fetch(`${API_BASE_URL}/follow.php`, {
                method: 'POST',
                headers: this.authHeaders(),
                credentials: 'include',
                body: JSON.stringify({
                    target_user_id: targetUserId
                })
            });

            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Takip hatası:', error);
            return false;
        }
    }
    async unfollowUser(targetUserId) {
        if (!this.currentUser) return false;

        try {
            const response = await fetch(`${API_BASE_URL}/follow.php`, {
                method: 'DELETE',
                headers: this.authHeaders(),
                credentials: 'include',
                body: JSON.stringify({
                    target_user_id: targetUserId
                })
            });

            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Takip bırakma hatası:', error);
            return false;
        }
    }
    async isFollowing(targetUserId) {
        if (!this.currentUser) return false;

        try {
            const response = await fetch(
                `${API_BASE_URL}/follow-status.php?follower_id=${this.currentUser.id}&followed_id=${targetUserId}`,
                { credentials: 'include' }
            );
            const data = await response.json();
            return data.is_following;
        } catch (error) {
            console.error('Takip durumu kontrol hatası:', error);
            return false;
        }
    }
    async getRating(contentType, contentId) {
        if (!this.currentUser) return null;

        try {
            const response = await fetch(
                `${API_BASE_URL}/user-status.php?content_type=${contentType}&content_id=${contentId}`,
                { method: 'GET', credentials: 'include' }
            );
            const data = await response.json();
            return data.success ? data.rating : null;
        } catch (error) {
            console.error('Puan getirme hatası:', error);
            return null;
        }
    }

    // Sunucu saat dilimi belirtmeden UTC zaman yazar ("2026-10-16 17:07:00"); bu biçim UTC olarak okunur,
    // yoksa tarayıcı yerel saat sayar ve yaşlar tarayıcının UTC farkı kadar kayar.
    parseServerDate(value) {
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(value)) {
            return new Date(value.replace(' ', 'T') + 'Z');
        }
        return new Date(value);
    }

    getTimeAgo(timestamp) {
        const now = new Date();
        const past = this.parseServerDate(timestamp);
        const seconds = Math.floor((now - past) / 1000);

        if (seconds < 60) return 'biraz önce';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} dakika önce`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} saat önce`;
        if (seconds < 2592000) return `${Math.floor(seconds / 86400)} gün önce`;
        return `${Math.floor(seconds / 2592000)} ay önce`;
    }

    formatDate(timestamp) {
        const date = this.parseServerDate(timestamp);
        const now = new Date();
        const diffMs = now - date;
        const diffSec = Math.floor(diffMs / 1000);
        const diffMin = Math.floor(diffSec / 60);
        const diffHour = Math.floor(diffMin / 60);
        const diffDay = Math.floor(diffHour / 24);

        if (diffSec < 60) {
            return 'Az önce';
        } else if (diffMin < 60) {
            return `${diffMin} dakika önce`;
        } else if (diffHour < 24) {
            return `${diffHour} saat önce`;
        } else if (diffDay < 7) {
            return `${diffDay} gün önce`;
        } else {
            return date.toLocaleDateString('tr-TR');
        }
    }

    // Renk kodlu puan rozeti: 7+ yeşil, 5-7 turuncu, altı kırmızı.
    ratingPillHtml(score) {
        const n = parseFloat(score);
        if (isNaN(n)) return '<span class="rating-pill">☆ N/A</span>';
        const cls = n >= 7 ? 'rating-high' : n >= 5 ? 'rating-mid' : 'rating-low';
        return `<span class="rating-pill ${cls}">⭐ ${n.toFixed(1)}</span>`;
    }

    // 1-10 arası puan dağılımını basit bir CSS bar grafiğine çevirir.
    renderRatingHistogram(containerEl, distribution) {
        const counts = Object.values(distribution);
        const max = Math.max(1, ...counts);
        containerEl.innerHTML = Object.keys(distribution)
            .sort((a, b) => a - b)
            .map(rating => {
                const count = distribution[rating] || 0;
                const heightPct = count > 0 ? Math.max(6, Math.round((count / max) * 100)) : 0;
                return `
                    <div class="rating-bar-col" title="${count} puan">
                        <div class="rating-bar" style="height: ${heightPct}%;"></div>
                        <div class="rating-bar-label">${rating}</div>
                    </div>
                `;
            }).join('');
    }

    ratingToStars(rating) {
        const stars = Math.round(rating / 2);
        return '⭐'.repeat(stars);
    }

    logout() {
        this.currentUser = null;
    }
}
const app = new SocialLibraryApp();
async function logout() {
    try {
        await fetch(`${API_BASE_URL}/logout.php`, { credentials: 'include' });
    } catch (e) { }
    app.logout();
    window.location.href = 'index.html';
}

// --- Mobil hamburger menü ---
function wireNavToggle() {
    document.querySelectorAll('.nav-toggle-btn').forEach(btn => {
        const links = btn.parentElement?.querySelector('.nav-links');
        if (!links || btn.dataset.navToggleWired) return;
        btn.dataset.navToggleWired = '1';

        const setOpen = (open) => {
            links.classList.toggle('nav-links-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            setOpen(!links.classList.contains('nav-links-open'));
        });

        links.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => setOpen(false));
        });

        document.addEventListener('click', (e) => {
            if (links.classList.contains('nav-links-open') && !links.contains(e.target) && !btn.contains(e.target)) {
                setOpen(false);
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) setOpen(false);
        });
    });
}

// --- Kart ve buton hareketleri: fare konumunu CSS değişkenlerine yazar, [data-tilt] öğeleri eğer ---
function wirePointerEffects() {
    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    const reduceMotion = motionReduced();

    document.addEventListener('pointermove', (e) => {
        const surface = e.target.closest?.('.card, [data-tilt]');
        if (!surface) return;
        const rect = surface.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        surface.style.setProperty('--mx', x + 'px');
        surface.style.setProperty('--my', y + 'px');

        if (!reduceMotion && surface.hasAttribute('data-tilt')) {
            const rx = (y / rect.height - 0.5) * -8;
            const ry = (x / rect.width - 0.5) * 8;
            surface.style.transform = 'perspective(900px) rotateX(' + rx.toFixed(2) + 'deg) rotateY(' + ry.toFixed(2) + 'deg)';
        }
    });

    document.addEventListener('pointerout', (e) => {
        const surface = e.target.closest?.('[data-tilt]');
        if (surface && !surface.contains(e.relatedTarget)) surface.style.transform = '';
    });
}

// --- Kaydırma ile belirme: .reveal öğeleri görünür olunca yumuşakça açılır ---
function wireReveal() {
    const items = document.querySelectorAll('.reveal');
    if (!items.length) return;
    if (!('IntersectionObserver' in window) || motionReduced()) {
        items.forEach(el => el.classList.add('is-visible'));
        return;
    }
    document.documentElement.classList.add('js-motion');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.12 });
    items.forEach(el => observer.observe(el));
}

document.addEventListener('DOMContentLoaded', () => {
    app.wireAllPasswordToggles();
    document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
        btn.classList.toggle('is-dark', app.isDarkMode());
        btn.addEventListener('click', () => app.toggleTheme());
    });
    app.initNotifications();
    wireNavToggle();
    wirePointerEffects();
    wireReveal();
    app.wireCsvImport();
    app.initAtmosphere();
    app.initMotionToggle();

    // Bazı sayfalar giriş yapmadan da gezilebiliyor — çıkış yapmamış birine
    // hesap gerektiren nav ögelerini (Akış/Sosyal/Profilim/Mesajlar/bildirim)
    // göstermenin anlamı yok, sadece Keşfet + tema + Giriş Yap kalsın.
    const LOGIN_REQUIRED_NAV_HREFS = ['feed.html', 'social.html', 'profile.html', 'messages.html'];
    app.initPromise.then(() => {
        if (app.currentUser && app.currentUser.is_admin) {
            const adminLink = document.getElementById('navAdminLink');
            if (adminLink) adminLink.hidden = false;
        }
        if (app.currentUser) return;

        document.querySelectorAll('.logout-btn').forEach(btn => {
            btn.textContent = 'Giriş Yap';
            btn.onclick = null;
            btn.addEventListener('click', () => { window.location.href = 'index.html'; });
        });

        LOGIN_REQUIRED_NAV_HREFS.forEach(href => {
            document.querySelectorAll(`.nav-link[href="${href}"]`).forEach(el => el.style.display = 'none');
        });
        document.querySelectorAll('.nav-icon-wrap').forEach(el => el.style.display = 'none');
    });
});

// PWA service worker kaydı — bkz. sw.js.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(err => {
            console.error('Service worker kayıt hatası:', err);
        });
    });
}

