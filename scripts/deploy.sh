
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

log() {
  printf '\n==> %s\n' "$1"
}

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

printf 'Application directory: %s\nWeb root: %s\n' \
  "$APP_DIR" "$WEB_ROOT"

# ============================================================
# 1. Validate Laravel application and tools
# ============================================================
log "Validating Laravel application"

for file in artisan composer.json public/index.php public/.htaccess; do
  [[ -f "$file" ]] || fail "Missing $file"
done

[[ -s .env ]] || fail "Missing or empty .env"

[[ -x "$PHP_BIN" ]] \
  || fail "PHP 8.4 executable unavailable: $PHP_BIN"

command -v composer >/dev/null \
  || fail "Composer is unavailable"

command -v rsync >/dev/null \
  || fail "rsync is unavailable"

"$PHP_BIN" -v

# ============================================================
# 2. SECURITY: Validate environment
# ============================================================
log "Checking environment security"

if LC_ALL=C grep -iq 'uat' .env; then
  fail "Deployment blocked: .env contains forbidden UAT references"
fi

log "Environment security validation passed"

# ============================================================
# 3. Prepare writable directories
# ============================================================
log "Preparing storage directories"

mkdir -p \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chmod -R u+rwX storage bootstrap/cache

# ============================================================
# 4. Install Composer dependencies
# ============================================================
log "Installing Composer dependencies"

composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader \
  --classmap-authoritative

[[ -f vendor/autoload.php ]] \
  || fail "Composer autoload file missing"

# ============================================================
# 5. Clear Laravel caches
# ============================================================
log "Clearing Laravel caches"

"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan event:clear

# ============================================================
# 6. SECURITY: Verify API routes and authentication middleware
# ============================================================
log "Checking API routes and authentication middleware"

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

// ------------------------------------------------------------
// Normalize route JSON
// ------------------------------------------------------------
if (str_starts_with($json, "\xEF\xBB\xBF")) {
    $json = substr($json, 3);
}

$routes = json_decode($json, true);

if (!is_array($routes)) {
    fwrite(STDERR, "ERROR: Invalid Laravel route JSON\n");
    fwrite(STDERR, "JSON error: " . json_last_error_msg() . "\n");
    exit(1);
}

// ------------------------------------------------------------
// Required API routes
// ------------------------------------------------------------
$requiredRoutes = [
    "api/health" => ["GET"],
    "api/v1/auth/send/otp" => ["POST"],
];

// ------------------------------------------------------------
// Explicit public API exceptions
//
// These routes may be accessed without authentication.
// All other API routes must have approved auth middleware.
// ------------------------------------------------------------
$publicRoutes = [
    "GET api/health",
    "HEAD api/health",
    "POST api/v1/auth/send/otp",
];

// ------------------------------------------------------------
// Accepted authentication middleware
//
// Add other approved authentication middleware if necessary.
// ------------------------------------------------------------
$allowedAuthMiddleware = [
    "auth",
    "auth:sanctum",
    "auth:api",
];

// ------------------------------------------------------------
// Results
// ------------------------------------------------------------
$errors = [];
$totalApiRoutes = 0;
$protectedRoutes = 0;
$publicRouteCount = 0;

// ------------------------------------------------------------
// Verify required API routes
// ------------------------------------------------------------
echo "\nChecking required API routes...\n";

foreach ($requiredRoutes as $uri => $requiredMethods) {
    foreach ($requiredMethods as $requiredMethod) {
        $found = false;

        foreach ($routes as $route) {
            $routeUri = $route["uri"] ?? "";
            $methods = explode("|", $route["method"] ?? "");

            if (
                $routeUri === $uri &&
                in_array($requiredMethod, $methods, true)
            ) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $errors[] = "Missing required route: $requiredMethod $uri";
            echo "FAILED: $requiredMethod $uri\n";
        } else {
            echo "VERIFIED: $requiredMethod $uri\n";
        }
    }
}

// ------------------------------------------------------------
// Validate authentication for every registered API route
// ------------------------------------------------------------
echo "\nChecking API authentication middleware...\n";

foreach ($routes as $route) {
    $uri = $route["uri"] ?? "";
    $methodString = $route["method"] ?? "";

    // Only validate routes within the api/ namespace.
    if (!str_starts_with($uri, "api/")) {
        continue;
    }

    $methods = explode("|", $methodString);

    $middleware = $route["middleware"] ?? [];

    if (is_string($middleware)) {
        $middleware = explode(",", $middleware);
    }

    if (!is_array($middleware)) {
        $middleware = [];
    }

    $middleware = array_map(
        static fn ($item) => trim((string) $item),
        $middleware
    );

    // Detect only specifically approved auth middleware.
    $hasAuth = false;

    foreach ($middleware as $item) {
        if (in_array($item, $allowedAuthMiddleware, true)) {
            $hasAuth = true;
            break;
        }
    }

    foreach ($methods as $method) {
        $method = trim($method);

        if ($method === "") {
            continue;
        }

        $totalApiRoutes++;
        $routeKey = "$method $uri";

        // Allow public routes by exact HTTP method and URI.
        if (in_array($routeKey, $publicRoutes, true)) {
            echo "PUBLIC ALLOWED: $routeKey\n";
            $publicRouteCount++;
            continue;
        }

        if (!$hasAuth) {
            $errors[] = "Unprotected API route: $routeKey";
            echo "FAILED: $routeKey (missing auth middleware)\n";
        } else {
            echo "PROTECTED: $routeKey\n";
            $protectedRoutes++;
        }
    }
}

