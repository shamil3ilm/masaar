# CompliPay Production Dockerfile
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
FROM node:20-alpine AS node-build

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
FROM php:8.2-fpm-alpine AS production

# Labels
LABEL maintainer="CompliPay Team"
LABEL description="CompliPay ZATCA E-Invoicing Compliance Platform"
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
RUN addgroup -g 1000 -S complipay \
    && adduser -u 1000 -S complipay -G complipay

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

# Copy built assets from node stage (if any)
COPY --from=node-build /app/public/build ./public/build

# Install Composer for autoload optimization
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Regenerate optimized autoloader with all files present
RUN composer dump-autoload --optimize --classmap-authoritative

# Create required directories
RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Set ownership to application user
RUN chown -R complipay:complipay /var/www/html

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

# Switch to non-root user for PHP-FPM
# Note: Nginx will run as root initially, then drop privileges
USER complipay

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
