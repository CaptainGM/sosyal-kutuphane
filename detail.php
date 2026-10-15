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
    <meta name="theme-color" content="#667eea">
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--color-bg);
        }

        .nav-links {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .detail-header {
            background: var(--color-accent-gradient);
            color: white;
            padding: 60px 0 40px;
            margin-bottom: 40px;
        }

        .detail-content {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }

        .poster-container {
            text-align: center;
        }

        .poster {
            width: 100%;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .content-info {
            padding: 20px 0;
        }

        .content-title {
            font-size: 32px;
            margin-bottom: 10px;
            color: white;
        }

        .content-meta {
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 20px;
            font-size: 16px;
        }

        .content-description {
            line-height: 1.8;
            margin-bottom: 30px;
            font-size: 16px;
            color: white;
        }

        .platform-rating {
            text-align: center;
            padding: 20px;
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .rating-score {
            font-size: 48px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .rating-count {
            font-size: 14px;
            opacity: 0.8;
        }

        .rating-section {
            background: var(--color-surface);
            padding: 25px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        .rating-section h3 {
            margin-bottom: 20px;
            color: var(--color-text);
        }

        .user-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 30px;
        }

        .action-btn {
            padding: 12px 16px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.04);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            color: var(--color-text);
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13.5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            user-select: none;
        }

        .action-btn svg {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
            stroke-width: 2;
        }

        .action-btn:hover {
            background: rgba(255, 255, 255, 0.09);
            border-color: rgba(255, 255, 255, 0.28);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            color: #ffffff;
        }

        .action-btn:active {
            transform: translateY(0) scale(0.97);
        }

        .action-btn.active {
            background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
            border-color: transparent !important;
            color: #ffffff !important;
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.45) !important;
        }

        .rating-input {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 20px 0;
        }

        .star-rating {
            display: flex;
            gap: 5px;
        }

        .star {
            font-size: 24px;
            cursor: pointer;
            color: var(--color-border);
            transition: color 0.2s;
        }

        .star:hover,
        .star.active {
            color: #ffc107;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.2s ease, filter 0.15s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn:active {
            transform: translateY(0) scale(0.98);
            filter: brightness(0.94);
        }

        .btn-primary {
            background: var(--color-accent-gradient);
            color: white;
            box-shadow: 0 3px 10px rgba(var(--color-accent-rgb), 0.35);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(var(--color-accent-rgb), 0.45);
        }

        .comments-section {
            background: var(--color-surface);
            padding: 30px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            width: 100%;
            overflow-x: hidden;
        }

        .comments-section h3 {
            margin-bottom: 20px;
            color: var(--color-text);
        }

        .comment-form {
            margin-bottom: 30px;
        }

        .comment-form textarea {
            width: 100%;
            padding: 16px;
            border: 1px solid var(--color-border);
            border-radius: 12px;
            resize: vertical;
            min-height: 110px;
            margin-bottom: 15px;
            font-family: inherit;
            background: var(--color-surface-2) !important;
            color: var(--color-text) !important;
            font-size: 14.5px;
            line-height: 1.6;
            transition: all 0.2s ease;
        }

        .comment-form textarea:focus {
            outline: none;
            border-color: var(--color-accent);
            box-shadow: 0 0 0 3px rgba(var(--color-accent-rgb), 0.25);
            background: var(--color-surface) !important;
        }

        .comment-form button {
            padding: 12px 24px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .comment-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.5);
            filter: brightness(1.05);
        }

        .comment-item {
            padding: 20px;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid var(--color-accent);
            word-break: break-word;
            overflow-wrap: break-word;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .comment-user {
            font-weight: 600;
            color: var(--color-accent);
            cursor: pointer;
        }

        .comment-user:hover {
            text-decoration: underline;
        }

        .comment-date {
            color: var(--color-text-faint);
            font-size: 14px;
        }

        .comment-text {
            line-height: 1.6;
            margin-bottom: 15px;
            color: var(--color-text-muted);
            word-break: break-word;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .comment-actions {
            display: flex;
            gap: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--color-border);
        }

        .action-btn-comment {
            background: none;
            border: none;
            color: var(--color-text-muted);
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: color 0.3s;
        }

        .action-btn-comment:hover {
            color: var(--color-accent);
        }

        .reply-form {
            margin-top: 15px;
            padding: 15px;
            background: var(--color-surface-2);
            border-radius: 8px;
        }

        .reply-form textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--color-border);
            border-radius: 5px;
            font-family: inherit;
            min-height: 80px;
            margin-bottom: 10px;
        }

        .reply-form button {
            padding: 8px 15px;
            background: var(--color-accent);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            margin-right: 5px;
        }

        .reply-form button:hover {
            background: var(--color-accent-2);
        }

        .reply-form button.cancel {
            background: #ccc;
        }

        .reply-form button.cancel:hover {
            background: #999;
        }

        .replies-container {
            margin-top: 15px;
            padding-left: 20px;
            border-left: 2px solid var(--color-accent);
        }

        .reply-item {
            background: var(--color-surface-2);
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .reply-header {
            font-weight: 600;
            color: var(--color-text);
            margin-bottom: 5px;
        }

        .reply-text {
            color: var(--color-text-muted);
            line-height: 1.5;
        }

        .loading {
            text-align: center;
            padding: 40px;
            font-size: 18px;
            color: var(--color-accent);
        }

        .no-results {
            text-align: center;
            padding: 60px;
            color: var(--color-text-faint);
        }

        @media (max-width: 768px) {
            .detail-content {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .poster-container {
                max-width: 300px;
                margin: 0 auto;
            }

            .user-actions {
                grid-template-columns: 1fr;
            }

            .content-title {
                font-size: 24px;
            }
        }

        /* Özel Liste Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--color-overlay);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: var(--color-surface);
            border-radius: 15px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 20px;
            color: var(--color-text);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: var(--color-text-faint);
        }

        .modal-close:hover {
            color: var(--color-text);
        }

        .custom-lists-container {
            margin-bottom: 20px;
        }

        .custom-list-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border: 2px solid var(--color-border);
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .custom-list-item:hover {
            border-color: var(--color-accent);
            background: var(--color-border);
        }

        .custom-list-item.selected {
            border-color: var(--color-accent);
            background: var(--color-accent);
            color: white;
        }

        .custom-list-item input[type="checkbox"] {
            margin-right: 12px;
            width: 18px;
            height: 18px;
        }

        .custom-list-name {
            flex: 1;
            font-weight: 500;
        }

        .custom-list-count {
            font-size: 12px;
            color: var(--color-text-faint);
        }

        .custom-list-item.selected .custom-list-count {
            color: rgba(255, 255, 255, 0.8);
        }

        .no-lists-message {
            text-align: center;
            padding: 30px;
            color: var(--color-text-faint);
        }

        .create-list-link {
            color: var(--color-accent);
            text-decoration: underline;
            cursor: pointer;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--color-border);
        }

        .modal-btn {
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .modal-btn-cancel {
            background: var(--color-surface-2);
            border: none;
            color: var(--color-text-muted);
        }

        .modal-btn-cancel:hover {
            background: var(--color-border);
        }

        .modal-btn-save {
            background: var(--color-accent-gradient);
            border: none;
            color: white;
        }

        .modal-btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(var(--color-accent-rgb), 0.4);
        }
    </style>
</head>

<body>

    <nav class="navbar">
        <div class="navbar-inner">
            <a href="search.html" class="logo" style="display: inline-flex; align-items: center; gap: 10px; font-weight: 800; letter-spacing: -0.4px;">
                <div style="width: 36px; height: 36px; border-radius: 11px; background: linear-gradient(135deg, #6366f1, #8b5cf6, #d946ef); display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.45); flex-shrink: 0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="m10 14 5 3-5 3v-6Z"/></svg>
                </div>
                <span>Sosyal Kütüphane</span>
            </a>
            <button class="nav-icon-btn nav-toggle-btn" aria-label="Menüyü aç/kapat" aria-expanded="false">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
            </button>
            <div class="nav-links">
                <a href="search.html" class="nav-link">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    Keşfet
                </a>
                <a href="feed.html" class="nav-link">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg>
                    Akış
                </a>
                <a href="messages.html" class="nav-link">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    Mesajlar
                </a>
                <a href="profile.html" class="nav-link">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Profilim
                </a>
                <div class="nav-icon-wrap">
                    <button class="nav-icon-btn" id="notifBellBtn" aria-label="Bildirimler">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                        <span class="nav-badge" id="notifBadge"></span>
                    </button>
                    <div class="nav-panel" id="notifPanel">
                        <div class="nav-panel-header">Bildirimler</div>
                        <div class="nav-panel-empty">Yükleniyor...</div>
                    </div>
                </div>
                <button class="nav-icon-btn theme-toggle-btn" aria-label="Tema değiştir">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                </button>
                <button class="logout-btn" onclick="logout()">Çıkış Yap</button>
            </div>
        </div>
    </nav>


    <div class="detail-header">
        <div class="container">
            <div id="loadingDetail" class="loading">İçerik yükleniyor...</div>
            <div id="detailContent" style="display: none;">
                <div class="detail-content">
                    <div class="poster-container">
                        <img id="contentPoster" class="poster" src="" alt="Poster">
                        <div class="platform-rating" id="platformRating">
                            <div class="rating-score" id="ratingScore">-/-</div>
                            <div class="rating-count" id="ratingCount">API Puanı</div>
                        </div>
                        <div class="platform-rating" id="userPlatformRating"
                            style="margin-top: 10px; background: rgba(var(--color-accent-rgb), 0.3);">
                            <div class="rating-score" id="userRatingScore">-/-</div>
                            <div class="rating-count" id="userRatingCount">👥 Kullanıcı Puanı</div>
                        </div>
                    </div>
                    <div class="content-info">
                        <h1 id="contentTitle" class="content-title"></h1>
                        <div id="contentMeta" class="content-meta"></div>
                        <div id="contentDescription" class="content-description"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <div class="container">
        <div style="margin-bottom: 20px;">
            <button id="followCreatorBtn" class="btn btn-primary" style="display: none;">
                👥 Yazarı Takip Et
            </button>
        </div>

        <div class="rating-section">
            <h3 style="display: flex; align-items: center; gap: 8px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/><circle cx="12" cy="12" r="4"/></svg>
                İşlemleriniz
            </h3>

            <div class="user-actions">
                <button id="rateBtn" class="action-btn" onclick="showRatingSection()">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <span class="btn-label">Puan Ver</span>
                </button>
                <button id="watchBtn" class="action-btn" onclick="toggleList('watched')">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span class="btn-label" id="watchBtnText">İzledim</span>
                </button>
                <button id="watchlistBtn" class="action-btn" onclick="toggleList('watchlist')">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
                    <span class="btn-label" id="watchlistBtnText">İzlenecekler</span>
                </button>
                <button id="readBtn" class="action-btn" onclick="toggleList('read')" style="display: none;">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    <span class="btn-label" id="readBtnText">Okudum</span>
                </button>
                <button id="readinglistBtn" class="action-btn" onclick="toggleList('readinglist')" style="display: none;">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/></svg>
                    <span class="btn-label" id="readinglistBtnText">Okunacaklar</span>
                </button>
                <button id="customListBtn" class="action-btn" onclick="showCustomListModal()">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/><line x1="12" x2="12" y1="11" y2="17"/><line x1="9" x2="15" y1="14" y2="14"/></svg>
                    <span class="btn-label">Özel Listeye Ekle</span>
                </button>
                <button id="watchTrailerBtn" class="action-btn btn-shimmer" onclick="handleWatchTrailerClick()" style="display: none;">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
                    <span class="btn-label">Fragmanı İzle</span>
                </button>
                <button id="storyCardBtn" class="action-btn" onclick="openStoryModal()">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="4" ry="4"/><circle cx="12" cy="12" r="3"/><line x1="16.5" x2="16.5" y1="7.5" y2="7.5"/></svg>
                    <span class="btn-label">Hikaye Kartı</span>
                </button>
            </div>

            <div id="providersSection" class="providers-section" style="display: none;">
                <h4 style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="15" x="2" y="7" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/></svg>
                    Nerede İzlenir? (Türkiye)
                </h4>
                <div id="providersList" class="providers-grid"></div>
            </div>

            <div id="ratingSection" style="display: none;">
                <div class="rating-input">
                    <span>Puanınız:</span>
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
                    <span id="currentRating">0/10</span>
                </div>
                <button class="btn btn-primary" onclick="submitRating()">Puanı Gönder</button>
            </div>

            <div id="userRatingSection"
                style="display: none; margin-top: 20px; padding: 15px; background: var(--color-surface-2); border-radius: 8px;">
                <h4 style="display: flex; align-items: center; gap: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    Sizin Puanınız
                </h4>
                <div id="userRatingDisplay"></div>
                <button class="btn btn-primary" onclick="showRatingSection()" style="margin-top: 10px;">Puanı Değiştir</button>
            </div>
        </div>

        <div class="comments-section">
            <h3 style="display: flex; align-items: center; gap: 8px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Yorumlar
            </h3>

            <div class="comment-form">
                <textarea id="commentText" placeholder="Bu içerik hakkında düşüncelerinizi paylaşın..."></textarea>
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 6px;">
                    <label class="spoiler-checkbox-label" style="margin: 0;">
                        <input type="checkbox" id="commentSpoiler">
                        <span>Bu yorum spoiler (sürprizbozan) içeriyor</span>
                    </label>
                    <button onclick="submitComment()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" x2="11" y1="2" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        <span>Yorum Gönder</span>
                    </button>
                </div>
            </div>

            <div id="comments-section">
                <p style="text-align: center; color: var(--color-text-faint);">Henüz yorum yapılmamış...</p>
            </div>
        </div>

        <div class="reco-section" id="similarSection" style="display:none;">
            <h2 class="reco-title">
                <span class="showcase-badge" style="background: rgba(99, 102, 241, 0.15); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; margin-right: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="12 8 8 12 12 16 12 8"/></svg>
                </span>
                Benzer İçerikler
            </h2>
            <div class="reco-scroll" id="similarScroll"></div>
        </div>
    </div>


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
    <script>
        let currentContent = null;
        let currentRating = 0;
        let currentContentType = '';
        let currentContentId = '';
        let currentTrailerKey = null;
        const commentTextById = {};

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

            list.innerHTML = all.slice(0, 8).map(p => {
                const logoUrl = p.logo_path ? `https://image.tmdb.org/t/p/w92${p.logo_path}` : '';
                return `
                    <div class="provider-badge" title="${app.escapeHtml(p.provider_name)}">
                        ${logoUrl ? `<img src="${logoUrl}" class="provider-logo" alt="${app.escapeHtml(p.provider_name)}">` : ''}
                        <span>${app.escapeHtml(p.provider_name)}</span>
                        <span class="provider-type-tag">${p.typeLabel}</span>
                    </div>
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

        function closeStoryModal(e) {
            if (e && e.target && e.target.classList.contains('story-modal-box')) return;
            const modal = document.getElementById('storyModal');
            modal.classList.remove('active');
        }

        function renderStoryCardCanvas() {
            const canvas = document.getElementById('storyCanvas');
            if (!canvas) return;
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
            const posterImg = document.getElementById('contentPoster');
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

            if (posterImg && posterImg.complete && posterImg.naturalWidth > 0) {
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
            const platformRatingText = document.getElementById('ratingScore')?.textContent?.trim() || '8.5/10';

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
            ctx.fillText(userRatingText ? `⭐ ${userRatingText}` : `⭐ TMDb ${platformRatingText}`, W / 2, ratingBoxY + 48);

            ctx.fillStyle = '#cbd5e1';
            ctx.font = '600 16px Plus Jakarta Sans, sans-serif';
            ctx.fillText(`@${username} tarafından puanlandı`, W / 2, ratingBoxY + 84);

            // Alt Bilgi
            ctx.fillStyle = 'rgba(255, 255, 255, 0.45)';
            ctx.font = '15px Plus Jakarta Sans, sans-serif';
            ctx.fillText('sosyalkutuphane.com • Birlikte Keşfet', W / 2, H - 55);
        }

        function downloadStoryCard() {
            const canvas = document.getElementById('storyCanvas');
            if (!canvas) return;
            const link = document.createElement('a');
            const title = (document.getElementById('contentTitle')?.textContent || 'icerik')
                .toLowerCase().replace(/[^a-z0-9]/g, '-');
            link.download = `sosyal-kutuphane-${title}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
            if (typeof app !== 'undefined') app.toast('Hikaye kartı başarıyla indirildi!', 'success');
        }

        async function shareStoryCard() {
            const canvas = document.getElementById('storyCanvas');
            if (!canvas) return;
            try {
                canvas.toBlob(async (blob) => {
                    if (!blob) return;
                    const file = new File([blob], 'hikaye-karti.png', { type: 'image/png' });
                    if (navigator.canShare && navigator.canShare({ files: [file] })) {
                        await navigator.share({
                            files: [file],
                            title: document.getElementById('contentTitle')?.textContent || 'Sosyal Kütüphane',
                            text: 'Sosyal Kütüphane profilimde inceledim! 🎬📚'
                        });
                    } else {
                        downloadStoryCard();
                    }
                });
            } catch (e) {
                console.error('Paylaşım hatası:', e);
                downloadStoryCard();
            }
        }

        document.addEventListener('DOMContentLoaded', async function () {
            if (typeof app !== 'undefined' && app.initPromise) {
                try {
                    await app.initPromise;
                } catch (e) {
                    console.error('App init error:', e);
                }
            }
            loadContentDetail();
            setupStarRating();
        });

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
                        poster: item.poster_path ? `https://image.tmdb.org/t/p/w200${item.poster_path}` : 'https://placehold.co/110x160?text=Yok',
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
                                    : 'https://placehold.co/110x160?text=Kitap',
                                type: 'book'
                            }));
                    }
                }

                if (items.length === 0) return;

                scroll.innerHTML = items.map(item => `
                    <div class="reco-card" onclick="goToDetail('${item.type}', '${item.id}')">
                        <img src="${item.poster}" alt="${app.escapeHtml(item.title)}" loading="lazy" onerror="this.src='https://placehold.co/110x160?text=Yok'">
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
                : 'https://placehold.co/300x450?text=Poster+Yok';
            document.getElementById('contentPoster').src = posterUrl;

            const year = movie.release_date ? movie.release_date.split('-')[0] : 'Bilinmiyor';
            const runtime = movie.runtime ? `${movie.runtime} dakika` : 'Bilinmiyor';
            const genres = movie.genres ? movie.genres.map(g => g.name).join(', ') : 'Bilinmiyor';

            document.getElementById('contentMeta').innerHTML = `
        <div>📅 ${year} | ⏱️ ${runtime}</div>
        <div>🎭 ${genres}</div>
    `;

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
                    followBtn.style.display = 'block';
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
                : 'https://placehold.co/300x450?text=Poster+Yok';
            document.getElementById('contentPoster').src = posterUrl;

            const year = series.first_air_date ? series.first_air_date.split('-')[0] : 'Bilinmiyor';
            const seasons = series.number_of_seasons ? `${series.number_of_seasons} sezon` : 'Bilinmiyor';
            const genres = series.genres ? series.genres.map(g => g.name).join(', ') : 'Bilinmiyor';

            document.getElementById('contentMeta').innerHTML = `
        <div>📅 ${year} | 📺 ${seasons}</div>
        <div>🎭 ${genres}</div>
    `;

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
                followBtn.style.display = 'block';
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
                : 'https://placehold.co/300x450?text=Kapak+Yok';
            document.getElementById('contentPoster').src = coverUrl;

            const authors = volumeInfo.authors ? volumeInfo.authors.join(', ') : 'Bilinmiyor';
            const pageCount = volumeInfo.pageCount ? `${volumeInfo.pageCount} sayfa` : 'Bilinmiyor';
            const publishedDate = volumeInfo.publishedDate ? volumeInfo.publishedDate.split('-')[0] : 'Bilinmiyor';
            const categories = volumeInfo.categories ? volumeInfo.categories.join(', ') : 'Bilinmiyor';

            document.getElementById('contentMeta').innerHTML = `
        <div>✍️ ${authors}</div>
        <div>📅 ${publishedDate} | 📖 ${pageCount}</div>
        <div>📚 ${categories}</div>
    `;

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

            if (type === 'movie' || type === 'series') {
                watchBtn.style.display = 'block';
                watchlistBtn.style.display = 'block';
                readBtn.style.display = 'none';
                readinglistBtn.style.display = 'none';
                const watchBtnSpan = document.getElementById('watchBtnText');
                if (watchBtnSpan) watchBtnSpan.textContent = type === 'series' ? '📺 İzliyorum' : '🎬 İzledim';
            } else {
                watchBtn.style.display = 'none';
                watchlistBtn.style.display = 'none';
                readBtn.style.display = 'block';
                readinglistBtn.style.display = 'block';
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

        function updateListButtons(status) {
            if (status === 'to_read') status = 'readinglist';

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

        async function loadComments() {
            try {
                const response = await fetch(
                    `/api/comments.php?content_type=${currentContentType}&content_id=${currentContentId}`,
                    { credentials: 'include' }
                );
                const data = await response.json();
                const commentsList = data.comments || [];

                let html = '<div style="display: flex; flex-direction: column; gap: 20px;">';

                if (commentsList.length === 0) {
                    html += '<p style="text-align: center; color: var(--color-text-faint); padding: 20px;">Henüz yorum yapılmamış...</p>';
                } else {
                    commentsList.forEach((comment) => {
                        const isOwnComment = (typeof app !== 'undefined' && app.currentUser) ? (comment.user_id === app.currentUser.id) : false;
                        const timeAgo = (typeof app !== 'undefined') ? app.getTimeAgo(comment.created_at) : comment.created_at;
                        const safeUsername = app.escapeHtml(comment.username);
                        const safeText = app.escapeHtml(comment.comment_text);
                        // Ham metni onclick'e gömmek yerine haritada tutuyoruz (kaçış/enjeksiyon riski).
                        commentTextById[comment.id] = comment.comment_text;

                        const commentBodyInner = safeText.length > 200 ? `
                                <span id="short-${comment.id}">${safeText.substring(0, 200)}...</span>
                                <span id="full-${comment.id}" style="display: none;">${safeText}</span>
                                <button onclick="toggleCommentText(${comment.id})" style="background: none; border: none; color: var(--color-accent); cursor: pointer; padding: 0; margin-left: 5px;">Daha Fazla</button>
                            ` : safeText;
                        const commentBodyHtml = comment.has_spoiler ? `
                            <div class="spoiler-wrap" id="spoiler-${comment.id}">
                                <button type="button" class="spoiler-reveal-btn" onclick="document.getElementById('spoiler-${comment.id}').classList.add('spoiler-revealed')">🙈 Spoiler içeriyor — göstermek için dokun</button>
                                <div class="spoiler-content">${commentBodyInner}</div>
                            </div>
                        ` : commentBodyInner;

                        html += `
                    <div class="comment-item" id="comment-${comment.id}" style="background: var(--color-surface); padding: 20px; border-radius: 10px; border-left: 4px solid var(--color-accent);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <div>
                                <strong style="color: var(--color-accent);">${safeUsername}</strong>
                                <span style="color: var(--color-text-faint); font-size: 12px; margin-left: 10px;">${timeAgo}</span>
                            </div>
                        </div>

                        <div id="comment-text-${comment.id}" style="margin: 10px 0; color: var(--color-text-muted);">
                            ${commentBodyHtml}
                        </div>

                        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px;">
                            <button id="like-btn-${comment.id}" onclick="likeComment(${comment.id})" style="background: none; border: none; color: ${comment.liked_by_me ? 'var(--color-danger)' : 'var(--color-accent)'}; cursor: pointer;">
                                ${comment.liked_by_me ? '❤️' : '🤍'} <span id="like-count-${comment.id}">${comment.likes_count || 0}</span>
                            </button>
                            <button onclick="showReplyForm(${comment.id})" style="background: none; border: none; color: var(--color-accent); cursor: pointer;">
                                💬 Yanıtla
                            </button>
                            ${isOwnComment ? `
                                <button onclick="showEditModal(${comment.id})" style="background: none; border: none; color: var(--color-accent); cursor: pointer;">
                                    ✏️ Düzenle
                                </button>
                                <button onclick="deleteComment(${comment.id})" style="background: none; border: none; color: var(--color-danger); cursor: pointer;">
                                    🗑️ Sil
                                </button>
                            ` : `
                                <button onclick="showReportModal(${comment.id})" style="background: none; border: none; color: var(--color-text-faint); cursor: pointer;">
                                    🚩 Şikayet Et
                                </button>
                            `}
                        </div>


                        <div id="reply-form-${comment.id}" style="display: none; margin-top: 15px; padding: 15px; background: var(--color-surface-2); border-radius: 8px;">
                            <textarea id="reply-text-${comment.id}" placeholder="@${safeUsername} kullanıcısına yanıt yazın..." style="width: 100%; padding: 10px; border: 1px solid var(--color-border); border-radius: 5px; min-height: 80px; font-family: inherit;"></textarea>
                            <label class="spoiler-checkbox-label">
                                <input type="checkbox" id="reply-spoiler-${comment.id}"> 🙈 Bu yanıt spoiler içeriyor
                            </label>
                            <div style="display: flex; gap: 10px; margin-top: 10px;">
                                <button onclick="submitReply(${comment.id})" class="btn btn-primary" style="padding: 8px 15px;">Yanıtla</button>
                                <button onclick="hideReplyForm(${comment.id})" class="btn btn-secondary" style="padding: 8px 15px;">İptal</button>
                            </div>
                        </div>


                        ${comment.replies && comment.replies.length > 0 ? `
                            <div style="margin-top: 15px; margin-left: 20px; border-left: 2px solid var(--color-border); padding-left: 15px;">
                                ${comment.replies.map(reply => {
                            const isOwnReply = (typeof app !== 'undefined' && app.currentUser) ? (reply.user_id === app.currentUser.id) : false;
                            const replyTimeAgo = (typeof app !== 'undefined') ? app.getTimeAgo(reply.created_at) : reply.created_at;
                            const safeReplyUsername = app.escapeHtml(reply.username);
                            const safeReplyText = app.escapeHtml(reply.comment_text);
                            const replyBodyHtml = reply.has_spoiler ? `
                                <div class="spoiler-wrap" id="spoiler-reply-${reply.id}">
                                    <button type="button" class="spoiler-reveal-btn" onclick="document.getElementById('spoiler-reply-${reply.id}').classList.add('spoiler-revealed')">🙈 Spoiler içeriyor — göstermek için dokun</button>
                                    <div class="spoiler-content">${safeReplyText}</div>
                                </div>
                            ` : safeReplyText;
                            return `
                                        <div style="background: var(--color-surface-2); padding: 12px; border-radius: 8px; margin-bottom: 10px;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="color: var(--color-accent); font-size: 14px;">${safeReplyUsername}</strong>
                                                <span style="color: var(--color-text-faint); font-size: 11px; margin-left: 8px;">${replyTimeAgo}</span>
                                            </div>
                                            <div style="margin: 0; color: var(--color-text-muted); font-size: 14px;">${replyBodyHtml}</div>
                                            <div style="display: flex; gap: 10px; margin-top: 8px;">
                                                <button onclick="likeComment(${reply.id})" style="background: none; border: none; color: ${reply.liked_by_me ? 'var(--color-danger)' : 'var(--color-accent)'}; cursor: pointer; font-size: 12px;">
                                                    ${reply.liked_by_me ? '❤️' : '🤍'} ${reply.likes_count || 0}
                                                </button>
                                                ${isOwnReply ? `
                                                    <button onclick="deleteComment(${reply.id})" style="background: none; border: none; color: var(--color-danger); cursor: pointer; font-size: 12px;">🗑️ Sil</button>
                                                ` : ''}
                                            </div>
                                        </div>
                                    `;
                        }).join('')}
                            </div>
                        ` : ''}
                    </div>
                `;
                    });
                }
                html += '</div>';
                document.getElementById('comments-section').innerHTML = html;
            } catch (error) {
                console.error('Yorum yükleme hatası:', error);
                document.getElementById('comments-section').innerHTML = '<p style="color: var(--color-danger);">❌ Yorumlar yüklenemedi</p>';
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
                    loadComments();
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
                    loadComments();
                    app.toast('Yorum silindi!', 'success');
                } else {
                    app.toast(data.message || 'Yorum silinemedi', 'error');
                }
            } catch (error) {
                console.error('Yorum silme hatası:', error);
                app.toast('Hata oluştu', 'error');
            }
        }

        function toggleCommentText(commentId) {
            const shortSpan = document.getElementById(`short-${commentId}`);
            const fullSpan = document.getElementById(`full-${commentId}`);
            const btn = event.target;

            if (fullSpan.style.display === 'none') {
                shortSpan.style.display = 'none';
                fullSpan.style.display = 'inline';
                btn.textContent = 'Az Göster';
            } else {
                shortSpan.style.display = 'inline';
                fullSpan.style.display = 'none';
                btn.textContent = 'Daha Fazla';
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
                    loadComments();
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
                    const likeCountSpan = document.getElementById(`like-count-${commentId}`);
                    const likeBtn = document.getElementById(`like-btn-${commentId}`);

                    if (likeCountSpan) {
                        likeCountSpan.textContent = data.likes_count;
                    }
                    if (likeBtn) {
                        if (data.liked) {
                            likeBtn.innerHTML = `❤️ <span id="like-count-${commentId}">${data.likes_count}</span>`;
                            likeBtn.style.color = 'var(--color-danger)';
                        } else {
                            likeBtn.innerHTML = `🤍 <span id="like-count-${commentId}">${data.likes_count}</span>`;
                            likeBtn.style.color = 'var(--color-accent)';
                        }
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
                window.location.href = 'login.html';
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

        function logout() {
            if (app) {
                app.logout();
            }
            window.location.href = 'login.html';
        }
    </script>
</body>

</html>