// Hareket tercihi: <html data-motion="full|reduced"> ilk boyamadan önce yazılır, CSS ve betikler yalnızca bunu okur.
// Sistem "hareketi azalt" derse (Windows'ta Ayarlar > Erişilebilirlik > Görsel efektler > Animasyon efektleri kapalıyken
// bütün tarayıcılar bunu bildirir) varsayılan azaltılmış moddur. Kullanıcı sitedeki düğmeyle tek tıkla animasyonları açabilir
// veya kapatabilir; seçim kaydedilir ve sayfa yenilenir, böylece CSS, Three.js ve eğilme efektleri tek kararla başlar.
(function () {
    var STORAGE_KEY = 'sk-motion';
    var root = document.documentElement;

    var stored = null;
    try {
        stored = window.localStorage.getItem(STORAGE_KEY);
    } catch (error) {
        stored = null;
    }

    var systemReduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var chosen = stored === 'full' || stored === 'reduced' ? stored : null;
    var mode = chosen || (systemReduced ? 'reduced' : 'full');

    root.setAttribute('data-motion', mode);
    root.setAttribute('data-motion-source', chosen ? 'user' : 'system');

    window.SKMotion = {
        mode: mode,
        systemReduced: systemReduced,
        userChoice: chosen,
        isReduced: function () {
            return mode === 'reduced';
        },
        set: function (next) {
            try {
                window.localStorage.setItem(STORAGE_KEY, next);
            } catch (error) {
                // Depolama kapalıysa seçim kalıcı olmaz; sayfa yine de yenilenir.
            }
            window.location.reload();
        },
        toggle: function () {
            this.set(mode === 'reduced' ? 'full' : 'reduced');
        },
    };
})();
