#!/usr/bin/env bash
#
# Laravel cPanel release-based deployment (Development server)
#
# Called by the GitHub Actions workflow from inside the new release directory:
#   bash scripts/deploy.sh deploy     build, migrate, verify, activate (default)
#   bash scripts/deploy.sh rollback   restore the version that was live before RELEASE_ID
#
# Server layout under $DEPLOY_BASE ($HOME/<REMOTE_DEPLOY_DIR>):
#   releases/<RELEASE_ID>/    code + vendor + per-release .env
#   shared/storage/           persistent storage shared by all releases
#   current -> releases/<ID>  stable path for cron jobs / CLI
#   .deploy/                  state, logs, history, backups, incoming uploads
#
# Required env: DEPLOY_BASE, RELEASE_ID
# Optional env: PHP_BIN, REMOTE_WEB_ROOT, KEEP_RELEASES, REQUIRE_DB_BACKUP,
#               EXPECTED_APP_HOST, EXPECTED_APP_ENV, FORBIDDEN_* (see validate-env.sh)

set -Eeuo pipefail
umask 027

MODE="${1:-deploy}"

PHP_BIN="${PHP_BIN:-/opt/cpanel/ea-php84/root/usr/bin/php}"
export PATH="${PHP_BIN%/*}:$HOME/.local/bin:$PATH"
export COMPOSER_NO_INTERACTION=1

: "${DEPLOY_BASE:?DEPLOY_BASE is required}"
: "${RELEASE_ID:?RELEASE_ID is required}"

if [[ ! "$RELEASE_ID" =~ ^[0-9]{14}-[0-9a-f]{7}$ ]]; then
  printf 'ERROR: Invalid RELEASE_ID: %s\n' "$RELEASE_ID" >&2
  exit 1
fi

BASE="$(cd "$DEPLOY_BASE" && pwd -P)"
RELEASES_DIR="$BASE/releases"
RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"
SHARED_DIR="$BASE/shared"
STATE_DIR="$BASE/.deploy"
BACKUP_DIR="$STATE_DIR/backups"
LOG_DIR="$STATE_DIR/logs"
STATE_FILE="$STATE_DIR/last-deploy.state"
HISTORY_FILE="$STATE_DIR/deployments.log"

REMOTE_WEB_ROOT="${REMOTE_WEB_ROOT:-public_html}"
WEB_ROOT="$HOME/$REMOTE_WEB_ROOT"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
REQUIRE_DB_BACKUP="${REQUIRE_DB_BACKUP:-1}"
DB_BACKUPS_TO_KEEP="${DB_BACKUPS_TO_KEEP:-5}"

if [[ ! "$KEEP_RELEASES" =~ ^[0-9]+$ ]] || (( KEEP_RELEASES < 2 )); then
  KEEP_RELEASES=5
fi

export WEB_ROOT

# Runtime state used by the failure handler
WORK_TMP=""
PREV_APP_DIR=""
WEBROOT_BACKUP=""
DB_BACKUP_FILE=""
MAINT_ENABLED=0
MIGRATIONS_RAN=0
ACTIVATED=0

# ============================================================
# Helpers
# ============================================================
log()  { printf '\n==> %s\n' "$1"; }
info() { printf '    %s\n' "$1"; }
warn() { printf 'WARNING: %s\n' "$1" >&2; }
fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }

kv_get() {
  [[ -f "$1" ]] || return 0
  awk -v k="$2" 'index($0, k "=") == 1 { print substr($0, length(k) + 2); exit }' "$1"
}

env_keys() {
  LC_ALL=C awk '
    { line = $0; sub(/\r$/, "", line) }
    line ~ /^[[:space:]]*#/ || line ~ /^[[:space:]]*$/ { next }
    {
      sub(/^[[:space:]]*export[[:space:]]+/, "", line)
      eq = index(line, "=")
      if (eq == 0) next
      key = substr(line, 1, eq - 1)
      gsub(/[[:space:]]/, "", key)
      if (key ~ /^[A-Za-z_][A-Za-z0-9_.]*$/) print key
    }' "$1" | LC_ALL=C sort -u
}

artisan() {
  "$PHP_BIN" "$RELEASE_DIR/artisan" "$@"
}

