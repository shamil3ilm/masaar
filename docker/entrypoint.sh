#!/bin/sh
# CompliPay Docker Entrypoint Script
# Initializes the application for production deployment

set -e

echo "=============================================="
echo "CompliPay - ZATCA E-Invoicing Platform"
echo "=============================================="
echo "Starting container initialization..."

# Wait for database to be ready
wait_for_db() {
    echo "Waiting for database connection..."
    max_attempts=30
    attempt=0

    while [ $attempt -lt $max_attempts ]; do
        if php artisan db:monitor --databases=mysql 2>/dev/null; then
            echo "Database connection established."
            return 0
        fi

        attempt=$((attempt + 1))
        echo "Attempt $attempt/$max_attempts - Database not ready, waiting..."
        sleep 2
    done

    echo "WARNING: Could not verify database connection after $max_attempts attempts."
    echo "Proceeding anyway - application may fail if database is not available."
}

# Run migrations if AUTO_MIGRATE is enabled
run_migrations() {
    if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
        echo "Running database migrations..."
        php artisan migrate --force --no-interaction
        echo "Migrations completed."
    else
        echo "AUTO_MIGRATE is not enabled. Skipping migrations."
        echo "Run 'php artisan migrate --force' manually if needed."
    fi
}

# Cache configuration for production
cache_config() {
    echo "Caching configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    echo "Configuration cached."
}

# Clear old caches (useful for updates)
clear_old_caches() {
    echo "Clearing old caches..."
    php artisan cache:clear 2>/dev/null || true
    php artisan config:clear 2>/dev/null || true
    php artisan route:clear 2>/dev/null || true
    php artisan view:clear 2>/dev/null || true
}

# Generate application key if not set
check_app_key() {
    if [ -z "${APP_KEY}" ]; then
        echo "ERROR: APP_KEY is not set!"
        echo "Generate one with: php artisan key:generate --show"
        exit 1
    fi
}

# Verify required environment variables
check_required_env() {
    echo "Verifying required environment variables..."

    missing_vars=""

    # Check critical variables
    [ -z "${APP_KEY}" ] && missing_vars="${missing_vars} APP_KEY"
    [ -z "${DB_HOST}" ] && missing_vars="${missing_vars} DB_HOST"
    [ -z "${DB_DATABASE}" ] && missing_vars="${missing_vars} DB_DATABASE"

    if [ -n "$missing_vars" ]; then
        echo "ERROR: Missing required environment variables:${missing_vars}"
        echo "Please set these variables and restart the container."
        exit 1
    fi

    echo "All required environment variables are set."
}

# Create storage directory structure
setup_storage() {
    echo "Setting up storage directories..."

    mkdir -p storage/framework/cache/data
    mkdir -p storage/framework/sessions
    mkdir -p storage/framework/views
    mkdir -p storage/logs
    mkdir -p storage/app/public
    mkdir -p bootstrap/cache

    # Set permissions (if running as root during setup)
    if [ "$(id -u)" = "0" ]; then
        chown -R complipay:complipay storage bootstrap/cache
        chmod -R 775 storage bootstrap/cache
    fi

    echo "Storage directories configured."
}

# Create storage link
create_storage_link() {
    if [ ! -L public/storage ]; then
        echo "Creating storage symlink..."
        php artisan storage:link 2>/dev/null || true
    fi
}

# Main initialization sequence
main() {
    check_required_env
    check_app_key
    setup_storage

    # Wait for dependencies
    wait_for_db

    # Prepare application
    clear_old_caches
    run_migrations
    cache_config
    create_storage_link

    echo "=============================================="
    echo "Container initialization complete!"
    echo "Starting application services..."
    echo "=============================================="

    # Execute the main command (supervisord)
    exec "$@"
}

# Run main function with all arguments
main "$@"
