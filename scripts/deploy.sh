
#!/usr/bin/env bash
set -euo pipefail

echo "======================================"
echo "Laravel cPanel deployment started"
echo "======================================"

export PATH="/opt/cpanel/ea-php84/root/usr/bin:$HOME/.local/bin:$PATH"

PHP_BIN="/opt/cpanel/ea-php84/root/usr/bin/php"

APP_DIR="$(pwd -P)"
REMOTE_WEB_ROOT="${REMOTE_WEB_ROOT:-public_html}"
WEB_ROOT="$HOME/$REMOTE_WEB_ROOT"

export WEB_ROOT

echo "Application directory: $APP_DIR"
echo "Web root: $WEB_ROOT"

# ======================================
# 1. Validate application
# ======================================
echo "Validating Laravel application"

test -f artisan
test -f composer.json
test -s .env
test -f public/index.php
test -f public/.htaccess

command -v composer >/dev/null
"$PHP_BIN" -v | head -n 1

# ======================================
# 2. Prepare storage directories
# ======================================
echo "Preparing storage directories"

mkdir -p \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chmod -R u+rwX storage bootstrap/cache

# ======================================
# 3. Install Composer packages
# ======================================
echo "Installing Composer dependencies"

composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader

test -f vendor/autoload.php

# ======================================
# 4. Clear Laravel caches
# ======================================
echo "Clearing Laravel caches"

"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan event:clear


# ======================================
# 5. Verify registered API routes
# ======================================
echo "Checking API routes"

HEALTH_ROUTES="$("$PHP_BIN" artisan route:list --path=api/health)"
AUTH_ROUTES="$("$PHP_BIN" artisan route:list --path=api/v1/auth)"

echo "$HEALTH_ROUTES"
echo "$AUTH_ROUTES"

if ! grep -Fq 'api/health' <<< "$HEALTH_ROUTES"; then
    echo "ERROR: Health API route missing"
    exit 1
fi

if ! grep -Fq 'api/v1/auth' <<< "$AUTH_ROUTES"; then
    echo "ERROR: Authentication API routes missing"
    exit 1
fi

echo "Laravel API routes registered successfully"


# ======================================
# 6. Database migrations
# ======================================

# ======================================
# 6. Database migrations
# ======================================
echo "Running database migrations"

"$PHP_BIN" artisan migrate \
  --force \
  --no-interaction

echo "Database migrations completed"

# ======================================
# 7. Initial database seeding
# ======================================

RUN_INITIAL_SEEDERS="${RUN_INITIAL_SEEDERS:-false}"

if [ "$RUN_INITIAL_SEEDERS" = "true" ]; then

    echo "======================================"
    echo "Running initial database seeders"
    echo "======================================"

    "$PHP_BIN" artisan db:seed \
      --class='Modules\UserManagement\Database\Seeders\UserSeeder' \
      --force \
      --no-interaction

    echo "Initial database seeding completed"

else

    echo "Skipping database seeders"

fi

# ======================================
# 7. Laravel storage link
# ======================================
echo "Creating storage link"

"$PHP_BIN" artisan storage:link

# ======================================
# 8. Cache configuration
# ======================================
echo "Caching Laravel configuration"

"$PHP_BIN" artisan config:cache

# ======================================
# 9. Prepare web root
# ======================================
echo "Preparing public web directory"

mkdir -p "$WEB_ROOT"
chmod 755 "$WEB_ROOT"

# Protect any environment file already
# present in the published directory.
if [ -f "$WEB_ROOT/.env" ]; then
  echo "WARNING: .env exists in the web root"
  echo "Remove it securely from the web root"
  exit 1
fi

# ======================================
# 10. Publish public files
# ======================================
echo "Publishing Laravel public files"

cp -R "$APP_DIR/public/." "$WEB_ROOT/"

# Do not leave an old static index.html
# that could override Laravel's index.php.
if [ -f "$WEB_ROOT/index.html" ]; then
  mkdir -p "$APP_DIR/.deploy-backups"

  mv "$WEB_ROOT/index.html" \
    "$APP_DIR/.deploy-backups/index.html.$(date +%Y%m%d%H%M%S)"
fi

# ======================================
# 11. Configure Laravel index.php
# ======================================
echo "Updating Laravel entry-point paths"

"$PHP_BIN" <<'PHP'
<?php

$webRoot = getenv('WEB_ROOT');
$appDir = getcwd();

$sourceFile = $appDir . '/public/index.php';
$targetFile = $webRoot . '/index.php';

$contents = file_get_contents($sourceFile);

if ($contents === false) {
    fwrite(STDERR, "Cannot read public/index.php\n");
    exit(1);
}

$replacements = [
    "__DIR__.'/../vendor/autoload.php'" =>
        var_export($appDir . '/vendor/autoload.php', true),

    "__DIR__ . '/../vendor/autoload.php'" =>
        var_export($appDir . '/vendor/autoload.php', true),

    "__DIR__.'/../bootstrap/app.php'" =>
        var_export($appDir . '/bootstrap/app.php', true),

    "__DIR__ . '/../bootstrap/app.php'" =>
        var_export($appDir . '/bootstrap/app.php', true),

    "__DIR__.'/../storage/framework/maintenance.php'" =>
        var_export($appDir . '/storage/framework/maintenance.php', true),

    "__DIR__ . '/../storage/framework/maintenance.php'" =>
        var_export($appDir . '/storage/framework/maintenance.php', true),
];

$updated = str_replace(
    array_keys($replacements),
    array_values($replacements),
    $contents
);

if (
    $updated === $contents ||
    !str_contains(
        $updated,
        $appDir . '/vendor/autoload.php'
    ) ||
    !str_contains(
        $updated,
        $appDir . '/bootstrap/app.php'
    )
) {
    fwrite(
        STDERR,
        "Unsupported Laravel index.php format\n"
    );
    exit(1);
}

$tempFile = $webRoot . '/.index.php.deploy';

if (file_put_contents($tempFile, $updated) === false) {
    fwrite(STDERR, "Cannot write index.php\n");
    exit(1);
}

chmod($tempFile, 0644);

if (!rename($tempFile, $targetFile)) {
    fwrite(STDERR, "Cannot publish index.php\n");
    exit(1);
}

echo "Laravel entry point configured\n";
PHP

# ======================================
# 12. Fix web file permissions
# ======================================
echo "Setting web permissions"

find "$APP_DIR/public" -type d -exec chmod 755 {} +
find "$APP_DIR/public" -type f -exec chmod 644 {} +

find "$WEB_ROOT" -type d -exec chmod 755 {} +
find "$WEB_ROOT" -type f -exec chmod 644 {} +

chmod 644 "$WEB_ROOT/index.php"
chmod 644 "$WEB_ROOT/.htaccess"

# ======================================
# 13. Verify deployment
# ======================================
echo "Verifying deployment"

test -f "$WEB_ROOT/index.php"
test -f "$WEB_ROOT/.htaccess"
test -f "$APP_DIR/vendor/autoload.php"

"$PHP_BIN" artisan --version
"$PHP_BIN" artisan migrate:status

echo "======================================"
echo "Laravel server deployment completed"
echo "======================================"
