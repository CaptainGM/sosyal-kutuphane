// Kullanıcıdan gelen metni innerHTML'e basmadan önce kaçış yapmak için kullanılır.
// Hem tarayıcıda <script> ile hem Node'da require/import ile çalışacak şekilde yazıldı
// (bkz. tests/escape-html.test.mjs) — mantık tek bir yerde, iki ortamda da test edilebilir.
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { escapeHtml };
}
