#!/usr/bin/env bash
set -euo pipefail

# Bootstrap the official PHP CLI images used by the private GitLab runner.
apt-get update -qq
apt-get install -y --no-install-recommends ca-certificates curl git unzip libicu-dev libonig-dev libzip-dev libsqlite3-dev libpng-dev
docker-php-ext-install -j2 intl mbstring pdo_mysql pdo_sqlite zip gd

installer="$(mktemp)"
trap 'rm -f -- "$installer"' EXIT
curl --fail --silent --show-error https://getcomposer.org/installer -o "$installer"
expected="$(curl --fail --silent --show-error https://composer.github.io/installer.sig)"
actual="$(php -r 'echo hash_file("sha384", $argv[1]);' "$installer")"
[[ "$actual" == "$expected" ]] || { echo 'Composer installer checksum mismatch' >&2; exit 1; }
php "$installer" --2 --install-dir=/usr/local/bin --filename=composer
composer --version
