# Sosyal Kütüphane

Film, dizi ve kitapları arayıp izlediklerini ve okuduklarını takip edebildiğin, puan verip yorum yazabildiğin bir sosyal kütüphane. PHP + MongoDB, arayüz düz HTML/CSS/JS.

**Canlı demo:** https://sosyal-kutuphane-qr9i.onrender.com

![CI](https://github.com/CaptainGM/sosyal-kutuphane/actions/workflows/ci.yml/badge.svg)

![Giriş ekranı](docs/screenshots/giris-koyu.png)

## Özellikler

- Film, dizi ve kitap arama (TMDB ve Google Books), yazım hatasına toleranslı
- İzlediklerim, izleyeceklerim, okuduklarım ve 1-10 puanlama
- Yorum, yanıt, beğeni; spoiler gizleme; dizilerde bölüm bazlı sohbet
- "Ne izlesem?" akşam seçici (süre, ruh hali, dönem ve dile göre öneri)
- Yayın platformuna göre filtre (Netflix, Prime Video, Disney+ ve diğerleri), "Ne izlesem?" dahil
- Letterboxd, IMDb ve Goodreads dışa aktarımlarını içe aktarma
- Yorumlara tepki (güldüm, ağladım, şaşırdım, düşündürdü)
- Takip edilen dizilerin yeni bölüm takvimi ve tarayıcı bildirimleri
- Karşılıklı takip eden arkadaşlarla ortak okuma / izleme
- Takip, akış, iki kullanıcının zevk eşleşmesi, yıllık özet
- Özel mesajlaşma, paylaşılabilir listeler, Instagram hikaye kartı
- Yönetim konsolu (`admin.html`): kullanıcılar, yorumlar, şikâyetler, işlem günlüğü
- Açık/koyu tema, azaltılmış hareket desteği

## Demo hesaplar

Giriş ekranındaki hazır butonlarla ya da `ahmet@test.com`, `ayse@test.com`, `test@test.com` ile girebilirsin. Şifre: `123456`

- Kütüphane, listeler ve izleme durumu kullanılabilir. Her girişte kütüphane sıfırlanır.
- Yorum, yanıt, beğeni, puan, şikâyet, takip, engelleme ve mesaj kapalıdır. Başkalarını etkileyen hiçbir işlem demo hesaptan yapılamaz.

## Yerelde çalıştırma

1. MongoDB'yi çalıştır (yerelde ya da Atlas'ta) ve `composer install` yap.
2. `.env.example` dosyasını `.env` olarak kopyala ve en azından `MONGO_URI` ile `TMDB_API_KEY` değerlerini doldur.
3. `php api/setup-db.php` komutunu bir kez çalıştır. İndeksleri ve demo hesapları oluşturur.
4. `START.bat` (Windows) veya `START.sh` ile sunucuyu başlat, `http://localhost:8000/index.html` adresini aç.

Docker ile: `docker compose up`

### Ortam değişkenleri

| Değişken | Zorunlu | Açıklama |
|---|---|---|
| `MONGO_URI`, `MONGO_DB` | Evet | MongoDB bağlantısı |
| `TMDB_API_KEY` | Evet | Film ve dizi verisi (ücretsiz, themoviedb.org) |
| `GOOGLE_BOOKS_API_KEY` | Hayır | Kitap verisi için; yoksa anonim kota kullanılır |
| `SMTP_*` | Hayır | Şifre sıfırlama e-postası; boşsa bağlantı sunucu loguna yazılır |
| `LLM_MODERATION_PROVIDER`, `LLM_MODERATION_API_KEY` | Hayır | Yorumlar için yapay zekâ ile ek küfür/hakaret taraması (`gemini` veya `deepseek`) |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | Hayır | Tarayıcı bildirimleri. `php scripts/make-vapid.php` ile üretilir; boşsa bildirimler kapalıdır |

Anahtarlar yalnızca `.env` veya ortam değişkenlerinde tutulur, depoya girmez. Bildirim anahtarı üretmek için `php scripts/make-vapid.php` çalıştırıp çıktıyı Render'a ekleyin.

## Yönetici yetkisi

```bash
php scripts/make-admin.php kullanici@ornek.com
```

Yönetici yetkisi yalnızca komut satırından verilir. Özel mesajların içeriği yönetim konsoluna bilerek dahil edilmemiştir.

## Canlıya alma (Render + MongoDB Atlas)

Render'da yeni bir Blueprint oluşturup bu depoyu bağla; `render.yaml` servisi kurar. Gizli değerleri Render panelinde gir: `MONGO_URI`, `TMDB_API_KEY`, `SITE_URL` (servisin adresi, `https://` ile, sonunda `/` olmadan). Atlas'ta Network Access listesine `0.0.0.0/0` eklenmelidir.

Ücretsiz planda servis bir süre hareketsiz kalınca uyur; ilk açılış birkaç saniye sürebilir. Yüklenen profil fotoğrafları kalıcı diskte değildir.

## Testler

```bash
# PHPUnit: çalışan bir sunucu ve izole bir test veritabanı gerektirir
MONGO_DB=social_library_test php -S 127.0.0.1:8001 -t . &
MONGO_DB=social_library_test php api/setup-db.php
TEST_BASE_URL=http://127.0.0.1:8001 TEST_MONGO_DB=social_library_test vendor/bin/phpunit

# Tarayıcı testleri (Playwright)
npm ci
npx playwright install chromium
npx playwright test
```

Testler `tests/` (PHPUnit, Node) ve `tests/e2e/` (Playwright, axe ile erişilebilirlik) altındadır. Her push'ta GitHub Actions aynı testleri çalıştırır.

## Klasör yapısı

```
index.html, search.html, tonight.html, detail.php, feed.html, social.html,
profile.html, user-profile.html, messages.html, wrapped.html, upcoming.html,
shared-list.html, admin.html   Sayfalar
api/            PHP API uçları (api/lib: öneri, moderasyon, demo, DM, denetim...)
js/, css/       İstemci kodu ve stiller
partials/       Gezinme çubuğunun tek kaynağı (scripts/sync-nav.mjs sayfalara yazar)
tests/          PHPUnit, Node ve Playwright testleri
scripts/        Yönetici atama, navbar eşitleme, test kullanıcısı yardımcıları
```

## Sorun giderme

- **"Veritabanı bağlantı hatası"**: `MONGO_URI` doğru mu, Atlas kümesi çalışıyor mu, Network Access'te IP izinli mi kontrol et.
- **Sunucuya bağlanılamıyor**: sunucunun açık olduğundan emin ol.
