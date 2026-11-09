
#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

# Actions: preflight | bootstrap-preflight | deploy | bootstrap | rollback | verify
# Bootstrap requires explicit GitHub Actions manual dispatch authorization.
# Bootstrap backs up populated database and public files but NEVER migrates.

ACTION="${1:?Action required}"
DEPLOY_DIR="${2:?Deployment directory required}"
WEB_DIR="${3:?Web root required}"
RELEASE_ID="${4:?Release ID required}"
EXPECTED_URL="${5:?Expected URL required}"
EXPECTED_DB_HOST="${6:?Expected DB host required}"
EXPECTED_DB_NAME="${7:?Expected DB name required}"

[[ "$ACTION" =~ ^(preflight|bootstrap-preflight|deploy|bootstrap|rollback|verify)$ ]] || exit 1
[[ "$DEPLOY_DIR" =~ ^[A-Za-z0-9_-]+$ ]] || exit 1

[[ "$WEB_DIR" =~ ^[A-Za-z0-9_][A-Za-z0-9_/-]*$ &&
   "$WEB_DIR" != *..* ]] || exit 1

[[ "$RELEASE_ID" =~ ^[0-9a-f]{40}$ ]] || exit 1

[[ "$EXPECTED_URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?/?$ ]] || exit 1
[[ "$EXPECTED_DB_HOST" =~ ^[A-Za-z0-9._-]+$ ]] || exit 1
[[ "$EXPECTED_DB_NAME" =~ ^[A-Za-z0-9_-]+$ ]] || exit 1

# ============================================================
# 1. Runtime and directories
# ============================================================

PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php

export PATH="$(dirname "$PHP_BIN"):$HOME/.local/bin:$PATH"
export COMPOSER_NO_INTERACTION=1

BASE="$HOME/$DEPLOY_DIR"
WEB_ROOT="$HOME/$WEB_DIR"

RELEASES="$BASE/.releases"
INCOMING="$BASE/.incoming"
BACKUPS="$BASE/.deploy-backups"

ACTIVE="$BASE/.active-release"
RELEASE="$RELEASES/$RELEASE_ID"
SNAP="$BACKUPS/$RELEASE_ID"

LOG="$BASE/.deployment-history.log"

OLD_APP=""
MAINTENANCE_STARTED=0
SWITCH_STARTED=0
SUCCESS=0

log() {
  printf '[%s] %s\n' \
    "$(date -u +'%FT%TZ')" "$*" | tee -a "$LOG"
}

fail() {
  log "ERROR: $*" >&2
  exit 1
}

[[ -d "$BASE" && ! -L "$BASE" ]] || {
  echo 'Missing or unsafe application directory' >&2
  exit 1
}

[[ -d "$WEB_ROOT" &&
   ! -L "$WEB_ROOT" &&
   "$BASE" != "$WEB_ROOT" ]] || fail 'Invalid web root'

[[ ! -e "$WEB_ROOT/.env" &&
   ! -L "$WEB_ROOT/.env" ]] || fail 'Unsafe .env in web root'

[[ -x "$PHP_BIN" ]] || fail 'PHP 8.4 unavailable'

# ============================================================
# 2. Prevent concurrent deployments
# ============================================================

command -v flock >/dev/null || fail 'flock is required'

exec 9>"$BASE/.deployment.lock"

flock -n 9 || fail 'Another deployment is running'

# ============================================================
# 3. Identify previous working Laravel application
# ============================================================

identify_previous() {

  OLD_APP=''

  if [[ -s "$ACTIVE" ]]; then

    OLD_APP="$(cat "$ACTIVE")"

    [[ "$OLD_APP" == "$RELEASES/"* ]] \
      || fail 'Unsafe active release path'

  elif [[ -f "$BASE/artisan" ]]; then

    OLD_APP="$BASE"

  fi

  [[ -n "$OLD_APP" ]] \
    || fail 'No previous Laravel application; restore a verified backup before deploying'

  for f in \
    artisan \
    composer.json \
    vendor/autoload.php \
    bootstrap/app.php \
    .env
  do

    [[ -f "$OLD_APP/$f" ]] \
      || fail "Previous application incomplete: $f"

  done

  [[ -f "$WEB_ROOT/index.php" &&
     ! -L "$WEB_ROOT/index.php" ]] \
    || fail 'Missing or unsafe live index.php'

  grep -Fq \
    "$OLD_APP/vendor/autoload.php" \
    "$WEB_ROOT/index.php" \
    || fail 'Live index.php references a different or missing vendor'

  grep -Fq \
    "$OLD_APP/bootstrap/app.php" \
    "$WEB_ROOT/index.php" \
    || fail 'Live index.php references a different or missing bootstrap'

  (
    cd "$OLD_APP"

    "$PHP_BIN" artisan --version >/dev/null

  ) || fail 'Previous Laravel installation cannot boot'
}

# ============================================================
# 4. Verify staging Laravel configuration
# ============================================================

check_config() {

  local app="$1"

  (
    cd "$app"

    "$PHP_BIN" -r '

      require "vendor/autoload.php";

      $app = require "bootstrap/app.php";

      $app->make(
          Illuminate\Contracts\Console\Kernel::class
      )->bootstrap();

      $db = config(
          "database.connections." .
          config("database.default")
      );

      $bad = static function ($message) {
          fwrite(STDERR, $message . "\n");
          exit(1);
      };

      $url = (string) config("app.url");

      if (config("app.env") !== "staging") {
          $bad("APP_ENV must be staging");
      }

      if (config("app.debug") !== false) {
          $bad("APP_DEBUG must be false");
      }

      $key = config("app.key");
      if (!is_string($key) || !str_starts_with($key, "base64:") ||
          strlen(base64_decode(substr($key, 7), true) ?: "") !== 32) {
          $bad("APP_KEY must be a valid 32-byte base64 Laravel key");
      }

      if (
          parse_url($url, PHP_URL_SCHEME) !== "https" ||
          strcasecmp(
              (string) parse_url($url, PHP_URL_HOST),
              (string) parse_url($argv[1], PHP_URL_HOST)
          ) !== 0
      ) {
          $bad("APP_URL mismatch");
      }

      if (
          !in_array(
              $db["driver"] ?? "",
              ["mysql", "mariadb"],
              true
          ) ||
          !empty($db["url"])
      ) {
          $bad("Expected ordinary MySQL/MariaDB config");
      }

      if (
          !is_string($db["host"] ?? null) ||
          strcasecmp($db["host"], $argv[2]) !== 0
      ) {
          $bad("DB_HOST mismatch");
      }

      if (($db["database"] ?? "") !== $argv[3]) {
          $bad("DB_DATABASE mismatch");
      }

      if (
          empty($db["username"]) ||
          !isset($db["password"]) ||
          $db["password"] === ""
      ) {
          $bad("DB credentials missing");
      }

      foreach (
          [
              $url,
              $db["host"],
              $db["database"],
              $db["username"]
          ] as $value
      ) {

          if (
              preg_match(
                  "~(^|[./_-])uat([./_-]|$)~i",
                  (string) $value
              )
          ) {
              $bad("UAT target detected");
          }
      }

      echo "Approved staging configuration verified\n";

    ' \
      "$EXPECTED_URL" \
      "$EXPECTED_DB_HOST" \
      "$EXPECTED_DB_NAME"
  )
}

# ============================================================
# 5. Preserve original encryption key
# ============================================================

check_key_unchanged() {

  local old="$1"
  local new="$2"
  local a b

  a="$(
    cd "$old"

    "$PHP_BIN" -r '
      require "vendor/autoload.php";

      $app = require "bootstrap/app.php";

      $app->make(
          Illuminate\Contracts\Console\Kernel::class
      )->bootstrap();

      echo hash(
          "sha256",
          (string) config("app.key")
      );
    '
  )"

  b="$(
    cd "$new"

    "$PHP_BIN" -r '
      require "vendor/autoload.php";

      $app = require "bootstrap/app.php";

      $app->make(
          Illuminate\Contracts\Console\Kernel::class
      )->bootstrap();

      echo hash(
          "sha256",
          (string) config("app.key")
      );
    '
  )"

  [[ -n "$a" && "$a" == "$b" ]] \
    || fail 'APP_KEY differs from previous application; refusing to rotate encryption key'
}