// ------------------------------------------------------------
// Final API security summary
// ------------------------------------------------------------
echo "\n========================================\n";
echo "API SECURITY VALIDATION SUMMARY\n";
echo "========================================\n";

echo "Total API method-route pairs: $totalApiRoutes\n";
echo "Protected method-route pairs: $protectedRoutes\n";
echo "Allowed public method-route pairs: $publicRouteCount\n";
echo "Security errors: " . count($errors) . "\n";

if ($totalApiRoutes === 0) {
    $errors[] = "No API routes were registered";
}

if (!empty($errors)) {
    fwrite(STDERR, "\nAPI SECURITY VALIDATION FAILED\n");

    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: $error\n");
    }

    exit(1);
}

echo "\nAll API routes passed security validation\n";

' "$ROUTES_FILE" \
  || fail "Laravel API route security verification failed"

rm -f "$ROUTES_FILE"
trap - EXIT

# ============================================================
# 7. Run database migrations
# ============================================================
log "Running database migrations"

"$PHP_BIN" artisan migrate \
  --force \
  --no-interaction

# ============================================================
# 8. Cache Laravel configuration, routes, events and views
# ============================================================
log "Optimizing Laravel"

"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan event:cache
"$PHP_BIN" artisan route:cache

"$PHP_BIN" artisan view:cache \
  || printf 'WARNING: view:cache failed; views will compile on first request\n'

# ============================================================
# 9. Prepare public web directory
# ============================================================
log "Preparing public web directory"

[[ "$WEB_ROOT" != "$APP_DIR" ]] \
  || fail "Web root cannot equal application directory"

mkdir -p "$WEB_ROOT"
chmod 755 "$WEB_ROOT"

[[ ! -e "$WEB_ROOT/.env" ]] \
  || fail "Unsafe .env detected in web root"

# ============================================================
# 10. Publish public assets
# ============================================================
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

# ============================================================
# 11. Link public storage
# ============================================================
log "Linking public storage"

if [[ -d "$WEB_ROOT/storage" && ! -L "$WEB_ROOT/storage" ]]; then
  fail "$WEB_ROOT/storage is a real directory; move it aside manually first"
fi

ln -sfn "$APP_DIR/storage/app/public" "$WEB_ROOT/storage"

# ============================================================
# 12. Atomically update Laravel index.php
# ============================================================
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
    "__DIR__.'/../vendor/autoload.php'"
        => var_export($appDir . '/vendor/autoload.php', true),

    "__DIR__ . '/../vendor/autoload.php'"
        => var_export($appDir . '/vendor/autoload.php', true),

    "__DIR__.'/../bootstrap/app.php'"
        => var_export($appDir . '/bootstrap/app.php', true),

    "__DIR__ . '/../bootstrap/app.php'"
        => var_export($appDir . '/bootstrap/app.php', true),

    "__DIR__.'/../storage/framework/maintenance.php'"
        => var_export($appDir . '/storage/framework/maintenance.php', true),

    "__DIR__ . '/../storage/framework/maintenance.php'"
        => var_export($appDir . '/storage/framework/maintenance.php', true),
];

$updated = str_replace(
    array_keys($replacements),
    array_values($replacements),
    $contents
);

if (
    $updated === $contents ||
    !str_contains($updated, $appDir . '/vendor/autoload.php') ||
    !str_contains($updated, $appDir . '/bootstrap/app.php')
) {
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

# ============================================================
# 13. Set web permissions
# ============================================================
log "Setting web permissions"

chmod 755 "$WEB_ROOT"

chmod 644 \
  "$WEB_ROOT/index.php" \
  "$WEB_ROOT/.htaccess"

# ============================================================
# 14. Verify deployment
# ============================================================
log "Verifying deployment"

[[ -f "$WEB_ROOT/index.php" ]] \
  || fail "Missing published index.php"

[[ -f "$WEB_ROOT/.htaccess" ]] \
  || fail "Missing published .htaccess"

[[ -L "$WEB_ROOT/storage" ]] \
  || fail "Missing storage link in web root"

[[ -f "$APP_DIR/vendor/autoload.php" ]] \
  || fail "Missing Composer autoload"

"$PHP_BIN" artisan --version

# ============================================================
# 15. Bring application back online
# ============================================================
log "Disabling maintenance mode"

"$PHP_BIN" artisan up

log "Laravel server deployment completed"
