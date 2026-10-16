<?php
// Demo hesapların örnek kütüphanesi: zevk eşleşmesi, rozetler ve özet gibi özellikler boş görünmesin diye
// kurulumda (php api/setup-db.php) bir kez eklenir. Yalnızca demo kullanıcılar (ahmet, ayse, test) etkilenir ve
// her kullanıcı için tek sefer çalışır (demo_library_seeded işareti); gerçek kullanıcı verisine dokunulmaz.
// Başlık, afiş yolu ve türler TMDB'den (tr-TR) alınan gerçek değerlerdir; TMDB anahtarı gerekmez.

const DEMO_TITLES = [
    'movie:27205' => ['title' => 'Başlangıç', 'poster' => '/xn0Kcg4e6p0mLxVS3nAWhNmW2Ni.jpg', 'date' => '2010-07-15', 'genres' => [[28, 'Aksiyon'], [878, 'Bilim-Kurgu'], [80, 'Suç']]],
    'movie:157336' => ['title' => 'Yıldızlararası', 'poster' => '/xbiycuc84TrieEWwkkuH2hoEa9S.jpg', 'date' => '2014-11-04', 'genres' => [[12, 'Macera'], [18, 'Dram'], [878, 'Bilim-Kurgu']]],
    'movie:155' => ['title' => 'Kara Şövalye', 'poster' => '/7IPCEr7ifdH5CtU97QG7XgAAtOp.jpg', 'date' => '2008-07-16', 'genres' => [[28, 'Aksiyon'], [53, 'Gerilim'], [80, 'Suç']]],
    'movie:550' => ['title' => 'Dövüş Kulübü', 'poster' => '/yjMuqAyJUoQZGWsZ0vZuYj5inAR.jpg', 'date' => '1999-10-15', 'genres' => [[18, 'Dram'], [53, 'Gerilim']]],
    'movie:496243' => ['title' => 'Parazit', 'poster' => '/nx7TmJDMkgyBc09DVo5ze52Wt3F.jpg', 'date' => '2019-05-30', 'genres' => [[35, 'Komedi'], [53, 'Gerilim'], [18, 'Dram']]],
    'movie:244786' => ['title' => 'Whiplash', 'poster' => '/q3Ev5vIC3fklycV9kfpVIdbRe14.jpg', 'date' => '2014-10-10', 'genres' => [[18, 'Dram'], [10402, 'Müzik'], [53, 'Gerilim']]],
    'movie:438631' => ['title' => 'Dune: Çöl Gezegeni', 'poster' => '/roeYIqWHyVHcjWDk6SOaepNEk0y.jpg', 'date' => '2021-09-15', 'genres' => [[878, 'Bilim-Kurgu'], [12, 'Macera']]],
    'movie:129' => ['title' => 'Ruhların Kaçışı', 'poster' => '/xvOEOMCzfV8qXkd1n1btZ8q4Psd.jpg', 'date' => '2001-07-20', 'genres' => [[16, 'Animasyon'], [10751, 'Aile'], [14, 'Fantastik']]],
    'movie:1124' => ['title' => 'Prestij', 'poster' => '/wiSuje8hdVuwM0pvhtSFirCHmJF.jpg', 'date' => '2006-10-19', 'genres' => [[18, 'Dram'], [9648, 'Gizem'], [878, 'Bilim-Kurgu']]],
    'movie:98' => ['title' => 'Gladyatör', 'poster' => '/aDb548BOkFfI4nFm0kx8A3Ezh7H.jpg', 'date' => '2000-05-04', 'genres' => [[28, 'Aksiyon'], [18, 'Dram'], [12, 'Macera']]],
    'series:1396' => ['title' => 'Breaking Bad', 'poster' => '/anFx9aTOOYqgS3v7x3R84Kz67ly.jpg', 'date' => '2008-01-20', 'genres' => [[18, 'Dram'], [80, 'Suç']]],
    'series:70523' => ['title' => 'Dark', 'poster' => '/iZjXCicy63skS2hov8ZcemRvGj0.jpg', 'date' => '2017-12-01', 'genres' => [[80, 'Suç'], [18, 'Dram'], [10765, 'Bilim Kurgu & Fantazi'], [9648, 'Gizem']]],
];