# ============================================================
# 6. Restore public files after failed deployment
# ============================================================

restore_snapshot() {

  [[ -f "$SNAP/manifest" ]] || return 1

  local rel dest

  while IFS= read -r -d '' rel; do

    rel="${rel#./}"

    [[ -n "$rel" &&
       "$rel" != /* &&
       "$rel" != *..* ]] || return 1

    dest="$WEB_ROOT/$rel"

    if [[ -f "$SNAP/old/$rel" ||
          -L "$SNAP/old/$rel" ]]; then

      mkdir -p "$(dirname "$dest")"

      rm -f -- "$dest"

      cp -a \
        "$SNAP/old/$rel" \
        "$dest"

    else

      rm -f -- "$dest"

    fi

  done < "$SNAP/manifest"

  # Restore old index.php atomically.

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

  # Restore public storage symlink.

  if [[ -L "$SNAP/storage" ]]; then

    ln -sfn \
      "$(readlink "$SNAP/storage")" \
      "$WEB_ROOT/storage"

  elif [[ -f "$SNAP/no-storage" ]]; then

    rm -f "$WEB_ROOT/storage"

  fi

  # Restore active-release pointer.

  if [[ -f "$SNAP/active" ]]; then

    cp -p \
      "$SNAP/active" \
      "$ACTIVE.restore.$RELEASE_ID"

    mv -f \
      "$ACTIVE.restore.$RELEASE_ID" \
      "$ACTIVE"

  else

    rm -f "$ACTIVE"

  fi
}

# ============================================================
# 7. Failure handler and maintenance recovery
# ============================================================

recover() {

  local code=$?

  trap - EXIT

  if [[ "$ACTION" == deploy || "$ACTION" == bootstrap ]]; then
    if [[ "$code" -ne 0 && "$SUCCESS" -eq 0 ]]; then
      log 'Deployment failed: attempting recovery'

      if (( SWITCH_STARTED )); then
        restore_snapshot || log 'CRITICAL: Public rollback failed'
      fi

      if (( MAINTENANCE_STARTED )) && [[ -n "$OLD_APP" ]]; then
        (
          cd "$OLD_APP"
          "$PHP_BIN" artisan up
        ) || log 'CRITICAL: Could not exit old maintenance mode'
      fi

      if [[ -d "$RELEASE" &&
            "$(cat "$ACTIVE" 2>/dev/null || true)" != "$RELEASE" ]]; then
        rm -rf -- "$RELEASE" || true
      fi

      log 'Database schema changes are NOT automatically reversed'
    fi
  fi

  if [[ "$ACTION" == deploy || "$ACTION" == bootstrap ]]; then

    rm -f \
      "$SNAP"/.mysql.* \
      "$SNAP"/.db.* \
      "$INCOMING/$RELEASE_ID.tar.gz" \
      "$INCOMING/$RELEASE_ID.sha256" \
      "$INCOMING/$RELEASE_ID.env"

  fi

  exit "$code"
}

# ============================================================
# 8. Explicit rollback action
# ============================================================

if [[ "$ACTION" == rollback ]]; then

  [[ -f "$SNAP/manifest" &&
     -f "$ACTIVE" &&
     "$(cat "$ACTIVE")" == "$RELEASE" ]] \
    || fail 'No applicable rollback snapshot'

  if [[ -f "$SNAP/bootstrap" ]]; then
    [[ ! -e "$SNAP/old-app" ]] || fail 'Invalid bootstrap snapshot'
  else
    [[ -s "$SNAP/old-app" ]] || fail 'Previous application snapshot missing'
    OLD_APP="$(cat "$SNAP/old-app")"
    [[ "$OLD_APP" == "$BASE" || "$OLD_APP" == "$RELEASES/"* ]] \
      || fail 'Unsafe rollback target'
    [[ -f "$OLD_APP/vendor/autoload.php" ]] \
      || fail 'Rollback application missing'
  fi

  restore_snapshot || fail 'Failed to restore public files'

  if [[ -n "$OLD_APP" ]]; then
    (
      cd "$OLD_APP"
      "$PHP_BIN" artisan up
    ) || fail 'Could not bring old app online'
  fi

  log 'Prior public state restored; bootstrap rollback cannot restore missing backend'
  log 'Verify database schema compatibility manually'

  exit 0
fi

# ============================================================
# 9. Verify the published release and actual DB connectivity
# ============================================================

if [[ "$ACTION" == verify ]]; then
  [[ -s "$ACTIVE" && "$(cat "$ACTIVE")" == "$RELEASE" ]] \
    || fail 'Expected release is not active'

  identify_previous
  check_config "$OLD_APP"

  log 'Verifying active Laravel release and database connectivity'
  (
    cd "$OLD_APP"
    "$PHP_BIN" -r '
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
      try {
          $connection = Illuminate\Support\Facades\DB::connection();
          $statement = $connection->select("SELECT 1 AS deployment_probe");
          if (count($statement) !== 1 ||
              (int) ($statement[0]->deployment_probe ?? 0) !== 1) {
              throw new RuntimeException("Database probe returned unexpected data");
          }
      } catch (Throwable $e) {
          fwrite(STDERR, "Database connectivity check failed\n");
          exit(1);
      }
      echo "Database connectivity verified\n";
    '

    # Verifies framework command registration, not that cron is installed.
    "$PHP_BIN" artisan schedule:list --no-ansi >/dev/null
    "$PHP_BIN" artisan migrate:status --no-ansi >/dev/null
  ) || fail 'Published application verification failed'

  log 'Active release, DB, migrations, and scheduler commands verified'
  exit 0
fi

# ============================================================
# 9. Pre-deployment recovery readiness verification
# ============================================================

log 'Checking application recovery readiness'

if [[ "$ACTION" == bootstrap-preflight || "$ACTION" == bootstrap ]]; then
  # Bootstrap is deliberately only permitted without an existing Laravel app.
  [[ ! -s "$ACTIVE" && ! -f "$BASE/artisan" ]] \
    || fail 'A Laravel installation already exists; use normal deploy instead'

  # Existing web contents may be broken; preserve them for forensics/rollback.
  [[ ! -L "$WEB_ROOT/index.php" ]] || fail 'Symlinked public index.php is unsafe'
  [[ ! -e "$WEB_ROOT/storage" || -L "$WEB_ROOT/storage" ]] \
    || fail 'Public storage is an actual directory; manual review needed'
  log 'One-time bootstrap allowed (no existing Laravel installation)'
else
  identify_previous
  check_config "$OLD_APP"

  [[ -r "$OLD_APP/.env" ]] || fail 'Cannot read previous .env'
  [[ -L "$WEB_ROOT/storage" ]] || fail 'Public storage symlink missing'
  [[ -d "$(readlink -f "$WEB_ROOT/storage")" ]] \
    || fail 'Public storage symlink target missing'
  [[ "$(readlink -f "$WEB_ROOT/storage")" == "$(readlink -f "$BASE/storage/app/public")" ]] \
    || fail 'Unexpected public storage target'
fi

# First-time bootstrap may begin with an empty deployment directory.
# Create persistent storage only in bootstrap mode; never replace existing files
# or symlinks, and do not change storage during a normal deployment.
if [[ "$ACTION" == bootstrap-preflight || "$ACTION" == bootstrap ]]; then
  if [[ ! -e "$BASE/storage" && ! -L "$BASE/storage" ]]; then
    mkdir -m 700 -- "$BASE/storage" \
      || fail 'Cannot create persistent storage'
    log 'Created persistent storage for first-time bootstrap'
  fi
fi

[[ -d "$BASE/storage" && ! -L "$BASE/storage" ]] \
  || fail 'Persistent storage missing or unsafe'

free="$(df -Pk "$BASE" | awk 'NR==2 {print $4}')"
[[ "$free" =~ ^[0-9]+$ ]] && (( free >= 524288 )) \
  || fail 'Less than 512 MiB free'
[[ -w "$BASE" ]] || fail 'Application base is not writable'

log 'Preflight checks passed'
if [[ "$ACTION" == preflight || "$ACTION" == bootstrap-preflight ]]; then
  exit 0
fi

# ============================================================
# 10. Prepare isolated deployment directories
# ============================================================

trap recover EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -p \
  "$INCOMING" \
  "$RELEASES" \
  "$BACKUPS"

chmod 700 "$INCOMING" "$BACKUPS"

[[ ! -e "$RELEASE" &&
   ! -e "$SNAP" ]] \
  || fail 'Release or snapshot already exists'

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
    || fail "Missing server tool: $tool"

done

"$PHP_BIN" -r '
  exit(
      PHP_VERSION_ID >= 80400 &&
      PHP_VERSION_ID < 80500 ? 0 : 1
  );
' || fail 'PHP 8.4.x required'

# ============================================================
# 11. Validate uploaded release and integrity
# ============================================================

[[ -s "$INCOMING/$RELEASE_ID.tar.gz" &&
   -s "$INCOMING/$RELEASE_ID.sha256" &&
   -s "$INCOMING/$RELEASE_ID.env" ]] \
  || fail 'Incomplete release upload'

(
  cd "$INCOMING"

  sha256sum -c "$RELEASE_ID.sha256"

) || fail 'Release checksum mismatch'

# Reject unsafe archive paths.

tar -tzf "$INCOMING/$RELEASE_ID.tar.gz" |
awk '
  /^\// || /(^|\/)\.\.(\/|$)/ || /\\/ {
    bad=1
  }
  END {
    exit bad
  }
' || fail 'Unsafe archive paths'

# Reject symlinks, hard links and unsupported entry types.

if tar -tvzf "$INCOMING/$RELEASE_ID.tar.gz" |
  awk '
    substr($1,1,1)!="-" &&
    substr($1,1,1)!="d" {
      bad=1
    }
    END {
      exit !bad
    }
  '
then

  fail 'Archive contains links or unsupported entries'

fi

# ============================================================
# 12. Extract new release separately
# ============================================================

log 'Preparing isolated release'

mkdir -m 750 "$RELEASE"

tar -xzf \
  "$INCOMING/$RELEASE_ID.tar.gz" \
  --no-same-owner \
  --no-same-permissions \
  -C "$RELEASE"

for f in \
  artisan \
  composer.json \
  composer.lock \
  public/index.php \
  public/.htaccess
do

  [[ -f "$RELEASE/$f" ]] \
    || fail "Release missing $f"

done

[[ ! -e "$RELEASE/public/.env" ]] \
  || fail 'Unsafe public .env'

cp \
  "$INCOMING/$RELEASE_ID.env" \
  "$RELEASE/.env"

chmod 600 "$RELEASE/.env"

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

# ============================================================
# 13. Composer production dependencies
# ============================================================

log 'Installing locked production dependencies'

(
  cd "$RELEASE"

  composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --classmap-authoritative

  composer check-platform-reqs --no-dev

  test -f vendor/autoload.php
)

# ============================================================
# 14. Validate new environment and APP_KEY
# ============================================================

log 'Validating new environment and encryption key'

check_config "$RELEASE"

if [[ "$ACTION" == deploy ]]; then
  check_key_unchanged "$OLD_APP" "$RELEASE"
else
  log 'Bootstrap: existing database encryption-key compatibility cannot be proven automatically'
fi

# ============================================================
# 15. Cache Laravel and verify required routes
# ============================================================

(
  cd "$RELEASE"

  "$PHP_BIN" artisan config:cache
  "$PHP_BIN" artisan event:cache
  "$PHP_BIN" artisan route:cache

  "$PHP_BIN" artisan view:cache \
    || log 'WARNING: view cache failed'

  "$PHP_BIN" artisan route:list --json |
  "$PHP_BIN" -r '

    $routes = json_decode(
        stream_get_contents(STDIN),
        true
    );

    if (!is_array($routes)) {
        exit(1);
    }

    foreach (
        [
            "api/health" => "GET",
            "api/v1/auth/send/otp" => "POST"
        ] as $uri => $method
    ) {

        $ok = false;

        foreach ($routes as $r) {

            if (
                ($r["uri"] ?? "") === $uri &&
                in_array(
                    $method,
                    explode("|", $r["method"] ?? ""),
                    true
                )
            ) {
                $ok = true;
                break;
            }
        }

        if (!$ok) {

            fwrite(
                STDERR,
                "Missing route: $method $uri\n"
            );

            exit(1);
        }

        echo "Verified $method $uri\n";
    }
  '
)

# ============================================================
# 16. Snapshot previous public files
# ============================================================

log 'Backing up public files and database before migrations'

mkdir -m 700 "$SNAP"
mkdir -m 700 "$SNAP/old"

if [[ "$ACTION" == bootstrap ]]; then
  : > "$SNAP/bootstrap"
else
  printf '%s\n' "$OLD_APP" > "$SNAP/old-app"
fi

if [[ -f "$ACTIVE" ]]; then
  cp -p "$ACTIVE" "$SNAP/active"
fi

# Private copy of the previous environment for disaster recovery.
# The running application's .env is never overwritten in place.
if [[ -n "$OLD_APP" && -r "$OLD_APP/.env" ]]; then
  cp -p "$OLD_APP/.env" "$SNAP/previous.env"
  chmod 600 "$SNAP/previous.env"
fi

if [[ -f "$WEB_ROOT/index.php" ]]; then
  cp -p "$WEB_ROOT/index.php" "$SNAP/index.php"
fi

if [[ -L "$WEB_ROOT/storage" ]]; then
  ln -s "$(readlink "$WEB_ROOT/storage")" "$SNAP/storage"
else
  : > "$SNAP/no-storage"
fi

# Full public root archival backup, including existing files not in manifest.
# Tar does not dereference storage symlinks.
log 'Creating full public web-root backup'
tar -czf "$SNAP/public-html-before.tar.gz" -C "$HOME" "$WEB_DIR"
[[ -s "$SNAP/public-html-before.tar.gz" ]] && \
  gzip -t "$SNAP/public-html-before.tar.gz" \
  || fail 'Public web-root backup invalid'
chmod 600 "$SNAP/public-html-before.tar.gz"

(
  cd "$RELEASE/public"

  find . \
    -type f \
    ! -path './index.php' \
    -print0

) > "$SNAP/manifest"

if [[ -f "$WEB_ROOT/index.html" &&
      ! -f "$RELEASE/public/index.html" ]]; then

  printf './index.html\0' >> "$SNAP/manifest"

fi

while IFS= read -r -d '' rel; do

  rel="${rel#./}"

  [[ -n "$rel" &&
     "$rel" != /* &&
     "$rel" != *..* ]] \
    || fail 'Unsafe public filename'

  if [[ -f "$WEB_ROOT/$rel" ||
        -L "$WEB_ROOT/$rel" ]]; then

    mkdir -p "$SNAP/old/$(dirname "$rel")"

    cp -a \
      "$WEB_ROOT/$rel" \
      "$SNAP/old/$rel"

  elif [[ -e "$WEB_ROOT/$rel" ]]; then

    fail "Public target is not a file: $rel"

  fi

done < "$SNAP/manifest"

# ============================================================
# 17. Create database backup
# ============================================================

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

    $c = config(
        "database.connections." .
        config("database.default")
    );

    $quote = static function ($v) {

        if (preg_match("/[\x00-\x1f]/", (string) $v)) {
            exit(1);
        }

        return str_replace(
            ["\\", "\""],
            ["\\\\", "\\\""],
            (string) $v
        );
    };

    $s = "[client]\n";

    foreach (
        ["host" => "host", "port" => "port", "username" => "user", "password" => "password"]
        as $k => $option
    ) {

        if (
            isset($c[$k]) &&
            $c[$k] !== ""
        ) {

            $s .= $option .
                "=\"" .
                $quote($c[$k]) .
                "\"\n";
        }
    }

    if (
        file_put_contents(
            getenv("MYSQL_CNF"),
            $s
        ) === false ||
        file_put_contents(
            getenv("DB_NAME_FILE"),
            (string) $c["database"]
        ) === false
    ) {
        exit(1);
    }
  '
)

chmod 600 "$MYSQL_CNF" "$DB_NAME_FILE"

[[ "$(cat "$DB_NAME_FILE")" == "$EXPECTED_DB_NAME" ]] \
  || fail 'Backup database mismatch'

mysqldump \
  --defaults-extra-file="$MYSQL_CNF" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --no-tablespaces \
  "$EXPECTED_DB_NAME" \
  | gzip -6 > "$SNAP/database.sql.gz"

rm -f "$MYSQL_CNF" "$DB_NAME_FILE"

[[ -s "$SNAP/database.sql.gz" ]] &&
  gzip -t "$SNAP/database.sql.gz" \
  || fail 'Invalid database backup'

chmod 600 "$SNAP/database.sql.gz"

# ============================================================
# 18. Maintenance mode and database migrations
# ============================================================

if [[ "$ACTION" == bootstrap ]]; then
  # Existing staging database is populated. Deliberately never migrate on bootstrap.
  log 'Bootstrap mode: skipping migrations and maintenance-mode changes'
else
  log 'Entering maintenance mode and running migrations'
  MAINTENANCE_STARTED=1
  (
    cd "$OLD_APP"
    "$PHP_BIN" artisan down --retry=15
  ) || fail 'Maintenance command failed'
  (
    cd "$RELEASE"
    "$PHP_BIN" artisan migrate --force --no-interaction
  )
fi

# ============================================================
# 19. Publish public assets
# ============================================================

log 'Publishing public assets and switching entry point'

SWITCH_STARTED=1

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
# 20. Atomically publish Laravel index.php
# ============================================================

APP_DIR="$RELEASE" \
WEB_ROOT="$WEB_ROOT" \
"$PHP_BIN" <<'PHP'
<?php

$app = getenv('APP_DIR');
$web = getenv('WEB_ROOT');

$source = file_get_contents(
    $app . '/public/index.php'
);

if ($source === false) {
    exit(1);
}

foreach (
    [
        'vendor/autoload.php',
        'bootstrap/app.php',
        'storage/framework/maintenance.php'
    ] as $path
) {

    $pattern =
        '~__DIR__\s*\.\s*([\x27\x22])/\.\./' .
        preg_quote($path, '~') .
        '\1~';

    $source = preg_replace(
        $pattern,
        var_export($app . '/' . $path, true),
        $source,
        -1,
        $count
    );

    if (
        !$count &&
        $path !== 'storage/framework/maintenance.php'
    ) {

        fwrite(
            STDERR,
            "Unsupported Laravel index.php: $path\n"
        );

        exit(1);
    }
}

$tmp = $web . '/.index.php.deploy.' .
    bin2hex(random_bytes(6));

if (file_put_contents($tmp, $source, LOCK_EX) === false) {
    exit(1);
}

chmod($tmp, 0644);

if (!rename($tmp, $web . '/index.php')) {

    @unlink($tmp);

    exit(1);
}
PHP

# ============================================================
# 21. Track active release
# ============================================================

printf '%s\n' "$RELEASE" \
  > "$ACTIVE.tmp.$RELEASE_ID"

mv -f \
  "$ACTIVE.tmp.$RELEASE_ID" \
  "$ACTIVE"

# ============================================================
# 22. Disable maintenance mode
# ============================================================

(
  cd "$RELEASE"

  "$PHP_BIN" artisan up
)

if [[ -n "$OLD_APP" ]]; then
  (
    cd "$OLD_APP"
    "$PHP_BIN" artisan up
  ) || log 'WARNING: Previous release maintenance flag not cleared'
fi

# ============================================================
# 23. Verify database and background commands
# ============================================================

log 'Checking database and queue/scheduler commands'

(
  cd "$RELEASE"

  "$PHP_BIN" artisan migrate:status \
    --no-ansi >/dev/null

  # Queue workers still require a real supervisor/Horizon health check.
  "$PHP_BIN" artisan queue:restart \
    || log 'WARNING: queue restart failed; check supervisor/Horizon'

  # A working Artisan scheduler command does not prove cron is installed.
  "$PHP_BIN" artisan schedule:list --no-ansi >/dev/null \
    || log 'WARNING: schedule:list failed; check cron'
)

# ============================================================
# 24. Deployment prepared successfully
# ============================================================

SUCCESS=1

log "Release $RELEASE_ID published"

log "External HTTP smoke checks will follow"