# Run a PHP snippet (from stdin) inside a booted Laravel app.
# Usage: laravel_php <app_dir> [args...] <<'PHP' ... PHP
# Inside the snippet: $argv[1] = app dir, $argv[2..] = extra args.
laravel_php() {
  local app_dir="$1"
  shift
  local file
  file="$(mktemp "$WORK_TMP/snippet.XXXXXX")"
  {
    printf '%s\n' '<?php'
    printf '%s\n' 'require $argv[1] . "/vendor/autoload.php";'
    printf '%s\n' '$app = require $argv[1] . "/bootstrap/app.php";'
    printf '%s\n' '$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
    cat
  } > "$file"
  "$PHP_BIN" "$file" "$app_dir" "$@"
}

# Live app before this deploy: the "current" release, or the legacy in-place app.
detect_previous_app() {
  local target=""
  if [[ -L "$BASE/current" ]]; then
    target="$(readlink -f "$BASE/current" || true)"
    if [[ -n "$target" && "$target" != "$RELEASE_DIR" && -f "$target/artisan" ]]; then
      printf '%s' "$target"
      return 0
    fi
  fi
  if [[ -f "$BASE/artisan" && -f "$BASE/vendor/autoload.php" ]]; then
    printf '%s' "$BASE"
  fi
  return 0
}

maint_down() {
  log "Enabling maintenance mode"
  artisan down --retry=15 || warn "Could not enable maintenance mode"
  if [[ "$PREV_APP_DIR" == "$BASE" ]]; then
    "$PHP_BIN" "$BASE/artisan" down --retry=15 \
      || warn "Could not enable maintenance mode on legacy app"
  fi
  MAINT_ENABLED=1
}

maint_up() {
  log "Disabling maintenance mode"
  local failed=0
  if [[ -f "$RELEASE_DIR/artisan" ]]; then
    artisan up || failed=1
  fi
  if [[ "$PREV_APP_DIR" == "$BASE" ]]; then
    "$PHP_BIN" "$BASE/artisan" up || failed=1
  fi
  MAINT_ENABLED=0
  if (( failed )); then
    warn "Could not disable maintenance mode. Run manually: $PHP_BIN $BASE/current/artisan up"
  fi
}

write_state() {
  {
    printf 'RELEASE_ID=%s\n' "$RELEASE_ID"
    printf 'PREV_APP_DIR=%s\n' "$PREV_APP_DIR"
    printf 'WEBROOT_BACKUP=%s\n' "$WEBROOT_BACKUP"
    printf 'DB_BACKUP_FILE=%s\n' "$DB_BACKUP_FILE"
  } > "$STATE_FILE.tmp"
  mv -f "$STATE_FILE.tmp" "$STATE_FILE"
}

record_history() {
  mkdir -p "$STATE_DIR"
  printf '%s | %-12s | release=%s | sha=%s | branch=%s | actor=%s | run=%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$RELEASE_ID" \
    "${DEPLOY_SHA:-?}" "${DEPLOY_BRANCH:-?}" "${DEPLOY_ACTOR:-?}" "${DEPLOY_RUN_URL:-?}" \
    >> "$HISTORY_FILE" || true
}

switch_current_symlink() {
  ln -sfn "releases/$(basename "$1")" "$BASE/.current.tmp"
  mv -Tf "$BASE/.current.tmp" "$BASE/current"
}

# Atomically write web-root index.php pointing at <app_dir> (logic unchanged from original)
publish_index_php() {
  INDEX_APP_DIR="$1" "$PHP_BIN" <<'PHP'
<?php

$webRoot = getenv('WEB_ROOT');
$appDir = getenv('INDEX_APP_DIR');

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
}

# Restore the previous live version (code, entry point, assets, storage link)
rollback_to() {
  local prev="$1" backup="$2"

  if [[ -z "$prev" || ! -f "$prev/artisan" ]]; then
    warn "No previous release available - cannot roll back automatically"
    return 1
  fi

  info "Restoring public assets from $prev/public"
  rsync -a \
    --chmod=D755,F644 \
    --exclude='/index.php' \
    --exclude='/storage' \
    "$prev/public/" "$WEB_ROOT/" || warn "Public asset restore incomplete"

  if [[ -f "$backup/.htaccess" ]]; then
    cp -p "$backup/.htaccess" "$WEB_ROOT/.htaccess.rollback"
    mv -f "$WEB_ROOT/.htaccess.rollback" "$WEB_ROOT/.htaccess"
  fi

  if [[ ! -e "$WEB_ROOT/storage" || -L "$WEB_ROOT/storage" ]]; then
    if [[ "$prev" == "$BASE" ]]; then
      ln -sfn "$BASE/storage/app/public" "$WEB_ROOT/storage"
    else
      ln -sfn "$SHARED_DIR/storage/app/public" "$WEB_ROOT/storage"
    fi
  fi

  if [[ -f "$backup/index.php" ]]; then
    cp -p "$backup/index.php" "$WEB_ROOT/.index.php.rollback" || return 1
    mv -f "$WEB_ROOT/.index.php.rollback" "$WEB_ROOT/index.php" || return 1
  else
    publish_index_php "$prev" || return 1
  fi

  if [[ "$prev" == "$BASE" ]]; then
    rm -f "$BASE/current"
  else
    switch_current_symlink "$prev"
  fi

  "$PHP_BIN" "$prev/artisan" queue:restart >/dev/null 2>&1 || true
  info "Previous version restored: $prev"
  return 0
}

