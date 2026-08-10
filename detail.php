<?php
// Bu blok sadece <head>'deki Open Graph/Twitter meta etiketlerini ve <title>'ı
// sunucu tarafında doldurmak için var — sayfanın geri kalanı (JS ile TMDB/Google
// Books'tan veri çeken kısım) tamamen değişmeden aynı kalıyor. api/config.php
// KASITLI OLARAK kullanılmıyor: o dosya JSON Content-Type/CORS/session başlıkları
// set ediyor, bu da bu HTML sayfasını bozardı.
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .action-btn {
            padding: 12px;
            border: 2px solid var(--color-accent);
            background: var(--color-surface);
            color: var(--color-accent);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .action-btn.active {
            background: var(--color-accent);
            color: white;
        }

        .action-btn:hover {
            transform: translateY(-2px);
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
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: var(--color-accent-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
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
            padding: 15px;
            border: 2px solid var(--color-border);
            border-radius: 8px;
            resize: vertical;
            min-height: 100px;
            margin-bottom: 15px;
            font-family: inherit;
        }

        .comment-form button {
            padding: 12px 24px;
            background: var(--color-accent);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }

        .comment-form button:hover {
            background: var(--color-accent-2);
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
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>

<body>

    <nav class="navbar">
        <a href="search.html" class="logo">🎬 Sosyal Kütüphane</a>
        <div class="nav-links">
            <a href="search.html" class="nav-link">🔍 Keşfet</a>
            <a href="feed.html" class="nav-link">📰 Akış</a>
            <a href="messages.html" class="nav-link">💬 Mesajlar</a>
            <a href="profile.html" class="nav-link">👤 Profilim</a>
            <div class="nav-icon-wrap">
                <button class="nav-icon-btn" id="notifBellBtn" aria-label="Bildirimler">
                    🔔
                    <span class="nav-badge" id="notifBadge"></span>
                </button>
                <div class="nav-panel" id="notifPanel">
                    <div class="nav-panel-header">Bildirimler</div>
                    <div class="nav-panel-empty">Yükleniyor...</div>
                </div>
            </div>
            <button class="nav-icon-btn theme-toggle-btn" aria-label="Tema değiştir">🌙</button>
            <button class="logout-btn" onclick="logout()">Çıkış</button>
        </div>
    </nav>


    <div class="detail-header">
        <div class="container">
            <div id="loadingDetail" class="loading">📖 İçerik yükleniyor...</div>
            <div id="detailContent" style="display: none;">
                <div class="detail-content">
                    <div class="poster-container">
                        <img id="contentPoster" class="poster" src="" alt="Poster">
                        <div class="platform-rating" id="platformRating">
                            <div class="rating-score" id="ratingScore">-/-</div>
                            <div class="rating-count" id="ratingCount">API Puanı</div>
                        </div>
                        <div class="platform-rating" id="userPlatformRating"
                            style="margin-top: 10px; background: rgba(102, 126, 234, 0.3);">
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
            <h3>🎯 İşlemleriniz</h3>

            <div class="user-actions">
                <button id="rateBtn" class="action-btn" onclick="showRatingSection()">⭐ Puan Ver</button>
                <button id="watchBtn" class="action-btn" onclick="toggleList('watched')">
                    <span id="watchBtnText">🎬 İzledim</span>
                </button>
                <button id="watchlistBtn" class="action-btn" onclick="toggleList('watchlist')">
                    <span id="watchlistBtnText">📋 İzlenecekler</span>
                </button>
                <button id="readBtn" class="action-btn" onclick="toggleList('read')" style="display: none;">
                    <span id="readBtnText">📖 Okudum</span>
                </button>
                <button id="readinglistBtn" class="action-btn" onclick="toggleList('readinglist')"
                    style="display: none;">
                    <span id="readinglistBtnText">📚 Okunacaklar</span>
                </button>
                <button id="customListBtn" class="action-btn" onclick="showCustomListModal()">
                    <span>📁 Özel Listeye Ekle</span>
                </button>
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
                <h4>⭐ Sizin Puanınız</h4>
                <div id="userRatingDisplay"></div>
                <button class="btn btn-primary" onclick="showRatingSection()" style="margin-top: 10px;">Puanı
                    Değiştir</button>
            </div>
        </div>

        <div class="comments-section">
            <h3>💬 Yorumlar</h3>

            <div class="comment-form">
                <textarea id="commentText" placeholder="Yorumunuzu yazın..."></textarea>
                <button onclick="submitComment()">✍️ Yorum Gönder</button>
            </div>

            <div id="comments-section">
                <p style="text-align: center; color: var(--color-text-faint);">Henüz yorum yapılmamış...</p>
            </div>
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

    <script src="js/escape-html.js"></script>
    <script src="js/app.js"></script>
    <script>
        let currentContent = null;
        let currentRating = 0;
        let currentContentType = '';
        let currentContentId = '';
        const commentTextById = {};

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

        async function loadContentDetail() {
            const { type, id } = getUrlParams();

            console.log('Loading content:', type, id);

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
                    console.log('Fetching book detail for ID:', id);
                    content = await app.getBookDetail(id);
                    console.log('Book API response:', content);
                }

                if (content) {
                    currentContent = content;
                    displayContentDetail(content, type);
                    loadUserInteractions();
                    loadComments();
                    loadPlatformUserRating();
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
                credits: movie.credits
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
                credits: series.credits
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
            const watchLabel = currentContentType === 'series' ? '📺 İzliyorum' : '🎬 İzledim';

            const buttons = {
                [watchKey]: { btn: document.getElementById('watchBtn'), text: watchLabel },
                'watchlist': { btn: document.getElementById('watchlistBtn'), text: '📋 İzlenecekler' },
                'read': { btn: document.getElementById('readBtn'), text: '📖 Okudum' },
                'readinglist': { btn: document.getElementById('readinglistBtn'), text: '📚 Okunacaklar' }
            };
            Object.entries(buttons).forEach(([key, { btn, text }]) => {
                if (btn && btn.style.display !== 'none') {
                    btn.classList.remove('active');
                    btn.style.background = 'var(--color-surface)';
                    btn.style.color = 'var(--color-accent)';
                    const span = btn.querySelector('span');
                    if (span) span.textContent = text;
                }
            });
            if (status && buttons[status] && buttons[status].btn && buttons[status].btn.style.display !== 'none') {
                const activeBtn = buttons[status].btn;
                activeBtn.classList.add('active');
                activeBtn.style.background = 'var(--color-accent)';
                activeBtn.style.color = 'white';
                const span = activeBtn.querySelector('span');
                if (span) span.textContent = '✓ ' + buttons[status].text;
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
                const response = await fetch('/api/comments.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({
                        content_type: currentContentType,
                        content_id: currentContentId,
                        comment_text: commentText,
                        parent_comment_id: null
                    })
                });

                const data = await response.json();
                if (data.success) {
                    document.getElementById('commentText').value = '';
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
                        // Düzenleme modalının orijinal metne erişebilmesi için ham metni bir haritada
                        // tutuyoruz — onclick içine gömmüyoruz (tırnak kaçışı/JS enjeksiyonu riski).
                        commentTextById[comment.id] = comment.comment_text;

                        html += `
                    <div class="comment-item" id="comment-${comment.id}" style="background: var(--color-surface); padding: 20px; border-radius: 10px; border-left: 4px solid var(--color-accent);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <div>
                                <strong style="color: var(--color-accent);">${safeUsername}</strong>
                                <span style="color: var(--color-text-faint); font-size: 12px; margin-left: 10px;">${timeAgo}</span>
                            </div>
                        </div>

                        <div id="comment-text-${comment.id}" style="margin: 10px 0; color: var(--color-text-muted);">
                            ${safeText.length > 200 ? `
                                <span id="short-${comment.id}">${safeText.substring(0, 200)}...</span>
                                <span id="full-${comment.id}" style="display: none;">${safeText}</span>
                                <button onclick="toggleCommentText(${comment.id})" style="background: none; border: none; color: var(--color-accent); cursor: pointer; padding: 0; margin-left: 5px;">Daha Fazla</button>
                            ` : `${safeText}`}
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
                            ` : ''}
                        </div>


                        <div id="reply-form-${comment.id}" style="display: none; margin-top: 15px; padding: 15px; background: var(--color-surface-2); border-radius: 8px;">
                            <textarea id="reply-text-${comment.id}" placeholder="@${safeUsername} kullanıcısına yanıt yazın..." style="width: 100%; padding: 10px; border: 1px solid var(--color-border); border-radius: 5px; min-height: 80px; font-family: inherit;"></textarea>
                            <div style="display: flex; gap: 10px; margin-top: 10px;">
                                <button onclick="submitReply(${comment.id})" style="background: var(--color-accent); color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer;">Yanıtla</button>
                                <button onclick="hideReplyForm(${comment.id})" style="background: #ccc; color: #333; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer;">İptal</button>
                            </div>
                        </div>


                        ${comment.replies && comment.replies.length > 0 ? `
                            <div style="margin-top: 15px; margin-left: 20px; border-left: 2px solid var(--color-border); padding-left: 15px;">
                                ${comment.replies.map(reply => {
                            const isOwnReply = (typeof app !== 'undefined' && app.currentUser) ? (reply.user_id === app.currentUser.id) : false;
                            const replyTimeAgo = (typeof app !== 'undefined') ? app.getTimeAgo(reply.created_at) : reply.created_at;
                            const safeReplyUsername = app.escapeHtml(reply.username);
                            const safeReplyText = app.escapeHtml(reply.comment_text);
                            return `
                                        <div style="background: var(--color-surface-2); padding: 12px; border-radius: 8px; margin-bottom: 10px;">
                                            <div style="margin-bottom: 8px;">
                                                <strong style="color: var(--color-accent); font-size: 14px;">${safeReplyUsername}</strong>
                                                <span style="color: var(--color-text-faint); font-size: 11px; margin-left: 8px;">${replyTimeAgo}</span>
                                            </div>
                                            <p style="margin: 0; color: var(--color-text-muted); font-size: 14px;">${safeReplyText}</p>
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
                const response = await fetch('/api/comments.php', {
                    method: 'POST',
                    headers: app.authHeaders(),
                    credentials: 'include',
                    body: JSON.stringify({
                        content_type: currentContentType,
                        content_id: currentContentId,
                        comment_text: replyText,
                        parent_comment_id: parentCommentId
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
                console.log('Like response:', data);

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