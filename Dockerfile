FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libpq-dev \
    libsodium-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        intl \
        sodium \
        opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Production OPcache config (CRITICAL for the php -S CLI server — opcache is off there by default)
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy the rest of the application
COPY . .

# Run post-install scripts
RUN COMPOSER_ALLOW_SUPERUSER=1 composer run-script post-install-cmd --no-interaction || true

# Build assets and clear cache
RUN APP_ENV=prod php bin/console cache:clear --no-debug || true
RUN APP_ENV=prod php bin/console asset-map:compile || true

EXPOSE 10000

# Start: update schema (safe, never drops data) then serve
CMD APP_ENV=prod php bin/console doctrine:schema:update --force --env=prod --no-debug && \
    APP_ENV=prod php bin/console app:create-admin --env=prod && \
    APP_ENV=prod php -S 0.0.0.0:${PORT:-10000} -t public/
