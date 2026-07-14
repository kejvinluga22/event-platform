#!/bin/sh
set -e

# Railway injects PORT at runtime; Apache's default config only knows 80.
PORT="${PORT:-80}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/*.conf

exec "$@"
