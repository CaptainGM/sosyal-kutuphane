<?php
// Sadece <head>'deki OG/Twitter meta etiketlerini sunucu tarafında dolduruyor,
// geri kalan sayfa JS ile client-side çalışmaya devam ediyor. api/config.php
// bilerek kullanılmıyor (CORS/JSON header'ları HTML sayfayı bozar).
$ogTitle = 'Sosyal Kütüphane';
$ogDescription = 'Film ve kitap sosyal ağı — izlediklerini, okuduklarını paylaş, keşfet.';
$ogImage = null;
$pageTitle = 'İçerik Detay - Sosyal Kütüphane';

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

$type = $_GET['type'] ?? null;
$id = $_GET['id'] ?? null;

if ($type && $id && file_exists(__DIR__ . '/vendor/autoload.php')) {
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        $mongoClient = new MongoDB\Client(getenv('MONGO_URI') ?: 'mongodb://localhost:27017');
        $ogDb = $mongoClient->selectDatabase(getenv('MONGO_DB') ?: 'social_library');

        $cached = null;
        if ($type === 'movie') {
            $cached = $ogDb->movies->findOne(['tmdb_id' => (int) $id]);
            if ($cached && ($cached->poster_path ?? null)) {
                $ogImage = 'https://image.tmdb.org/t/p/w500' . $cached->poster_path;
            }
        } elseif ($type === 'book') {
            $cached = $ogDb->books->findOne(['google_books_id' => (string) $id]);
            if ($cached && ($cached->cover_url ?? null)) {
                $ogImage = $cached->cover_url;
            }
        } elseif ($type === 'series') {
            $cached = $ogDb->series->findOne(['tmdb_id' => (int) $id]);
            if ($cached && ($cached->poster_path ?? null)) {
                $ogImage = 'https://image.tmdb.org/t/p/w500' . $cached->poster_path;
            }
        }

        if ($cached && ($cached->title ?? null)) {
            $ogTitle = $cached->title;
            $pageTitle = $cached->title . ' - Sosyal Kütüphane';
            $overview = $cached->overview ?? $cached->description ?? null;
            if ($overview) {
                $ogDescription = mb_strlen($overview) > 200 ? mb_substr($overview, 0, 200) . '...' : $overview;
            }
        }
    } catch (Throwable $e) {
        // Mongo'ya ulaşılamıyorsa sayfa yine de jenerik önizlemeyle çalışmaya devam eder.
    }
}
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#05060b">
    <link rel="icon" type="image/svg+xml" href="img/logo.svg">
    <link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192.png">
    <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
    <title><?= htmlspecialchars($pageTitle) ?></title>

    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
    <?php if ($ogImage): ?>
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <?php else: ?>
    <meta name="twitter:card" content="summary">
    <?php endif; ?>

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/ui.css">
    <script src="js/motion.js"></script>
    <style>
        /* Detay sayfasına özel stiller. Ortak bileşenler css/style.css ve css/ui.css içinde. */

        /* --- Sinematik hero: backdrop görseli, üstte koyu gradyan, altta sayfa rengine erir --- */
        .detail-hero {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            margin-top: -78px;
            padding: 150px 0 140px;
            color: #ffffff;
        }

        .detail-hero::before {
            content: '';
            position: absolute;
            inset: -8px;
            z-index: -2;
            background: var(--hero-bg, var(--color-accent-gradient)) center 22% / cover no-repeat;
            filter: saturate(1.1) brightness(0.62);
            transform: scale(1.04);
            -webkit-mask-image: linear-gradient(180deg, #000 calc(100% - 120px), transparent 100%);
            mask-image: linear-gradient(180deg, #000 calc(100% - 120px), transparent 100%);
        }

        .detail-hero[data-backdrop="cover"]::before {
            filter: blur(28px) saturate(1.3) brightness(0.55);
            transform: scale(1.3);
        }

        .detail-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: -1;
            background:
                radial-gradient(900px 420px at 18% 0%, rgba(var(--color-accent-rgb), 0.38), transparent 70%),
                linear-gradient(180deg, rgba(5, 6, 11, 0.4) 0%, rgba(5, 6, 11, 0.74) calc(100% - 120px), transparent 100%);
        }

        .detail-hero-inner {
            padding-bottom: 0;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr);
            gap: 44px;
            align-items: end;
        }

        .poster-container {
            position: relative;
            display: grid;
            gap: 14px;
        }

        .poster {
            display: block;
            width: 100%;
            aspect-ratio: 2 / 3;
            border-radius: 22px;
            object-fit: cover;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 44px 90px -34px rgba(0, 0, 0, 0.9), 0 0 0 1px rgba(255, 255, 255, 0.16);
        }

        .score-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .platform-rating {
            padding: 12px 10px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.12);
            text-align: center;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .rating-score {
            font-size: 26px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.02em;
        }

        .rating-count {
            margin-top: 6px;
            font-size: 11.5px;
            font-weight: 700;
            opacity: 0.85;
        }

        .content-info {
            display: grid;
            gap: 18px;
            align-content: end;
            min-width: 0;
        }

        .content-title {
            font-size: clamp(32px, 5.2vw, 62px);
            font-weight: 800;
            line-height: 1.02;
            letter-spacing: -0.04em;
            color: #ffffff;
            text-wrap: balance;
        }

        .content-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .content-meta .chip {
            border-color: rgba(255, 255, 255, 0.24);
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
        }

        .content-description {
            max-width: 72ch;
            font-size: 16.5px;
            line-height: 1.75;
            color: rgba(255, 255, 255, 0.92);
        }

        .detail-follow {
            display: none;
            justify-self: start;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: var(--radius-pill);
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            font: inherit;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            backdrop-filter: blur(10px);
        }

        .detail-follow:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .detail-follow:focus-visible {
            outline: none;
            box-shadow: var(--focus-ring);
        }

        /* Aksiyon satırı hero içinde: cam düğmeler, gerekirse sarar. */
        .detail-hero .user-actions {
            grid-auto-flow: row;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            margin-bottom: 0;
            padding: 0;
            overflow: visible;
        }

        .detail-hero .action-btn {
            min-height: 48px;
            border-color: rgba(255, 255, 255, 0.24);
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: none;
        }

        .detail-hero .action-btn:hover {
            border-color: rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }

        .detail-hero .action-btn.active,
        .detail-hero .action-btn.active:hover {
            border-color: transparent;
            background: var(--color-accent-gradient);
        }

        .detail-hero .action-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* --- Ana alan --- */
        .detail-main {
            position: relative;
            z-index: 1;
            margin-top: -72px;
            padding-top: 0;
        }

        .discuss-panel {
            padding: 24px;
        }

        .comment-form {
            display: grid;
            gap: 12px;
            margin-bottom: 26px;
        }

        .comment-form textarea {
            width: 100%;
            min-height: 112px;
            padding: 16px 18px;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            background: color-mix(in srgb, var(--color-surface-2) 80%, transparent);
            color: var(--color-text);
            font: inherit;
            font-size: 15px;
            line-height: 1.6;
            resize: vertical;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .comment-form textarea:focus {
            outline: none;
            border-color: rgba(var(--color-accent-rgb), 0.7);
            box-shadow: var(--focus-ring);
        }

        .comment-form-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .comment-form button,
        .sc-send-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 22px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--radius-pill);
            background: var(--color-accent-gradient);
            color: #ffffff;
            font: inherit;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 14px 30px -14px rgba(var(--color-accent-rgb), 0.95);
            transition: transform 0.2s ease;
        }

        .comment-form button:hover,
        .sc-send-btn:hover {
            transform: translateY(-2px);
        }

        .comment-form button:focus-visible,
        .sc-send-btn:focus-visible {
            outline: none;
            box-shadow: var(--focus-ring);
        }

        /* --- Puan verme --- */
        .rating-input {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 14px;
            margin: 14px 0 18px;
        }

        .star-rating {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .star {
            font-size: 26px;
            line-height: 1;
            color: var(--color-border);
            cursor: pointer;
            transition: color 0.2s ease, transform 0.15s ease;
        }

        .star:hover,
        .star.active {
            color: #fbbf24;
        }

        .star:hover {
            transform: scale(1.15);
        }

        .side-title {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 15px;
            font-weight: 800;
        }

        .side-title svg {
            width: 17px;
            height: 17px;
            color: var(--color-accent);
        }

        /* Nerede izlenir paneli (rozet stilleri css/style.css'te) */
        .providers-section {
            margin: 0;
        }

        .providers-section h4 {
            margin-bottom: 12px;
        }

        /* --- Sezon sohbeti --- */
        .sc-intro {
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .sc-intro svg {
            flex: none;
            width: 20px;
            height: 20px;
            margin-top: 2px;
            color: var(--color-accent);
        }

        .sc-seasons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        .sc-seasons button.chip small {
            opacity: 0.8;
            font-weight: 800;
        }

        .sc-summary {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px 20px;
            margin-bottom: 14px;
        }

        .sc-summary .meter {
            flex: 1;
            min-width: 160px;
        }

        .sc-list {
            display: grid;
            gap: 10px;
        }

        .sc-episode {
            display: grid;
            grid-template-columns: 128px minmax(0, 1fr) auto;
            gap: 14px;
            align-items: center;
            padding: 10px;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            background: color-mix(in srgb, var(--color-surface-2) 55%, transparent);
            transition: border-color 0.2s ease;
        }

        .sc-episode.is-open {
            border-color: rgba(var(--color-accent-rgb), 0.6);
            background: rgba(var(--color-accent-rgb), 0.08);
        }

        .sc-still {
            width: 128px;
            aspect-ratio: 16 / 9;
            border-radius: 10px;
            object-fit: cover;
            background: var(--color-surface-2);
        }

        .sc-still--empty {
            display: grid;
            place-items: center;
            color: var(--color-text-faint);
            font-weight: 800;
        }

        .sc-ep-main {
            display: grid;
            gap: 6px;
            min-width: 0;
        }

        .sc-ep-title {
            overflow: hidden;
            font-weight: 800;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sc-ep-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            color: var(--color-text-faint);
            font-size: 12.5px;
        }

        .sc-ep-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .sc-thread {
            display: grid;
            gap: 16px;
            margin-top: 16px;
            padding: 20px;
            border: 1px solid rgba(var(--color-accent-rgb), 0.4);
            border-radius: var(--radius-lg);
            background: color-mix(in srgb, var(--color-surface) 70%, transparent);
        }

        .sc-thread-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .sc-thread-title {
            font-size: 17px;
            font-weight: 800;
        }

        .sc-lock {
            display: grid;
            justify-items: center;
            gap: 12px;
            padding: 28px 16px;
            text-align: center;
        }

        .sc-lock svg {
            width: 34px;
            height: 34px;
            color: var(--color-accent);
        }

        /* --- Modal (özel liste) --- */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1000;
            justify-content: center;
            align-items: center;
            background: var(--color-overlay);
            backdrop-filter: blur(6px);
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            width: 90%;
            max-width: 460px;
            max-height: 80vh;
            overflow-y: auto;
            padding: 28px;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-lg);
            background: var(--color-surface);
            box-shadow: var(--shadow-hover);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 20px;
            font-weight: 800;
        }

        .modal-close {
            border: none;
            background: none;
            color: var(--color-text-faint);
            font-size: 28px;
            line-height: 1;
            cursor: pointer;
        }

        .modal-close:hover {
            color: var(--color-text);
        }

        .custom-list-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            margin-bottom: 10px;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            cursor: pointer;
            transition: border-color 0.2s ease, background-color 0.2s ease;
        }

        .custom-list-item:hover {
            border-color: rgba(var(--color-accent-rgb), 0.6);
            background: rgba(var(--color-accent-rgb), 0.08);
        }

        .custom-list-item.selected {
            border-color: transparent;
            background: var(--color-accent-gradient);
            color: #ffffff;
        }

        .custom-list-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 12px;
        }

        .custom-list-name {
            flex: 1;
            font-weight: 700;
        }

        .custom-list-count {
            color: var(--color-text-faint);
            font-size: 12px;
        }

        .custom-list-item.selected .custom-list-count {
            color: rgba(255, 255, 255, 0.85);
        }

        .no-lists-message {
            padding: 30px;
            color: var(--color-text-faint);
            text-align: center;
        }

        .create-list-link {
            color: var(--color-accent);
            text-decoration: underline;
            cursor: pointer;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--glass-border);
        }

        .modal-btn {
            padding: 10px 24px;
            border-radius: var(--radius-pill);
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }

        .modal-btn-cancel {
            border: 1px solid var(--glass-border);
            background: var(--color-surface-2);
            color: var(--color-text-muted);
        }

        .modal-btn-save {
            border: none;
            background: var(--color-accent-gradient);
            color: #ffffff;
        }

        @media (max-width: 860px) {
            .detail-grid {
                grid-template-columns: 1fr;
                gap: 26px;
                align-items: start;
            }

            /* Telefonda afiş ilk ekranı kaplamasın: küçük ve ortada, başlık ve düğmeler hemen altında görünür. */
            .poster-container {
                max-width: 200px;
                margin: 0 auto;
            }

            .poster {
                border-radius: 16px;
            }

            .platform-rating {
                padding: 8px 6px;
            }

            .detail-hero {
                padding-top: 104px;
            }

            .sc-episode {
                grid-template-columns: 96px minmax(0, 1fr);
            }

            .sc-still {
                width: 96px;
            }

            .sc-ep-actions {
                grid-column: 1 / -1;
                justify-content: flex-start;
            }
        }
    </style>
