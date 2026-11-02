
#!/usr/bin/env bash

set -euo pipefail

echo "=================================="
echo "Laravel deployment started"
echo "=================================="

# ====================================
# 1. Configure PHP environment
# ====================================
export PATH="/opt/cpanel/ea-php84/root/usr/bin:$HOME/.local/bin:$PATH"

# ====================================
# 2. Validate Laravel project
# ====================================
echo "Validating Laravel project"

test -f artisan
test -f composer.json
test -s .env

# ====================================
# 3. Check PHP and Composer
# ====================================
echo "Checking PHP"
php -v

echo "Checking Composer"
composer --version

# ====================================
# 4. Prepare Laravel directories
# ====================================
echo "Preparing Laravel directories"

mkdir -p \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chmod -R u+rwX storage bootstrap/cache

# ====================================
# 5. Install Composer dependencies
# ====================================
echo "Installing Composer dependencies"

composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader \
  -v

# ====================================
# 6. Verify Composer autoload
# ====================================
echo "Verifying Composer autoload"

if [ ! -f vendor/autoload.php ]; then
  echo "ERROR: vendor/autoload.php is missing"
  exit 1
fi

echo "Composer dependencies installed successfully"

# ====================================
# 7. Clear Laravel caches
# ====================================
echo "Clearing Laravel caches"

php artisan optimize:clear

# ====================================
# 8. Run database migrations
# ====================================
echo "Running database migrations"

php artisan migrate \
  --force \
  --no-interaction

# ====================================
# 9. Create storage link
# ====================================
echo "Creating storage link"

php artisan storage:link

# ====================================
# 10. Optimize Laravel configuration
# ====================================
echo "Optimizing Laravel"

php artisan config:cache

# Enable after all Blade issues are fixed
# php artisan view:cache

# ====================================
# 11. Verify deployment
# ====================================
echo "Verifying application"

php artisan --version
php artisan migrate:status

echo "=================================="
echo "Laravel deployment successful"
echo "=================================="
