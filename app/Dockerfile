FROM php:8.3-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl unzip zip ca-certificates \
    libicu-dev libzip-dev libxml2-dev libonig-dev \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libcurl4-openssl-dev nodejs npm \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" intl mbstring pdo_mysql zip gd xml dom opcache curl bcmath \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/begreen-entrypoint
RUN chmod +x /usr/local/bin/begreen-entrypoint

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts

COPY package.json package-lock.json webpack.config.js ./
RUN if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; fi

COPY . .

RUN composer dump-autoload --optimize --no-interaction \
 && if [ -f package-lock.json ]; then npm run build && rm -rf /opt/begreen-build && mkdir -p /opt/begreen-build && cp -a public/build/. /opt/begreen-build/; fi

ENTRYPOINT ["begreen-entrypoint"]
CMD ["php-fpm"]
