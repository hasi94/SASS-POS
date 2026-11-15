# T-023: the local-dev application image for the Docker Compose alternative
# documented in docs/docker-dev-environment.md. This image carries the PHP
# runtime, the extensions the module host needs and Composer only - it copies
# no application source. The repository is bind-mounted at /app by
# docker-compose.yml, so local edits are reflected without a rebuild and this
# build context (infra/docker/app) stays tiny.

FROM php:8.2-cli-bookworm

# git/unzip for Composer, libicu/libzip/libpng -dev for the intl/zip/gd
# extensions built below.
RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# mbstring, openssl, fileinfo and pdo_sqlite ship built into this base image
# already. pdo_sqlite matters: the Pest suite runs on SQLite.
RUN docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        intl \
        bcmath \
        gd \
        zip \
        pcntl

# phpredis, pinned: config/database.php's REDIS_CLIENT default is `phpredis`,
# not predis.
RUN pecl install redis-6.1.0 \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app/modules/host
