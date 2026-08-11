# 📚 Sosyal Kütüphane

![CI](https://github.com/CaptainGM/sosyal-kutuphane/actions/workflows/ci.yml/badge.svg)

Film, Dizi ve Kitap Sosyal Ağı Uygulaması

![Giriş ekranı](screenshot.png)

Karanlık mod + tür filtreleme:
![Karanlık mod](screenshot-dark.png)

Doğrudan mesajlaşma:
![Mesajlaşma](screenshot-messages.png)

## Mimari

```mermaid
flowchart LR
    B[Tarayıcı] --> API[PHP API]
    API --> DB[(MongoDB)]
    API --> MAIL["PHPMailer / SMTP"]
```

## 🐳 Hızlı Başlangıç (Docker)

```bash
docker compose up
```

`http://localhost:8000/index.html` adresinde açılır, veritabanı ve demo hesaplar otomatik oluşturulur (aşağıdaki demo hesaplarla giriş yapabilirsiniz).

## 🚀 Manuel Kurulum

### Adım 0: Bağımlılıklar, veritabanı ve ortam değişkenleri

MongoDB'nin yerelde çalıştığından emin ol (varsayılan port 27017), sonra PHP bağımlılıklarını kur:

```bash
composer install
```

`.env.example` dosyasını `.env` olarak kopyala ve gerekirse değerleri düzenle (varsayılanlar yerel MongoDB için zaten çalışır):

```bash
copy .env.example .env
```

`.env`'de `TMDB_API_KEY`'i doldurun ([themoviedb.org](https://www.themoviedb.org/settings/api)'dan ücretsiz alınır) — film/dizi arama ve keşif bu olmadan çalışmaz. `GOOGLE_BOOKS_API_KEY` opsiyoneldir (boş bırakılırsa Google'ın düşük anonim kotası kullanılır, kitap aramasında zaman zaman 429 hatası görülebilir).

E-posta ile şifre sıfırlama özelliğini kullanacaksanız `.env`'de `SMTP_USERNAME`/`SMTP_PASSWORD` alanlarını doldurun (opsiyonel — boş bırakılırsa sıfırlama linki e-posta yerine sunucu loguna yazılır).

`api/setup-db.php` dosyasını bir kez tarayıcıda açarak MongoDB indekslerini ve demo hesapları oluşturun.

### Adım 1: Server Başlat
`START.bat` dosyasına çift tıkla (Windows) veya `START.sh` (Mac/Linux)

### Adım 2: Tarayıcıda Aç
```
http://localhost:8000/index.html
```

### Adım 3: Giriş Yap
Örnek hesaplar (setup-db.php ile oluşturulur):
- **admin** / admin@test.com
- **test** / test@test.com
- **ahmet** / ahmet@test.com
- **ayse** / ayse@test.com

(Şifre: 123456)

Veya yeni hesap oluştur.

## ✨ Özellikler
- 🔍 Film, Dizi ve Kitap Arama, tür/kategori filtreleme
- ⭐ İzlediklerim (Film/Dizi) / Okuduklarım, puanlama
- 💬 Yorum Yapma (yanıt, beğeni)
- 👥 Takip Sistemi
- 📱 Sosyal Feed
- 🔔 Gerçek zamanlıya yakın bildirimler (takip, mesaj — otomatik yenilenen zil)
- 💬 Kullanıcılar arası doğrudan mesajlaşma
- 📁 Özel listeler, popüler içerikler
- ⚙️ Hesap ayarları: şifre değiştirme, e-posta değiştirme (çift adımlı e-posta doğrulamalı), dosya ile avatar yükleme
- 🌙 Karanlık mod
- 🔐 Hash'lenmiş şifreler, CSRF koruması, giriş denemesi sınırlama

## 📂 Dosya Yapısı
```
sosyal-kutuphane/
├── START.bat              # Server Başlatıcı (Windows)
├── START.sh               # Server Başlatıcı (Mac/Linux)
├── index.html             # Giriş Sayfası
├── search.html             # Arama & Keşfet
├── profile.html            # Profil, hesap ayarları
├── detail.php              # İçerik Detayı (film/dizi/kitap, Open Graph önizlemesi)
├── messages.html            # Mesajlaşma
├── api/                    # Backend API'leri (PHP)
├── js/                     # JavaScript Dosyaları
├── css/                    # Stil Dosyaları (tasarım token'ları, karanlık mod)
└── tests/                  # PHPUnit + Node testleri
```

## Teknoloji

PHP + MongoDB (`mongodb/mongodb` composer paketi) + vanilla HTML/CSS/JS. PHPMailer (şifre sıfırlama ve e-posta doğrulama kodları için). MongoDB yerelde ya da [Atlas](https://www.mongodb.com/atlas) gibi bulut bir cluster'da çalışabilir — `.env`'deki `MONGO_URI`'yi değiştirmen yeterli.

**TMDB/Google Books önbelleği:** Tarayıcı bu dış API'lere hiç doğrudan gitmiyor — tüm istekler `api/tmdb-proxy.php` ve `api/books-proxy.php` üzerinden geçip Atlas'ta (`api_cache` koleksiyonu) önbelleklenir (popüler/en yüksek puanlı listeler 6 saat, arama 1 saat, detay 24 saat). Böylece aynı veri tüm ziyaretçiler arasında paylaşılır, yavaş internetli kullanıcılar her sayfa açılışında aynı içeriği baştan indirmek zorunda kalmaz, ve dış API anahtarları istemci tarafında görünmez.

## 🧪 Testler

**Dikkat:** Testler gerçek HTTP istekleriyle çalışan bir sunucuya karşı koşar. Eğer o sunucu `.env`'deki üretim `MONGO_URI`/`MONGO_DB`'yi (ör. Atlas) kullanıyorsa, testler **canlı veritabanını sahte kullanıcı/film/dizi kayıtlarıyla kirletir** (bir kez böyle oldu, temizlendi — bkz. `tests/bootstrap.php`'deki not). Bu yüzden testler için ayrı bir port + ayrı bir veritabanı adıyla ikinci bir sunucu başlatılmalı:

```bash
composer install    # phpunit/phpunit dahil (dev bağımlılık)
MONGO_DB=social_library_test php -S localhost:8001 &
TEST_BASE_URL=http://localhost:8001 vendor/bin/phpunit --testdox
node --test tests/escape-html.test.mjs   # XSS-kaçış birim testleri
```

(Windows PowerShell'de: `$env:MONGO_DB='social_library_test'; $env:TEST_BASE_URL='http://localhost:8001'` şeklinde ayrı satırlarda ayarlanır.) `social_library_test` veritabanı aynı cluster içinde otomatik oluşur, üretim verisiyle hiç karışmaz.

`master`/`main`'e her push'ta ve her PR'da GitHub Actions aynı testleri kendi MongoDB servis konteynerine karşı otomatik çalıştırır (bkz. `.github/workflows/ci.yml`) — orada zaten izole bir konteyner olduğu için bu risk yok.

## ⚠️ Sorun Giderme

**"Sunucuya bağlanılamıyor"**
→ START.bat'i çalıştırdığından emin ol

**"Veritabanı bağlantı hatası"**
→ MongoDB servisinin çalıştığından ve `.env`'deki `MONGO_URI`'nin doğru olduğundan emin ol. Atlas kullanıyorsan Atlas konsolunda **Network Access**'e mevcut IP'nin (veya geliştirme için `0.0.0.0/0`) eklendiğini kontrol et — eklenmemişse bağlantı TLS aşamasında sessizce başarısız olur.

**"Giriş yapılamıyor"**
→ Tarayıcıyı yenile (F5)

## 🎯 İlk Adım
1. Ortam değişkenlerini ayarla, `api/setup-db.php`'yi bir kez çalıştır
2. START.bat çalıştır
3. http://localhost:8000/index.html aç
4. Hesap oluştur veya demo hesapla giriş yap
5. Film/Dizi/Kitap ara ve keşfet!
