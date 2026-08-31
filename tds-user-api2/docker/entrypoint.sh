#!/bin/sh
# docker/entrypoint.sh - Symfony container startup

set -e

echo "Starting Symfony application..."

# Wait for database
if [ -n "$DATABASE_URL" ]; then
    echo "Waiting for database..."
    # Use Doctrine DBAL to wait until the configured database accepts queries
    until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
        sleep 2
    done
    echo "Database is ready."
fi

# Run migrations in production
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "Running database migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

# Ensure cache is warm
php bin/console cache:warmup --env="${APP_ENV:-prod}" 2>/dev/null || true

# Fix permissions on var directory
chown -R www-data:www-data var/

echo "Application ready."

exec "$@"