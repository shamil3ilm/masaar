# Production Dockerfile
# ZATCA E-Invoicing Compliance Platform
# Laravel 12 / PHP 8.2+

# =============================================================================
# Stage 1: Composer Dependencies
# =============================================================================
FROM composer:2.7 AS composer-deps

WORKDIR /app

# Copy composer files first for better caching
COPY composer.json composer.lock ./

# Install dependencies without dev packages
# Note: We use --no-scripts as scripts may need the full app context
RUN composer install \
    --no-dev \
    --no-scripts \
    --ignore-platform-reqs \
    --prefer-dist

# =============================================================================
# Stage 2: Frontend Assets (if needed)
# =============================================================================
# Node 20 left support in April 2026, so this stage compiled the assets a
# deployment serves on an unsupported runtime. 24 is the current LTS and the
# version the build is actually exercised on - locally and, since this change,
# in CI. DockerRuntimeVersionTest pins the three together.
FROM node:24-alpine AS node-build

WORKDIR /app

# Copy package files
COPY package.json package-lock.json* ./

# Install all dependencies (including dev for build tools)
RUN if [ -f package-lock.json ]; then npm ci; elif [ -f package.json ]; then npm install; fi

# Copy source files for building
COPY resources/ ./resources/
COPY vite.config.js* ./
COPY tailwind.config.js* ./
COPY postcss.config.js* ./

# Build assets (skip if no vite config)
RUN if [ -f vite.config.js ]; then npm run build; else mkdir -p public/build; fi

# =============================================================================
# Stage 3: Production Image
# =============================================================================
# The PHP version must satisfy composer.json, which requires ^8.4. This said
# 8.2, and because the composer stage above installs with
# --ignore-platform-reqs the mismatch could not stop the build: the image
# built, the vulnerability scan passed, the container started, and the first
# request to reach PHP died in vendor/composer/platform_check.php with
# "Your Composer dependencies require a PHP version >= 8.4.1". Nothing in CI
# ran the image then, so nothing said so; the "Compose stack starts" job now
# boots it and asks its readiness probe.
#
# DockerRuntimeVersionTest now pins this line to composer.json and to ci.yml,
# so the three cannot disagree again. See the note further down for why there
# is no build-time check as well.
FROM php:8.4-fpm-alpine AS production

# Labels
LABEL maintainer="Platform team"
LABEL description="E-invoicing compliance platform"
LABEL version="1.0.0"

# Environment variables
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr
ENV OPCACHE_ENABLE=1
ENV OPCACHE_VALIDATE_TIMESTAMPS=0
ENV OPCACHE_MAX_ACCELERATED_FILES=20000
ENV OPCACHE_MEMORY_CONSUMPTION=256
ENV OPCACHE_JIT_BUFFER_SIZE=100M

# Install system dependencies
RUN apk add --no-cache \
    # Required for GD
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    # Required for ZIP
    libzip-dev \
    zip \
    unzip \
    # Required for XML/DOM
    libxml2-dev \
    # Required for intl
    icu-dev \
    # Required for OpenSSL/ECDSA signing
    openssl \
    openssl-dev \
    # Required for GMP
    gmp-dev \
    # Required for mbstring (oniguruma)
    oniguruma-dev \
    # Supervisor for queue workers
    supervisor \
    # Nginx for serving
    nginx \
    # Curl for healthchecks
    curl \
    # mysqldump, for backup:run. Alpine's is MariaDB's client, which dumps a
    # MySQL 8 server; nothing in the image could take a backup without it.
    mysql-client \
    # Git for composer
    git \
    # Linux headers for some extensions
    linux-headers

# Install PHP extensions (dom is part of xml, don't install separately)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        bcmath \
        intl \
        opcache \
        gmp \
        pcntl \
        sockets

# Install Redis extension
RUN apk add --no-cache pcre-dev $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del pcre-dev $PHPIZE_DEPS

# Configure OPcache for production
RUN echo "opcache.enable=${OPCACHE_ENABLE}" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=${OPCACHE_VALIDATE_TIMESTAMPS}" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=${OPCACHE_MAX_ACCELERATED_FILES}" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=${OPCACHE_MEMORY_CONSUMPTION}" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit_buffer_size=${OPCACHE_JIT_BUFFER_SIZE}" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit=1255" >> /usr/local/etc/php/conf.d/opcache.ini

# PHP production settings
RUN echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time=60" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "upload_max_filesize=64M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size=64M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "expose_php=Off" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "display_errors=Off" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "log_errors=On" >> /usr/local/etc/php/conf.d/custom.ini

# Create application user
RUN addgroup -g 1000 -S app \
    && adduser -u 1000 -S app -G app

