#!/bin/sh
set -eu

fail() {
    printf '%s\n' "Startup validation failed: $1." >&2
    exit 1
}

case "${PORT:-}" in
    ''|*[!0-9]*) fail "PORT" ;;
esac

if [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then
    fail "PORT"
fi

case "$PORT" in
    18012|18013|19099) fail "PORT" ;;
esac

case "${RUN_MIGRATIONS:-false}" in
    true|false) ;;
    *) fail "RUN_MIGRATIONS" ;;
esac

php artisan app:validate-production

install -d -o www-data -g www-data \
    bootstrap/cache \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

if [ -L public/storage ]; then
    storage_link_target=$(readlink public/storage) || fail "public/storage symlink"
    if [ "$storage_link_target" != '../storage/app/public' ]; then
        fail "public/storage symlink"
    fi
elif [ -e public/storage ]; then
    fail "public/storage path"
else
    ln -s ../storage/app/public public/storage
fi

case "${FILESYSTEM_DISK:-local}" in
    local|public)
        printf '%s\n' 'Warning: local Product uploads are ephemeral on Render Free; do not upload or delete Product images before the Cloudinary checkpoint.' >&2
        ;;
esac

if [ "$RUN_MIGRATIONS" = 'true' ]; then
    su -s /bin/sh www-data -c 'php artisan migrate --force'
fi

su -s /bin/sh www-data -c 'php artisan config:cache'
su -s /bin/sh www-data -c 'php artisan route:cache'
su -s /bin/sh www-data -c 'php artisan view:cache'

apache2ctl configtest
exec apache2-foreground
