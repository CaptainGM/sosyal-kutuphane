// Profil sayfalarının (profile.html, user-profile.html) ortak görünüm yardımcıları.
// Kullanıcı verisi kaçışlanarak HTML metnine çevrilir; çağıran taraf döneni innerHTML'e yazar.
// escapeHtml (js/escape-html.js) ve app (js/app.js) bu dosyadan önce yüklenmiş olmalıdır.
const ProfileView = (() => {
    const TYPE_META = {
        movie: { label: 'Film', icon: 'film' },
        series: { label: 'Dizi', icon: 'tv' },
        book: { label: 'Kitap', icon: 'book' }
    };

    const esc = (value) => escapeHtml(value);

    function meta(type) {
        return TYPE_META[type] || TYPE_META.movie;
    }

    function posterUrl(type, poster, size = 'w342') {
        if (!poster) return 'img/no-poster.svg';
        if (type === 'book') return String(poster).replace('http://', 'https://');
        return `https://image.tmdb.org/t/p/${size}${poster}`;
    }

    function detailHref(type, id) {
        return `detail.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`;
    }

    // Boş durum kutusu: simge, başlık, açıklama ve isteğe bağlı bağlantı düğmesi.
    function empty(iconName, title, hint, cta) {
        return `<div class="empty">
            <div class="empty-icon">${app.icon(iconName)}</div>
            <div class="empty-title">${esc(title)}</div>
            <p>${esc(hint)}</p>
            ${cta ? `<a class="btn-glass" href="${esc(cta.href)}">${esc(cta.label)}</a>` : ''}
        </div>`;
    }

    // Kütüphane öğesi (get-user-content.php satırı) için afiş kartı.
    function libraryCard(type, item) {
        const id = type === 'book' ? (item.google_books_id || item.id) : (item.tmdb_id || item.id);
        const cover = type === 'book' ? posterUrl('book', item.cover_url) : posterUrl(type, item.poster_path);
        const rating = item.rating ? `<span class="chip chip--warm">★ ${esc(item.rating)}/10</span>` : '';
        return `<a class="content-card" href="${esc(detailHref(type, id))}">
            <img src="${esc(cover)}" alt="" loading="lazy" onerror="this.onerror=null;this.src='img/no-poster.svg'">
            <div class="info"><h3>${esc(item.title || 'Adsız')}</h3>${rating ? `<div class="meta">${rating}</div>` : ''}</div>
        </a>`;
    }

    function diaryRow(entry, date) {
        const typeMeta = meta(entry.type);
        const day = date.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long' });
        return `<a class="diary-row" href="${esc(detailHref(entry.type, entry.id))}">
            <img src="${esc(posterUrl(entry.type, entry.poster, 'w154'))}" alt="" loading="lazy" onerror="this.onerror=null;this.src='img/no-poster.svg'">
            <span class="diary-row-text"><strong>${esc(entry.title)}</strong><span class="faint">${esc(typeMeta.label)} · ${esc(day)}</span></span>
            ${entry.rating ? `<span class="chip chip--warm">★ ${esc(entry.rating)}/10</span>` : ''}
        </a>`;
    }

    // Günlük kayıtlarını ay ay gruplayıp HTML döndürür (diary.php: type, id, title, poster, rating, date).
    function diaryMonths(entries) {
        const groups = new Map();
        entries.forEach(entry => {
            const date = app.parseServerDate(entry.date);
            const key = date.toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' });
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push({ entry, date });
        });
        return [...groups].map(([month, rows]) => `
            <section class="diary-month" aria-label="${esc(month)}">
                <h3 class="diary-month-title">${esc(month)} <span class="chip">${rows.length}</span></h3>
                <div class="diary-rows">${rows.map(({ entry, date }) => diaryRow(entry, date)).join('')}</div>
            </section>
        `).join('');
    }

    return { TYPE_META, esc, meta, posterUrl, detailHref, empty, libraryCard, diaryRow, diaryMonths };
})();
