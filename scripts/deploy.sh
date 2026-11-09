
#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

# ============================================================
# 1. Deployment configuration
# ============================================================

ACTION="${1:?Action required}"
DEPLOY_DIR="${2:?Deployment directory required}"
WEB_DIR="${3:?Web root required}"
RELEASE_ID="${4:?Release ID required}"

EXPECTED_URL="${5:?Expected URL required}"
EXPECTED_DB_HOST="${6:?Expected DB host required}"
EXPECTED_DB_NAME="${7:?Expected DB name required}"

[[ "$DEPLOY_DIR" =~ ^[A-Za-z0-9_-]+$ ]] || exit 1

[[ "$WEB_DIR" =~ ^[A-Za-z0-9_][A-Za-z0-9_/-]*$ ]] || exit 1
[[ "$WEB_DIR" != *..* ]] || exit 1

[[ "$RELEASE_ID" =~ ^[0-9a-f]{40}$ ]] || exit 1

[[ "$EXPECTED_URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?/?$ ]] || exit 1
[[ "$EXPECTED_DB_HOST" =~ ^[A-Za-z0-9._-]+$ ]] || exit 1
[[ "$EXPECTED_DB_NAME" =~ ^[A-Za-z0-9_-]+$ ]] || exit 1

PHP_BIN="/opt/cpanel/ea-php84/root/usr/bin/php"

export PATH="$(dirname "$PHP_BIN"):$HOME/.local/bin:$PATH"
export COMPOSER_NO_INTERACTION=1

BASE="$HOME/$DEPLOY_DIR"
WEB_ROOT="$HOME/$WEB_DIR"

INCOMING="$BASE/.incoming"
RELEASES="$BASE/.releases"
BACKUPS="$BASE/.deploy-backups"

ACTIVE="$BASE/.active-release"

RELEASE="$RELEASES/$RELEASE_ID"
SNAP="$BACKUPS/$RELEASE_ID"

LOG="$BASE/.deployment-history.log"

OLD_APP=""
MAINTENANCE_STARTED=0
SWITCH_STARTED=0
SUCCESS=0

mkdir -p "$BASE" "$INCOMING" "$RELEASES" "$BACKUPS"

chmod 700 "$INCOMING" "$BACKUPS"

log() {
  printf '[%s] %s\n' \
    "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" \
    "$*" | tee -a "$LOG"
}

fail() {
  log "ERROR: $*" >&2
  exit 1
}

# ============================================================
# 2. Prevent concurrent server deployments
# ============================================================

command -v flock >/dev/null \
  || fail "flock is unavailable"

exec 9>"$BASE/.deployment.lock"

flock -n 9 \
  || fail "Another deployment or rollback is running"

# ============================================================
# 3. Restore previous public files
# ============================================================

restore_public() {

  [[ -f "$SNAP/manifest" ]] || return 1

  local relative

  while IFS= read -r -d '' relative; do

    [[ "$relative" != /* && "$relative" != *..* ]] \
      || return 1

    if [[ -e "$SNAP/old/$relative" || -L "$SNAP/old/$relative" ]]; then

      mkdir -p "$(dirname "$WEB_ROOT/$relative")"

      rm -f -- "$WEB_ROOT/$relative"

      cp -a \
        "$SNAP/old/$relative" \
        "$WEB_ROOT/$relative"

    else

      rm -f -- "$WEB_ROOT/$relative"

    fi

  done < "$SNAP/manifest"

  # Restore original index.php atomically.

  if [[ -f "$SNAP/index.php" ]]; then

    cp -p \
      "$SNAP/index.php" \
      "$WEB_ROOT/.index.php.restore.$RELEASE_ID"

    mv -f \
      "$WEB_ROOT/.index.php.restore.$RELEASE_ID" \
      "$WEB_ROOT/index.php"

  else

    rm -f "$WEB_ROOT/index.php"

  fi

  # Restore previous public storage link.

  if [[ -L "$SNAP/storage" ]]; then

    ln -sfn \
      "$(readlink "$SNAP/storage")" \
      "$WEB_ROOT/storage"

  elif [[ -f "$SNAP/no-storage" ]]; then

    rm -f "$WEB_ROOT/storage"

  fi

  # Restore previous active release.

  if [[ -f "$SNAP/active" ]]; then

    cp "$SNAP/active" \
      "$ACTIVE.tmp.$RELEASE_ID"

    mv -f \
      "$ACTIVE.tmp.$RELEASE_ID" \
      "$ACTIVE"

  else

    rm -f "$ACTIVE"

  fi
}

# ============================================================
# 4. Automatic deployment failure recovery
# ============================================================

recover() {

  local result=$?

  trap - EXIT

  if [[ "$ACTION" == "deploy" &&
        "$SUCCESS" -ne 1 &&
        "$result" -ne 0 ]]; then

    log "Deployment failed, starting recovery"

    if (( SWITCH_STARTED )); then

      restore_public \
        || log "WARNING: Public file rollback failed"

    fi

    if (( MAINTENANCE_STARTED )) && [[ -n "$OLD_APP" ]]; then

      (
        cd "$OLD_APP"
        "$PHP_BIN" artisan up
      ) || log "WARNING: Maintenance recovery failed"

    fi

    # Remove incomplete inactive release.

    if [[ -d "$RELEASE" &&
          "$(cat "$ACTIVE" 2>/dev/null || true)" != "$RELEASE" ]]; then

      rm -rf -- "$RELEASE" || true

    fi

    log "WARNING: Database migrations are not automatically reversed"
  fi

  # Cleanup temporary backup credentials and uploaded files.

  if [[ "$ACTION" == "deploy" ]]; then

    rm -f -- \
      "$SNAP"/.mysql.* \
      "$SNAP"/.db.*

    rm -f -- \
      "$INCOMING/$RELEASE_ID.tar.gz" \
      "$INCOMING/$RELEASE_ID.sha256" \
      "$INCOMING/$RELEASE_ID.env"

  fi

  exit "$result"
}

trap recover EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ============================================================
# 5. Explicit rollback functionality
# ============================================================

if [[ "$ACTION" == "rollback" ]]; then

  [[ -f "$SNAP/manifest" ]] \
    || fail "Rollback snapshot not found"

  [[ -f "$ACTIVE" &&
     "$(cat "$ACTIVE")" == "$RELEASE" ]] \
    || fail "Release is no longer active"

  log "Rolling back release $RELEASE_ID"

  restore_public \
    || fail "Unable to restore previous public files"

  if [[ -f "$SNAP/old-app" ]]; then

    OLD_APP="$(cat "$SNAP/old-app")"

    [[ "$OLD_APP" == "$BASE" ||
       "$OLD_APP" == "$RELEASES/"* ]] \
      || fail "Unsafe previous application path"

    (
      cd "$OLD_APP"
      "$PHP_BIN" artisan up
    ) || fail "Previous application recovery failed"

  fi

  log "Previous application entry point restored"

  log "WARNING: Check database schema compatibility"

  exit 0
fi

[[ "$ACTION" == "deploy" ]] \
  || fail "Unknown deployment action"

# ============================================================
# 6. Validate required tools and PHP version
# ============================================================

log "Validating deployment tools"

[[ -x "$PHP_BIN" ]] \
  || fail "PHP 8.4 executable unavailable"

"$PHP_BIN" -r '
  exit(
    PHP_VERSION_ID >= 80400 &&
    PHP_VERSION_ID < 80500 ? 0 : 1
  );
' || fail "PHP 8.4.x is required"

for tool in \
  tar \
  rsync \
  sha256sum \
  composer \
  mysqldump \
  gzip \
  curl
do

  command -v "$tool" >/dev/null \
    || fail "Missing required command: $tool"

done

# ============================================================
# 7. Validate uploaded release
# ============================================================

log "Validating release files"

[[ -s "$INCOMING/$RELEASE_ID.tar.gz" ]] \
  || fail "Release archive missing"

[[ -s "$INCOMING/$RELEASE_ID.sha256" ]] \
  || fail "Checksum missing"

[[ -s "$INCOMING/$RELEASE_ID.env" ]] \
  || fail "Environment file missing"

[[ ! -e "$RELEASE" ]] \
  || fail "Release already exists"

[[ "$WEB_ROOT" != "$BASE" ]] \
  || fail "Web root cannot equal application directory"

[[ ! -e "$WEB_ROOT/.env" ]] \
  || fail "Unsafe .env detected in web root"

[[ -d "$WEB_ROOT" && ! -L "$WEB_ROOT" ]] \
  || fail "Missing or symlinked web root"

# ============================================================
# 8. Verify server disk space
# ============================================================

log "Checking server disk space"

FREE_KB="$(df -Pk "$BASE" | awk 'NR==2 {print $4}')"

[[ "$FREE_KB" =~ ^[0-9]+$ ]] \
  || fail "Cannot determine free disk space"

(( FREE_KB >= 524288 )) \
  || fail "Less than 512 MiB free"

# ============================================================
# 9. Verify release integrity
# ============================================================

log "Verifying SHA-256 checksum"

(
  cd "$INCOMING"

  sha256sum -c "$RELEASE_ID.sha256"

) || fail "Release checksum verification failed"

# Reject unsafe archive paths.

"$PHP_BIN" -r '

$lines = [];

exec(
    "tar -tzf " . escapeshellarg($argv[1]),
    $lines,
    $status
);

if ($status !== 0) {
    exit(1);
}

foreach ($lines as $path) {

    if (
        $path === "" ||
        str_starts_with($path, "/") ||
        preg_match("~(^|/)\.\.(/|$)~", $path) ||
        str_contains($path, "\\")
    ) {
        exit(1);
    }
}

' "$INCOMING/$RELEASE_ID.tar.gz" \
  || fail "Unsafe archive entry detected"

# Reject archive links and unsupported entry types.

if tar -tvzf "$INCOMING/$RELEASE_ID.tar.gz" |
  awk '
    substr($1,1,1) !~ /[-d]/ {
      bad=1
    }
    END {
      exit !bad
    }
  '
then
  fail "Archive contains links or unsupported entries"
fi

# ============================================================
# 10. Extract new code into isolated release
# ============================================================

log "Extracting isolated release"

mkdir -m 750 "$RELEASE"

tar -xzf \
  "$INCOMING/$RELEASE_ID.tar.gz" \
  --no-same-owner \
  --no-same-permissions \
  -C "$RELEASE"

for file in \
  artisan \
  composer.json \
  composer.lock \
  public/index.php \
  public/.htaccess
do

  [[ -f "$RELEASE/$file" ]] \
    || fail "Missing release file: $file"

done

[[ ! -e "$RELEASE/public/.env" ]] \
  || fail "Unsafe public environment file"

# ============================================================
# 11. Install environment without overwriting old .env
# ============================================================

log "Installing release environment"

cp \
  "$INCOMING/$RELEASE_ID.env" \
  "$RELEASE/.env"

chmod 600 "$RELEASE/.env"

# ============================================================
# 12. Preserve shared Laravel storage
# ============================================================

log "Preparing persistent storage"

mkdir -p \
  "$BASE/storage/app/public" \
  "$BASE/storage/framework/cache/data" \
  "$BASE/storage/framework/sessions" \
  "$BASE/storage/framework/views" \
  "$BASE/storage/logs"

rm -rf "$RELEASE/storage"

ln -s \
  "$BASE/storage" \
  "$RELEASE/storage"

mkdir -p "$RELEASE/bootstrap/cache"

chmod -R u+rwX \
  "$BASE/storage" \
  "$RELEASE/bootstrap/cache"

# ============================================================
# 13. Install Composer dependencies
# ============================================================

log "Installing Composer dependencies"

(
  cd "$RELEASE"

  COMPOSER_BIN="$(command -v composer)"

  "$PHP_BIN" "$COMPOSER_BIN" install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --classmap-authoritative

  "$PHP_BIN" "$COMPOSER_BIN" check-platform-reqs --no-dev

  [[ -f vendor/autoload.php ]]

)

# ============================================================
# 14. Validate Laravel environment security
# ============================================================

log "Validating environment isolation"

(
  cd "$RELEASE"

  "$PHP_BIN" -r '

    require "vendor/autoload.php";

    $app = require "bootstrap/app.php";

    $app->make(
        Illuminate\Contracts\Console\Kernel::class
    )->bootstrap();

    $cfg = config(
        "database.connections." . config("database.default")
    );

    $url = (string) config("app.url");

    $expectedHost = parse_url(
        $argv[1],
        PHP_URL_HOST
    );

    $actualHost = parse_url(
        $url,
        PHP_URL_HOST
    );

    $error = static function ($message) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    };

    if (config("app.env") !== "development") {
        $error("APP_ENV must be development");
    }

    if (config("app.debug") !== false) {
        $error("APP_DEBUG must be false");
    }

    if (!config("app.key")) {
        $error("APP_KEY missing");
    }

    if (
        parse_url($url, PHP_URL_SCHEME) !== "https" ||
        !$actualHost ||
        strcasecmp($actualHost, $expectedHost) !== 0
    ) {
        $error("APP_URL does not match approved domain");
    }

    if (
        !in_array(
            $cfg["driver"] ?? "",
            ["mysql", "mariadb"],
            true
        )
    ) {
        $error("Only MySQL/MariaDB supported");
    }

    if (
        !empty($cfg["url"]) ||
        is_array($cfg["host"] ?? null)
    ) {
        $error("Unsupported database configuration");
    }

    if (
        strcasecmp(
            (string) ($cfg["host"] ?? ""),
            $argv[2]
        ) !== 0
    ) {
        $error("Unapproved database host");
    }

    if (
        (string) ($cfg["database"] ?? "") !== $argv[3]
    ) {
        $error("Unapproved database name");
    }

    if (
        empty($cfg["username"]) ||
        !isset($cfg["password"]) ||
        $cfg["password"] === ""
    ) {
        $error("Database credentials missing");
    }

    foreach (
        [
            $url,
            (string) $cfg["host"],
            (string) $cfg["database"],
            (string) $cfg["username"]
        ] as $value
    ) {

        if (
            preg_match(
                "~(^|[./_-])uat([./_-]|$)~i",
                $value
            )
        ) {
            $error("Forbidden UAT environment reference");
        }
    }

    echo "Environment security verified\n";

  ' \
    "$EXPECTED_URL" \
    "$EXPECTED_DB_HOST" \
    "$EXPECTED_DB_NAME"

  # ==========================================================
  # 15. Clear and rebuild Laravel caches
  # ==========================================================

  log "Optimizing Laravel"

  "$PHP_BIN" artisan optimize:clear

  "$PHP_BIN" artisan config:cache
  "$PHP_BIN" artisan event:cache
  "$PHP_BIN" artisan route:cache

  "$PHP_BIN" artisan view:cache \
    || log "WARNING: view:cache failed"

  # ==========================================================
  # 16. Verify required API routes
  # ==========================================================

  log "Verifying required API routes"

  "$PHP_BIN" artisan route:list --json |
  "$PHP_BIN" -r '

    $routes = json_decode(
        stream_get_contents(STDIN),
        true
    );

    if (!is_array($routes)) {
        exit(1);
    }

    $required = [
        "api/health" => "GET",
        "api/v1/auth/send/otp" => "POST"
    ];

    foreach ($required as $uri => $method) {

        $found = false;

        foreach ($routes as $route) {

            $methods = explode(
                "|",
                $route["method"] ?? ""
            );

            if (
                ($route["uri"] ?? "") === $uri &&
                in_array($method, $methods, true)
            ) {
                $found = true;
            }
        }

        if (!$found) {

            fwrite(
                STDERR,
                "Missing route: $method $uri\n"
            );

            exit(1);
        }

        echo "Verified $method $uri\n";
    }

  '

  "$PHP_BIN" artisan --version

)

# ============================================================
# 17. Identify previous application
# ============================================================

if [[ -s "$ACTIVE" ]]; then

  OLD_APP="$(cat "$ACTIVE")"

  [[ "$OLD_APP" == "$RELEASES/"* &&
     -f "$OLD_APP/artisan" ]] \
    || fail "Invalid active release pointer"

elif [[ -f "$BASE/artisan" ]]; then

  OLD_APP="$BASE"

fi

# ============================================================
# 18. Backup affected public files
# ============================================================

log "Backing up previous public files"

mkdir -m 700 "$SNAP"
mkdir -m 700 "$SNAP/old"

if [[ -n "$OLD_APP" ]]; then

  printf '%s\n' "$OLD_APP" \
    > "$SNAP/old-app"

fi

if [[ -f "$ACTIVE" ]]; then

  cp -p "$ACTIVE" "$SNAP/active"

fi

if [[ -f "$WEB_ROOT/index.php" ]]; then

  cp -p \
    "$WEB_ROOT/index.php" \
    "$SNAP/index.php"

fi

if [[ -L "$WEB_ROOT/storage" ]]; then

  ln -s \
    "$(readlink "$WEB_ROOT/storage")" \
    "$SNAP/storage"

elif [[ -e "$WEB_ROOT/storage" ]]; then

  fail "Public storage is a real directory"

else

  touch "$SNAP/no-storage"

fi

# Record files which may be overwritten.

(
  cd "$RELEASE/public"

  find . \
    -type f \
    ! -path './index.php' \
    -print0

) > "$SNAP/manifest"

if [[ -f "$WEB_ROOT/index.html" &&
      ! -f "$RELEASE/public/index.html" ]]; then

  printf './index.html\0' \
    >> "$SNAP/manifest"

fi

while IFS= read -r -d '' rel; do

  relative="${rel#./}"

  [[ -n "$relative" &&
     "$relative" != /* &&
     "$relative" != *..* ]] \
    || fail "Unsafe public filename"

  if [[ -e "$WEB_ROOT/$relative" ||
        -L "$WEB_ROOT/$relative" ]]; then

    [[ ! -d "$WEB_ROOT/$relative" ]] \
      || fail "Public target is a directory"

    mkdir -p \
      "$SNAP/old/$(dirname "$relative")"

    cp -a \
      "$WEB_ROOT/$relative" \
      "$SNAP/old/$relative"

  fi

done < "$SNAP/manifest"

# ============================================================
# 19. Database backup before migrations
# ============================================================

log "Creating database backup"

MYSQL_CNF="$(mktemp "$SNAP/.mysql.XXXXXXXX")"
DB_NAME_FILE="$(mktemp "$SNAP/.db.XXXXXXXX")"

(
  cd "$RELEASE"

  MYSQL_CNF="$MYSQL_CNF" \
  DB_NAME_FILE="$DB_NAME_FILE" \
  "$PHP_BIN" -r '

    require "vendor/autoload.php";

    $app = require "bootstrap/app.php";

    $app->make(
        Illuminate\Contracts\Console\Kernel::class
    )->bootstrap();

    $cfg = config(
        "database.connections." . config("database.default")
    );

    $escape = static function ($value) {

        if (preg_match("/[\x00-\x1f]/", (string) $value)) {
            exit(1);
        }

        return str_replace(
            ["\\", "\""],
            ["\\\\", "\\\""],
            (string) $value
        );
    };

    $text = "[client]\n";

    foreach (
        ["host", "port", "username", "password"]
        as $key
    ) {

        if (
            isset($cfg[$key]) &&
            $cfg[$key] !== ""
        ) {

            $text .= $key .
                "=\"" .
                $escape($cfg[$key]) .
                "\"\n";
        }
    }

    if (
        file_put_contents(
            getenv("MYSQL_CNF"),
            $text
        ) === false
    ) {
        exit(1);
    }

    if (
        file_put_contents(
            getenv("DB_NAME_FILE"),
            $cfg["database"]
        ) === false
    ) {
        exit(1);
    }

  '
)

chmod 600 "$MYSQL_CNF" "$DB_NAME_FILE"

DB_NAME="$(cat "$DB_NAME_FILE")"

[[ "$DB_NAME" == "$EXPECTED_DB_NAME" ]] \
  || fail "Database name mismatch"

mysqldump \
  --defaults-extra-file="$MYSQL_CNF" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --no-tablespaces \
  "$DB_NAME" \
  | gzip -6 > "$SNAP/database.sql.gz"

rm -f "$MYSQL_CNF" "$DB_NAME_FILE"

gzip -t "$SNAP/database.sql.gz" \
  || fail "Database backup verification failed"

[[ -s "$SNAP/database.sql.gz" ]] \
  || fail "Database backup is empty"

chmod 600 "$SNAP/database.sql.gz"

log "Database backup completed"

# ============================================================
# 20. Enable maintenance mode
# ============================================================

if [[ -n "$OLD_APP" &&
      -f "$OLD_APP/vendor/autoload.php" ]]; then

  log "Enabling Laravel maintenance mode"

  (
    cd "$OLD_APP"

    "$PHP_BIN" artisan down --retry=15

  ) || fail "Cannot enable maintenance mode"

  MAINTENANCE_STARTED=1

fi

# ============================================================
# 21. Run database migrations
# ============================================================

log "Running database migrations"

(
  cd "$RELEASE"

  "$PHP_BIN" artisan migrate \
    --force \
    --no-interaction
)

# ============================================================
# 22. Publish new public files
# ============================================================

SWITCH_STARTED=1

log "Publishing Laravel public files"

rsync -a \
  --chmod=D755,F644 \
  --exclude='/index.php' \
  --exclude='/storage' \
  "$RELEASE/public/" \
  "$WEB_ROOT/"

if [[ ! -f "$RELEASE/public/index.html" ]]; then

  rm -f "$WEB_ROOT/index.html"

fi

ln -sfn \
  "$BASE/storage/app/public" \
  "$WEB_ROOT/storage"

# ============================================================
# 23. Atomically publish Laravel index.php
# ============================================================

log "Updating Laravel entry point"

APP_DIR="$RELEASE" \
WEB_ROOT="$WEB_ROOT" \
"$PHP_BIN" <<'PHP'
<?php

$app = getenv('APP_DIR');
$web = getenv('WEB_ROOT');

$index = file_get_contents(
    $app . '/public/index.php'
);

if ($index === false) {
    exit(1);
}

foreach (
    [
        'vendor/autoload.php',
        'bootstrap/app.php',
        'storage/framework/maintenance.php'
    ] as $path
) {

    $pattern = '~__DIR__\s*\.\s*([\x27\x22])/\.\./'
        . preg_quote($path, '~')
        . '\1~';

    $count = 0;

    $index = preg_replace(
        $pattern,
        var_export($app . '/' . $path, true),
        $index,
        -1,
        $count
    );

    if (
        !$count &&
        $path !== 'storage/framework/maintenance.php'
    ) {

        fwrite(
            STDERR,
            "Unsupported Laravel index.php format: $path\n"
        );

        exit(1);
    }
}

$tmp = $web . '/.index.php.deploy.'
    . bin2hex(random_bytes(6));

if (file_put_contents($tmp, $index, LOCK_EX) === false) {
    exit(1);
}

chmod($tmp, 0644);

if (!rename($tmp, $web . '/index.php')) {

    unlink($tmp);

    exit(1);
}
PHP

# ============================================================
# 24. Update active release pointer
# ============================================================

printf '%s\n' "$RELEASE" \
  > "$ACTIVE.tmp.$RELEASE_ID"

mv -f \
  "$ACTIVE.tmp.$RELEASE_ID" \
  "$ACTIVE"

# ============================================================
# 25. Disable maintenance mode
# ============================================================

log "Disabling maintenance mode"

(
  cd "$RELEASE"

  "$PHP_BIN" artisan up
)

# ============================================================
# 26. Verify database, queues and scheduler
# ============================================================

log "Verifying Laravel services"

(
  cd "$RELEASE"

  "$PHP_BIN" artisan migrate:status \
    --no-ansi >/dev/null

  "$PHP_BIN" artisan queue:restart \
    || log "WARNING: Queue restart signal failed"

  "$PHP_BIN" artisan schedule:list \
    --no-ansi >/dev/null \
    || log "WARNING: Scheduler verification failed"

)

# ============================================================
# 27. Complete deployment
# ============================================================

SUCCESS=1

log "Release deployed successfully: $RELEASE_ID"

log "Previous application preserved for rollback"

log "Awaiting external GitHub Actions health verification"
