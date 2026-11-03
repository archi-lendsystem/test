#!/usr/bin/env bash
set -euo pipefail
umask 027

PHP_BIN="/opt/cpanel/ea-php84/root/usr/bin/php"
export PATH="/opt/cpanel/ea-php84/root/usr/bin:$HOME/.local/bin:$PATH"
export COMPOSER_NO_INTERACTION=1

APP_DIR="$(pwd -P)"
REMOTE_WEB_ROOT="${REMOTE_WEB_ROOT:-public_html}"
WEB_ROOT="$HOME/$REMOTE_WEB_ROOT"
export WEB_ROOT

log() { printf '\n==> %s\n' "$1"; }

fail() {
  printf 'ERROR: %s\n' "$1" >&2
  printf 'The app may still be in maintenance mode. After fixing, run:\n  %s %s/artisan up\n' \
    "$PHP_BIN" "$APP_DIR" >&2
  exit 1
}

on_error() {
  printf '\nERROR: Deployment failed at line %s.\n' "$1" >&2
  printf 'The app may still be in maintenance mode. After fixing, run:\n  %s %s/artisan up\n' \
    "$PHP_BIN" "$APP_DIR" >&2
}
trap 'on_error $LINENO' ERR

log "Laravel cPanel deployment started"
printf 'Application directory: %s\nWeb root: %s\n' "$APP_DIR" "$WEB_ROOT"

# ------------------------------------------------------------
# 1. Validate application and tools
# ------------------------------------------------------------
log "Validating Laravel application"
for file in artisan composer.json public/index.php public/.htaccess; do
  [[ -f "$file" ]] || fail "Missing $file"
done
[[ -s .env ]] || fail "Missing or empty .env"
[[ -x "$PHP_BIN" ]] || fail "PHP 8.4 executable unavailable: $PHP_BIN"
command -v composer >/dev/null || fail "Composer is unavailable"
command -v rsync >/dev/null || fail "rsync is unavailable"
"$PHP_BIN" -v

# ------------------------------------------------------------
# 2. Prepare writable directories
# ------------------------------------------------------------
log "Preparing storage directories"
mkdir -p storage/app/public storage/framework/cache/data \
  storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R u+rwX storage bootstrap/cache

# ------------------------------------------------------------
# 3. Install dependencies
# ------------------------------------------------------------
log "Installing Composer dependencies"
composer install --no-dev --prefer-dist --no-interaction --no-progress \
  --optimize-autoloader --classmap-authoritative
[[ -f vendor/autoload.php ]] || fail "Composer autoload file missing"

# ------------------------------------------------------------
# 4. Clear caches
# ------------------------------------------------------------
log "Clearing Laravel caches"
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan event:clear

# ------------------------------------------------------------
# 5. Verify Laravel API routes
# ------------------------------------------------------------
log "Checking API routes"

ROUTES_FILE="$(mktemp)"
trap 'rm -f "$ROUTES_FILE"' EXIT

"$PHP_BIN" artisan route:list --json > "$ROUTES_FILE" \
  || fail "Laravel route:list command failed"

"$PHP_BIN" -r '
$file = $argv[1];
$json = file_get_contents($file);

if ($json === false) {
    fwrite(STDERR, "ERROR: Cannot read routes JSON\n");
    exit(1);
}

// Remove UTF-8 BOM
if (str_starts_with($json, "\xEF\xBB\xBF")) {
    $json = substr($json, 3);
}

$routes = json_decode($json, true);

if (!is_array($routes)) {
    fwrite(STDERR, "ERROR: Invalid Laravel route JSON\n");
    fwrite(STDERR, "JSON error: " . json_last_error_msg() . "\n");
    fwrite(STDERR, "Output length: " . strlen($json) . " bytes\n");
    fwrite(STDERR, "Output preview:\n" . substr($json, 0, 500) . "\n");
    exit(1);
}

$required = [
    "api/health"           => "GET",
    "api/v1/auth/send/otp" => "POST",
];

foreach ($required as $uri => $method) {
    $found = false;

    foreach ($routes as $route) {
        $methods = explode("|", $route["method"] ?? "");

        if (($route["uri"] ?? "") === $uri && in_array($method, $methods, true)) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        fwrite(STDERR, "ERROR: Missing route: $method $uri\n");
        exit(1);
    }

    echo "Verified: $method $uri\n";
}

echo "Laravel API routes verified successfully\n";
' "$ROUTES_FILE" || fail "Laravel API route verification failed"

rm -f "$ROUTES_FILE"
trap - EXIT

