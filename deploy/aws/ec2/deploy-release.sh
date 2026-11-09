#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run this script as root through AWS Systems Manager." >&2
    exit 1
fi

if [[ "$#" -ne 2 ]]; then
    echo "Usage: $0 <built-source-directory> <release-id>" >&2
    exit 2
fi

source_dir="$(realpath "$1")"
release_id="$2"
release_root=/var/www/gownsea/releases
release_dir="${release_root}/${release_id}"

if [[ ! "$release_id" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo "Release ID may contain only letters, numbers, dot, underscore and dash." >&2
    exit 2
fi

if [[ ! -f "${source_dir}/artisan" || ! -f "${source_dir}/public/build/manifest.json" ]]; then
    echo "Source directory must contain artisan and a production Vite build." >&2
    exit 1
fi

if [[ ! -r /var/www/gownsea/shared/.env ]]; then
    echo "Create /var/www/gownsea/shared/.env from the protected production secret store first." >&2
    exit 1
fi

if [[ -e "$release_dir" ]]; then
    echo "Release directory already exists: ${release_dir}" >&2
    exit 1
fi

install -d -o www-data -g www-data -m 0750 "$release_dir"
tar -C "$source_dir" \
    --exclude='./.env' \
    --exclude='./.env.*' \
    --exclude='./.git' \
    --exclude='./node_modules' \
    --exclude='./storage' \
    --exclude='./tests' \
    --exclude='./test-results' \
    --exclude='./playwright-report' \
    --exclude='./vendor' \
    -cf - . | tar -C "$release_dir" -xf -
ln -s /var/www/gownsea/shared/.env "${release_dir}/.env"
ln -s /var/www/gownsea/shared/storage "${release_dir}/storage"
chown -R www-data:www-data "$release_dir"
chmod -R g+rwX "${release_dir}/bootstrap/cache"

runuser -u www-data -- env COMPOSER_HOME=/var/www/gownsea/shared/composer composer \
    --working-dir="$release_dir" install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction

if [[ -L /var/www/gownsea/current ]]; then
    runuser -u www-data -- php /var/www/gownsea/current/artisan down --retry=60
else
    runuser -u www-data -- php "${release_dir}/artisan" down --retry=60
fi
runuser -u www-data -- php "${release_dir}/artisan" migrate --force
runuser -u www-data -- php "${release_dir}/artisan" optimize

ln -sfn "$release_dir" /var/www/gownsea/current.next
mv -Tf /var/www/gownsea/current.next /var/www/gownsea/current
runuser -u www-data -- php /var/www/gownsea/current/artisan up

if systemctl list-unit-files gownsea-queue.service --no-legend | grep -q '^gownsea-queue.service'; then
    systemctl restart gownsea-queue.service
fi
systemctl reload nginx

echo "Deployed release ${release_id}. Verify /up, queue health, scheduler activity and S3 backup before customer traffic."
