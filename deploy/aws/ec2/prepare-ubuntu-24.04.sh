#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run this script as root (for example, through AWS Systems Manager)." >&2
    exit 1
fi

if [[ ! -r /etc/os-release ]] || ! grep -q '^VERSION_ID="24.04"$' /etc/os-release; then
    echo "This host setup targets Ubuntu Server 24.04 LTS." >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive
script_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
apt-get update
apt-get install --yes --no-install-recommends \
    ca-certificates certbot composer git nginx postgresql-client python3-certbot-nginx supervisor unzip \
    php8.3-bcmath php8.3-cli php8.3-curl php8.3-fpm php8.3-gd php8.3-intl \
    php8.3-mbstring php8.3-pgsql php8.3-redis php8.3-sqlite3 php8.3-xml \
    php8.3-zip

if ! id gownsea >/dev/null 2>&1; then
    useradd --system --home-dir /var/www/gownsea --create-home --shell /usr/sbin/nologin gownsea
fi
usermod --append --groups www-data gownsea

install -d -o gownsea -g www-data -m 0750 \
    /var/www/gownsea/releases \
    /var/www/gownsea/shared
install -d -o gownsea -g www-data -m 0770 \
    /var/www/gownsea/shared/storage \
    /var/www/gownsea/shared/storage/app/private \
    /var/www/gownsea/shared/storage/app/public \
    /var/www/gownsea/shared/storage/framework/cache/data \
    /var/www/gownsea/shared/storage/framework/sessions \
    /var/www/gownsea/shared/storage/framework/testing \
    /var/www/gownsea/shared/storage/framework/views \
    /var/www/gownsea/shared/storage/logs \
    /var/lib/gownsea/backups

install -o root -g root -m 0644 "${script_directory}/gownsea-queue.service" /etc/systemd/system/gownsea-queue.service
install -o root -g root -m 0644 "${script_directory}/gownsea-scheduler.service" /etc/systemd/system/gownsea-scheduler.service
install -o root -g root -m 0644 "${script_directory}/gownsea-scheduler.timer" /etc/systemd/system/gownsea-scheduler.timer
systemctl daemon-reload

echo "Base runtime installed. Attach the GOWNSEA EC2 instance profile, configure SSM, TLS, database/cache endpoints and /var/www/gownsea/shared/.env before deploying application code."