prune_files() {
  local dir="$1" pattern="$2" keep="$3"
  [[ -d "$dir" ]] || return 0
  find "$dir" -maxdepth 1 -type f -name "$pattern" -printf '%T@ %p\n' \
    | sort -rn \
    | tail -n +"$((keep + 1))" \
    | cut -d' ' -f2- \
    | while IFS= read -r f; do rm -f -- "$f"; done
}

# prune_dirs <dir> <keep> [protected paths...]
prune_dirs() {
  local dir="$1" keep="$2"
  shift 2
  [[ -d "$dir" ]] || return 0

  local all=() d i p skip excess
  while IFS= read -r d; do
    all+=("$d")
  done < <(find "$dir" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | LC_ALL=C sort)

  excess=$(( ${#all[@]} - keep ))
  for (( i = 0; i < excess; i++ )); do
    skip=0
    for p in "$@"; do
      if [[ "$dir/${all[i]}" == "$p" ]]; then skip=1; fi
    done
    if (( skip == 0 )) && grep -qF "$dir/${all[i]}/" "$WEB_ROOT/index.php" 2>/dev/null; then
      skip=1
    fi
    if (( skip == 0 )); then
      rm -rf -- "${dir:?}/${all[i]}"
      info "Pruned ${all[i]}"
    fi
  done
}

# ============================================================
# Failure handling: rollback, maintenance recovery, reporting
# ============================================================
on_exit() {
  local rc=$?
  trap - EXIT
  set +e

  if (( rc != 0 )) && [[ "$MODE" == "deploy" ]]; then
    printf '\nERROR: Deployment of release %s failed (exit %s).\n' "$RELEASE_ID" "$rc" >&2

    if (( ACTIVATED )); then
      log "Automatic rollback"
      if ! rollback_to "$PREV_APP_DIR" "$WEBROOT_BACKUP"; then
        warn "AUTOMATIC ROLLBACK FAILED - manual intervention required"
      fi
    fi

    if (( MAINT_ENABLED )); then
      maint_up
    fi

    if (( MIGRATIONS_RAN )); then
      warn "Database migrations ran before the failure. Code was restored; the schema was NOT."
      if [[ -n "$DB_BACKUP_FILE" ]]; then
        warn "Pre-migration database backup: $DB_BACKUP_FILE"
      fi
    fi

    record_history "FAILED"
    printf 'Full log: %s\n' "$LOG_DIR/$RELEASE_ID.log" >&2
  fi

  if [[ -n "$WORK_TMP" ]]; then
    rm -rf -- "$WORK_TMP"
  fi

  exit "$rc"
}

trap on_exit EXIT
trap 'printf "ERROR: command failed at line %s\n" "$LINENO" >&2' ERR
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# Release metadata (written by the workflow)
DEPLOY_SHA="$(kv_get "$RELEASE_DIR/RELEASE" DEPLOY_SHA)"
DEPLOY_BRANCH="$(kv_get "$RELEASE_DIR/RELEASE" DEPLOY_BRANCH)"
DEPLOY_ACTOR="$(kv_get "$RELEASE_DIR/RELEASE" DEPLOY_ACTOR)"
DEPLOY_RUN_URL="$(kv_get "$RELEASE_DIR/RELEASE" DEPLOY_RUN_URL)"

init_run() {
  mkdir -p "$STATE_DIR" "$BACKUP_DIR/env" "$BACKUP_DIR/db" "$BACKUP_DIR/webroot" "$LOG_DIR"
  chmod 700 "$STATE_DIR" "$BACKUP_DIR"
  WORK_TMP="$(mktemp -d "$STATE_DIR/tmp.XXXXXX")"
  exec > >(tee -a "$LOG_DIR/$1") 2>&1
}

# ============================================================
# 1. Validate release, tools and web root
# ============================================================
validate_release() {
  log "Validating Laravel application"

  [[ "$(pwd -P)" == "$RELEASE_DIR" ]] \
    || fail "deploy.sh must run from inside $RELEASE_DIR"

  local file
  for file in artisan composer.json public/index.php public/.htaccess scripts/validate-env.sh; do
    [[ -f "$file" ]] || fail "Missing $file"
  done

  [[ -s .env ]] || fail "Missing or empty .env"

  [[ -x "$PHP_BIN" ]] || fail "PHP 8.4 executable unavailable: $PHP_BIN"
  "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || fail "PHP >= 8.4 required"

  command -v composer >/dev/null || fail "Composer is unavailable"
  command -v rsync >/dev/null || fail "rsync is unavailable"

  "$PHP_BIN" -v
  composer --version

  log "Checking web root"
  [[ "$WEB_ROOT" != "$RELEASE_DIR" && "$WEB_ROOT" != "$BASE" ]] \
    || fail "Web root cannot equal application directory"
  [[ ! -e "$WEB_ROOT/.env" ]] || fail "Unsafe .env detected in web root"
  if [[ -d "$WEB_ROOT/storage" && ! -L "$WEB_ROOT/storage" ]]; then
    fail "$WEB_ROOT/storage is a real directory; move it aside manually first"
  fi
}

# ============================================================
# 2. SECURITY: Validate environment
# ============================================================
validate_environment() {
  log "Checking environment security"

  bash scripts/validate-env.sh .env || fail "Deployment blocked: environment validation failed"
  chmod 600 .env

  # Configuration completeness (warnings only)
  local new_keys="$WORK_TMP/env.keys" missing removed
  env_keys .env > "$new_keys"

  if [[ -f .env.example ]]; then
    missing="$(LC_ALL=C comm -23 <(env_keys .env.example) "$new_keys" | tr '\n' ' ')"
    [[ -z "${missing// /}" ]] || warn ".env is missing keys defined in .env.example: $missing"
  fi

  if [[ -n "$PREV_APP_DIR" && -f "$PREV_APP_DIR/.env" ]]; then
    removed="$(LC_ALL=C comm -23 <(env_keys "$PREV_APP_DIR/.env") "$new_keys" | tr '\n' ' ')"
    [[ -z "${removed// /}" ]] || warn "Keys present in the previous deployment but not in this .env: $removed"
  fi

  # Backups: legacy .env once, then every release's .env
  if [[ "$PREV_APP_DIR" == "$BASE" && -f "$BASE/.env" ]] \
      && ! ls "$BACKUP_DIR/env"/legacy-*.env >/dev/null 2>&1; then
    install -m 600 "$BASE/.env" "$BACKUP_DIR/env/legacy-$(date -u +%Y%m%d%H%M%S).env"
    info "Backed up legacy .env"
  fi
  install -m 600 .env "$BACKUP_DIR/env/$RELEASE_ID.env"

  info "Environment security validation passed"
}

# ============================================================
# 3. Shared storage (migrated once from the legacy layout)
# ============================================================
prepare_shared_storage() {
  log "Preparing shared storage"

  if [[ ! -d "$SHARED_DIR/storage" ]]; then
    mkdir -p "$SHARED_DIR"
    if [[ -d "$BASE/storage" && ! -L "$BASE/storage" ]]; then
      info "First release-based deploy: copying existing storage to shared/storage"
      rm -rf "$SHARED_DIR/storage.tmp"
      rsync -a "$BASE/storage/" "$SHARED_DIR/storage.tmp/"
      mv "$SHARED_DIR/storage.tmp" "$SHARED_DIR/storage"
    fi
  fi

  mkdir -p \
    "$SHARED_DIR/storage/app/public" \
    "$SHARED_DIR/storage/framework/cache/data" \
    "$SHARED_DIR/storage/framework/sessions" \
    "$SHARED_DIR/storage/framework/views" \
    "$SHARED_DIR/storage/logs"

  chmod -R u+rwX "$SHARED_DIR/storage"

  rm -rf "$RELEASE_DIR/storage"
  ln -s "$SHARED_DIR/storage" "$RELEASE_DIR/storage"

  mkdir -p "$RELEASE_DIR/bootstrap/cache"
  chmod -R u+rwX "$RELEASE_DIR/bootstrap/cache"
}

# ============================================================
# 4. Composer dependencies + platform compatibility
# ============================================================
install_dependencies() {
  log "Installing Composer dependencies"

  if [[ -n "$PREV_APP_DIR" && -d "$PREV_APP_DIR/vendor" && ! -e vendor ]]; then
    info "Seeding vendor/ from the previous release"
    cp -a "$PREV_APP_DIR/vendor" vendor || { warn "Could not seed vendor/"; rm -rf vendor; }
  fi

  composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --classmap-authoritative

  [[ -f vendor/autoload.php ]] || fail "Composer autoload file missing"

  log "Checking platform requirements against server PHP"
  composer check-platform-reqs --no-dev || fail "Server PHP does not satisfy composer requirements"
}

# ============================================================
# 5. Clear caches (fresh release, kept for parity)
# ============================================================
clear_caches() {
  log "Clearing Laravel caches"
  artisan config:clear
  artisan route:clear
  artisan view:clear
  artisan event:clear
}

# ============================================================
# 6. Verify required API routes (unchanged logic)
# ============================================================
verify_routes() {
  log "Checking required API routes"

  local routes_file="$WORK_TMP/routes.json"

  artisan route:list --json > "$routes_file" \
    || fail "Laravel route:list command failed"

  "$PHP_BIN" -r '
$json = file_get_contents($argv[1]);

if ($json === false) {
    fwrite(STDERR, "ERROR: Cannot read routes JSON\n");
    exit(1);
}

if (str_starts_with($json, "\xEF\xBB\xBF")) {
    $json = substr($json, 3);
}

$routes = json_decode($json, true);

if (!is_array($routes)) {
    fwrite(STDERR, "ERROR: Invalid Laravel route JSON: " . json_last_error_msg() . "\n");
    exit(1);
}

$requiredRoutes = [
    "api/health" => ["GET"],
    "api/v1/auth/send/otp" => ["POST"],
];

$errors = [];

foreach ($requiredRoutes as $uri => $requiredMethods) {
    foreach ($requiredMethods as $requiredMethod) {
        $found = false;

        foreach ($routes as $route) {
            $methods = explode("|", $route["method"] ?? "");
            if (($route["uri"] ?? "") === $uri && in_array($requiredMethod, $methods, true)) {
                $found = true;
                break;
            }
        }

        if ($found) {
            echo "VERIFIED: $requiredMethod $uri\n";
        } else {
            $errors[] = "Missing required route: $requiredMethod $uri";
        }
    }
}

if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: $error\n");
    }
    exit(1);
}

echo "Required API routes verified\n";
' "$routes_file" \
    || fail "Required Laravel API route verification failed"
}

# ============================================================
# 7. Cache config/events/routes/views (before maintenance window)
# ============================================================
optimize_release() {
  log "Optimizing Laravel"
  artisan config:cache
  artisan event:cache
  artisan route:cache
  artisan view:cache \
    || printf 'WARNING: view:cache failed; views will compile on first request\n'
}

# ============================================================
# 8. Database backup (only when migrations are pending)
# ============================================================
backup_unavailable() {
  DB_BACKUP_FILE=""
  if [[ "$REQUIRE_DB_BACKUP" == "1" ]]; then
    fail "Database backup required but unavailable: $1 (set REQUIRE_DB_BACKUP=0 to override)"
  fi
  warn "Database backup skipped: $1"
}

backup_database() {
  log "Backing up database before migrations"

  local cnf="$WORK_TMP/db.cnf" meta driver database dump_bin
  meta="$(laravel_php "$RELEASE_DIR" "$cnf" <<'PHP'
$cnfPath = $argv[2];
$name = config("database.default");
$c = config("database.connections.$name") ?? [];
$driver = $c["driver"] ?? "";

if (in_array($driver, ["mysql", "mariadb"], true)) {
    $q = fn ($v) => '"' . addcslashes((string) $v, "\\\"") . '"';
    $lines = [
        "[client]",
        "user=" . $q($c["username"] ?? ""),
        "password=" . $q($c["password"] ?? ""),
    ];
    if (!empty($c["unix_socket"])) {
        $lines[] = "socket=" . $q($c["unix_socket"]);
    } else {
        $host = $c["host"] ?? "127.0.0.1";
        $lines[] = "host=" . $q(is_array($host) ? reset($host) : $host);
        $lines[] = "port=" . (int) ($c["port"] ?? 3306);
    }
    umask(077);
    if (file_put_contents($cnfPath, implode("\n", $lines) . "\n") === false) {
        exit(1);
    }
    chmod($cnfPath, 0600);
}

echo $driver, "\n", ($c["database"] ?? ""), "\n";
PHP
)" || fail "Could not read database configuration"

  driver="$(sed -n 1p <<<"$meta")"
  database="$(sed -n 2p <<<"$meta")"
  DB_BACKUP_FILE="$BACKUP_DIR/db/$RELEASE_ID.sql.gz"

  case "$driver" in
    mysql|mariadb)
      dump_bin="$(command -v mysqldump || command -v mariadb-dump || true)"
      if [[ -z "$dump_bin" ]]; then
        backup_unavailable "mysqldump not found"
        return 0
      fi

      if "$dump_bin" --defaults-extra-file="$cnf" \
            --single-transaction --quick --routines --triggers --no-tablespaces \
            "$database" | gzip -6 > "$DB_BACKUP_FILE.part" \
          && gzip -t "$DB_BACKUP_FILE.part" \
          && gzip -dc "$DB_BACKUP_FILE.part" | tail -n 1 | grep -q 'Dump completed'; then
        mv -f "$DB_BACKUP_FILE.part" "$DB_BACKUP_FILE"
      else
        rm -f "$DB_BACKUP_FILE.part" "$cnf"
        backup_unavailable "mysqldump failed or produced an incomplete dump"
        return 0
      fi
      ;;
    sqlite)
      if [[ -f "$database" ]]; then
        gzip -c "$database" > "$DB_BACKUP_FILE"
      else
        backup_unavailable "SQLite database file not found"
        return 0
      fi
      ;;
    *)
      backup_unavailable "automatic backup not supported for driver '$driver'"
      return 0
      ;;
  esac

  rm -f "$cnf"
  chmod 600 "$DB_BACKUP_FILE"
  info "Database backup: $DB_BACKUP_FILE ($(du -h "$DB_BACKUP_FILE" | cut -f1))"
  prune_files "$BACKUP_DIR/db" '*.sql.gz' "$DB_BACKUPS_TO_KEEP"
}

