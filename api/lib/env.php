<?php
// .env yükleyici (basit satır parser, harici paket yok). Ortamda zaten tanımlı değişkenlerin üzerine yazmaz;
// bu yüzden Render gibi bir barındırıcıdaki gerçek ortam değişkenleri her zaman önceliklidir.
function loadDotEnv(string $path): void {
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}
