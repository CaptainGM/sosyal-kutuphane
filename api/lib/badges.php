<?php
// Rozet tanımları ve ilerleme hesabı. Yan etkisi yok: sayılar dışarıdan verilir,
// böylece birim testleriyle veritabanı olmadan doğrulanabilir (bkz. api/badges.php).

function badgeDefinitions(): array {
    return [
        ['id' => 'first-step', 'title' => 'İlk Adım', 'description' => 'Kütüphanene ilk içeriğini ekledin', 'metric' => 'items', 'goal' => 1],
        ['id' => 'film-10', 'title' => 'Sinefil', 'description' => '10 film izledin', 'metric' => 'watched_movies', 'goal' => 10],
        ['id' => 'film-100', 'title' => 'Film Arşivcisi', 'description' => '100 film izledin', 'metric' => 'watched_movies', 'goal' => 100],
        ['id' => 'book-5', 'title' => 'Kitap Kurdu', 'description' => '5 kitap okudun', 'metric' => 'read_books', 'goal' => 5],
        ['id' => 'book-25', 'title' => 'Kütüphane Ustası', 'description' => '25 kitap okudun', 'metric' => 'read_books', 'goal' => 25],
        ['id' => 'rater-25', 'title' => 'Eleştirmen', 'description' => '25 içeriğe puan verdin', 'metric' => 'ratings', 'goal' => 25],
        ['id' => 'writer-10', 'title' => 'Yorumcu', 'description' => '10 yorum yazdın', 'metric' => 'reviews', 'goal' => 10],
        ['id' => 'social-5', 'title' => 'Sosyal Kelebek', 'description' => '5 takipçiye ulaştın', 'metric' => 'followers', 'goal' => 5],
        ['id' => 'curator-3', 'title' => 'Koleksiyoncu', 'description' => '3 özel liste oluşturdun', 'metric' => 'lists', 'goal' => 3],
    ];
}

// $counts: items, watched_movies, read_books, ratings, reviews, followers, lists anahtarlarını içerir.
function computeBadges(array $counts): array {
    $badges = [];
    foreach (badgeDefinitions() as $definition) {
        $value = max(0, (int) ($counts[$definition['metric']] ?? 0));
        $badges[] = [
            'id' => $definition['id'],
            'title' => $definition['title'],
            'description' => $definition['description'],
            'progress' => min($value, $definition['goal']),
            'goal' => $definition['goal'],
            'unlocked' => $value >= $definition['goal'],
        ];
    }
    return $badges;
}