# ------------------------------------------------------------
# 6. Migrations
# ------------------------------------------------------------
log "Running database migrations"
"$PHP_BIN" artisan migrate --force --no-interaction

# ------------------------------------------------------------
# 7. Cache config, routes, events and views
# ------------------------------------------------------------
log "Optimizing Laravel"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan event:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache \
  || printf 'WARNING: view:cache failed; views will compile on first request\n'

# ------------------------------------------------------------
# 8. Prepare web root
# ------------------------------------------------------------
log "Preparing public web directory"
[[ "$WEB_ROOT" != "$APP_DIR" ]] || fail "Web root cannot equal application directory"
mkdir -p "$WEB_ROOT"
chmod 755 "$WEB_ROOT"
[[ ! -e "$WEB_ROOT/.env" ]] || fail "Unsafe .env detected in web root"

# ------------------------------------------------------------
# 9. Publish public assets (index.php is written separately, atomically)
# ------------------------------------------------------------
log "Publishing Laravel public files"
rsync -a \
  --chmod=D755,F644 \
  --exclude='/index.php' \
  --exclude='/storage' \
  "$APP_DIR/public/" "$WEB_ROOT/"

if [[ -f "$WEB_ROOT/index.html" ]]; then
  mkdir -p "$APP_DIR/.deploy-backups"
  mv "$WEB_ROOT/index.html" \
    "$APP_DIR/.deploy-backups/index.html.$(date +%Y%m%d%H%M%S)"
fi

# ------------------------------------------------------------
# 10. Link public storage into web root
# ------------------------------------------------------------
log "Linking public storage"
if [[ -d "$WEB_ROOT/storage" && ! -L "$WEB_ROOT/storage" ]]; then
  fail "$WEB_ROOT/storage is a real directory; move it aside manually first"
fi
ln -sfn "$APP_DIR/storage/app/public" "$WEB_ROOT/storage"

# ------------------------------------------------------------
# 11. Write index.php with absolute paths (atomic rename)
# ------------------------------------------------------------
log "Updating Laravel entry-point paths"
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
    "__DIR__.'/../vendor/autoload.php'"                => var_export($appDir . '/vendor/autoload.php', true),
    "__DIR__ . '/../vendor/autoload.php'"              => var_export($appDir . '/vendor/autoload.php', true),
    "__DIR__.'/../bootstrap/app.php'"                  => var_export($appDir . '/bootstrap/app.php', true),
    "__DIR__ . '/../bootstrap/app.php'"                => var_export($appDir . '/bootstrap/app.php', true),
    "__DIR__.'/../storage/framework/maintenance.php'"  => var_export($appDir . '/storage/framework/maintenance.php', true),
    "__DIR__ . '/../storage/framework/maintenance.php'" => var_export($appDir . '/storage/framework/maintenance.php', true),
];

$updated = str_replace(array_keys($replacements), array_values($replacements), $contents);

if ($updated === $contents ||
    !str_contains($updated, $appDir . '/vendor/autoload.php') ||
    !str_contains($updated, $appDir . '/bootstrap/app.php')) {
    fwrite(STDERR, "Unsupported Laravel index.php format\n");
    exit(1);
}

$tempFile = $webRoot . '/.index.php.deploy';
if (file_put_contents($tempFile, $updated) === false) {
    fwrite(STDERR, "Cannot write index.php\n");
    exit(1);
}
chmod($tempFile, 0644);

if (!rename($tempFile, $targetFile)) {
    @unlink($tempFile);
    fwrite(STDERR, "Cannot publish index.php\n");
    exit(1);
}

echo "Laravel entry point configured\n";
PHP

# ------------------------------------------------------------
# 12. Set permissions
# ------------------------------------------------------------
log "Setting web permissions"
chmod 755 "$WEB_ROOT"
chmod 644 "$WEB_ROOT/index.php" "$WEB_ROOT/.htaccess"

# ------------------------------------------------------------
# 13. Verify
# ------------------------------------------------------------
log "Verifying deployment"
[[ -f "$WEB_ROOT/index.php" ]] || fail "Missing published index.php"
[[ -f "$WEB_ROOT/.htaccess" ]] || fail "Missing published .htaccess"
[[ -L "$WEB_ROOT/storage" ]] || fail "Missing storage link in web root"
[[ -f "$APP_DIR/vendor/autoload.php" ]] || fail "Missing Composer autoload"
"$PHP_BIN" artisan --version

# ------------------------------------------------------------
# 14. Bring application back online
# ------------------------------------------------------------
log "Disabling maintenance mode"
"$PHP_BIN" artisan up

log "Laravel server deployment completed"