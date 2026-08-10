
const API_BASE_URL = '/api';
// TMDB ve Google Books istekleri artık doğrudan bu dış servislere değil, kendi
// sunucumuzdaki önbellekli vekil (proxy) uçlarına gidiyor — bkz. api/tmdb-proxy.php
// ve api/books-proxy.php. Böylece aynı popüler/en yüksek puanlı liste tüm
// ziyaretçiler arasında paylaşılan bir önbellekten sunuluyor (yavaş internetli
// kullanıcılar her sayfa açılışında aynı veriyi baştan indirmek zorunda kalmıyor)
// ve TMDB anahtarı istemci tarafında görünmüyor.
const TMDB_PROXY_URL = `${API_BASE_URL}/tmdb-proxy.php`;
const BOOKS_PROXY_URL = `${API_BASE_URL}/books-proxy.php`;
const fetchOptions = {
    credentials: 'include'
};

// Tema, sayfa çizilmeden önce (henüz DOMContentLoaded olmadan) uygulanır ki
// koyu modda kısa süreliğine beyaz bir "flash" görünmesin.
(function applyStoredTheme() {
    const saved = localStorage.getItem('theme');
    if (saved === 'dark' || saved === 'light') {
        document.documentElement.setAttribute('data-theme', saved);
    }
})();

class SocialLibraryApp {
    constructor() {
        this.currentUser = null;
        this.initPromise = this.init();
    }

    async init() {
        try {
            const response = await fetch(`${API_BASE_URL}/auth-status.php`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.user) {
                this.currentUser = data.user;
                this.csrfToken = data.csrf_token || null;
                console.log('✅ User logged in:', this.currentUser.username);
            } else {
                this.currentUser = null;
                this.csrfToken = null;
                console.log('Not authenticated');
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

    // Kullanıcıdan gelen metni innerHTML'e basmadan önce kaçış yapmak için kullan.
    // Gerçek mantık js/escape-html.js'te (tarayıcı + Node testlerinde ortak kullanılsın diye).
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
        document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
            btn.textContent = next === 'dark' ? '☀️' : '🌙';
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

    // --- Şifre göster/gizle ---
    wirePasswordToggle(input) {
        if (!input || input.dataset.toggleWired) return;
        input.dataset.toggleWired = '1';

        const wrap = document.createElement('div');
        wrap.className = 'password-field-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'password-toggle-btn';
        btn.setAttribute('aria-label', 'Şifreyi göster/gizle');
        btn.textContent = '👁️';
        btn.onclick = () => {
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.textContent = showing ? '👁️' : '🙈';
        };
        wrap.appendChild(btn);
    }

    wireAllPasswordToggles(root = document) {
        root.querySelectorAll('input[type="password"]').forEach(input => this.wirePasswordToggle(input));
    }

    // --- Bildirim zili (nav'da id="notifBellBtn"/"notifBadge"/"notifPanel" varsa çalışır) ---
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
        setInterval(() => this._refreshNotifications(panel, badge, panel.classList.contains('open')), 20000);
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

    async getTopRatedMovies() {
        try {
            const response = await fetch(`${TMDB_PROXY_URL}?endpoint=list&type=movie&list=top_rated&page=1`);
            const data = await response.json();
            return (data.results || []).slice(0, 12);
        } catch (error) {
            console.error('En iyi filmler hatası:', error);
            return [];
        }
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

    // --- Diziler (TMDB /tv) — filmlerle aynı desen, sadece uç nokta ve alan adları farklı (name/first_air_date) ---
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

    async getTopRatedSeries() {
        try {
            const response = await fetch(`${TMDB_PROXY_URL}?endpoint=list&type=series&list=top_rated&page=1`);
            const data = await response.json();
            return (data.results || []).slice(0, 12);
        } catch (error) {
            console.error('En iyi diziler hatası:', error);
            return [];
        }
    }

    async getPopularSeries() {
        try {
            const response = await fetch(`${TMDB_PROXY_URL}?endpoint=list&type=series&list=popular&page=1`);
            const data = await response.json();
            return (data.results || []).slice(0, 12);
        } catch (error) {
            console.error('Popüler diziler hatası:', error);
            return [];
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
            if (data.success) {
                console.log('✅ İçerik kaydedildi');
                return true;
            }
            return false;
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
            if (data.success) {
                console.log('✅ Yorum eklendi');
                return true;
            }
            return false;
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

    getTimeAgo(timestamp) {
        const now = new Date();
        const past = new Date(timestamp);
        const seconds = Math.floor((now - past) / 1000);

        if (seconds < 60) return 'biraz önce';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} dakika önce`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} saat önce`;
        if (seconds < 2592000) return `${Math.floor(seconds / 86400)} gün önce`;
        return `${Math.floor(seconds / 2592000)} ay önce`;
    }

    formatDate(timestamp) {
        const date = new Date(timestamp);
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

document.addEventListener('DOMContentLoaded', () => {
    app.wireAllPasswordToggles();
    document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
        btn.textContent = app.isDarkMode() ? '☀️' : '🌙';
        btn.addEventListener('click', () => app.toggleTheme());
    });
    app.initNotifications();
});

