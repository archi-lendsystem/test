#!/usr/bin/env bash
set -euo pipefail

export PATH="/opt/cpanel/ea-php84/root/usr/bin:$HOME/.local/bin:$PATH"
APP_DIR="$(pwd -P)"
REMOTE_WEB_ROOT="${REMOTE_WEB_ROOT:-public_html}"
WEB_ROOT="$HOME/$REMOTE_WEB_ROOT"
export WEB_ROOT
PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php

echo '=== Laravel deployment ==='
echo "Application directory: $APP_DIR"
echo "Published web root: $WEB_ROOT"

# Use an existing directory only for the expected domain, not an arbitrary cPanel account.
test -f artisan
test -f composer.json
test -s .env
test -f public/index.php
test -f public/.htaccess
command -v composer >/dev/null
"$PHP_BIN" -v | head -n 1

mkdir -p storage/app/public storage/framework/cache/data \
  storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R u+rwX storage bootstrap/cache

composer install --no-dev --prefer-dist --no-interaction \
  --no-progress --optimize-autoloader
test -f vendor/autoload.php

# Clear stale route/config caches without deleting application cache data.
"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan event:clear

# Confirm that the newly deployed Laravel source really defines the health route.
"$PHP_BIN" artisan route:list --path=api/health | tee /dev/stderr | grep -Eq 'api/health'

"$PHP_BIN" artisan migrate --force --no-interaction
"$PHP_BIN" artisan storage:link
"$PHP_BIN" artisan config:cache
# Not enabling route:cache; module or closure routes may make it unsafe.

mkdir -p "$WEB_ROOT"
chmod 755 "$WEB_ROOT"

# Preserve other files in public_html; publish Laravel's public assets.
# Do not delete unrelated cPanel content or overwrite .env in the web root.
cp -R "$APP_DIR/public/." "$WEB_ROOT/"
find "$APP_DIR/public" -type d -exec chmod 755 {} +
find "$APP_DIR/public" -type f -exec chmod 644 {} +

# Keep index.html from shadowing index.php, but back it up first.
if [ -f "$WEB_ROOT/index.html" ]; then
  mkdir -p "$APP_DIR/.deploy-backups"
  mv "$WEB_ROOT/index.html" \
    "$APP_DIR/.deploy-backups/index.html.$(date +%Y%m%d%H%M%S)"
fi

# public_html is a sibling of backend-api, so adjust vendor/bootstrap paths.
"$PHP_BIN" <<'PHP'
<?php
$webRoot = getenv('WEB_ROOT');
$appDir = getcwd();
$source = file_get_contents($appDir . '/public/index.php');
if ($source === false) { fwrite(STDERR, "Cannot read public/index.php\n"); exit(1); }
$replace = [
    "__DIR__.'/../vendor/autoload.php'" => var_export($appDir . '/vendor/autoload.php', true),
    "__DIR__ . '/../vendor/autoload.php'" => var_export($appDir . '/vendor/autoload.php', true),
    "__DIR__.'/../bootstrap/app.php'" => var_export($appDir . '/bootstrap/app.php', true),
    "__DIR__ . '/../bootstrap/app.php'" => var_export($appDir . '/bootstrap/app.php', true),
    "__DIR__.'/../storage/framework/maintenance.php'" => var_export($appDir . '/storage/framework/maintenance.php', true),
    "__DIR__ . '/../storage/framework/maintenance.php'" => var_export($appDir . '/storage/framework/maintenance.php', true),
];
$updated = str_replace(array_keys($replace), array_values($replace), $source);
if ($updated === $source ||
    !str_contains($updated, $appDir . '/vendor/autoload.php') ||
    !str_contains($updated, $appDir . '/bootstrap/app.php')) {
    fwrite(STDERR, "Unsupported public/index.php layout; review deployment path mapping\n");
    exit(1);
}
$tmp = $webRoot . '/.index.php.deploy';
if (file_put_contents($tmp, $updated) === false) { exit(1); }
chmod($tmp, 0644);
if (!rename($tmp, $webRoot . '/index.php')) { exit(1); }
PHP

chmod 644 "$WEB_ROOT/index.php" "$WEB_ROOT/.htaccess"
test -f "$WEB_ROOT/index.php"
test -f "$WEB_ROOT/.htaccess"

"$PHP_BIN" artisan --version
"$PHP_BIN" artisan migrate:status

echo '=== Server-side Laravel deployment completed ==='
