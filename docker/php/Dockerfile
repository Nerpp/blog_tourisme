FROM php:8.4-fpm-bookworm AS development

ARG XDEBUG_VERSION=3.5.3
ARG XDEBUG_SHA256=f073de91bea046106abf4d6071c963ea71e58571df6ce58948ceca89d121cb2d

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
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
    && curl --fail --location --silent --show-error \
        --retry 5 \
        --retry-delay 5 \
        --retry-all-errors \
        --output "/tmp/xdebug-${XDEBUG_VERSION}.tgz" \
        "https://xdebug.org/files/xdebug-${XDEBUG_VERSION}.tgz" \
    && echo "${XDEBUG_SHA256}  /tmp/xdebug-${XDEBUG_VERSION}.tgz" | sha256sum -c - \
    && tar -xzf "/tmp/xdebug-${XDEBUG_VERSION}.tgz" -C /tmp \
    && cd "/tmp/xdebug-${XDEBUG_VERSION}" \
    && phpize \
    && ./configure --enable-xdebug \
    && make -j"$(nproc)" \
    && make install \
    && cd / \
    && docker-php-ext-enable xdebug \
    && rm -rf "/tmp/xdebug-${XDEBUG_VERSION}.tgz" "/tmp/xdebug-${XDEBUG_VERSION}" \
    && ldd "$(php-config --extension-dir)"/*.so \
        | awk '/=> \// { print $(NF-1) }' \
        | sort -u \
        | xargs -r readlink -f \
        | sort -u \
        | xargs -r dpkg-query --search \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
        autoconf \
        dpkg-dev \
        file \
        g++ \
        gcc \
        libc6-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        make \
        pkg-config \
        re2c \
    && rm -rf /var/lib/apt/lists/* \
    && php -r '$gd = gd_info(); if (!extension_loaded("exif") || !extension_loaded("intl") || !extension_loaded("mbstring") || !extension_loaded("pdo_mysql") || !extension_loaded("zip") || !function_exists("imagejpeg") || !function_exists("imagepng") || !function_exists("imagewebp") || empty($gd["JPEG Support"]) || empty($gd["PNG Support"]) || empty($gd["WebP Support"])) { fwrite(STDERR, "Required PHP extensions and GD JPEG, PNG, and WebP support must remain available.\n"); exit(1); }' \
    && php -r 'exit(extension_loaded("xdebug") ? 0 : 1);' \
    && php --ri xdebug

COPY docker/panther-browser-version.env /tmp/panther-browser-version.env

RUN set -eu; \
    . /tmp/panther-browser-version.env; \
    architecture="$(dpkg --print-architecture)"; \
    if [ "$architecture" != "amd64" ]; then \
        echo "Architecture Panther non prise en charge : $architecture (linux64/amd64 attendu)." >&2; \
        exit 1; \
    fi; \
    chrome_archive=/tmp/chrome-linux64.zip; \
    driver_archive=/tmp/chromedriver-linux64.zip; \
    base_url="https://storage.googleapis.com/chrome-for-testing-public/${CHROME_FOR_TESTING_VERSION}/linux64"; \
    curl --fail --location --silent --show-error \
        --retry 5 \
        --retry-delay 5 \
        --retry-all-errors \
        --output "$chrome_archive" \
        "$base_url/chrome-linux64.zip"; \
    curl --fail --location --silent --show-error \
        --retry 5 \
        --retry-delay 5 \
        --retry-all-errors \
        --output "$driver_archive" \
        "$base_url/chromedriver-linux64.zip"; \
    echo "${CHROME_FOR_TESTING_CHROME_SHA256}  ${chrome_archive}" | sha256sum -c -; \
    echo "${CHROME_FOR_TESTING_DRIVER_SHA256}  ${driver_archive}" | sha256sum -c -; \
    install -d -m 0755 /opt/chrome-for-testing /tmp/chromedriver-extract; \
    unzip -q "$chrome_archive" -d /opt/chrome-for-testing; \
    unzip -q "$driver_archive" -d /tmp/chromedriver-extract; \
    install -m 0755 \
        /tmp/chromedriver-extract/chromedriver-linux64/chromedriver \
        /opt/chrome-for-testing/chromedriver; \
    install -m 0644 \
        /tmp/panther-browser-version.env \
        /opt/chrome-for-testing/version.env; \
    /opt/chrome-for-testing/chrome-linux64/chrome --version; \
    /opt/chrome-for-testing/chromedriver --version; \
    if ldd /opt/chrome-for-testing/chrome-linux64/chrome | grep -q 'not found'; then \
        echo 'Une dépendance de Chrome for Testing est introuvable.' >&2; \
        exit 1; \
    fi; \
    rm -rf \
        "$chrome_archive" \
        "$driver_archive" \
        /tmp/chromedriver-extract \
        /tmp/panther-browser-version.env

ENV XDEBUG_MODE=off \
    PANTHER_CHROME_BINARY=/opt/chrome-for-testing/chrome-linux64/chrome \
    PATH=/opt/chrome-for-testing:${PATH}

COPY docker/php/profile.d/panther-browser.sh /etc/profile.d/panther-browser.sh
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