// [içerik, durum, puan]. Ahmet ve Ayşe bilerek hem uyuşan hem ayrışan puanlar içerir (Dövüş Kulübü, Başlangıç).
const DEMO_LIBRARIES = [
    'ahmet' => [
        ['movie:27205', 'watched', 9], ['movie:157336', 'watched', 10], ['movie:155', 'watched', 10], ['movie:550', 'watched', 8],
        ['movie:496243', 'watched', 9], ['movie:244786', 'watched', 9], ['movie:438631', 'watched', 8], ['movie:1124', 'watchlist', null],
        ['series:1396', 'watched', 10], ['series:70523', 'watching', 9],
    ],
    'ayse' => [
        ['movie:496243', 'watched', 10], ['movie:129', 'watched', 9], ['movie:157336', 'watched', 9], ['movie:98', 'watched', 7],
        ['movie:550', 'watched', 3], ['movie:27205', 'watched', 6], ['movie:438631', 'watchlist', null], ['movie:1124', 'watchlist', null],
        ['series:70523', 'watched', 10], ['series:1396', 'watched', 7],
    ],
    'test' => [
        ['movie:129', 'watched', 9], ['movie:496243', 'watched', 8], ['movie:98', 'watched', 9], ['movie:155', 'watched', 7],
        ['movie:244786', 'watched', 8], ['series:1396', 'watched', 9],
    ],
];

function demoContentId($db, string $type, int $tmdbId, array $title): int {
    $collection = $type === 'series' ? 'series' : 'movies';
    $genres = array_map(static fn (array $g): array => ['id' => $g[0], 'name' => $g[1]], $title['genres']);
    $fields = [
        '_id' => nextSequence($db, $collection),
        'tmdb_id' => $tmdbId,
        'title' => $title['title'],
        'poster_path' => $title['poster'],
        'overview' => null,
        'genres' => $genres,
        'created_at' => new MongoDB\BSON\UTCDateTime(),
    ];
    $fields[$type === 'series' ? 'first_air_date' : 'release_date'] = $title['date'];

    $db->$collection->updateOne(['tmdb_id' => $tmdbId], ['$setOnInsert' => $fields], ['upsert' => true]);
    return (int) $db->$collection->findOne(['tmdb_id' => $tmdbId])['_id'];
}

// Eklenen durum sayısını döndürür. Zaten kütüphanede olan içeriğin durumu ve puanı değiştirilmez.
function seedDemoLibraries($db): int {
    $inserted = 0;
    foreach (DEMO_LIBRARIES as $username => $items) {
        $user = $db->users->findOne(['username' => $username], ['projection' => ['demo_library_seeded' => 1]]);
        if (!$user || !empty($user['demo_library_seeded'])) {
            continue;
        }

        foreach ($items as [$ref, $status, $rating]) {
            [$type, $tmdbId] = explode(':', $ref);
            $contentId = demoContentId($db, $type, (int) $tmdbId, DEMO_TITLES[$ref]);
            $statusCollection = $type === 'series' ? 'user_series_status' : 'user_movie_status';
            $idField = $type === 'series' ? 'series_id' : 'movie_id';

            if ($db->$statusCollection->findOne(['user_id' => $user['_id'], $idField => $contentId])) {
                continue;
            }
            $db->$statusCollection->insertOne([
                '_id' => nextSequence($db, $statusCollection),
                'user_id' => $user['_id'],
                $idField => $contentId,
                'status' => $status,
                'rating' => $rating,
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
            $inserted++;
        }
        $db->users->updateOne(['_id' => $user['_id']], ['$set' => ['demo_library_seeded' => true]]);
    }
    return $inserted;
}
