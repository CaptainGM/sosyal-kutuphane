FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS libssl-dev unzip git \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && apt-get remove -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

COPY . .
EXPOSE 8000
CMD php api/setup-db.php && php -S 0.0.0.0:8000
