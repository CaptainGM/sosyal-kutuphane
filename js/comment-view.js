// Yorum görünümü: gövde (spoiler dahil), zaman damgaları, içerik bağlantısı ve kart iskeleti.
// detail.php ve sosyal sayfa bu dosyayı kullanır; düğmeler ve işlevler kendi sayfalarında kalır.
// escapeHtml (escape-html.js) ve app (app.js) genel kapsamdan okunur.
(function (global) {
    const EYE_OFF_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>';
    const HEART_PATH = 'M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z';
    const CONTENT_LABELS = { movie: 'Film', series: 'Dizi', book: 'Kitap' };
    const NO_POSTER = 'img/no-poster.svg';

    // Yorum tepkileri: her yoruma en fazla bir tepki. Sayılar ve kendi tepkisi sunucudan gelir.
    const REACTION_TYPES = [
        ['laugh', '😂', 'Güldüm'],
        ['cry', '😢', 'Ağladım'],
        ['wow', '😮', 'Şaşırdım'],
        ['think', '🤔', 'Düşündürdü'],
    ];

    function reactionButtons(comment) {
        if (!comment.reactions || (typeof app !== 'undefined' && app.currentUser && app.currentUser.is_demo)) return '';
        const items = REACTION_TYPES.map(([key, emoji, label]) => {
            const count = Number(comment.reactions[key]) || 0;
            const mine = comment.my_reaction === key;
            return `<button type="button" class="cv-react${mine ? ' is-on' : ''}" data-reaction-comment="${Number(comment.id)}" data-reaction-type="${key}" aria-pressed="${mine}" aria-label="${label}${count ? `, ${count}` : ''}" title="${label}"><span aria-hidden="true">${emoji}</span><span class="cv-react-count" aria-hidden="true">${count || ''}</span></button>`;
        }).join('');
        return `<span class="cv-reactions" role="group" aria-label="Tepkiler">${items}</span>`;
    }

    // Tepki düğmesi: aynı tepkiye ikinci basış geri alır, başka tepki öncekinin yerine geçer.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-reaction-type]');
        if (!button) return;
        const commentId = Number(button.dataset.reactionComment);
        const type = button.dataset.reactionType;
        const removing = button.getAttribute('aria-pressed') === 'true';
        const notify = (message) => { if (typeof app !== 'undefined') app.toast(message, 'error'); };
        button.disabled = true;
        try {
            const response = await fetch('/api/comment-reaction.php', {
                method: removing ? 'DELETE' : 'POST',
                headers: typeof app !== 'undefined' ? app.authHeaders() : { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify(removing ? { comment_id: commentId } : { comment_id: commentId, type }),
            });
            const data = await response.json();
            if (!data.success) {
                notify(data.message || 'Tepki kaydedilemedi');
                return;
            }
            const group = button.closest('.cv-reactions');
            if (group) {
                group.outerHTML = reactionButtons({ id: commentId, reactions: data.reactions, my_reaction: data.my_reaction });
            }
        } catch (error) {
            notify('Bağlantı hatası');
        } finally {
            button.disabled = false;
        }
    });

    // Beğeni simgesi: beğenildiğinde dolu, değilse çerçeveli. Renk düğmenin "is-liked" durumundan gelir.
    function heartIcon(liked) {
        return `<svg width="14" height="14" viewBox="0 0 24 24" fill="${liked ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="${HEART_PATH}"/></svg>`;
    }

    // app, app.js'te üst düzey const olarak tanımlanır: window üzerinden değil, genel kapsamdan okunur.
    function toDate(value) {
        if (typeof app !== 'undefined' && typeof app.parseServerDate === 'function') {
            return app.parseServerDate(value);
        }
        return new Date(value);
    }

    function ago(value) {
        if (!value) return '';
        if (typeof app !== 'undefined' && typeof app.getTimeAgo === 'function') {
            return app.getTimeAgo(value);
        }
        return toDate(value).toLocaleString('tr-TR');
    }

    function absolute(value) {
        const date = toDate(value);
        return isNaN(date) ? '' : date.toLocaleString('tr-TR', { dateStyle: 'medium', timeStyle: 'short' });
    }

    // Spoiler yorumu diğer kullanıcılara buzlu camla gösterilir; yazarın kendi yorumu açık kalır (etiketiyle).
    function body(comment, viewerId = null) {
        const text = `<div class="cv-text">${escapeHtml(comment.comment_text)}</div>`;
        if (!comment.has_spoiler) {
            return text;
        }
        const isOwn = viewerId !== null && Number(comment.user_id) === Number(viewerId);
        if (isOwn) {
            return `<div class="cv-own-spoiler"><span class="cv-chip">Spoiler</span>${text}</div>`;
        }
        return `<div class="spoiler-wrap">
            <div class="spoiler-content" aria-hidden="true">${text}</div>
            <button type="button" class="spoiler-reveal-btn" data-spoiler-reveal aria-label="Spoiler metnini göster">${EYE_OFF_ICON}<span>Spoileri gör</span></button>
            <button type="button" class="spoiler-hide-btn" data-spoiler-hide aria-label="Spoiler metnini tekrar gizle">${EYE_OFF_ICON}<span>Tekrar gizle</span></button>
        </div>`;
    }

    // Sağ üstteki zamanlar: ilk yazım her zaman, düzenleme yalnızca düzenlenmişse.
    function times(comment) {
        const created = `<span class="cv-time" title="${escapeHtml(absolute(comment.created_at))}">İlk yorum: ${escapeHtml(ago(comment.created_at))}</span>`;
        if (!comment.edited) {
            return `<div class="cv-times">${created}</div>`;
        }
        const edited = `<span class="cv-time cv-edited" title="${escapeHtml(absolute(comment.updated_at))}">Düzenlendi: ${escapeHtml(ago(comment.updated_at))}</span>`;
        return `<div class="cv-times">${created}${edited}</div>`;
    }

    // Yorumun hakkında olduğu film, dizi veya kitap: afiş ve başlık, tıklanınca içerik sayfası açılır.
    function contentLink(comment) {
        if (!comment.content_url) {
            return '';
        }
        const label = CONTENT_LABELS[comment.content_type] || 'İçerik';
        const title = comment.content_title || `${label} #${comment.content_id}`;
        const poster = comment.content_poster || NO_POSTER;
        return `<a class="cv-content" href="${escapeHtml(comment.content_url)}" aria-label="${escapeHtml(title)} sayfasına git">
            <img src="${escapeHtml(poster)}" alt="" width="54" height="81" loading="lazy" onerror="this.onerror=null;this.src='${NO_POSTER}'">
            <span class="cv-content-meta">
                <span class="cv-content-type">${escapeHtml(label)}</span>
                <span class="cv-content-title">${escapeHtml(title)}</span>
            </span>
        </a>`;
    }

    function avatar(comment) {
        // Yüklenmiş fotoğraf (yol/URL) görsel olarak, emoji avatar metin olarak gösterilir.
        const value = typeof comment.avatar_url === 'string' ? comment.avatar_url.trim() : '';
        if (value && /^(?:https?:\/\/|\/(?!\/))/i.test(value)) {
            return `<img class="cv-avatar" src="${escapeHtml(value)}" alt="" width="40" height="40" loading="lazy">`;
        }
        if (value) {
            return `<span class="cv-avatar cv-avatar--initial" aria-hidden="true">${escapeHtml(value)}</span>`;
        }
        const initial = escapeHtml(String(comment.username || '?').trim().charAt(0).toUpperCase());
        return `<span class="cv-avatar cv-avatar--initial" aria-hidden="true">${initial}</span>`;
    }

    // options: viewerId (giriş yapan kullanıcı), actions (düğme HTML'i), below (yanıt formu ve yanıtlar), withContent (içerik kartı sağda).
    function card(comment, options = {}) {
        const { viewerId = null, actions = '', below = '', withContent = false } = options;
        const hasContent = withContent && comment.content_url;
        const name = escapeHtml(comment.username || 'Kullanıcı');
        return `<article class="cv-card${hasContent ? ' cv-card--content' : ''}" id="comment-${comment.id}" data-comment-id="${comment.id}">
            <div class="cv-main">
                <header class="cv-head">
                    <div class="cv-author">${avatar(comment)}<strong class="cv-name">${name}</strong></div>
                    ${times(comment)}
                </header>
                ${body(comment, viewerId)}
                ${actions ? `<div class="cv-actions">${actions}</div>` : ''}
                ${below}
            </div>
            ${hasContent ? contentLink(comment) : ''}
        </article>`;
    }

    // Spoiler düğmeleri: kart yeniden çizildiğinde de çalışsın diye tek bir genel dinleyici.
    // Açınca odak "Tekrar gizle" düğmesine, gizleyince "Spoileri gör" düğmesine geçer; gizliyken metin ekran okuyucudan saklanır.
    document.addEventListener('click', (event) => {
        if (!event.target.closest) return;
        const reveal = event.target.closest('[data-spoiler-reveal]');
        const hide = event.target.closest('[data-spoiler-hide]');
        const wrap = (reveal || hide) && (reveal || hide).closest('.spoiler-wrap');
        if (!wrap) return;

        const content = wrap.querySelector('.spoiler-content');
        if (reveal) {
            wrap.classList.add('spoiler-revealed');
            if (content) content.removeAttribute('aria-hidden');
            const hideButton = wrap.querySelector('[data-spoiler-hide]');
            if (hideButton) hideButton.focus();
        } else {
            wrap.classList.remove('spoiler-revealed');
            if (content) content.setAttribute('aria-hidden', 'true');
            const revealButton = wrap.querySelector('[data-spoiler-reveal]');
            if (revealButton) revealButton.focus();
        }
    });

    global.CommentView = { body, times, card, contentLink, heartIcon, reactionButtons, ago, absolute };
})(window);
