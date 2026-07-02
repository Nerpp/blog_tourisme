FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        chromium \
        chromium-driver \
        curl \
        default-mysql-client \
        ffmpeg \
        fonts-liberation \
        git \
        libasound2 \
        libatk-bridge2.0-0 \
        libfreetype6-dev \
        libgbm1 \
        libgtk-3-0 \
        libicu-dev \
        libjpeg62-turbo-dev \
        libnss3 \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libxss1 \
        libzip-dev \
        pkg-config \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    && php -r '$gd = gd_info(); if (!extension_loaded("exif") || !function_exists("imagejpeg") || !function_exists("imagepng") || !function_exists("imagewebp") || empty($gd["JPEG Support"]) || empty($gd["PNG Support"]) || empty($gd["WebP Support"])) { fwrite(STDERR, "GD must support JPEG, PNG and WebP; EXIF must be enabled.\n"); exit(1); }' \
    && pecl channel-update pecl.php.net \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && rm -rf /var/lib/apt/lists/*

ENV XDEBUG_MODE=off

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]