</head>

<body>

    <a class="skip-link" href="#main">İçeriğe geç</a>
<nav class="navbar" aria-label="Ana gezinme">
    <div class="navbar-inner">
        <a href="search.html" class="logo">
            <span class="logo-mark"><img src="img/logo.svg" alt="" width="34" height="34"></span>
            <span>Sosyal Kütüphane</span>
        </a>
        <button class="nav-icon-btn nav-toggle-btn" aria-label="Menüyü aç/kapat" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg></button>
        <div class="nav-links">
            <a href="search.html" class="nav-link active" data-nav="search" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>Keşfet</a>
            <a href="tonight.html" class="nav-link" data-nav="tonight"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="12" height="12" x="2" y="10" rx="2" ry="2"/><path d="m17.92 14 3.5-3.5a2.24 2.24 0 0 0 0-3l-5-4.92a2.24 2.24 0 0 0-3 0L10 6"/><path d="M6 18h.01"/><path d="M10 14h.01"/><path d="M15 6h.01"/><path d="M18 9h.01"/></svg>Ne izlesem?</a>
            <a href="feed.html" class="nav-link" data-nav="feed"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 8h10"/><path d="M7 12h10"/><path d="M7 16h6"/></svg>Akış</a>
            <a href="social.html" class="nav-link" data-nav="social"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Sosyal</a>
            <a href="profile.html" class="nav-link" data-nav="profile"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Profilim</a>
            <a href="messages.html" class="nav-link" data-nav="messages"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Mesajlar</a>
            <a href="admin.html" class="nav-link" data-nav="admin" id="navAdminLink" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>Yönetim</a>
            <div class="nav-icon-wrap">
                <button class="nav-icon-btn" id="notifBellBtn" aria-label="Bildirimler"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg><span class="nav-badge" id="notifBadge"></span></button>
                <div class="nav-panel" id="notifPanel">
                    <div class="nav-panel-header">Bildirimler</div>
                    <div class="nav-panel-empty">Yükleniyor...</div>
                </div>
            </div>
            <button class="nav-icon-btn theme-toggle-btn" aria-label="Tema değiştir"><svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg><svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg></button>
            <button class="logout-btn" onclick="logout()">Çıkış Yap</button>
        </div>
    </div>
    <span id="userWelcome" style="display:none;"></span>
