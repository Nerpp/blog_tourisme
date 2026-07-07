FROM php:8.4-fpm-bookworm AS development

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

FROM development AS ci

ARG CHROME_FOR_TESTING_VERSION=145.0.7632.117
ARG CHROME_HEADLESS_SHELL_SHA256=187692424c038e5481495f2475e5fcf1b4125e33df9ae39ba86bd0a3d8569acf
ARG CHROMEDRIVER_SHA256=7f397fa71614b2d97dcb06a87f3297c91725042803654d6c7fedcd10387a5df8

RUN curl -fsSLo /tmp/chrome-headless-shell-linux64.zip \
        "https://storage.googleapis.com/chrome-for-testing-public/${CHROME_FOR_TESTING_VERSION}/linux64/chrome-headless-shell-linux64.zip" \
    && curl -fsSLo /tmp/chromedriver-linux64.zip \
        "https://storage.googleapis.com/chrome-for-testing-public/${CHROME_FOR_TESTING_VERSION}/linux64/chromedriver-linux64.zip" \
    && echo "${CHROME_HEADLESS_SHELL_SHA256}  /tmp/chrome-headless-shell-linux64.zip" | sha256sum -c - \
    && echo "${CHROMEDRIVER_SHA256}  /tmp/chromedriver-linux64.zip" | sha256sum -c - \
    && mkdir -p /opt/chrome-for-testing /opt/chromedriver \
    && unzip -q /tmp/chrome-headless-shell-linux64.zip -d /opt/chrome-for-testing \
    && unzip -q /tmp/chromedriver-linux64.zip -d /tmp/chromedriver \
    && install -m 0755 /tmp/chromedriver/chromedriver-linux64/chromedriver /opt/chromedriver/chromedriver \
    && ln -s /opt/chromedriver/chromedriver /usr/local/bin/chromedriver \
    && /opt/chrome-for-testing/chrome-headless-shell-linux64/chrome-headless-shell --version \
    && /opt/chromedriver/chromedriver --version \
    && rm -rf /tmp/chrome-headless-shell-linux64.zip /tmp/chromedriver-linux64.zip /tmp/chromedriver

COPY --chmod=0755 docker/php/chrome-for-testing /usr/local/bin/chrome-for-testing
