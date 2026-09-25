#!/usr/bin/env bash
set -Eeuo pipefail
umask 0027

base=/var/www/back
revision=${1:?Usage: deploy.sh COMMIT_SHA}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid commit SHA'; exit 1; }
exec 9>"$base/deploy.lock"
flock -n 9 || { echo 'Another deployment is running'; exit 1; }
release="$base/releases/$revision-$(date -u +%Y%m%d%H%M%S)"
archive="$base/incoming/$revision.tar.gz"
test -f "$archive"
mkdir "$release"
tar --extract --gzip --file "$archive" --directory "$release" --no-same-owner
test -f "$release/artisan"
test -f "$release/composer.lock"
test ! -e "$release/.env"
ln -s "$base/shared/.env" "$release/.env"
# Replace only the newly extracted storage skeleton, never the shared data.
mv "$release/storage" "$release/storage-skeleton"
ln -s "$base/shared/storage" "$release/storage"
cd "$release"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan config:cache
php artisan route:cache
# Backup runs as root through a fixed, narrowly scoped sudo command.
sudo /usr/local/sbin/salon-backup
# The migration credential is outside the application tree and unreadable by PHP-FPM.
(
    source /home/deploy/.config/salon/migrations.env
    export DB_USERNAME DB_PASSWORD
    export APP_CONFIG_CACHE="$release/bootstrap/cache/migrations-uncached.php"
    php artisan migrate --force --no-interaction
)
previous=$(readlink -f "$base/current" || true)
ln -s "$release" "$base/current.next"
mv -Tf "$base/current.next" "$base/current"
sudo /usr/bin/systemctl reload php8.2-fpm
if ! curl --fail --silent --show-error --retry 3 --retry-delay 2 https://vps123924.serveur-vps.net/up >/dev/null; then
    if [[ -n "$previous" && -d "$previous" ]]; then
        ln -s "$previous" "$base/current.rollback"
        mv -Tf "$base/current.rollback" "$base/current"
        sudo /usr/bin/systemctl reload php8.2-fpm
    fi
    echo 'Health check failed; previous code restored where available. Database migrations were NOT reversed.' >&2
    exit 1
fi
echo "Deployed $revision"
