// Navbar'ı tek kaynaktan (partials/navbar.html) tüm sayfalara yazar ve her sayfada ortak stil bağlantılarını doğrular.
// Statik HTML sayfaları aynı navbar'ı paylaşır; elle kopyalamak yerine bu betik çalıştırılır:
//   node scripts/sync-nav.mjs
// Yeni bir sayfa eklerken PAGES tablosuna adını ve hangi menü öğesinin etkin görüneceğini yaz.
// shared-list.html bilerek listede yok: herkese açık sayfa olduğu için giriş yapmamış ziyaretçiye uygun sade bir üst çubuğu var.
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

// sayfa -> etkin menü anahtarı (partials/navbar.html içindeki data-nav değerleri)
const PAGES = {
    'search.html': 'search',
    'detail.php': 'search',
    'upcoming.html': 'search',
    'tonight.html': 'tonight',
    'feed.html': 'feed',
    'social.html': 'social',
    'user-profile.html': 'social',
    'profile.html': 'profile',
    'wrapped.html': 'profile',
    'messages.html': 'messages',
    'admin.html': 'admin',
    'gizlilik.html': 'gizlilik',
};

// Altbilgi (gizlilik, kurallar, destek bağlantıları) menüsü olmayan sayfalara da yazılır. admin.html'de altbilgi yoktur.
const FOOTER_ONLY = ['index.html', 'reset-password.html', 'shared-list.html'];

const partial = readFileSync(join(root, 'partials/navbar.html'), 'utf8').replace(/\r\n/g, '\n').trimEnd();

const footer = readFileSync(join(root, 'partials/footer.html'), 'utf8').replace(/\r\n/g, '\n').trimEnd();

// Altbilgiyi ilk </main>'in hemen arkasına koyar; zaten varsa içeriğini günceller.
function withFooter(html, page) {
    const existing = /<footer class="site-footer"[\s\S]*?<\/footer>/;
    if (existing.test(html)) {
        return html.replace(existing, () => footer);
    }
    if (!html.includes('</main>')) {
        throw new Error(`${page}: </main> bulunamadı, altbilgi eklenemedi`);
    }
    return html.replace('</main>', () => `</main>\n${footer}`);
}

function navFor(active) {
    return partial.replace(
        new RegExp(`class="nav-link" data-nav="${active}"`),
        `class="nav-link active" data-nav="${active}" aria-current="page"`
    );
}

let updated = 0;
for (const [page, active] of Object.entries(PAGES)) {
    const path = join(root, page);
    const raw = readFileSync(path, 'utf8');
    const eol = raw.includes('\r\n') ? '\r\n' : '\n';
    let html = raw.replace(/\r\n/g, '\n');

    // Önceki çalıştırmadan kalan "içeriğe atla" bağlantısı ve navbar tek parça olarak değiştirilir.
    const block = /(?:<a class="skip-link"[^\n]*<\/a>\n)?<nav class="navbar"[\s\S]*?<\/nav>/;
    if (block.test(html)) {
        html = html.replace(block, () => navFor(active));
    } else if (/<body[^>]*>/.test(html)) {
        html = html.replace(/(<body[^>]*>)\n?/, (match) => `${match.trimEnd()}\n${navFor(active)}\n`);
    } else {
        throw new Error(`${page}: <body> bulunamadı`);
    }

    // Ortak stil katmanı: style.css'ten sonra ui.css yüklenir.
    if (!html.includes('css/ui.css')) {
        html = html.replace(
            '<link rel="stylesheet" href="css/style.css">',
            '<link rel="stylesheet" href="css/style.css">\n    <link rel="stylesheet" href="css/ui.css">'
        );
    }

    if (page !== 'admin.html') {
        html = withFooter(html, page);
    }

    writeFileSync(path, html.replace(/\n/g, eol));
    updated++;
}

for (const page of FOOTER_ONLY) {
    const path = join(root, page);
    const raw = readFileSync(path, 'utf8');
    const eol = raw.includes('\r\n') ? '\r\n' : '\n';
    writeFileSync(path, withFooter(raw.replace(/\r\n/g, '\n'), page).replace(/\n/g, eol));
    updated++;
}

console.log(`${updated} sayfa eşitlendi`);