# ============================================================
# 9. Migrations (connectivity check, preview, backup, migrate)
# ============================================================
run_migrations() {
  log "Checking database connectivity and pending migrations"

  local out rc=0 has_pending=0
  out="$(artisan migrate:status --no-interaction 2>&1)" || rc=$?

  if (( rc != 0 )); then
    if grep -qi 'migration table not found' <<<"$out"; then
      has_pending=1
      info "Migration table not found - all migrations are pending"
    else
      printf '%s\n' "$out" | tail -n 20 >&2
      fail "Cannot read migration status (database unreachable or misconfigured)"
    fi
  elif grep -q 'Pending' <<<"$out"; then
    has_pending=1
    info "$(grep -c 'Pending' <<<"$out") pending migration(s)"
  fi

  if (( has_pending == 0 )); then
    info "No pending migrations - no maintenance window or DB backup needed"
    return 0
  fi

  log "Previewing migration SQL"
  local preview="$LOG_DIR/$RELEASE_ID.migrations.sql"
  if artisan migrate --pretend --force --no-interaction > "$preview" 2>&1; then
    if grep -Eiq 'drop[[:space:]]+(table|column)|truncate|rename[[:space:]]+(table|column)' "$preview"; then
      warn "Pending migrations contain destructive statements - review $preview"
    fi
  else
    warn "migrate --pretend failed (preview only, continuing) - see $preview"
  fi

  backup_database
  maint_down

  log "Running database migrations"
  MIGRATIONS_RAN=1
  artisan migrate --force --no-interaction
}

