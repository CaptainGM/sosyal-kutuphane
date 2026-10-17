// Ortak okuma / izleme: detay sayfasında karşılıklı takip ettiğin birini davet et; profil sayfasında davetleri yanıtla,
// kendi ilerlemeni işaretle. app (app.js) genel kapsamdan okunur. Misafir ve demo hesaplar bu araçları görmez.
(function () {
    const esc = (value) => app.escapeHtml(String(value ?? ''));

    async function api(method, body, query = '') {
        const response = await fetch('/api/buddy-reads.php' + query, {
            method,
            headers: method === 'GET' ? {} : app.authHeaders(),
            credentials: 'include',
            body: body ? JSON.stringify(body) : undefined,
        });
        return response.json();
    }

    // ---- Detay sayfası: davet kutusu ----
    async function wireInviteBox() {
        const box = document.getElementById('buddyBox');
        if (!box) return;
        await app.initPromise;
        if (!app.currentUser || app.currentUser.is_demo) return;

        const data = await api('GET', null, '?action=friends');
        if (!data.success || !data.friends || data.friends.length === 0) return;

        const select = document.getElementById('buddyFriend');
        select.innerHTML = data.friends.map((friend) => `<option value="${Number(friend.id)}">${esc(friend.username)}</option>`).join('');
        box.hidden = false;

        document.getElementById('buddyInviteBtn').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const params = new URLSearchParams(window.location.search);
            const type = params.get('type');
            const id = params.get('id');
            const title = document.getElementById('contentTitle')?.textContent?.trim() || '';
            const poster = document.getElementById('contentPoster')?.src || '';
            button.disabled = true;
            try {
                const result = await api('POST', {
                    action: 'create',
                    partner_id: Number(select.value),
                    content_type: type,
                    content_id: type === 'book' ? id : Number(id),
                    title,
                    poster: poster.includes('image.tmdb.org/') ? poster : null,
                });
                app.toast(result.message || 'Bir sorun oluştu', result.success ? 'success' : 'error');
            } finally {
                button.disabled = false;
            }
        });
    }

    // ---- Profil sayfası: davetler ve ilerleme ----
    function unitFor(type) {
        return type === 'book' ? 'sayfa' : type === 'series' ? 'bölüm' : '';
    }

    function itemHtml(item) {
        const unit = unitFor(item.content_type);
        const poster = item.poster ? `<img src="${esc(item.poster)}" alt="" loading="lazy" decoding="async">` : '';
        const head = `<div class="buddy-head">${poster}<div><strong>${esc(item.title)}</strong><small>${esc(item.partner.username || 'Arkadaş')} ile</small></div></div>`;

        if (item.status === 'pending') {
            if (item.role === 'partner') {
                return `<article class="buddy-item">${head}<p>Seni ortak okumaya davet etti.</p>
                    <div class="buddy-actions">
                        <button type="button" class="btn btn-primary" data-buddy-respond="${Number(item.id)}" data-accept="1">Kabul et</button>
                        <button type="button" class="btn btn-outline" data-buddy-respond="${Number(item.id)}" data-accept="0">Reddet</button>
                    </div></article>`;
            }
            return `<article class="buddy-item">${head}<p>Davet gönderildi, yanıt bekleniyor.</p>
                <div class="buddy-actions"><button type="button" class="btn btn-outline" data-buddy-leave="${Number(item.id)}">Daveti geri çek</button></div></article>`;
        }

        const progress = unit
            ? `<label class="buddy-progress">${unit === 'sayfa' ? 'Kaçıncı sayfadasın' : 'Kaçıncı bölümdesin'}
                   <input type="number" min="0" max="100000" value="${Number(item.me.progress) || 0}" data-buddy-progress="${Number(item.id)}" aria-label="${unit}"></label>`
            : '';
        const theirParts = [];
        if (unit && item.them.progress) theirParts.push(`${item.them.progress} ${unit}`);
        if (item.them.done) theirParts.push('tamamladı');
        return `<article class="buddy-item">${head}
            <div class="buddy-row">${progress}
                <label class="buddy-done"><input type="checkbox" data-buddy-done="${Number(item.id)}" ${item.me.done ? 'checked' : ''}> Ben tamamladım</label>
            </div>
            <p class="buddy-them">${esc(item.partner.username)}: ${esc(theirParts.join(' · ') || 'henüz işaretlemedi')}</p>
            <div class="buddy-actions"><button type="button" class="btn btn-outline" data-buddy-leave="${Number(item.id)}">Ortak okumadan ayrıl</button></div>
        </article>`;
    }

    async function renderList() {
        const list = document.getElementById('buddyList');
        const card = document.getElementById('buddyCard');
        if (!list || !card) return;
        const data = await api('GET');
        if (!data.success) {
            list.innerHTML = `<p class="cv-empty">${esc(data.message || 'Ortak okumalar yüklenemedi.')}</p>`;
            return;
        }
        list.innerHTML = data.items.length
            ? data.items.map(itemHtml).join('')
            : '<p class="cv-empty">Henüz ortak okuman yok. Bir yapımın sayfasından karşılıklı takip ettiğin birini davet edebilirsin.</p>';
    }

    async function act(body) {
        const result = await api('POST', body);
        app.toast(result.message || 'Bir sorun oluştu', result.success ? 'success' : 'error');
        await renderList();
    }

    function wireProfileCard() {
        const card = document.getElementById('buddyCard');
        if (!card) return;
        card.addEventListener('click', (event) => {
            const respond = event.target.closest('[data-buddy-respond]');
            const leave = event.target.closest('[data-buddy-leave]');
            if (respond) {
                act({ action: 'respond', id: Number(respond.dataset.buddyRespond), accept: respond.dataset.accept === '1' });
            } else if (leave && confirm('Bu ortak okumadan ayrılmak ya da daveti geri çekmek istiyor musun?')) {
                act({ action: 'leave', id: Number(leave.dataset.buddyLeave) });
            }
        });
        card.addEventListener('change', (event) => {
            const progress = event.target.closest('[data-buddy-progress]');
            const done = event.target.closest('[data-buddy-done]');
            if (progress) {
                act({ action: 'progress', id: Number(progress.dataset.buddyProgress), value: Number(progress.value) || 0 });
            } else if (done) {
                act({ action: 'done', id: Number(done.dataset.buddyDone), done: done.checked });
            }
        });
    }

    async function init() {
        await app.initPromise;
        if (!app.currentUser || app.currentUser.is_demo) {
            const card = document.getElementById('buddyCard');
            if (card) card.hidden = true;
            return;
        }
        wireInviteBox();
        if (document.getElementById('buddyCard')) {
            wireProfileCard();
            await renderList();
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
