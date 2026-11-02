
#!/usr/bin/env bash
set -euo pipefail

echo "=================================="
echo "Laravel deployment started"
echo "=================================="

# ====================================
# 1. Configure server environment
# ====================================
export PATH="/opt/cpanel/ea-php84/root/usr/bin:$HOME/.local/bin:$PATH"

APP_DIR="$(pwd)"
WEB_ROOT="$HOME/public_html"
export WEB_ROOT

# ====================================
# 2. Validate Laravel application
# ====================================
echo "Validating application"

test -f artisan
test -f composer.json
test -s .env
test -f public/index.php
test -f public/.htaccess

php -v
composer --version

# ====================================
# 3. Prepare Laravel directories
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
# 4. Install Composer dependencies
# ====================================
echo "Installing Composer dependencies"

composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader \
  -v

test -f vendor/autoload.php

# ====================================
# 5. Clear Laravel caches
# ====================================
echo "Clearing Laravel caches"

php artisan optimize:clear

# ====================================
# 6. Run database migrations
# ====================================
echo "Running database migrations"

php artisan migrate \
  --force \
  --no-interaction

# ====================================
# 7. Create Laravel storage link
# ====================================
echo "Creating storage link"

php artisan storage:link

# ====================================
# 8. Cache Laravel configuration
# ====================================
echo "Optimizing Laravel"

php artisan config:cache

# Enable after all module views are fixed.
# php artisan view:cache

# ====================================
# 9. Prepare public_html
# ====================================
echo "Preparing domain document root"

mkdir -p "$WEB_ROOT"
chmod 755 "$WEB_ROOT"

# Backup previous website entry file if present.
mkdir -p "$APP_DIR/.deploy-backups"

if [ -f "$WEB_ROOT/index.html" ]; then
  mv "$WEB_ROOT/index.html" \
    "$APP_DIR/.deploy-backups/index.html.$(date +%Y%m%d%H%M%S)"
fi

# ====================================
# 10. Publish Laravel public files
# ====================================
echo "Publishing Laravel public files"

cp -R "$APP_DIR/public/." "$WEB_ROOT/"

# Files extracted under a restrictive umask may
# not be readable by the web server.
find "$APP_DIR/public" -type d -exec chmod 755 {} +
find "$APP_DIR/public" -type f -exec chmod 644 {} +

find "$WEB_ROOT" -type d -exec chmod 755 {} +
find "$WEB_ROOT" -type f -exec chmod 644 {} +

# ====================================
# 11. Fix Laravel index.php paths
# ====================================
echo "Configuring Laravel entry point"

php <<'PHP'
<?php

$webRoot = getenv('WEB_ROOT');
$appDir = getcwd();
$appName = basename($appDir);

$sourceFile = $appDir . '/public/index.php';
$targetFile = $webRoot . '/index.php';

$contents = file_get_contents($sourceFile);

if ($contents === false) {
    fwrite(STDERR, "Cannot read Laravel index.php\n");
    exit(1);
}

// Laravel normally looks for vendor/bootstrap one
// directory above public/. public_html is a sibling
// of the Laravel application, so adjust those paths.
$replacements = [
    "__DIR__.'/../" =>
        "__DIR__.'/../{$appName}/",

    "__DIR__ . '/../" =>
        "__DIR__ . '/../{$appName}/",
];

$updated = str_replace(
    array_keys($replacements),
    array_values($replacements),
    $contents
);

if (
    $updated === $contents ||
    !str_contains($updated, "{$appName}/vendor/autoload.php") ||
    !str_contains($updated, "{$appName}/bootstrap/app.php")
) {
    fwrite(
        STDERR,
        "Unsupported index.php format. Public entry point not updated.\n"
    );
    exit(1);
}

$tempFile = $webRoot . '/.index.php.deploy';

if (file_put_contents($tempFile, $updated) === false) {
    fwrite(STDERR, "Cannot write entry point\n");
    exit(1);
}

chmod($tempFile, 0644);

if (!rename($tempFile, $targetFile)) {
    fwrite(STDERR, "Cannot publish entry point\n");
    exit(1);
}

echo "Laravel index.php configured successfully\n";
PHP

# ====================================
# 12. Verify published files
# ====================================
echo "Verifying public website"

test -f "$WEB_ROOT/index.php"
test -f "$WEB_ROOT/.htaccess"
test -f "$APP_DIR/vendor/autoload.php"

chmod 644 "$WEB_ROOT/index.php"
chmod 644 "$WEB_ROOT/.htaccess"

# ====================================
# 13. Verify Laravel deployment
# ====================================
echo "Verifying Laravel"

php artisan --version
php artisan migrate:status

echo "=================================="
echo "Laravel deployment successful"
echo "Website: https://test.thelendsystem.com"
echo "=================================="