# ============================================================
# 10. Pre-activation smoke tests (new code, real DB/cache/storage)
# ============================================================
smoke_test_release() {
  log "Running pre-activation smoke tests"

  laravel_php "$RELEASE_DIR" <<'PHP' || fail "Pre-activation smoke tests failed"
$failed = false;
$check = function (string $name, callable $fn) use (&$failed) {
    try {
        $fn();
        echo "    OK    {$name}\n";
    } catch (\Throwable $e) {
        $failed = true;
        fwrite(STDERR, "    FAIL  {$name}: " . get_class($e) . ": " . $e->getMessage() . "\n");
    }
};

$check("database connection", fn () => \Illuminate\Support\Facades\DB::select("select 1"));

$check("cache read/write", function () {
    $key = "deploy-smoke-" . bin2hex(random_bytes(4));
    \Illuminate\Support\Facades\Cache::put($key, "ok", 60);
    if (\Illuminate\Support\Facades\Cache::get($key) !== "ok") {
        throw new \RuntimeException("cache value mismatch");
    }
    \Illuminate\Support\Facades\Cache::forget($key);
});

$check("storage writable", function () {
    foreach (["app", "framework/cache", "framework/sessions", "framework/views", "logs"] as $dir) {
        if (!is_writable(storage_path($dir))) {
            throw new \RuntimeException(storage_path($dir) . " is not writable");
        }
    }
});

$check("queue backend", function () {
    $name = config("queue.default");
    $driver = config("queue.connections.$name.driver");
    if ($driver === "database") {
        $table = config("queue.connections.$name.table", "jobs");
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
            throw new \RuntimeException("queue table '$table' is missing");
        }
    }
    \Illuminate\Support\Facades\Queue::connection($name);
});