</nav>


    <header class="detail-hero" id="detailHero">
        <div class="page detail-hero-inner">
            <div id="loadingDetail" class="loading">İçerik yükleniyor...</div>
            <div id="detailContent" style="display: none;">
                <div class="detail-grid">
                    <div class="poster-container" data-tilt>
                        <img id="contentPoster" class="poster" src="img/no-poster.svg" alt="">
                        <div class="score-row">
                            <div class="platform-rating" id="platformRating">
                                <div class="rating-score" id="ratingScore">-/-</div>
                                <div class="rating-count" id="ratingCount">API Puanı</div>
                            </div>
                            <div class="platform-rating" id="userPlatformRating">
                                <div class="rating-score" id="userRatingScore">-/-</div>
                                <div class="rating-count" id="userRatingCount">Kullanıcı Puanı</div>
                            </div>
                        </div>
                    </div>
                    <div class="content-info">
                        <h1 id="contentTitle" class="content-title"></h1>
                        <div id="contentMeta" class="content-meta"></div>
                        <div id="contentDescription" class="content-description"></div>
                        <button id="followCreatorBtn" type="button" class="detail-follow">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/></svg>
                            <span>Yazarı Takip Et</span>
                        </button>

                        <div class="user-actions">
                            <button type="button" id="rateBtn" class="action-btn" onclick="showRatingSection()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                <span class="btn-label">Puan Ver</span>
                            </button>
                            <button type="button" id="watchBtn" class="action-btn" onclick="toggleList('watched')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <span class="btn-label" id="watchBtnText">İzledim</span>
                            </button>
                            <button type="button" id="watchlistBtn" class="action-btn" onclick="toggleList('watchlist')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
                                <span class="btn-label" id="watchlistBtnText">İzlenecekler</span>
                            </button>
                            <button type="button" id="readBtn" class="action-btn" onclick="toggleList('read')" style="display: none;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                <span class="btn-label" id="readBtnText">Okudum</span>
                            </button>
                            <button type="button" id="readinglistBtn" class="action-btn" onclick="toggleList('readinglist')" style="display: none;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/></svg>
                                <span class="btn-label" id="readinglistBtnText">Okunacaklar</span>
                            </button>
                            <button type="button" id="customListBtn" class="action-btn" onclick="showCustomListModal()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/><line x1="12" x2="12" y1="11" y2="17"/><line x1="9" x2="15" y1="14" y2="14"/></svg>
                                <span class="btn-label">Özel Listeye Ekle</span>
                            </button>
                            <button type="button" id="watchTrailerBtn" class="action-btn" onclick="handleWatchTrailerClick()" style="display: none;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
                                <span class="btn-label">Fragmanı İzle</span>
                            </button>
                            <button type="button" id="storyCardBtn" class="action-btn" onclick="openStoryModal()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="4" ry="4"/><circle cx="12" cy="12" r="3"/><line x1="16.5" x2="16.5" y1="7.5" y2="7.5"/></svg>
                                <span class="btn-label">Hikaye Kartı</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main id="main" class="page detail-main">
        <section class="buddy-box" id="buddyBox" aria-labelledby="buddyBoxTitle" hidden>
            <div class="buddy-box-inner">
                <strong id="buddyBoxTitle">Arkadaşınla birlikte ilerle</strong>
                <div class="buddy-row">
                    <select id="buddyFriend" aria-label="Arkadaş seç"></select>
                    <button type="button" class="btn btn-primary" id="buddyInviteBtn">Ortak okumaya davet et</button>
                </div>
                <p class="buddy-hint">Yalnızca karşılıklı takip ettiğin biri davet edilebilir. İlerlemeniz ancak davet kabul edilince görünür.</p>
            </div>
        </section>

        <div class="split">
            <div class="stack">
                <section class="panel discuss-panel" id="discussPanel" aria-label="Tartışma">
                    <div class="panel-head">
                        <div class="seg" role="group" aria-label="Tartışma türü">
                            <button type="button" class="seg-btn active" data-pane-btn="comments" aria-pressed="true" onclick="selectDiscussPane('comments')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                Yorumlar
                            </button>
                            <button type="button" class="seg-btn" id="seasonTabBtn" data-pane-btn="season" aria-pressed="false" onclick="selectDiscussPane('season')" hidden>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="15" x="2" y="7" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/></svg>
                                Sezon sohbeti
                            </button>
                        </div>
                    </div>

                    <div id="commentsPane">
                        <div class="comment-form">
                            <textarea id="commentText" aria-label="Yorumun" placeholder="Bu içerik hakkında düşüncelerinizi paylaşın..."></textarea>
                            <div class="comment-form-row">
                                <label class="spoiler-checkbox-label" style="margin: 0;">
                                    <input type="checkbox" id="commentSpoiler">
                                    <span>Bu yorum spoiler (sürprizbozan) içeriyor</span>
                                </label>
                                <button type="button" onclick="submitComment()">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" x2="11" y1="2" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                    <span>Yorum Gönder</span>
                                </button>
                            </div>
                        </div>

                        <div id="comments-section">
                            <p class="cv-empty">Henüz yorum yapılmamış...</p>
                        </div>
                    </div>

                    <div id="seasonPane" hidden>
                        <div class="notice sc-intro">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>
                            <div><strong>Spoiler yok, sohbet var.</strong> Bir bölümü izlediğini işaretle, o bölümün sohbeti açılır. İzlemediğin bölümlerin yorumları sana hiç gösterilmez; her hafta yayınlanan bölümün sohbeti en üstte.</div>
                        </div>
                        <div class="sc-seasons" id="seasonTabs" role="group" aria-label="Sezonlar"></div>
                        <div id="seasonSummary"></div>
                        <div class="sc-list" id="episodeList"></div>
                        <div id="episodeThread" aria-live="polite"></div>
                    </div>
                </section>
            </div>

            <aside class="stack sticky-side" aria-label="Yan paneller">
                <section id="providersSection" class="panel providers-section" style="display: none;">
                    <h4 class="side-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="15" x="2" y="7" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/></svg>
                        Nerede İzlenir? (Türkiye)
                    </h4>
                    <div id="providersList" class="providers-grid"></div>
                </section>

                <section id="ratingSection" class="panel" style="display: none;">
                    <h4 class="side-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        Puanınız
                    </h4>
                    <div class="rating-input">
                        <div class="star-rating" id="starRating">
                            <span class="star" data-rating="1">☆</span>
                            <span class="star" data-rating="2">☆</span>
                            <span class="star" data-rating="3">☆</span>
                            <span class="star" data-rating="4">☆</span>
                            <span class="star" data-rating="5">☆</span>
                            <span class="star" data-rating="6">☆</span>
                            <span class="star" data-rating="7">☆</span>
                            <span class="star" data-rating="8">☆</span>
                            <span class="star" data-rating="9">☆</span>
                            <span class="star" data-rating="10">☆</span>
                        </div>
                        <span id="currentRating" class="chip chip--accent">0/10</span>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="submitRating()">Puanı Gönder</button>
                </section>

                <section id="userRatingSection" class="panel" style="display: none;">
                    <h4 class="side-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        Sizin Puanınız
                    </h4>
                    <div id="userRatingDisplay"></div>
                    <button type="button" class="btn btn-primary" onclick="showRatingSection()" style="margin-top: 10px;">Puanı Değiştir</button>
                </section>
            </aside>
        </div>

        <section class="reco-section" id="similarSection" style="display:none;">
            <div class="section-head">
                <h2 class="section-title">
                    <span class="section-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polygon points="12 8 8 12 12 16 12 8"/></svg></span>
                    Benzer İçerikler
                </h2>
            </div>
            <div class="reco-scroll" id="similarScroll"></div>
        </section>
    </main>

    <div id="customListModal" class="modal-overlay" onclick="closeCustomListModal(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3>📁 Özel Listeye Ekle</h3>
                <button class="modal-close" onclick="closeCustomListModal()">&times;</button>
            </div>
            <div id="customListsContainer" class="custom-lists-container">
                <div class="loading">Listeler yükleniyor...</div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-cancel" onclick="closeCustomListModal()">İptal</button>
                <button class="modal-btn modal-btn-save" onclick="saveToCustomLists()">Kaydet</button>
            </div>
        </div>
    </div>

    <!-- Fragman Modalı -->
    <div id="trailerModal" class="trailer-modal-overlay" onclick="closeTrailerModal(event)">
        <div class="trailer-modal-box" onclick="event.stopPropagation()">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 18px; background: rgba(15, 23, 42, 0.95); border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                <span style="font-size: 14px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
                    Resmi Fragman
                </span>
                <button type="button" class="trailer-bar-close-btn" onclick="closeTrailerModal()" aria-label="Fragmanı Kapat" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.35); color: #fca5a5; padding: 6px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
                    <span>Kapat</span>
                </button>
            </div>
            <div class="trailer-iframe-wrap">
                <iframe id="trailerIframe" src="" allowfullscreen allow="autoplay; encrypted-media"></iframe>
            </div>
        </div>
    </div>

    <!-- Hikaye Kartı (Instagram Story) Modalı -->
    <div id="storyModal" class="story-modal-overlay" onclick="closeStoryModal(event)">
        <div class="story-modal-box" onclick="event.stopPropagation()">
            <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                <h3 style="margin: 0; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="4" ry="4"/><circle cx="12" cy="12" r="3"/><line x1="16.5" x2="16.5" y1="7.5" y2="7.5"/></svg>
                    Hikaye Kartı
                </h3>
                <button class="modal-close" onclick="closeStoryModal()" style="font-size: 24px; cursor: pointer; background: none; border: none; color: var(--color-text);">&times;</button>
            </div>
            <p style="font-size: 13px; color: var(--color-text-muted); margin: 0; text-align: center;">Instagram veya sosyal medyada paylaşabileceğin özel kart</p>
            <div class="story-canvas-container">
                <canvas id="storyCanvas" width="720" height="1280"></canvas>
            </div>
            <div class="story-modal-actions">
                <button class="btn btn-primary" onclick="downloadStoryCard()" style="display: inline-flex; align-items: center; gap: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    <span>Görseli İndir</span>
                </button>
                <button class="btn btn-secondary" onclick="shareStoryCard()" style="display: inline-flex; align-items: center; gap: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/></svg>
                    <span>Paylaş</span>
                </button>
            </div>
        </div>
    </div>

    <script src="js/escape-html.js"></script>
    <script src="js/app.js"></script>
    <script src="js/comment-view.js"></script>
    <script src="js/buddy.js"></script>
    <script>
        let currentContent = null;
        let currentRating = 0;
        let currentContentType = '';
        let currentContentId = '';
        let currentTrailerKey = null;
        const commentTextById = {};

        function metaChips(items) {
            return items.filter(Boolean).map(item => `<span class="chip">${escapeHtml(item)}</span>`).join('');
        }

        // Hero arka planı: TMDB backdrop'u; kitaplarda kapak bulanıklaştırılarak kullanılır.
        function setHeroBackdrop(backdropPath, coverUrl) {
            const hero = document.getElementById('detailHero');
            if (!hero) return;
            if (backdropPath) {
                hero.style.setProperty('--hero-bg', `url("https://image.tmdb.org/t/p/w1280${backdropPath}")`);
            } else if (coverUrl && coverUrl !== 'img/no-poster.svg') {
                hero.style.setProperty('--hero-bg', `url("${coverUrl.replace(/"/g, '%22')}")`);
                hero.dataset.backdrop = 'cover';
            }
        }

        /* --- Fragman Oynatıcı Yönetimi --- */
        function setupTrailerButton(content) {
            const btn = document.getElementById('watchTrailerBtn');
            if (!btn) return;
            currentTrailerKey = null;

            const videos = content.videos?.results || [];
            let trailer = videos.find(v => v.site === 'YouTube' && v.type === 'Trailer');
            if (!trailer) {
                trailer = videos.find(v => v.site === 'YouTube' && (v.type === 'Teaser' || v.type === 'Clip'));
            }
            if (!trailer && videos.length > 0 && videos[0].site === 'YouTube') {
                trailer = videos[0];
            }

            if (trailer && trailer.key) {
                currentTrailerKey = trailer.key;
                btn.style.display = 'inline-flex';
            } else {
                btn.style.display = 'none';
            }
        }

        function handleWatchTrailerClick() {
            if (!currentTrailerKey) {
                if (typeof app !== 'undefined') app.toast('Bu içerik için fragman bulunamadı.', 'info');
                return;
            }
            const modal = document.getElementById('trailerModal');
            const iframe = document.getElementById('trailerIframe');
            iframe.src = `https://www.youtube.com/embed/${currentTrailerKey}?autoplay=1&rel=0`;
            modal.classList.add('active');
        }

        function closeTrailerModal(e) {
            if (e && e.target && e.target.classList.contains('trailer-modal-box')) return;
            const modal = document.getElementById('trailerModal');
            const iframe = document.getElementById('trailerIframe');
            if (modal) modal.classList.remove('active');
            if (iframe) iframe.src = '';
        }

        // ESC tuşu ile modalları kapatma
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeTrailerModal();
                closeStoryModal();
            }
        });

        /* --- Nerede İzlenir? (Watch Providers) --- */
        // Platformun kendi arama sayfası (başlık bazlı). Eşleşmeyen platformda TMDB'nin bölge sayfası açılır.
        const PROVIDER_SEARCH_URLS = [
            { match: /netflix/i, url: (t) => `https://www.netflix.com/search?q=${t}` },
            { match: /prime video|amazon/i, url: (t) => `https://www.primevideo.com/search?phrase=${t}` },
            { match: /apple tv|itunes/i, url: (t) => `https://tv.apple.com/search?term=${t}` },
            { match: /youtube/i, url: (t) => `https://www.youtube.com/results?search_query=${t}` },
        ];

        function providerLink(providerName, title, fallback) {
            const rule = PROVIDER_SEARCH_URLS.find(r => r.match.test(providerName || ''));
            return rule && title ? rule.url(encodeURIComponent(title)) : (fallback || '#');
        }

        function displayWatchProviders(content) {
            const section = document.getElementById('providersSection');
            const list = document.getElementById('providersList');
            if (!section || !list) return;

            const providersData = content['watch/providers']?.results || {};
            const trData = providersData['TR'] || providersData['US'] || null;
            const isFallbackUS = !providersData['TR'] && !!providersData['US'];

            list.innerHTML = '';
            if (!trData) {
                section.style.display = 'none';
                return;
            }

            const flatrate = trData.flatrate || [];
            const rent = trData.rent || [];
            const buy = trData.buy || [];

            const all = [];
            const seen = new Set();

            flatrate.forEach(p => {
                if (!seen.has(p.provider_id)) {
                    seen.add(p.provider_id);
                    all.push({ ...p, typeLabel: 'Abonelik' });
                }
            });
            rent.forEach(p => {
                if (!seen.has(p.provider_id)) {
                    seen.add(p.provider_id);
                    all.push({ ...p, typeLabel: 'Kiralık' });
                }
            });
            buy.forEach(p => {
                if (!seen.has(p.provider_id)) {
                    seen.add(p.provider_id);
                    all.push({ ...p, typeLabel: 'Satın Al' });
                }
            });

            if (all.length === 0) {
                list.innerHTML = '<span class="no-providers-msg">Şu anda dijital platformlarda yayında bulunmuyor.</span>';
                section.style.display = 'block';
                return;
            }

            const heading = section.querySelector('h4');
            if (heading) {
                heading.innerHTML = `📺 Nerede İzlenir? ${isFallbackUS ? '<span style="font-size:11px;font-weight:normal;opacity:0.7;">(Global)</span>' : '(Türkiye)'}`;
            }

            const title = content.title || content.name || '';
            const regionLink = trData.link || '';
            list.innerHTML = all.slice(0, 8).map(p => {
                const logoUrl = p.logo_path ? `https://image.tmdb.org/t/p/w92${p.logo_path}` : '';
                const href = providerLink(p.provider_name, title, regionLink);
                return `
                    <a class="provider-badge" href="${app.escapeHtml(href)}" target="_blank" rel="noopener noreferrer" title="${app.escapeHtml(p.provider_name)} üzerinde ara">
                        ${logoUrl ? `<img src="${logoUrl}" class="provider-logo" alt="${app.escapeHtml(p.provider_name)}">` : ''}
                        <span>${app.escapeHtml(p.provider_name)}</span>
                        <span class="provider-type-tag">${p.typeLabel}</span>
                    </a>
                `;
            }).join('');

            section.style.display = 'block';
        }

        /* --- Görsel Hikaye Kartı (Instagram Story) --- */
        function openStoryModal() {
            const modal = document.getElementById('storyModal');
            modal.classList.add('active');
            renderStoryCardCanvas();
        }

        // Afişi tuvale CORS izniyle yükler. Sayfadaki <img> CORS'suz yüklendiği için onu çizmek tuvali "kirletir"
        // ve toDataURL/toBlob sessizce hata verirdi (indir/paylaş çalışmazdı). Yüklenemezse null döner, kart afişsiz çizilir.
        function loadStoryPoster() {
            const source = document.getElementById('contentPoster')?.src;
            if (!source) return Promise.resolve(null);
            return new Promise((resolve) => {
                const image = new Image();
                image.crossOrigin = 'anonymous';
                image.onload = () => resolve(image);
                image.onerror = () => resolve(null);
                image.src = source;
            });
        }

        function closeStoryModal(e) {
            if (e && e.target && e.target.classList.contains('story-modal-box')) return;
            const modal = document.getElementById('storyModal');
            modal.classList.remove('active');
        }

        async function renderStoryCardCanvas() {
            const canvas = document.getElementById('storyCanvas');
            if (!canvas) return;
            const posterImg = await loadStoryPoster();
            const ctx = canvas.getContext('2d');
            const W = canvas.width;  // 720
            const H = canvas.height; // 1280

            // Arka plan gradient
            const bgGrad = ctx.createLinearGradient(0, 0, W, H);
            bgGrad.addColorStop(0, '#0f172a');
            bgGrad.addColorStop(0.5, '#1e1b4b');
            bgGrad.addColorStop(1, '#090d16');
            ctx.fillStyle = bgGrad;
            ctx.fillRect(0, 0, W, H);

            // Üst Başlık & Rozet
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 26px Plus Jakarta Sans, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('📚 SOSYAL KÜTÜPHANE', W / 2, 85);

            ctx.fillStyle = 'rgba(255, 255, 255, 0.65)';
            ctx.font = '16px Plus Jakarta Sans, sans-serif';
            const typeLabel = currentContentType === 'movie' ? '🎬 Film İncelemesi' : (currentContentType === 'series' ? '📺 Dizi İncelemesi' : '📖 Kitap İncelemesi');
            ctx.fillText(typeLabel, W / 2, 118);

            // Poster çizimi
            const posterW = 320;
            const posterH = 480;
            const posterX = (W - posterW) / 2;
            const posterY = 160;

            // Poster gölgesi
            ctx.save();
            ctx.shadowColor = 'rgba(99, 102, 241, 0.55)';
            ctx.shadowBlur = 35;
            ctx.shadowOffsetY = 12;
            ctx.fillStyle = '#1e293b';
            ctx.beginPath();
            ctx.roundRect(posterX, posterY, posterW, posterH, 18);
            ctx.fill();
            ctx.restore();

            if (posterImg && posterImg.naturalWidth > 0) {
                ctx.save();
                ctx.beginPath();
                ctx.roundRect(posterX, posterY, posterW, posterH, 18);
                ctx.clip();
                ctx.drawImage(posterImg, posterX, posterY, posterW, posterH);
                ctx.restore();
            }

            // İçerik Başlığı
            const title = document.getElementById('contentTitle')?.textContent || 'Sosyal Kütüphane';
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 32px Plus Jakarta Sans, sans-serif';
            ctx.textAlign = 'center';

            const words = title.split(' ');
            let line1 = '';
            let line2 = '';
            words.forEach(w => {
                if ((line1 + ' ' + w).length < 24) {
                    line1 += (line1 ? ' ' : '') + w;
                } else {
                    line2 += (line2 ? ' ' : '') + w;
                }
            });
            let titleY = posterY + posterH + 65;
            ctx.fillText(line1, W / 2, titleY);
            if (line2) {
                titleY += 40;
                ctx.fillText(line2.substring(0, 26) + (line2.length > 26 ? '...' : ''), W / 2, titleY);
            }

            // Puan Kutusu
            const ratingBoxY = titleY + 45;
            const userRatingEl = document.getElementById('userRatingDisplay');
            const userRatingText = (userRatingEl && userRatingEl.style.display !== 'none') ? userRatingEl.textContent.trim() : '';
            const rawPlatformRating = document.getElementById('ratingScore')?.textContent?.trim() || '';
            const platformRatingText = /\d/.test(rawPlatformRating) ? rawPlatformRating : '';

            ctx.fillStyle = 'rgba(255, 255, 255, 0.08)';
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.18)';
            ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.roundRect(W / 2 - 180, ratingBoxY, 360, 105, 22);
            ctx.fill();
            ctx.stroke();

            const username = (typeof app !== 'undefined' && app.currentUser) ? app.currentUser.username : 'Kitapkurdu';
            ctx.fillStyle = '#fbbf24';
            ctx.font = 'bold 30px Plus Jakarta Sans, sans-serif';
            const ratingLine = userRatingText ? `⭐ ${userRatingText}` : (platformRatingText ? `⭐ TMDb ${platformRatingText}` : (isUnreleasedContent ? '⏳ Çok yakında' : '⭐ Sosyal Kütüphane'));
            ctx.fillText(ratingLine, W / 2, ratingBoxY + 48);

            ctx.fillStyle = '#cbd5e1';
            ctx.font = '600 16px Plus Jakarta Sans, sans-serif';
            ctx.fillText(userRatingText ? `@${username} tarafından puanlandı` : `@${username} ile keşfedildi`, W / 2, ratingBoxY + 84);

            // Alt Bilgi
            ctx.fillStyle = 'rgba(255, 255, 255, 0.45)';
            ctx.font = '15px Plus Jakarta Sans, sans-serif';
            ctx.fillText('sosyalkutuphane.com • Birlikte Keşfet', W / 2, H - 55);
        }

        function storyFileName() {
            const title = (document.getElementById('contentTitle')?.textContent || 'icerik')
                .toLocaleLowerCase('tr')
                .replace(/ç/g, 'c').replace(/ğ/g, 'g').replace(/ı/g, 'i').replace(/ö/g, 'o').replace(/ş/g, 's').replace(/ü/g, 'u')
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'icerik';
            return `sosyal-kutuphane-${title}.png`;
        }

        // Tuvali PNG dosyasına çevirir; tuval kirlenmişse (afiş CORS'suz geldiyse) null döner.
        function storyBlob() {
            const canvas = document.getElementById('storyCanvas');
            return new Promise((resolve) => {
                try {
                    canvas.toBlob((blob) => resolve(blob), 'image/png');
                } catch (error) {
                    console.error('Hikaye kartı dışa aktarılamadı:', error);
                    resolve(null);
                }
            });
        }

        function saveBlob(blob, fileName) {
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.download = fileName;
            link.href = url;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 2000);
        }

        async function downloadStoryCard() {
            const blob = await storyBlob();
            if (!blob) {
                app.toast('Kart görseli oluşturulamadı. Sayfayı yenileyip tekrar dene.', 'error');
                return;
            }
            saveBlob(blob, storyFileName());
            app.toast('Hikaye kartı indirildi!', 'success');
        }

        async function shareStoryCard() {
            const blob = await storyBlob();
            if (!blob) {
                app.toast('Kart görseli oluşturulamadı. Sayfayı yenileyip tekrar dene.', 'error');
                return;
            }
            const title = document.getElementById('contentTitle')?.textContent || 'Sosyal Kütüphane';
            const file = new File([blob], storyFileName(), { type: 'image/png' });
            try {
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    await navigator.share({ files: [file], title, text: `${title} - Sosyal Kütüphane` });
                    return;
                }
                // Dosya paylaşımı desteklenmiyorsa (masaüstü tarayıcılar) sayfa bağlantısını paylaşmayı dene.
                if (navigator.share) {
                    await navigator.share({ title, text: `${title} - Sosyal Kütüphane`, url: window.location.href });
                    return;
                }
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(window.location.href);
                    app.toast('Bağlantı panoya kopyalandı. Görseli indirip birlikte paylaşabilirsin.', 'success');
                    return;
                }
            } catch (error) {
                if (error && error.name === 'AbortError') return; // kullanıcı paylaşım penceresini kapattı
                console.error('Paylaşım hatası:', error);
            }
            saveBlob(blob, storyFileName());
            app.toast('Paylaşım bu cihazda desteklenmiyor; görsel indirildi.', 'info');
        }

        document.addEventListener('DOMContentLoaded', async function () {
            // İçerik oturum bilgisini beklemeden yüklenir (oturum isteği yavaş olabilir); kullanıcıya özel
            // parçalar (izledim, puan, yorum düzenleme) kendi işlevlerinde oturum bilgisini bekler.
            loadContentDetail();
            setupStarRating();
            if (typeof app !== 'undefined' && app.initPromise) {
                try {
                    await app.initPromise;
                } catch (e) {
                    console.error('App init error:', e);
                }
            }
            if (app?.currentUser?.is_demo) applyDemoRestrictions();
        });

        // Demo hesap: yorum ve puan yapılamaz. Form yerine açıklama gösterilir (sunucu da reddeder).
        function applyDemoRestrictions() {
            const form = document.querySelector('#commentsPane .comment-form');
            if (form) {
                form.hidden = true;
                form.insertAdjacentHTML('beforebegin', '<div class="notice" role="note" style="margin-bottom: 22px;"><span>Demo hesapta yorum yazılamaz. Kendi hesabını oluşturarak yorum yapabilirsin.</span></div>');
            }
            const rateBtn = document.getElementById('rateBtn');
            if (rateBtn) {
                rateBtn.disabled = true;
                rateBtn.title = 'Demo hesapta puan verilemez';
            }
        }

        function getUrlParams() {
            const urlParams = new URLSearchParams(window.location.search);
            return {
                type: urlParams.get('type'),
                id: urlParams.get('id')
            };
        }

        function goToDetail(type, id) {
            window.location.href = `detail.php?type=${type}&id=${id}`;
        }

        // Film/dizi: TMDB'nin similar uç noktası. Kitap: Google Books'ta eşdeğeri
        // yok, o yüzden kitabın ilk kategorisiyle arama yapıp kendisini çıkarıyoruz.
        async function loadSimilarContent(type, id, content) {
            const section = document.getElementById('similarSection');
            const scroll = document.getElementById('similarScroll');
            if (!section || !scroll) return;

            try {
                let items = [];

                if (type === 'movie' || type === 'series') {
                    const response = await fetch(`${TMDB_PROXY_URL}?endpoint=similar&type=${type}&id=${id}`);
                    const data = await response.json();
                    items = (data.results || []).slice(0, 12).map(item => ({
                        id: item.id,
                        title: item.title || item.name || 'Bilinmiyor',
                        poster: item.poster_path ? `https://image.tmdb.org/t/p/w200${item.poster_path}` : 'img/no-poster.svg',
                        type
                    }));
                } else {
                    const category = content?.volumeInfo?.categories?.[0];
                    if (category) {
                        const query = `subject:"${category}"`;
                        const response = await fetch(`${BOOKS_PROXY_URL}?action=search&q=${encodeURIComponent(query)}&maxResults=13`);
                        const data = await response.json();
                        items = (data.items || [])
                            .filter(b => b.id !== id)
                            .slice(0, 12)
                            .map(b => ({
                                id: b.id,
                                title: b.volumeInfo?.title || 'Bilinmiyor',
                                poster: b.volumeInfo?.imageLinks?.thumbnail
                                    ? b.volumeInfo.imageLinks.thumbnail.replace('http://', 'https://')
                                    : 'img/no-poster.svg',
                                type: 'book'
                            }));
                    }
                }

                if (items.length === 0) return;

                scroll.innerHTML = items.map(item => `
                    <div class="reco-card" onclick="goToDetail('${item.type}', '${item.id}')">
                        <img src="${item.poster}" alt="${app.escapeHtml(item.title)}" loading="lazy" onerror="this.src='img/no-poster.svg'">
                        <div class="reco-card-title">${app.escapeHtml(item.title)}</div>
                    </div>
                `).join('');
                section.style.display = 'block';
            } catch (error) {
                console.error('Benzer içerik yükleme hatası:', error);
            }
        }

        async function loadContentDetail() {
            const { type, id } = getUrlParams();

            if (!type || !id) {
                showError('Geçersiz içerik!');
                return;
            }

            currentContentType = type;
            currentContentId = id;

            try {
                if (typeof app === 'undefined') {
                    showError('Uygulama başlatılamadı!');
                    return;
                }

                let content;
                if (type === 'movie') {
                    content = await app.getMovieDetail(id);
                } else if (type === 'series') {
                    content = await app.getSeriesDetail(id);
                } else {
                    content = await app.getBookDetail(id);
                }

                if (content) {
                    currentContent = content;
                    displayContentDetail(content, type);
                    loadUserInteractions();
                    loadComments();
                    loadPlatformUserRating();
                    loadSimilarContent(type, id, content);
                } else {
                    console.error('Content is null for:', type, id);
                    showError('İçerik bulunamadı!');
                }
            } catch (error) {
                console.error('İçerik yükleme hatası:', error);
                showError('İçerik yüklenirken hata oluştu!');
            }
        }


        function displayContentDetail(content, type) {
            const loadingDiv = document.getElementById('loadingDetail');
            const contentDiv = document.getElementById('detailContent');

            loadingDiv.style.display = 'none';
            contentDiv.style.display = 'block';

            if (type === 'movie') {
                displayMovieDetail(content);
            } else if (type === 'series') {
                displaySeriesDetail(content);
            } else {
                displayBookDetail(content);
            }

            setupActionButtons(type);
            applyUnreleasedState(type);

            // Sezon sohbeti yalnızca dizilerde vardır; ?tab=season bağlantısı doğrudan o sekmeyi açar.
            const seasonTab = document.getElementById('seasonTabBtn');
            if (seasonTab && type === 'series') {
                seasonTab.hidden = false;
                if (new URLSearchParams(window.location.search).get('tab') === 'season') {
                    selectDiscussPane('season');
                }
            }
            if (isUnreleasedContent) {
                if (seasonTab) seasonTab.hidden = true;
                selectDiscussPane('comments');
            }
        }

        // Çıkmamış yapım: puan, "izledim" ve yorum kapalıdır (sunucu da reddeder); izlenecekler listesi açık kalır.
        let isUnreleasedContent = false;
        function applyUnreleasedState(type) {
            isUnreleasedContent = type !== 'book' && !!currentContent && app.isUnreleased(currentContent.release_date);
            if (!isUnreleasedContent) return;
            const when = app.formatReleaseDate(currentContent.release_date);
            const hint = `Henüz yayınlanmadı (${when}); çıkış tarihinden sonra açılır.`;
            document.getElementById('contentMeta').insertAdjacentHTML('afterbegin', metaChips([`Yakında · ${when}`]));
            ['rateBtn', 'watchBtn'].forEach((id) => {
                const button = document.getElementById(id);
                if (!button) return;
                button.disabled = true;
                button.title = hint;
            });
            const form = document.querySelector('#commentsPane .comment-form');
            if (form) {
                form.hidden = true;
                form.insertAdjacentHTML('beforebegin', `<div class="notice" id="unreleasedNotice" role="note" style="margin-bottom: 22px;">
                    <svg class="ui-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Bu yapım henüz yayınlanmadı (${escapeHtml(when)}). Çıkış tarihinden sonra yorum yapabilir, puan verebilir ve izlediğini işaretleyebilirsin. Şimdilik izlenecekler listene ekleyebilirsin.</span>
                </div>`);
            }
        }

        function displayMovieDetail(movie) {
            currentContent = {
                id: movie.id,
                title: movie.title,
                poster_path: movie.poster_path,
                overview: movie.overview,
                release_date: movie.release_date,
                runtime: movie.runtime,
                genres: movie.genres,
                vote_average: movie.vote_average,
                vote_count: movie.vote_count,
                credits: movie.credits,
                videos: movie.videos,
                'watch/providers': movie['watch/providers']
            };

            document.getElementById('contentTitle').textContent = movie.title;

            const posterUrl = movie.poster_path
                ? `https://image.tmdb.org/t/p/w500${movie.poster_path}`
                : 'img/no-poster.svg';
            document.getElementById('contentPoster').src = posterUrl;

            const year = movie.release_date ? movie.release_date.split('-')[0] : null;
            document.getElementById('contentMeta').innerHTML = metaChips([
                year,
                movie.runtime ? `${movie.runtime} dk` : null,
                ...(movie.genres || []).slice(0, 4).map(genre => genre.name)
            ]);
            setHeroBackdrop(movie.backdrop_path);

            document.getElementById('contentDescription').textContent = movie.overview || 'Açıklama bulunamadı.';

            document.getElementById('ratingScore').textContent = movie.vote_average
                ? `${movie.vote_average.toFixed(1)}/10`
                : '-/-';
            document.getElementById('ratingCount').textContent = movie.vote_count
                ? `🎬 TMDb: ${movie.vote_count.toLocaleString()} oy`
                : '🎬 TMDb Puanı';

            const followBtn = document.getElementById('followCreatorBtn');
            if (followBtn && movie.credits && movie.credits.crew) {
                const director = movie.credits.crew.find(person => person.job === 'Director');
                if (director) {
                    followBtn.textContent = `👥 ${director.name}'i Takip Et`;
                    followBtn.dataset.creatorId = director.id;
                    followBtn.style.display = 'inline-flex';
                }
            }

            setupTrailerButton(movie);
            displayWatchProviders(movie);
        }

        function displaySeriesDetail(series) {
            currentContent = {
                id: series.id,
                title: series.name,
                poster_path: series.poster_path,
                overview: series.overview,
                release_date: series.first_air_date,
                genres: series.genres,
                vote_average: series.vote_average,
                vote_count: series.vote_count,
                credits: series.credits,
                videos: series.videos,
                'watch/providers': series['watch/providers']
            };

            document.getElementById('contentTitle').textContent = series.name;

            const posterUrl = series.poster_path
                ? `https://image.tmdb.org/t/p/w500${series.poster_path}`
                : 'img/no-poster.svg';
            document.getElementById('contentPoster').src = posterUrl;

            const year = series.first_air_date ? series.first_air_date.split('-')[0] : null;
            document.getElementById('contentMeta').innerHTML = metaChips([
                year,
                series.number_of_seasons ? `${series.number_of_seasons} sezon` : null,
                ...(series.genres || []).slice(0, 4).map(genre => genre.name)
            ]);
            setHeroBackdrop(series.backdrop_path);

            document.getElementById('contentDescription').textContent = series.overview || 'Açıklama bulunamadı.';

            document.getElementById('ratingScore').textContent = series.vote_average
                ? `${series.vote_average.toFixed(1)}/10`
                : '-/-';
            document.getElementById('ratingCount').textContent = series.vote_count
                ? `📺 TMDb: ${series.vote_count.toLocaleString()} oy`
                : '📺 TMDb Puanı';

            const followBtn = document.getElementById('followCreatorBtn');
            if (followBtn && series.created_by && series.created_by.length > 0) {
                const creator = series.created_by[0];
                followBtn.textContent = `👥 ${creator.name}'i Takip Et`;
                followBtn.dataset.creatorId = creator.id;
                followBtn.style.display = 'inline-flex';
            }

            setupTrailerButton(series);
            displayWatchProviders(series);
        }

        async function followCreator(creatorName, creatorId) {
            if (typeof app === 'undefined' || !app.currentUser) {
                app.toast('Takip etmek için giriş yapmalısınız!', 'error');
                return;
            }

            try {
                const targetUserId = creatorId;

                const response = await fetch('/api/follow.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({ target_user_id: targetUserId })
                });

                const data = await response.json();

                if (data.success) {
                    app.toast(`${creatorName} takip edildi!`, 'success');
                } else {
                    app.toast(data.message, 'error');
                }
            } catch (error) {
                console.error('Takip hatası:', error);
                app.toast('Takip işlemi başarısız oldu!', 'error');
            }
        }

        function displayBookDetail(book) {
            const volumeInfo = book.volumeInfo || {};
            currentContent = {
                id: book.id,
                volumeInfo: volumeInfo,
                title: volumeInfo.title,
                imageLinks: volumeInfo.imageLinks
            };

            document.getElementById('contentTitle').textContent = volumeInfo.title || 'Bilinmiyor';

            const coverUrl = volumeInfo.imageLinks?.thumbnail
                ? volumeInfo.imageLinks.thumbnail.replace('http://', 'https://')
                : 'img/no-poster.svg';
            document.getElementById('contentPoster').src = coverUrl;

            document.getElementById('contentMeta').innerHTML = metaChips([
                ...(volumeInfo.authors || []).slice(0, 2),
                volumeInfo.publishedDate ? volumeInfo.publishedDate.split('-')[0] : null,
                volumeInfo.pageCount ? `${volumeInfo.pageCount} sayfa` : null,
                ...(volumeInfo.categories || []).slice(0, 3)
            ]);
            setHeroBackdrop(null, coverUrl);

            document.getElementById('contentDescription').textContent =
                volumeInfo.description || 'Açıklama bulunamadı.';

            document.getElementById('ratingScore').textContent = volumeInfo.averageRating
                ? `${volumeInfo.averageRating}/5`
                : '-/-';
            document.getElementById('ratingCount').textContent = '📚 Google Books Puanı';

            const trailerBtn = document.getElementById('watchTrailerBtn');
            if (trailerBtn) trailerBtn.style.display = 'none';
            const providersSec = document.getElementById('providersSection');
            if (providersSec) providersSec.style.display = 'none';
        }

        
        async function loadPlatformUserRating() {
            try {
                const response = await fetch(
                    `/api/content-rating.php?content_type=${currentContentType}&content_id=${currentContentId}`
                );
                const data = await response.json();

                if (data.success && data.data) {
                    const { average_rating, total_votes } = data.data;

                    if (average_rating && total_votes > 0) {
                        document.getElementById('userRatingScore').textContent = `${average_rating}/10`;
                        document.getElementById('userRatingCount').textContent = `👥 ${total_votes} kullanıcı oyladı`;
                    } else {
                        document.getElementById('userRatingScore').textContent = '-/-';
                        document.getElementById('userRatingCount').textContent = '👥 Henüz oy yok';
                    }
                }
            } catch (error) {
                console.error('Platform puanı yükleme hatası:', error);
                document.getElementById('userRatingScore').textContent = '-/-';
                document.getElementById('userRatingCount').textContent = '👥 Kullanıcı Puanı';
            }
        }
        function setupActionButtons(type) {
            const watchBtn = document.getElementById('watchBtn');
            const watchlistBtn = document.getElementById('watchlistBtn');
            const readBtn = document.getElementById('readBtn');
            const readinglistBtn = document.getElementById('readinglistBtn');

            const isScreenContent = type === 'movie' || type === 'series';
            watchBtn.style.display = isScreenContent ? 'inline-flex' : 'none';
            watchlistBtn.style.display = isScreenContent ? 'inline-flex' : 'none';
            readBtn.style.display = isScreenContent ? 'none' : 'inline-flex';
            readinglistBtn.style.display = isScreenContent ? 'none' : 'inline-flex';

            if (isScreenContent) {
                const watchBtnSpan = document.getElementById('watchBtnText');
                if (watchBtnSpan) watchBtnSpan.textContent = type === 'series' ? 'İzliyorum' : 'İzledim';
            }
        }
        function setupStarRating() {
            const stars = document.querySelectorAll('.star');
            stars.forEach(star => {
                star.addEventListener('click', function () {
                    const rating = parseInt(this.getAttribute('data-rating'));
                    currentRating = rating;
                    updateStarDisplay();
                });
            });
        }

        function updateStarDisplay() {
            const stars = document.querySelectorAll('.star');
            stars.forEach(star => {
                const starRating = parseInt(star.getAttribute('data-rating'));
                if (starRating <= currentRating) {
                    star.textContent = '★';
                    star.classList.add('active');
                } else {
                    star.textContent = '☆';
                    star.classList.remove('active');
                }
            });
            document.getElementById('currentRating').textContent = `${currentRating}/10`;
        }

        function showRatingSection() {
            if (typeof app === 'undefined' || !app.currentUser) {
                app.toast('Puan vermek için giriş yapmalısınız!', 'error');
                return;
            }

            const section = document.getElementById('ratingSection');
            const userRatingSection = document.getElementById('userRatingSection');

            section.style.display = 'block';
            userRatingSection.style.display = 'none';
            section.scrollIntoView({ behavior: motionReduced() ? 'auto' : 'smooth', block: 'center' });

            const userRating = app.getRating(currentContentType, currentContentId);
            if (userRating) {
                currentRating = userRating.rating;
                updateStarDisplay();
            } else {
                currentRating = 0;
                updateStarDisplay();
            }
        }
        async function submitRating() {
            if (typeof app === 'undefined' || !app.currentUser) {
                app.toast('Puan vermek için giriş yapmalısınız!', 'error');
                return;
            }

            if (currentRating === 0) {
                app.toast('Lütfen puan seçin!', 'error');
                return;
            }

            try {
                const isScreenContent = currentContentType === 'movie' || currentContentType === 'series';
                let contentData = {
                    title: currentContent?.title,
                    poster_path: isScreenContent ? currentContent?.poster_path : null,
                    cover_url: currentContentType === 'book' ? (currentContent?.volumeInfo?.imageLinks?.thumbnail || null) : null,
                    overview: isScreenContent ? (currentContent?.overview || null) : (currentContent?.volumeInfo?.description || null),
                    first_air_date: currentContentType === 'series' ? currentContent?.release_date : undefined,
                    genres: isScreenContent ? (currentContent?.genres?.map(g => g.id) || null) : null,
                    categories: currentContentType === 'book' ? (currentContent?.volumeInfo?.categories || null) : null
                };

                const response = await fetch('/api/user-status.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({
                        content_type: currentContentType,
                        content_id: currentContentId,
                        status: currentContentType === 'series' ? 'watching' : (isScreenContent ? 'watched' : 'read'),
                        rating: currentRating,
                        content_data: contentData
                    })
                });

                const data = await response.json();
                if (data.success) {
                    app.toast(`${currentRating}/10 puanınız kaydedildi!`, 'success');
                    document.getElementById('ratingSection').style.display = 'none';
                    loadUserInteractions();
                    loadPlatformUserRating(); 
                } else {
                    app.toast('Puan gönderilemedi: ' + (data.message || 'Hata oluştu'), 'error');
                }
            } catch (error) {
                console.error('Puan gönderme hatası:', error);
                app.toast('Bağlantı hatası!', 'error');
            }
        }
        async function toggleList(listType) {
            if (typeof app === 'undefined' || !app.currentUser) {
                app.toast('Bu işlem için giriş yapmalısınız!', 'error');
                return;
            }

            try {
                const apiStatus = listType === 'readinglist'
                    ? 'to_read'
                    : (currentContentType === 'series' && listType === 'watched' ? 'watching' : listType);
                // Aynı listeye ikinci kez basmak seçimi geri alır.
                if (currentListStatus && currentListStatus === (apiStatus === 'to_read' ? 'readinglist' : apiStatus)) {
                    await removeFromList(apiStatus);
                    return;
                }
                const isScreenContent = currentContentType === 'movie' || currentContentType === 'series';
                let contentData = {
                    title: currentContent?.title || currentContent?.volumeInfo?.title,
                    poster_path: isScreenContent ? currentContent?.poster_path : null,
                    cover_url: currentContentType === 'book' ? (currentContent?.volumeInfo?.imageLinks?.thumbnail || null) : null,
                    overview: isScreenContent ? (currentContent?.overview || null) : (currentContent?.volumeInfo?.description || null),
                    first_air_date: currentContentType === 'series' ? currentContent?.release_date : undefined,
                    genres: isScreenContent ? (currentContent?.genres?.map(g => g.id) || null) : null,
                    categories: currentContentType === 'book' ? (currentContent?.volumeInfo?.categories || null) : null
                };

                const response = await fetch('/api/user-status.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({
                        content_type: currentContentType,
                        content_id: currentContentId,
                        status: apiStatus,
                        rating: 0,
                        content_data: contentData
                    })
                });

                const data = await response.json();
                if (data.success) {
                    const statusTexts = {
                        'watched': 'İzledim',
                        'watching': 'İzliyorum',
                        'watchlist': 'İzlenecekler',
                        'read': 'Okudum',
                        'readinglist': 'Okunacaklar',
                        'to_read': 'Okunacaklar'
                    };
                    app.toast(`${statusTexts[apiStatus] || apiStatus} listesine eklendi!`, 'success');
                    loadUserInteractions();
                } else {
                    app.toast('Hata: ' + (data.message || 'Bilinmeyen hata'), 'error');
                }
            } catch (error) {
                console.error('Liste ekleme hatası:', error);
                app.toast('Bağlantı hatası!', 'error');
            }
        }

        // Kullanıcının bu içerikteki mevcut liste durumu ve puanı (düğmelerin geri alınması için).
        let currentListStatus = null;
        let currentUserRating = null;

        async function removeFromList(apiStatus) {
            const isWatchLike = apiStatus === 'watched' || apiStatus === 'watching' || apiStatus === 'read';
            if (isWatchLike && currentUserRating && !confirm('Listeden çıkarırsan verdiğin puan da silinir. Devam edilsin mi?')) {
                return;
            }
            try {
                const response = await fetch('/api/user-status.php', {
                    method: 'DELETE',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({ content_type: currentContentType, content_id: currentContentId })
                });
                const data = await response.json();
                if (data.success) {
                    app.toast('Listeden çıkarıldı.', 'success');
                    loadUserInteractions();
                    loadPlatformUserRating();
                } else {
                    app.toast('Hata: ' + (data.message || 'Bilinmeyen hata'), 'error');
                }
            } catch (error) {
                console.error('Listeden çıkarma hatası:', error);
                app.toast('Bağlantı hatası!', 'error');
            }
        }

        function updateListButtons(status) {
            if (status === 'to_read') status = 'readinglist';
            currentListStatus = status || null;

            const watchKey = currentContentType === 'series' ? 'watching' : 'watched';
            const watchLabel = currentContentType === 'series' ? 'İzliyorum' : 'İzledim';

            const buttons = {
                [watchKey]: { btn: document.getElementById('watchBtn'), text: watchLabel },
                'watchlist': { btn: document.getElementById('watchlistBtn'), text: 'İzlenecekler' },
                'read': { btn: document.getElementById('readBtn'), text: 'Okudum' },
                'readinglist': { btn: document.getElementById('readinglistBtn'), text: 'Okunacaklar' }
            };
            Object.entries(buttons).forEach(([key, { btn, text }]) => {
                if (btn && btn.style.display !== 'none') {
                    btn.classList.remove('active');
                    btn.style.background = '';
                    btn.style.color = '';
                    const label = btn.querySelector('.btn-label') || btn.querySelector('span');
                    if (label) label.textContent = text;
                }
            });
            if (status && buttons[status] && buttons[status].btn && buttons[status].btn.style.display !== 'none') {
                const activeBtn = buttons[status].btn;
                activeBtn.classList.add('active');
                const label = activeBtn.querySelector('.btn-label') || activeBtn.querySelector('span');
                if (label) label.textContent = '✓ ' + buttons[status].text;
            }
        }
        async function loadUserInteractions() {
            if (typeof app !== 'undefined' && app.initPromise) await app.initPromise.catch(() => { });
            if (typeof app === 'undefined' || !app.currentUser) return;

            try {
                const statusResponse = await fetch(
                    `/api/user-status.php?content_type=${currentContentType}&content_id=${currentContentId}`,
                    { method: 'GET', credentials: 'include' }
                );
                const statusData = await statusResponse.json();

                if (statusData.success && statusData.data) {
                    const listStatus = statusData.data.status;
                    const userRating = statusData.data.rating;
                    currentUserRating = userRating || null;

                    updateListButtons(listStatus);

                    const userRatingSection = document.getElementById('userRatingSection');
                    const userRatingDisplay = document.getElementById('userRatingDisplay');

                    if (userRating) {
                        userRatingSection.style.display = 'block';
                        userRatingDisplay.innerHTML = `
                    <div style="font-size: 24px; font-weight: bold;">${app.ratingToStars(userRating)}</div>
                    <div style="color: var(--color-text-muted);">${userRating}/10</div>
                `;
                    } else {
                        userRatingSection.style.display = 'none';
                    }
                }
            } catch (error) {
                console.error('User interactions yükleme hatası:', error);
            }
        }

        async function submitComment() {
            if (typeof app === 'undefined' || !app.currentUser) {
                app.toast('Yorum yapmak için giriş yapmalısınız!', 'error');
                return;
            }

            const commentText = document.getElementById('commentText').value.trim();
            if (!commentText) {
                app.toast('Lütfen yorumunuzu yazın!', 'error');
                return;
            }

            try {
                const hasSpoiler = document.getElementById('commentSpoiler')?.checked || false;
                const response = await fetch('/api/comments.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({
                        content_type: currentContentType,
                        content_id: currentContentId,
                        comment_text: commentText,
                        parent_comment_id: null,
                        has_spoiler: hasSpoiler
                    })
                });

                const data = await response.json();
                if (data.success) {
                    document.getElementById('commentText').value = '';
                    document.getElementById('commentSpoiler').checked = false;
                    loadComments();
                    app.toast('Yorumunuz gönderildi!', 'success');
                } else {
                    app.toast('Yorum gönderilemedi: ' + (data.message || 'Hata oluştu'), 'error');
                }
            } catch (error) {
                console.error('Yorum gönderme hatası:', error);
                app.toast('Bağlantı hatası!', 'error');
            }
        }

        const ICON_ATTRS = 'width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
        const REPLY_ICON = `<svg ${ICON_ATTRS}><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`;
        const PENCIL_ICON = `<svg ${ICON_ATTRS}><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>`;
        const TRASH_ICON = `<svg ${ICON_ATTRS}><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>`;
        const FLAG_ICON = `<svg ${ICON_ATTRS}><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>`;

        function heartIcon(liked) {
            return CommentView.heartIcon(liked);
        }

        // Bir yorum listesinin HTML'i: genel yorumlar ve bölüm sohbeti aynı kartı kullanır.
        function commentListHtml(comments) {
            const viewerId = app?.currentUser?.id ?? null;
            const items = comments.map((comment) => {
                // Düzenleme penceresi ham metni buradan okur; metin HTML'e gömülmez.
                commentTextById[comment.id] = comment.comment_text;
                const isOwn = viewerId !== null && Number(comment.user_id) === Number(viewerId);
                const ownActions = isOwn
                    ? `<button type="button" class="cv-action" onclick="showEditModal(${comment.id})">${PENCIL_ICON}<span>Düzenle</span></button>
                        <button type="button" class="cv-action cv-action--danger" onclick="deleteComment(${comment.id})">${TRASH_ICON}<span>Sil</span></button>`
                    : `<button type="button" class="cv-action" onclick="showReportModal(${comment.id})">${FLAG_ICON}<span>Şikayet Et</span></button>`;
                const actions = `
                    <button type="button" id="like-btn-${comment.id}" class="cv-action${comment.liked_by_me ? ' is-liked' : ''}" onclick="likeComment(${comment.id})">
                        ${heartIcon(comment.liked_by_me)} <span id="like-count-${comment.id}">${comment.likes_count || 0}</span>
                    </button>
                    <button type="button" class="cv-action" onclick="showReplyForm(${comment.id})">${REPLY_ICON}<span>Yanıtla</span></button>
                    ${CommentView.reactionButtons(comment)}
                    ${ownActions}`;
                const below = replyFormHtml(comment) + repliesHtml(comment, viewerId);
                return CommentView.card(comment, { viewerId, actions, below });
            });
            return `<div class="cv-list">${items.join('')}</div>`;
        }

        async function loadComments() {
            // Yorum kartlarında "kendi yorumun" düzenleme ve silme düğmeleri oturum bilgisine bağlıdır.
            if (typeof app !== 'undefined' && app.initPromise) await app.initPromise.catch(() => { });
            try {
                const response = await fetch(
                    `/api/comments.php?content_type=${currentContentType}&content_id=${currentContentId}`,
                    { credentials: 'include' }
                );
                const data = await response.json();
                const commentsList = data.comments || [];

                if (commentsList.length === 0) {
                    document.getElementById('comments-section').innerHTML = '<p class="cv-empty">Henüz yorum yapılmamış...</p>';
                    return;
                }
                document.getElementById('comments-section').innerHTML = commentListHtml(commentsList);
            } catch (error) {
                console.error('Yorum yükleme hatası:', error);
                document.getElementById('comments-section').innerHTML = '<p class="cv-empty cv-empty--error">❌ Yorumlar yüklenemedi</p>';
            }
        }

        function replyFormHtml(comment) {
            return `<div id="reply-form-${comment.id}" class="cv-reply-form" style="display: none;">
                <textarea id="reply-text-${comment.id}" class="cv-textarea" maxlength="1000" aria-label="Yanıtın" placeholder="@${escapeHtml(comment.username)} kullanıcısına yanıt yazın..."></textarea>
                <label class="spoiler-checkbox-label">
                    <input type="checkbox" id="reply-spoiler-${comment.id}">
                    <span>Bu yanıt spoiler (sürprizbozan) içeriyor</span>
                </label>
                <div class="cv-form-actions">
                    <button type="button" class="btn btn-primary" onclick="submitReply(${comment.id})">Yanıtla</button>
                    <button type="button" class="btn btn-secondary" onclick="hideReplyForm(${comment.id})">İptal</button>
                </div>
            </div>`;
        }

        function repliesHtml(comment, viewerId) {
            if (!comment.replies || comment.replies.length === 0) {
                return '';
            }
            const items = comment.replies.map((reply) => {
                const isOwnReply = viewerId !== null && Number(reply.user_id) === Number(viewerId);
                return `<article class="cv-reply" id="comment-${reply.id}">
                    <header class="cv-head">
                        <div class="cv-author"><strong class="cv-name">${escapeHtml(reply.username || 'Kullanıcı')}</strong></div>
                        ${CommentView.times(reply)}
                    </header>
                    ${CommentView.body(reply, viewerId)}
                    <div class="cv-actions">
                        <button type="button" id="like-btn-${reply.id}" class="cv-action${reply.liked_by_me ? ' is-liked' : ''}" onclick="likeComment(${reply.id})">
                            ${heartIcon(reply.liked_by_me)} <span id="like-count-${reply.id}">${reply.likes_count || 0}</span>
                        </button>
                        ${isOwnReply ? `<button type="button" class="cv-action cv-action--danger" onclick="deleteComment(${reply.id})">${TRASH_ICON}<span>Sil</span></button>` : ''}
                    </div>
                </article>`;
            });
            return `<div class="cv-replies">${items.join('')}</div>`;
        }

        // --- Tartışma alanı: genel yorumlar ve (diziler için) sezon sohbeti ---
        const seasonChat = { loaded: false, seasons: [], season: null, episodes: [], openEpisode: null };

        function selectDiscussPane(pane) {
            document.querySelectorAll('[data-pane-btn]').forEach((button) => {
                const active = button.dataset.paneBtn === pane;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', String(active));
            });
            document.getElementById('commentsPane').hidden = pane !== 'comments';
            document.getElementById('seasonPane').hidden = pane !== 'season';
            if (pane === 'season') {
                initSeasonChat();
            }
        }

        // Etkin alanı yeniler: bölüm sohbeti açıksa o, değilse genel yorumlar.
        function refreshComments() {
            const seasonPane = document.getElementById('seasonPane');
            if (seasonPane && !seasonPane.hidden && seasonChat.openEpisode !== null) {
                loadEpisodeThread();
            } else {
                loadComments();
            }
        }

        function seasonLabel(number) {
            return number === 0 ? 'Özel bölümler' : `Sezon ${number}`;
        }

        function formatAirDate(date) {
            if (!date) return 'Tarih yok';
            return new Date(`${date}T00:00:00Z`).toLocaleDateString('tr-TR', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
        }

        const EPISODE_STATE_CHIPS = {
            this_week: '<span class="chip chip--accent">Bu hafta</span>',
            upcoming: '<span class="chip chip--warm">Yakında</span>',
        };

        async function initSeasonChat() {
            const list = document.getElementById('episodeList');
            if (!app.currentUser) {
                list.innerHTML = '<div class="notice notice--warn">Sezon sohbetini kullanmak için giriş yapmalısın.</div>';
                return;
            }
            if (seasonChat.loaded) return;

            list.innerHTML = '<div class="loading">Sezonlar yükleniyor...</div>';
            try {
                const response = await fetch(`/api/episodes.php?series_id=${encodeURIComponent(currentContentId)}`, { credentials: 'include' });
                const data = await response.json();
                if (!data.success) {
                    list.innerHTML = `<div class="notice notice--warn">${escapeHtml(data.message || 'Bölüm listesi alınamadı.')}</div>`;
                    return;
                }
                seasonChat.loaded = true;
                seasonChat.seasons = data.seasons;
                const regular = data.seasons.filter(season => season.number > 0);
                const nextSeason = data.series.next_episode ? data.series.next_episode.season : null;
                const start = nextSeason !== null && nextSeason !== undefined && data.seasons.some(s => s.number === nextSeason)
                    ? nextSeason
                    : (regular.length ? regular[regular.length - 1].number : (data.seasons[0] ? data.seasons[0].number : 1));
                await loadSeason(start);
            } catch (error) {
                console.error('Sezon sohbeti yükleme hatası:', error);
                list.innerHTML = '<div class="notice notice--danger">Bağlantı hatası: sezonlar alınamadı.</div>';
            }
        }

        function renderSeasonTabs() {
            document.getElementById('seasonTabs').innerHTML = seasonChat.seasons.map((season) => {
                const progress = season.progress && season.progress.total ? `${season.progress.watched}/${season.progress.total}` : '';
                return `<button type="button" class="chip" aria-pressed="${season.number === seasonChat.season}" onclick="loadSeason(${season.number})">${seasonLabel(season.number)}${progress ? ` <small>${progress}</small>` : ''}</button>`;
            }).join('');
        }

        async function loadSeason(number) {
            seasonChat.season = number;
            seasonChat.openEpisode = null;
            document.getElementById('episodeThread').innerHTML = '';
            document.getElementById('seasonSummary').innerHTML = '';
            renderSeasonTabs();

            const list = document.getElementById('episodeList');
            list.innerHTML = '<div class="loading">Bölümler yükleniyor...</div>';
            try {
                const response = await fetch(`/api/episodes.php?series_id=${encodeURIComponent(currentContentId)}&season=${number}`, { credentials: 'include' });
                const data = await response.json();
                if (!data.success) {
                    list.innerHTML = `<div class="notice notice--warn">${escapeHtml(data.message || 'Bölümler alınamadı.')}</div>`;
                    return;
                }
                seasonChat.episodes = data.episodes;
                renderSeasonSummary();
                renderEpisodeList();
            } catch (error) {
                console.error('Bölüm listesi hatası:', error);
                list.innerHTML = '<div class="notice notice--danger">Bağlantı hatası: bölümler alınamadı.</div>';
            }
        }

        function renderSeasonSummary() {
            const total = seasonChat.episodes.length;
            const watched = seasonChat.episodes.filter(episode => episode.watched).length;
            const percent = total ? Math.round((watched / total) * 100) : 0;
            const allDone = total > 0 && watched === total;
            document.getElementById('seasonSummary').innerHTML = total ? `
                <div class="sc-summary">
                    <div class="meter" role="progressbar" aria-label="Sezon ilerlemesi" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${percent}"><span style="width: ${percent}%"></span></div>
                    <span class="faint">${watched}/${total} bölüm izlendi</span>
                    <button type="button" class="btn-glass btn-sm" onclick="markSeasonWatched(${!allDone})">${allDone ? 'İşaretleri kaldır' : 'Tüm sezonu izledim'}</button>
                </div>` : '';
        }

        function renderEpisodeList() {
            const list = document.getElementById('episodeList');
            if (!seasonChat.episodes.length) {
                list.innerHTML = '<div class="empty"><div class="empty-title">Bu sezonda bölüm bulunamadı</div></div>';
                return;
            }
            list.innerHTML = seasonChat.episodes.map((episode) => {
                const open = seasonChat.openEpisode === episode.number;
                const upcoming = episode.state === 'upcoming';
                const still = episode.still
                    ? `<img class="sc-still" src="${escapeHtml(episode.still)}" alt="" loading="lazy">`
                    : `<div class="sc-still sc-still--empty" aria-hidden="true">B${episode.number}</div>`;
                return `
                    <article class="sc-episode${open ? ' is-open' : ''}" id="episode-${episode.number}">
                        ${still}
                        <div class="sc-ep-main">
                            <div class="sc-ep-title">B${episode.number} · ${escapeHtml(episode.name || 'İsimsiz bölüm')}</div>
                            <div class="sc-ep-meta">
                                <span>${formatAirDate(episode.air_date)}</span>
                                ${episode.runtime ? `<span>${episode.runtime} dk</span>` : ''}
                                ${EPISODE_STATE_CHIPS[episode.state] || ''}
                                <span>${episode.thread_count} konu</span>
                            </div>
                        </div>
                        <div class="sc-ep-actions">
                            <button type="button" class="chip" aria-pressed="${episode.watched}" ${upcoming ? 'disabled' : ''} onclick="setEpisodeWatched(${seasonChat.season}, ${episode.number}, ${!episode.watched})">${episode.watched ? '✓ İzledim' : 'İzledim olarak işaretle'}</button>
                            <button type="button" class="btn-glass btn-sm" onclick="openEpisodeThread(${episode.number})">${open ? 'Sohbeti kapat' : 'Sohbeti aç'}</button>
                        </div>
                    </article>`;
            }).join('');
        }

        // Sunucudan gelen ilerleme durumunu bölümlere, sezon sekmelerine ve açık sohbete yansıtır.
        function applyEpisodeProgress(data) {
            const watched = data.watched || {};
            const counts = data.season_counts || {};
            seasonChat.episodes.forEach((episode) => {
                episode.watched = Boolean(watched[`${seasonChat.season}:${episode.number}`]);
            });
            seasonChat.seasons.forEach((season) => {
                const total = season.progress && season.progress.total ? season.progress.total : null;
                const count = counts[season.number] || 0;
                season.progress = { watched: count, total, percent: total ? Math.round((count / total) * 100) : null };
            });
            renderSeasonTabs();
            renderSeasonSummary();
            renderEpisodeList();
            if (seasonChat.openEpisode !== null) {
                loadEpisodeThread();
            }
        }

        async function postEpisodeProgress(body) {
            try {
                const response = await fetch('/api/episode-progress.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({ series_id: Number(currentContentId), ...body })
                });
                const data = await response.json();
                if (!data.success) {
                    app.toast(data.message || 'İşaret kaydedilemedi', 'error');
                    return;
                }
                applyEpisodeProgress(data);
            } catch (error) {
                console.error('Bölüm ilerlemesi hatası:', error);
                app.toast('Bağlantı hatası', 'error');
            }
        }

        function setEpisodeWatched(season, episode, watched) {
            return postEpisodeProgress({ season, episode, watched });
        }

        function markSeasonWatched(watched) {
            const numbers = seasonChat.episodes.filter(episode => episode.state !== 'upcoming').map(episode => episode.number);
            return postEpisodeProgress({ season: seasonChat.season, episodes: numbers, watched });
        }

        function openEpisodeThread(number) {
            seasonChat.openEpisode = seasonChat.openEpisode === number ? null : number;
            renderEpisodeList();
            if (seasonChat.openEpisode === null) {
                document.getElementById('episodeThread').innerHTML = '';
                return;
            }
            loadEpisodeThread().then(() => {
                const thread = document.getElementById('episodeThread');
                thread.scrollIntoView({ behavior: motionReduced() ? 'auto' : 'smooth', block: 'nearest' });
            });
        }

        async function loadEpisodeThread() {
            const thread = document.getElementById('episodeThread');
            const number = seasonChat.openEpisode;
            const episode = seasonChat.episodes.find(item => item.number === number);
            if (!episode) {
                thread.innerHTML = '';
                return;
            }
            const title = `B${episode.number} · ${episode.name || 'Bölüm'}`;
            thread.innerHTML = '<div class="sc-thread"><div class="loading">Sohbet yükleniyor...</div></div>';

            try {
                const response = await fetch(
                    `/api/comments.php?content_type=series&content_id=${encodeURIComponent(currentContentId)}&season=${seasonChat.season}&episode=${number}`,
                    { credentials: 'include' }
                );
                const data = await response.json();
                if (!data.success) {
                    thread.innerHTML = `<div class="sc-thread"><div class="notice notice--warn">${escapeHtml(data.message || 'Sohbet yüklenemedi')}</div></div>`;
                    return;
                }

                if (data.locked) {
                    const lockIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
                    const action = episode.state === 'upcoming'
                        ? '<p class="muted">Bu bölüm henüz yayınlanmadı.</p>'
                        : `<button type="button" class="btn btn-primary" onclick="setEpisodeWatched(${seasonChat.season}, ${number}, true)">Bu bölümü izledim, sohbeti aç</button>`;
                    thread.innerHTML = `<div class="sc-thread"><div class="sc-lock">${lockIcon}
                        <div class="sc-thread-title">${escapeHtml(title)} sohbeti kilitli</div>
                        <p class="muted">Bu bölümde ${data.thread_count} konu var. Spoiler yememen için yorumları, bölümü izlediğini işaretleyene kadar göstermiyoruz.</p>
                        ${action}</div></div>`;
                    return;
                }

                const sendIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" x2="11" y1="2" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
                thread.innerHTML = `<div class="sc-thread">
                    <div class="sc-thread-head"><span class="sc-thread-title">${escapeHtml(title)} sohbeti</span><span class="chip chip--accent">${data.comments.length} konu</span></div>
                    <div class="comment-form">
                        <textarea id="threadText" aria-label="Bölüm yorumun" maxlength="1000" placeholder="Bu bölüm hakkında ne düşünüyorsun?"></textarea>
                        <div class="comment-form-row"><span class="faint">Yalnızca bu bölümü izleyenler görür.</span><button type="button" class="sc-send-btn" onclick="submitThreadComment()">${sendIcon}<span>Gönder</span></button></div>
                    </div>
                    ${data.comments.length ? commentListHtml(data.comments) : '<p class="cv-empty">Bu bölüm için henüz kimse yazmamış. İlk yorumu sen yaz!</p>'}
                </div>`;
            } catch (error) {
                console.error('Bölüm sohbeti hatası:', error);
                thread.innerHTML = '<div class="sc-thread"><div class="notice notice--danger">Bağlantı hatası: sohbet alınamadı.</div></div>';
            }
        }

        async function submitThreadComment() {
            const textarea = document.getElementById('threadText');
            const text = textarea ? textarea.value.trim() : '';
            if (!text) {
                app.toast('Lütfen yorumunu yaz!', 'error');
                return;
            }

            try {
                const response = await fetch('/api/comments.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({
                        content_type: 'series',
                        content_id: currentContentId,
                        comment_text: text,
                        season_number: seasonChat.season,
                        episode_number: seasonChat.openEpisode
                    })
                });
                const data = await response.json();
                if (!data.success) {
                    app.toast(data.message || 'Yorum gönderilemedi', 'error');
                    return;
                }
                app.toast('Yorumun gönderildi!', 'success');
                const episode = seasonChat.episodes.find(item => item.number === seasonChat.openEpisode);
                if (episode) {
                    episode.thread_count += 1;
                    renderEpisodeList();
                }
                loadEpisodeThread();
            } catch (error) {
                console.error('Bölüm yorumu hatası:', error);
                app.toast('Bağlantı hatası!', 'error');
            }
        }

        function showReplyForm(commentId) {
            if (typeof app === 'undefined' || !app.currentUser) {
                app.toast('Yanıt yazmak için giriş yapmalısınız!', 'error');
                return;
            }
            document.getElementById(`reply-form-${commentId}`).style.display = 'block';
            document.getElementById(`reply-text-${commentId}`).focus();
        }
        function hideReplyForm(commentId) {
            document.getElementById(`reply-form-${commentId}`).style.display = 'none';
            document.getElementById(`reply-text-${commentId}`).value = '';
        }
        async function submitReply(parentCommentId) {
            const replyText = document.getElementById(`reply-text-${parentCommentId}`).value.trim();
            if (!replyText) {
                app.toast('Yanıt boş olamaz!', 'error');
                return;
            }

            try {
                const hasSpoiler = document.getElementById(`reply-spoiler-${parentCommentId}`)?.checked || false;
                const response = await fetch('/api/comments.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({
                        content_type: currentContentType,
                        content_id: currentContentId,
                        comment_text: replyText,
                        parent_comment_id: parentCommentId,
                        has_spoiler: hasSpoiler
                    })
                });

                const data = await response.json();
                if (data.success) {
                    hideReplyForm(parentCommentId);
                    refreshComments();
                    app.toast('Yanıtınız gönderildi!', 'success');
                } else {
                    app.toast('Yanıt gönderilemedi: ' + (data.message || 'Hata oluştu'), 'error');
                }
            } catch (error) {
                console.error('Yanıt gönderme hatası:', error);
                app.toast('Bağlantı hatası!', 'error');
            }
        }

        async function deleteComment(commentId) {
            if (!(await app.confirmDialog('Yorumu silmek istediğinize emin misiniz?'))) return;

            try {
                const response = await fetch('/api/comments.php', {
                    method: 'DELETE',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({ comment_id: commentId })
                });

                const data = await response.json();
                if (data.success) {
                    refreshComments();
                    app.toast('Yorum silindi!', 'success');
                } else {
                    app.toast(data.message || 'Yorum silinemedi', 'error');
                }
            } catch (error) {
                console.error('Yorum silme hatası:', error);
                app.toast('Hata oluştu', 'error');
            }
        }

        function showReportModal(commentId) {
            const html = `
                <div class="modal-overlay" id="reportModal" onclick="if(event.target===this) closeReportModal()">
                    <div class="modal-box" style="max-width: 420px;">
                        <h3 style="margin-bottom: 15px;">🚩 Yorumu Şikayet Et</h3>
                        <textarea id="reportReason" class="form-control" placeholder="Neden şikayet ediyorsunuz?" style="min-height: 80px; margin-bottom: 15px; font-family: inherit;"></textarea>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn btn-primary" style="flex: 1;" onclick="submitReport(${commentId})">Gönder</button>
                            <button class="btn btn-secondary" style="flex: 1;" onclick="closeReportModal()">İptal</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', html);
        }

        function closeReportModal() {
            document.getElementById('reportModal')?.remove();
        }

        async function submitReport(commentId) {
            const reason = document.getElementById('reportReason').value.trim();
            if (!reason) {
                app.toast('Lütfen bir neden yazın', 'error');
                return;
            }

            try {
                const response = await fetch('/api/report-comment.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({ comment_id: commentId, reason })
                });
                const data = await response.json();
                if (data.success) {
                    app.toast('Şikayetiniz alındı, incelenecek', 'success');
                    closeReportModal();
                } else {
                    app.toast(data.message || 'Hata oluştu', 'error');
                }
            } catch (error) {
                console.error('Şikayet gönderme hatası:', error);
                app.toast('Bağlantı hatası', 'error');
            }
        }

        function showEditModal(commentId) {
            const rawText = commentTextById[commentId] || '';

            const modal = document.createElement('div');
            modal.id = 'editModal';
            modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: var(--color-overlay); display: flex; justify-content: center; align-items: center; z-index: 1000;';

            const modalContent = document.createElement('div');
            modalContent.style.cssText = 'background: var(--color-surface); padding: 25px; border-radius: 10px; width: 90%; max-width: 500px; box-shadow: 0 4px 15px rgba(0,0,0,0.2);';

            const title = document.createElement('h3');
            title.style.cssText = 'margin-top: 0; margin-bottom: 15px; color: var(--color-text);';
            title.textContent = 'Yorumu Düzenle';

            const textarea = document.createElement('textarea');
            textarea.id = 'editText';
            textarea.style.cssText = 'width: 100%; min-height: 120px; padding: 10px; border: 1px solid var(--color-border); border-radius: 5px; font-family: inherit; font-size: 14px;';
            textarea.value = rawText;

            const buttonDiv = document.createElement('div');
            buttonDiv.style.cssText = 'display: flex; gap: 10px; margin-top: 15px; justify-content: flex-end;';

            const cancelBtn = document.createElement('button');
            cancelBtn.style.cssText = 'background: #ccc; color: #333; border: none; padding: 8px 20px; border-radius: 5px; cursor: pointer;';
            cancelBtn.textContent = 'İptal';
            cancelBtn.onclick = () => modal.remove();

            const saveBtn = document.createElement('button');
            saveBtn.style.cssText = 'background: var(--color-accent); color: white; border: none; padding: 8px 20px; border-radius: 5px; cursor: pointer;';
            saveBtn.textContent = 'Kaydet';
            saveBtn.onclick = () => submitEditComment(commentId);

            buttonDiv.appendChild(cancelBtn);
            buttonDiv.appendChild(saveBtn);
            modalContent.appendChild(title);
            modalContent.appendChild(textarea);
            modalContent.appendChild(buttonDiv);
            modal.appendChild(modalContent);
            modal.onclick = (e) => { if (e.target === modal) modal.remove(); };

            document.body.appendChild(modal);
            textarea.focus();
        }

        async function submitEditComment(commentId) {
            const newText = document.getElementById('editText').value.trim();
            if (!newText) {
                app.toast('Yorum boş olamaz!', 'error');
                return;
            }

            try {
                const response = await fetch('/api/comments.php', {
                    method: 'PUT',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({
                        comment_id: commentId,
                        comment_text: newText
                    })
                });

                const data = await response.json();
                if (data.success) {
                    document.getElementById('editModal').remove();
                    refreshComments();
                    app.toast('Yorum düzenlendi!', 'success');
                } else {
                    app.toast(data.message || 'Yorum düzenlenemedi', 'error');
                }
            } catch (error) {
                console.error('Yorum düzenleme hatası:', error);
                app.toast('Hata oluştu', 'error');
            }
        }

        async function editCommentPrompt(commentId) {
            showEditModal(commentId);
        }

        async function likeComment(commentId) {
            if (typeof app === 'undefined' || !app.currentUser) {
                app.toast('Beğenmek için giriş yapmalısınız!', 'error');
                return;
            }

            try {
                const response = await fetch('/api/like-comment.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({ comment_id: commentId })
                });

                const data = await response.json();

                if (data.success) {
                    const likeBtn = document.getElementById(`like-btn-${commentId}`);
                    if (likeBtn) {
                        likeBtn.classList.toggle('is-liked', Boolean(data.liked));
                        likeBtn.innerHTML = `${heartIcon(Boolean(data.liked))} <span id="like-count-${commentId}">${data.likes_count}</span>`;
                    }
                } else {
                    console.error('Like error:', data.message);
                    app.toast('Beğeni eklenemedi: ' + (data.message || 'Bilinmeyen hata'), 'error');
                }
            } catch (error) {
                console.error('Like hatası:', error);
                app.toast('Hata oluştu: ' + error.message, 'error');
            }
        }

        function showError(message) {
            const loadingDiv = document.getElementById('loadingDetail');
            loadingDiv.innerHTML = `<div class="no-results">❌ ${message}</div>`;
        }
        let selectedCustomLists = [];

        async function showCustomListModal() {
            if (!app?.currentUser) {
                app.toast('Bu özellik için giriş yapmalısınız!', 'error');
                window.location.href = 'index.html';
                return;
            }

            const modal = document.getElementById('customListModal');
            const container = document.getElementById('customListsContainer');
            modal.classList.add('active');
            try {
                const response = await fetch('/api/custom-lists.php', { credentials: 'include' });
                const data = await response.json();

                if (data.success && data.lists && data.lists.length > 0) {
                    let html = '';
                    data.lists.forEach(list => {
                        const isInList = list.items?.some(item =>
                            item.content_type === currentContentType &&
                            String(item.content_id) === String(currentContentId)
                        );

                        html += `
                            <div class="custom-list-item ${isInList ? 'selected' : ''}" 
                                 onclick="toggleCustomListSelection(this, ${list.id})"
                                 data-list-id="${list.id}">
                                <input type="checkbox" ${isInList ? 'checked' : ''} onclick="event.stopPropagation()">
                                <span class="custom-list-name">${app.escapeHtml(list.name)}</span>
                                <span class="custom-list-count">${list.item_count || 0} içerik</span>
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `
                        <div class="no-lists-message">
                            <p>Henüz özel listeniz yok.</p>
                            <p><a class="create-list-link" href="profile.html">Profil sayfasından yeni liste oluşturun →</a></p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Liste yükleme hatası:', error);
                container.innerHTML = '<div class="no-lists-message">Listeler yüklenirken hata oluştu.</div>';
            }
        }

        function closeCustomListModal(event) {
            if (event && event.target !== event.currentTarget) return;
            document.getElementById('customListModal').classList.remove('active');
        }

        function toggleCustomListSelection(element, listId) {
            element.classList.toggle('selected');
            const checkbox = element.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;
        }

        async function saveToCustomLists() {
            const selectedItems = document.querySelectorAll('.custom-list-item.selected');
            const listIds = Array.from(selectedItems).map(item => parseInt(item.dataset.listId));

            if (listIds.length === 0) {
                app.toast('En az bir liste seçmelisiniz!', 'error');
                return;
            }
            const contentTitle = currentContent?.title || currentContent?.volumeInfo?.title || 'İçerik';

            try {
                let successCount = 0;
                for (const listId of listIds) {
                    const response = await fetch('/api/custom-lists.php', {
                        method: 'POST',
                        headers: app.authHeaders(),
                        credentials: 'include',
                        body: JSON.stringify({
                            action: 'add_item',
                            list_id: listId,
                            content_type: currentContentType,
                            content_id: currentContentId,
                            content_title: contentTitle
                        })
                    });

                    const data = await response.json();
                    if (data.success) {
                        successCount++;
                    } else if (!data.message?.includes('zaten')) {
                        console.error('Liste ekleme hatası:', data.message);
                    } else {
                        successCount++;
                    }
                }

                if (successCount > 0) {
                    app.toast('İçerik seçili listelere eklendi!', 'success');
                }
                closeCustomListModal();
            } catch (error) {
                console.error('Kaydetme hatası:', error);
                app.toast('Bir hata oluştu!', 'error');
            }
        }
    </script>
</body>

</html>