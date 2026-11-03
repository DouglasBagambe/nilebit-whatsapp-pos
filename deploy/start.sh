#!/usr/bin/env bash
set -euo pipefail
: "${APP_KEY_BASE64:?Set the shared encryption key in Render}"
export APP_KEY="base64:${APP_KEY_BASE64}"
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-}}"
if [[ ! "$APP_URL" =~ ^https:// ]]; then
  echo 'A canonical HTTPS APP_URL is required.' >&2
  exit 1
fi
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
if [[ "${1:-web}" == "migrate" ]]; then
  exec php artisan migrate --force --no-interaction
elif [[ "${1:-web}" == "worker" ]]; then
  php artisan whatsapp:recover --no-interaction
  exec php artisan queue:work --sleep=2 --tries=30 --timeout=60 --max-time=3600 --no-interaction
elif [[ "${1:-web}" == "web" ]]; then
  sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
  sed -i "s/VirtualHost \*:10000/VirtualHost *:${PORT:-10000}/" /etc/apache2/sites-available/000-default.conf
  exec apache2-foreground
fi
echo 'Unknown service mode.' >&2
exit 1