exit($failed ? 1 : 0);
PHP
}

# ============================================================
# 11. Activate release (atomic entry-point switch)
# ============================================================
activate_release() {
  log "Activating release $RELEASE_ID"

  WEBROOT_BACKUP="$BACKUP_DIR/webroot/$RELEASE_ID"
  mkdir -p "$WEBROOT_BACKUP"

  local f
  for f in index.php .htaccess; do
    if [[ -f "$WEB_ROOT/$f" ]]; then
      cp -p "$WEB_ROOT/$f" "$WEBROOT_BACKUP/$f"
    fi
  done

  write_state
  ACTIVATED=1

  if [[ "$PREV_APP_DIR" == "$BASE" && -d "$BASE/storage/app" ]]; then
    info "Syncing files uploaded to legacy storage during this deployment"
    rsync -a --ignore-existing "$BASE/storage/app/" "$SHARED_DIR/storage/app/"
  fi

  mkdir -p "$WEB_ROOT"
  chmod 755 "$WEB_ROOT"

  info "Publishing public assets"
  rsync -a \
    --chmod=D755,F644 \
    --exclude='/index.php' \
    --exclude='/storage' \
    "$RELEASE_DIR/public/" "$WEB_ROOT/"

  if [[ -f "$WEB_ROOT/index.html" ]]; then
    mv "$WEB_ROOT/index.html" "$BACKUP_DIR/webroot/index.html.$(date +%Y%m%d%H%M%S)"
  fi

  info "Linking public storage"
  ln -sfn "$SHARED_DIR/storage/app/public" "$WEB_ROOT/storage"

  info "Switching web entry point"
  publish_index_php "$RELEASE_DIR"

  chmod 644 "$WEB_ROOT/index.php" "$WEB_ROOT/.htaccess"

  switch_current_symlink "$RELEASE_DIR"
  info "Release $RELEASE_ID is live"
}

