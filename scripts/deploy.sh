
#!/usr/bin/env bash

set -euo pipefail

echo "=================================="
echo "Laravel deployment started"
echo "=================================="

# 1. Configure PHP environment
export PATH="/opt/cpanel/ea-php84/root/usr/bin:$HOME/.local/bin:$PATH"

# 2. Verify application
test -f artisan
test -f composer.json
test -s .env

echo "Checking PHP"
php -v

echo "Checking Composer"
composer --version

# 3. Prepare directories
echo "Preparing Laravel directories"

mkdir -p \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chmod -R u+rwX storage bootstrap/cache

# 4. Install dependencies
echo "Installing Composer dependencies"

composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --optimize-autoloader \
  -v

# 5. Clear configuration cache
echo "Clearing Laravel configuration"

php artisan config:clear

# 6. Run database migrations
echo "Running database migrations"

php artisan migrate \
  --force \
  --no-interaction

# 7. Link public storage
echo "Creating storage link"

php artisan storage:link

# 8. Optimize application
echo "Optimizing Laravel"

php artisan config:cache

# Enable after all Blade views compile correctly.
# php artisan view:cache

# 9. Verify deployment
echo "Verifying Laravel"

php artisan --version
php artisan migrate:status

echo "=================================="
echo "Laravel deployment successful"
echo "=================================="
