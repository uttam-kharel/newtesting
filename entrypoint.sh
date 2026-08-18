#!/bin/sh
set -e

# ---------------------------------------------------------------------------
# 1. Run database migrations.
#
#    Migrations are guarded by Laravel's migrations table, so this is safe to
#    run on every cold start. Retries give serverless Neon time to wake up.
#    (For high-traffic production apps, prefer running migrations in CI and
#    setting an env flag to skip them here.)
# ---------------------------------------------------------------------------
attempt=1
until php artisan migrate --force --no-interaction; do
    if [ "$attempt" -ge 30 ]; then
        echo "[entrypoint] Migrations failed after ${attempt} attempts." >&2
        exit 1
    fi
    echo "[entrypoint] Database not ready (attempt ${attempt}/30), retrying in 2s..." >&2
    attempt=$((attempt + 1))
    sleep 2
done

# ---------------------------------------------------------------------------
# 2. Rebuild Laravel's caches with the *runtime* environment.
#
#    Config is intentionally not cached at build time because Vercel injects
#    environment variables when the container starts. Container storage is
#    ephemeral, so regenerating on each boot is cheap and always in sync.
# ---------------------------------------------------------------------------
php artisan optimize

# ---------------------------------------------------------------------------
# 3. Hand off to FrankenPHP.
# ---------------------------------------------------------------------------
exec "$@"