# ============================================================
# 12. Verify deployment
# ============================================================
verify_deployment() {
  log "Verifying deployment"

  [[ -f "$WEB_ROOT/index.php" ]] || fail "Missing published index.php"
  grep -qF "$RELEASE_DIR/vendor/autoload.php" "$WEB_ROOT/index.php" \
    || fail "index.php does not point at the new release"
  [[ -f "$WEB_ROOT/.htaccess" ]] || fail "Missing published .htaccess"
  [[ -L "$WEB_ROOT/storage" ]] || fail "Missing storage link in web root"
  [[ -f "$RELEASE_DIR/vendor/autoload.php" ]] || fail "Missing Composer autoload"
  [[ "$(readlink "$BASE/current")" == "releases/$RELEASE_ID" ]] || fail "current symlink not updated"

  artisan --version
}

# ============================================================
# 13. Queue workers and scheduler
# ============================================================
check_workers_and_scheduler() {
  log "Checking queue workers and scheduler"

  artisan queue:restart || warn "queue:restart failed - workers may run old code"

  local cron stale tasks qdriver
  cron="$(crontab -l 2>/dev/null || true)"

  stale="$(grep 'artisan' <<<"$cron" | grep -F "$BASE" | grep -vF "$BASE/current" || true)"
  if [[ -n "$stale" ]]; then
    warn "Cron entries call artisan outside $BASE/current and will keep running OLD code."
    warn "Update them to use: $PHP_BIN $BASE/current/artisan ..."
  fi

  tasks="$(artisan schedule:list 2>/dev/null || true)"
  if [[ -n "$tasks" ]] && ! grep -qi 'no scheduled tasks' <<<"$tasks"; then
    if grep -q 'schedule:run' <<<"$cron"; then
      info "Scheduler cron entry found"
    else
      warn "Scheduled tasks are defined but no 'schedule:run' cron entry was found"
    fi
  else
    info "No scheduled tasks defined"
  fi

  qdriver="$(laravel_php "$RELEASE_DIR" 2>/dev/null <<'PHP' || true
echo config("queue.connections." . config("queue.default") . ".driver");
PHP
)"

  if [[ -n "$qdriver" && "$qdriver" != "sync" && "$qdriver" != "null" ]]; then
    if grep -q 'queue:work' <<<"$cron" \
        || pgrep -u "$(id -u)" -f 'artisan queue:work' >/dev/null 2>&1; then
      info "Queue worker found (driver: $qdriver)"
    else
      warn "Queue driver '$qdriver' is in use but no queue:work cron entry or running worker was found"
    fi
  fi
}