# php-fpm serves requests as app: the user that owns storage and runs the
# queue workers. Its pool defaulted to the base image's www-data, which owns
# nothing here, so a request could not write a log line, a compiled view or
# a session - and the first request to log an error threw while logging it.
# The grep fails the build if the stock pool file ever stops matching.
RUN sed -i -e 's/^user = www-data$/user = app/' -e 's/^group = www-data$/group = app/' \
        /usr/local/etc/php-fpm.d/www.conf \
    && grep -q '^user = app$' /usr/local/etc/php-fpm.d/www.conf \
    && grep -q '^group = app$' /usr/local/etc/php-fpm.d/www.conf

# Set working directory
WORKDIR /var/www/html

# Copy Nginx configuration
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Copy Supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy application files (as root first for composer operations)
COPY . .

# Copy Composer dependencies from build stage
COPY --from=composer-deps /app/vendor ./vendor

# There is deliberately no platform check here.
#
# A `RUN php vendor/composer/platform_check.php` line stood here and could
# only ever fail: composer does not generate that file when it installs with
# --ignore-platform-reqs, which the composer stage above does and should,
# because that stage only downloads packages. The build failed on a missing
# input file - and had it somehow found one, it would have been checking
# nothing.
#
# What catches the mistake it was written for is
# tests/Feature/Architecture/DockerRuntimeVersionTest, which compares the
# `FROM php:` line above against composer.json and ci.yml and runs in the
# test job on every push. That is where the 8.2-against-^8.4 mismatch was
# found.
#
# What nothing checks is narrower: whether the php:8.4-fpm-alpine tag resolves
# to a patch below the 8.4.1 the lock requires. `composer check-platform-reqs`
# would answer that - it reads installed.json, so --ignore-platform-reqs does
# not blind it - but composer is not in this stage, and adding it to chase a
# window this narrow is not worth a third unverifiable build step.

# Copy built assets from node stage (if any)
COPY --from=node-build /app/public/build ./public/build

# Install Composer for autoload optimization
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Regenerate optimized autoloader with all files present
# Use --no-scripts to avoid running artisan commands that need bootstrap
RUN composer dump-autoload --optimize --classmap-authoritative --no-scripts

# Create required directories
RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# The pages nginx serves when php-fpm cannot answer (public/50x.html and
# 50x.json), written from the brand this image is built with. The container
# writes them again as it starts (entrypoint.sh), from the deployment's own.
#
# APP_ENV=build for this command alone: a build has no .env, and the
# production guards in AppServiceProvider check a deployment's settings,
# which a build does not have - they would refuse to boot on the defaults.
RUN APP_ENV=build php artisan platform:static-errors \
    && rm -f storage/framework/views/*.php

# Set ownership to application user
RUN chown -R app:app /var/www/html

# Set proper permissions
RUN chmod -R 775 storage bootstrap/cache

# Remove unnecessary files for production
RUN rm -rf \
    .git \
    .github \
    tests \
    docker \
    docs \
    .env.example \
    .editorconfig \
    .gitattributes \
    .gitignore \
    phpunit.xml \
    README.md \
    CHANGELOG.md \
    CONTRIBUTING.md \
    SECURITY.md \
    SUPPORT.md \
    TERMS.md \
    LICENSE

# Expose port
EXPOSE 80

# Healthcheck
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/api/health || exit 1

# Entrypoint script
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# No USER directive, deliberately.
#
# `USER app` stood here with a comment saying nginx would run as root and
# drop privileges - which that line made impossible. A process cannot acquire
# a privilege it was not started with, and everything in this image is built
# on supervisord having it:
#
#   supervisord.conf declares [supervisord] user=root, and supervisord started
#   as a non-root user refuses to run at all rather than pretend it dropped;
#   nginx and php-fpm are declared with no user= because their masters are
#   meant to be root - nginx to bind :80 and open /var/log/nginx, php-fpm to
#   hand its pool to www-data;
#   the four queue workers carry user=app, which is the per-program drop
#   that only works when supervisord is root;
#   entrypoint.sh guards its chown with `if [ "$(id -u)" = "0" ]`, written for
#   a root start.
#
# So the container could not have served a request, and nothing noticed
# because nothing ran the image - the deployment guide installed onto a host
# directly, and the image job only built and scanned. The `nginx -t` step
# added in the same change as this comment is what found it: nginx could not
# open its error log as uid 1000. CI's "Compose stack starts" job now runs the
# image end to end, and found four more faults the first time it did.
#
# Privileges are still dropped, by the thing designed to drop them: nginx
# workers run as `user nginx` from nginx.conf, php-fpm's pool runs as app,
# and so do the queue workers. Only the supervisord and master processes are
# root.
#
# Running the whole container rootless is the better end state - nginx on 8080
# with no user directive, no user=root in supervisord, the published port
# remapped. The stack job is where that change would be proven.

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
