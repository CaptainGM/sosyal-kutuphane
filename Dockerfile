FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS libssl-dev unzip git \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && docker-php-ext-enable opcache \
    && echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && apt-get remove -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

COPY . .
# Çok süreçli sunucu: eşzamanlı istekler birbirini beklemez.
# Canlı akış (SSE) varsayılan olarak kapalı: her açık akış bir süreci tutar (bkz. README).
ENV PHP_CLI_SERVER_WORKERS=4
ENV ENABLE_SSE=0

EXPOSE 8000
CMD php api/setup-db.php && php -S 0.0.0.0:${PORT:-8000} router.php