# ============================================================
# Modes
# ============================================================
deploy_main() {
  init_run "$RELEASE_ID.log"

  log "Laravel cPanel deployment started"
  printf 'Release: %s\nRelease dir: %s\nWeb root: %s\nCommit: %s (%s)\n' \
    "$RELEASE_ID" "$RELEASE_DIR" "$WEB_ROOT" "${DEPLOY_SHA:-?}" "${DEPLOY_BRANCH:-?}"

  PREV_APP_DIR="$(detect_previous_app)"
  info "Currently live: ${PREV_APP_DIR:-none (first deployment)}"

  validate_release
  validate_environment
  prepare_shared_storage
  install_dependencies
  clear_caches
  verify_routes
  optimize_release
  run_migrations
  smoke_test_release
  activate_release
  write_state
  verify_deployment
  check_workers_and_scheduler

  # Original behaviour: always bring the app online after a successful deploy
  maint_up

  record_history "SUCCESS"

  log "Pruning old releases and backups"
  prune_dirs "$RELEASES_DIR" "$KEEP_RELEASES" "$RELEASE_DIR" "$PREV_APP_DIR"
  prune_dirs "$BACKUP_DIR/webroot" "$KEEP_RELEASES" "$WEBROOT_BACKUP"
  prune_files "$BACKUP_DIR/env" '*.env' $(( KEEP_RELEASES * 2 ))
  prune_files "$LOG_DIR" '*.log' 50
  prune_files "$LOG_DIR" '*.migrations.sql' 20

  log "Laravel server deployment completed (release $RELEASE_ID)"
}

rollback_main() {
  init_run "$RELEASE_ID.rollback.log"

  log "Rolling back release $RELEASE_ID"

  [[ -f "$STATE_FILE" ]] || fail "No deployment state file found"
  [[ "$(kv_get "$STATE_FILE" RELEASE_ID)" == "$RELEASE_ID" ]] \
    || fail "Last deployment state belongs to a different release - refusing to roll back"

  PREV_APP_DIR="$(kv_get "$STATE_FILE" PREV_APP_DIR)"
  WEBROOT_BACKUP="$(kv_get "$STATE_FILE" WEBROOT_BACKUP)"
  DB_BACKUP_FILE="$(kv_get "$STATE_FILE" DB_BACKUP_FILE)"

  rollback_to "$PREV_APP_DIR" "$WEBROOT_BACKUP" || fail "Rollback failed - manual intervention required"

  "$PHP_BIN" "$PREV_APP_DIR/artisan" up || warn "Could not run artisan up on the restored version"

  if [[ -n "$DB_BACKUP_FILE" ]]; then
    warn "Migrations from $RELEASE_ID were NOT reverted. Pre-migration backup: $DB_BACKUP_FILE"
  fi

  record_history "ROLLED_BACK"

  cd "$BASE"
  if [[ "$(readlink "$BASE/current" 2>/dev/null || true)" != "releases/$RELEASE_ID" ]] \
      && ! grep -qF "$RELEASE_DIR/" "$WEB_ROOT/index.php" 2>/dev/null; then
    rm -rf -- "$RELEASE_DIR"
    info "Removed rolled-back release directory"
  fi

  log "Rollback completed"
}

case "$MODE" in
  deploy)   deploy_main ;;
  rollback) rollback_main ;;
  *)        fail "Unknown mode '$MODE' (use: deploy | rollback)" ;;
esac