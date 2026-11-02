
#!/usr/bin/env bash
set -euo pipefail

export PATH="/opt/cpanel/ea-php84/root/usr/bin:$HOME/.local/bin:$PATH"

mkdir -p \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chmod -R u+rwX storage bootstrap/cache

composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --optimize-autoloader

php artisan config:clear
php artisan migrate --force
php artisan storage:link
php artisan config:cache

# Enable only when all Blade views compile.
# php artisan view:cache

php artisan --version
