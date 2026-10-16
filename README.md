# Sosyal Kütüphane

Film, dizi ve kitaplar için yapılmış bir sosyal kütüphane ve paylaşım platformu. Kullanıcılar içerikleri arayıp izlediklerine/okuduklarına puan verebiliyor, yorum yazabiliyor, fragmanları izleyebiliyor, nerede yayınlandığını görüp Instagram hikaye kartları oluşturabiliyor. PHP ve MongoDB ile geliştirildi.

![CI](https://github.com/CaptainGM/sosyal-kutuphane/actions/workflows/ci.yml/badge.svg)

![Giriş ekranı](screenshot.webp)

Karanlık mod ve arama:
![Karanlık mod](screenshot-dark.png)

Mesajlaşma:
![Mesajlaşma](screenshot-messages.png)

## ✨ Öne Çıkan Özellikler

- 🔍 **Film, Dizi ve Kitap Keşfi**: TMDb ve Google Books entegrasyonu, tür ve yıl filtreleme
- 🧭 **Gelişmiş TMDb Keşif Motoru (Discover API)**: Yıl ve tür filtrelerinde tüm TMDb arşivi taranır (2025/2026 dahil on binlerce yapım ve sunucu taraflı sayfalama)
- 💡 **Akıllı Çapraz Arama**: Film sekmesindeyken dizi arandığında (ör. Güç Yüzükleri) otomatik dizi arşivi taranıp kullanıcıya doğrudan sonuç kartı ve kategori geçiş butonu sunulur
- 🍿 **Nerede İzlenir? (Türkiye)**: TMDb Watch Providers ile Netflix, Prime Video, BluTV, Apple TV yayın durumu (Abonelik / Kiralık / Satın Al)
- 🛡️ **Spoiler Koruması (Buzlu Cam)**: Sürprizbozan içeren yorumlar blurlu cam arkasında gizlenir, sadece tıklayanlar okuyabilir (göz simgeli şık kontrol)
- ⚡ **Canlı Arama Önerileri (Autocomplete)**: Arama kutusuna yazarken anında afişli ve puanlı canlı sonuç listesi (çapraz kategori destekli)
- 📈 **Gelişmiş Puan ve Yıl Filtreleri**: 2026 dahil dinamik yıl filtreleme ve TMDb güvenilir oy tabanlı (vote_count) en iyiler listelemesi
- ⌨️ **Caps Lock ve Durum Uyarıları**: Giriş ekranında Caps Lock algılama, zümrüt yeşili başarı ve kırmızı hata bildirimleri
- 📸 **Instagram Hikaye Kartı (Story Generator)**: HTML5 Canvas ile film/kitap afişi, puanı ve kullanıcı adıyla otomatik dikey (9:16) hikaye kartı üretip tek tıkla indirme veya paylaşma
- ⚡ **Tek Tıkla Demo Girişi**: Giriş sayfasında şifre yazmadan tek tıkla admin veya demo hesapla giriş yapabilme
- 👁️ **Sıfır Kaymalı Parola Göster/Gizle**: Modern SVG ikonlarla formu kaydırmayan akıcı şifre toggle kontrolü
- 🎨 **Modern Cam Tasarımı (Glassmorphism)**: `motionsites.ai` ve `opensourceui` esintili derin dark tema, ortam ışığı (ambient mesh), şık butonlar ve tam açık tema uyumu
- ⭐ **Kütüphane Yönetimi**: İzlediklerim, İzleyeceklerim, Okuduklarım, Okuyacaklarım ve 1-10 arası puanlama
- 💬 **Yorum ve Sosyal Etkileşim**: İçeriklere yorum yapma, yorum beğenme, yanıtlama
- 👥 **Takip ve Sosyal Akış**: Kullanıcıları takip etme, kişisel feed akışı
- 🔔 **Canlı Bildirimler**: Takip, yorum ve mesaj bildirimleri (navbar'da dinamik rozet)
- 💬 **Doğrudan Mesajlaşma (DM)**: Kullanıcılar arasında özel mesajlaşma
- 📁 **Özel Listeler**: İstediğin başlıkta koleksiyon listesi oluşturup paylaşma
- ⚙️ **Hesap Yönetimi**: Profil fotoğrafı (avatar) yükleme, şifre ve 2FA (Authenticator) ayarları
- 🌙 **Karanlık / Açık Tema**: Göz yormayan koyu mod desteği (formlar ve textarea dahil tam uyumlu)

## Kullanılan Teknolojiler

PHP 8+, MongoDB (`mongodb/mongodb` paketi), HTML/CSS/JS, PHPMailer (şifre sıfırlama ve doğrulama kodları için). MongoDB yerelde ya da Atlas'ta çalışabilir, `.env` içindeki `MONGO_URI` değerini ayarlamak yeterli.

Film/dizi verisi TMDB'den, kitap verisi Google Books'tan geliyor. Tarayıcı bu servislere doğrudan gitmiyor, istekler `api/tmdb-proxy.php` ve `api/books-proxy.php` üzerinden geçip `api_cache` koleksiyonunda saklanıyor (popüler listeler 6 saat, arama 1 saat, detay 24 saat). Böylece API anahtarları tarayıcıda gizli kalıyor ve aynı veri tekrar tekrar indirilmiyor.

## Çalıştırma

### Docker ile:

```bash
docker compose up
```

`http://localhost:8000/index.html` adresinde açılır, veritabanı ve demo hesaplar otomatik oluşturulur.

### Manuel Kurulum:

1. MongoDB'nin çalıştığından emin olun (varsayılan port 27017) ve `composer install` çalıştırın.
2. `.env.example` dosyasını `.env` olarak kopyalayın (`copy .env.example .env`). Yerel MongoDB için varsayılanlar yeterlidir.
3. `.env` içine `TMDB_API_KEY` yazın ([themoviedb.org](https://www.themoviedb.org/settings/api) üzerinden ücretsiz alınıyor), film ve dizi araması ile fragman/nerede izlenir özellikleri bu anahtarla çalışır. `GOOGLE_BOOKS_API_KEY` opsiyoneldir.
4. Şifre sıfırlama e-postası için `SMTP_USERNAME` ve `SMTP_PASSWORD` doldurulabilir. Boş bırakılırsa sıfırlama linki sunucu loguna yazılır.
5. Tarayıcıda `http://localhost:8000/api/setup-db.php` adresini bir kez açın, indeksleri ve demo hesapları oluşturur.
6. `START.bat` (Windows) ya da `START.sh` (Mac/Linux) ile sunucuyu başlatıp `http://localhost:8000/index.html` adresine girin.

### Demo Hesaplar:
Giriş sayfasındaki hazır demo butonlarına tıklayabilir veya manuel girebilirsiniz:
- **admin@test.com** (Yönetici)
- **ahmet@test.com** (Kullanıcı)
- **ayse@test.com** (Kullanıcı)
- **test@test.com** (Kullanıcı)

*(Tüm demo hesapların şifresi: `123456`)*

## Render'da Yayınlama

Repodaki `Dockerfile` Render'da doğrudan çalışmaktadır:

1. [render.com](https://render.com)'da GitHub ile girip **New > Web Service** ile bu repoyu seçin, Runtime olarak **Docker** seçilir.
2. Environment sekmesine `.env` içindeki değerleri ekleyin: `MONGO_URI`, `MONGO_DB`, `TMDB_API_KEY`, `GOOGLE_BOOKS_API_KEY` (isteğe bağlı).
3. `SITE_URL` olarak Render'ın verdiği `https://<servis-adi>.onrender.com` adresini girin ve yeniden deploy edin (CORS bu değere bakar).
4. İlk açılışta `api/setup-db.php` indeksleri ve demo hesapları kendiliğinden oluşturur.

## Testler

Testler izole test veritabanına karşı koşar:

```bash
composer install
MONGO_DB=social_library_test php -S localhost:8001 &
TEST_BASE_URL=http://localhost:8001 vendor/bin/phpunit --testdox
node --test tests/escape-html.test.mjs
```

PowerShell'de `MONGO_DB` ve `TEST_BASE_URL` değişkenleri `$env:MONGO_DB='social_library_test'` biçiminde ayrı satırlarda verilir. Her push'ta GitHub Actions aynı testleri kendi MongoDB konteynerine karşı çalıştırır (`.github/workflows/ci.yml`).

## Klasör Yapısı

```
index.html     Giriş ve kayıt sayfası (motionsites stili)
search.html    Arama ve keşfet sayfası
profile.html   Profil ve hesap ayarları
messages.html  Doğrudan mesajlaşma
detail.php     İçerik detayı, fragman oynatıcı ve hikaye kartı
api/           PHP backend API uçları
js/            JavaScript yardımcıları ve istemci mantığı
css/           Stil dosyaları (tasarım token'ları, cam efektleri, karanlık mod)
tests/         PHPUnit ve Node testleri
START.bat      Geliştirme sunucusu başlatıcı (Windows)
START.sh       Geliştirme sunucusu başlatıcı (Mac/Linux)
```

## Sorun Giderme

- **"Sunucuya bağlanılamıyor"**: `START.bat` veya dev sunucusunun açık olduğunu kontrol edin.
- **"Veritabanı bağlantı hatası"**: MongoDB servisinin açık olduğunu ve `.env` içindeki `MONGO_URI`'nin doğru olduğunu kontrol edin. Atlas kullanıyorsanız Network Access listesine mevcut IP adresinizi (veya geliştirme için `0.0.0.0/0`) ekleyin.
- **"Giriş yapılamıyor"**: Tarayıcıyı yenileyin (F5) veya demo butonlarını kullanın.
