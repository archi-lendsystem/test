#!/usr/bin/env bash
#
# Validates a Laravel .env before it is deployed to the DEVELOPMENT server.
# Compatible with bash 3.2 (macOS runner) and bash 4+/5 (cPanel server).
# Never prints secret values - only key names (and APP_ENV).
#
# Usage: bash scripts/validate-env.sh <path-to-env-file>
#
# Optional environment variables:
#   EXPECTED_APP_HOST    Host APP_URL must use (derived from BACKEND_URL by the workflow)
#   EXPECTED_APP_ENV     Allowed APP_ENV values, comma-separated (e.g. "development,local")
#   FORBIDDEN_DB_HOSTS   Comma-separated DB hosts that must never be used (UAT/prod)
#   FORBIDDEN_DB_NAMES   Comma-separated DB names that must never be used (UAT/prod)
#   FORBIDDEN_APP_HOSTS  Comma-separated APP_URL hosts that must never be used (UAT/prod)

set -euo pipefail
set -f

ENV_FILE="${1:-}"
ERRORS=0

err()   { printf 'ENV ERROR: %s\n' "$1" >&2; ERRORS=$((ERRORS + 1)); }
warn()  { printf 'ENV WARNING: %s\n' "$1" >&2; }
lower() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }
trim()  { printf '%s' "$1" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//'; }

if [[ -z "$ENV_FILE" || ! -f "$ENV_FILE" || ! -r "$ENV_FILE" || ! -s "$ENV_FILE" ]]; then
  echo "ENV ERROR: env file is missing, empty or unreadable" >&2
  exit 1
fi

# First definition wins (same as Laravel's immutable dotenv loader)
env_get() {
  LC_ALL=C awk -v key="$1" '
    { line = $0; sub(/\r$/, "", line) }
    line ~ /^[[:space:]]*#/ { next }
    {
      sub(/^[[:space:]]*export[[:space:]]+/, "", line)
      eq = index(line, "=")
      if (eq == 0) next
      k = substr(line, 1, eq - 1)
      gsub(/[[:space:]]/, "", k)
      if (k != key) next
      v = substr(line, eq + 1)
      sub(/^[[:space:]]+/, "", v)
      sub(/[[:space:]]+$/, "", v)
      if (v ~ /^".*"$/ || v ~ /^\047.*\047$/) {
        v = substr(v, 2, length(v) - 2)
      } else {
        sub(/[[:space:]]+#.*$/, "", v)
      }
      printf "%s", v
      exit
    }' "$ENV_FILE"
}

env_keys_all() {
  LC_ALL=C awk '
    { line = $0; sub(/\r$/, "", line) }
    line ~ /^[[:space:]]*#/ || line ~ /^[[:space:]]*$/ { next }
    {
      sub(/^[[:space:]]*export[[:space:]]+/, "", line)
      eq = index(line, "=")
      if (eq == 0) next
      k = substr(line, 1, eq - 1)
      gsub(/[[:space:]]/, "", k)
      if (k ~ /^[A-Za-z_][A-Za-z0-9_.]*$/) print k
    }' "$ENV_FILE"
}

host_of() {
  printf '%s' "$1" \
    | sed -E 's|^[A-Za-z][A-Za-z0-9+.-]*://||; s|^[^@/]*@||; s|[/:?#].*$||' \
    | tr '[:upper:]' '[:lower:]'
}

in_list() {
  local needle item old_ifs="$IFS"
  needle="$(lower "$(trim "$1")")"
  [[ -n "$needle" ]] || return 1
  IFS=','
  for item in $2; do
    item="$(lower "$(trim "$item")")"
    if [[ -n "$item" && "$item" == "$needle" ]]; then
      IFS="$old_ifs"
      return 0
    fi
  done
  IFS="$old_ifs"
  return 1
}

# ------------------------------------------------------------
# 1. Original strict guard (kept unchanged: any "uat" text blocks)
# ------------------------------------------------------------
uat_where="$(LC_ALL=C awk '
  { line = $0; sub(/\r$/, "", line) }
  tolower(line) ~ /uat/ {
    if (line ~ /^[[:space:]]*#/) { printf "comment(line %d) ", NR; next }
    l = line
    sub(/^[[:space:]]*export[[:space:]]+/, "", l)
    eq = index(l, "=")
    k = (eq > 0) ? substr(l, 1, eq - 1) : "line " NR
    gsub(/[[:space:]]/, "", k)
    printf "%s ", k
  }' "$ENV_FILE")"

if [[ -n "$uat_where" ]]; then
  err "contains the text 'uat' in: ${uat_where% }"
fi

# ------------------------------------------------------------
# 2. Structure: malformed lines and duplicate keys
# ------------------------------------------------------------
bad_lines="$(LC_ALL=C awk '
  { line = $0; sub(/\r$/, "", line) }
  line ~ /^[[:space:]]*#/ || line ~ /^[[:space:]]*$/ { next }
  {
    sub(/^[[:space:]]*export[[:space:]]+/, "", line)
    if (line !~ /^[A-Za-z_][A-Za-z0-9_.]*[[:space:]]*=/) printf "%d ", NR
  }' "$ENV_FILE")"

if [[ -n "$bad_lines" ]]; then
  warn "lines not in KEY=VALUE form (ok only for multi-line quoted values): ${bad_lines% }"
fi

CRITICAL_KEYS=" APP_ENV APP_KEY APP_URL APP_DEBUG DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_URL "
for k in $(env_keys_all | LC_ALL=C sort | uniq -d); do
  if [[ "$CRITICAL_KEYS" == *" $k "* ]]; then
    err "duplicate definition of $k"
  else
    warn "duplicate definition of $k"
  fi
done

# ------------------------------------------------------------
# 3. Required keys
# ------------------------------------------------------------
for k in APP_ENV APP_KEY APP_URL DB_CONNECTION; do
  if [[ -z "$(env_get "$k")" ]]; then
    err "$k is missing or empty"
  fi
done

APP_KEY_VALUE="$(env_get APP_KEY)"
if [[ -n "$APP_KEY_VALUE" && "$APP_KEY_VALUE" != base64:* ]]; then
  warn "APP_KEY does not start with 'base64:'"
fi

# ------------------------------------------------------------
# 4. Environment isolation
# ------------------------------------------------------------
APP_ENV_LC="$(lower "$(env_get APP_ENV)")"

case "$APP_ENV_LC" in
  uat|prod|production|live|preprod|pre-prod|preproduction)
    err "APP_ENV='$APP_ENV_LC' is a UAT/production environment"
    ;;
esac

if [[ -n "${EXPECTED_APP_ENV:-}" && -n "$APP_ENV_LC" ]] && ! in_list "$APP_ENV_LC" "$EXPECTED_APP_ENV"; then
  err "APP_ENV='$APP_ENV_LC' is not one of the allowed values ($EXPECTED_APP_ENV)"
fi

# uat/prod/live as a separate token in identity values (e.g. app_prod, uat-db)
TOKEN_RE='(^|[^a-z0-9])(uat|prod|production|live)([^a-z0-9]|$)'
for k in APP_URL DB_HOST DB_DATABASE DB_USERNAME DB_URL REDIS_HOST SESSION_DOMAIN; do
  v="$(lower "$(env_get "$k")")"
  if [[ -n "$v" && "$v" =~ $TOKEN_RE ]]; then
    err "$k looks like a UAT/production value"
  fi
done

# ------------------------------------------------------------
# 5. Domain verification
# ------------------------------------------------------------
APP_URL_VALUE="$(env_get APP_URL)"
APP_HOST="$(host_of "$APP_URL_VALUE")"

if [[ -n "$APP_URL_VALUE" && ! "$APP_URL_VALUE" =~ ^https?:// ]]; then
  err "APP_URL must start with http:// or https://"
fi

if [[ -n "${EXPECTED_APP_HOST:-}" && -n "$APP_HOST" && "$APP_HOST" != "$(lower "$EXPECTED_APP_HOST")" ]]; then
  err "APP_URL host does not match the BACKEND_URL host configured for this deployment"
fi

if [[ -n "${FORBIDDEN_APP_HOSTS:-}" ]] && in_list "$APP_HOST" "$FORBIDDEN_APP_HOSTS"; then
  err "APP_URL points to a forbidden (UAT/production) host"
fi

# ------------------------------------------------------------
# 6. Database credential isolation
# ------------------------------------------------------------
DB_CONNECTION_LC="$(lower "$(env_get DB_CONNECTION)")"
DB_HOST_VALUE="$(env_get DB_HOST)"
DB_NAME_VALUE="$(env_get DB_DATABASE)"
DB_URL_VALUE="$(env_get DB_URL)"

case "$DB_CONNECTION_LC" in
  mysql|mariadb|pgsql|sqlsrv)
    if [[ -z "$DB_URL_VALUE" ]]; then
      for k in DB_HOST DB_DATABASE DB_USERNAME; do
        if [[ -z "$(env_get "$k")" ]]; then
          err "$k is required for DB_CONNECTION=$DB_CONNECTION_LC"
        fi
      done
    fi
    if [[ -z "$(env_get DB_PASSWORD)" && -z "$DB_URL_VALUE" ]]; then
      warn "DB_PASSWORD is empty"
    fi
    ;;
  sqlite|"")
    ;;
  *)
    warn "unrecognised DB_CONNECTION"
    ;;
esac

if [[ -n "$DB_URL_VALUE" ]]; then
  warn "DB_URL is set and overrides DB_HOST/DB_DATABASE"
  DB_URL_HOST="$(host_of "$DB_URL_VALUE")"
  if [[ -n "${FORBIDDEN_DB_HOSTS:-}" ]] && in_list "$DB_URL_HOST" "$FORBIDDEN_DB_HOSTS"; then
    err "DB_URL points to a forbidden (UAT/production) database host"
  fi
fi

if [[ -n "${FORBIDDEN_DB_HOSTS:-}" ]] && in_list "$DB_HOST_VALUE" "$FORBIDDEN_DB_HOSTS"; then
  err "DB_HOST is a forbidden (UAT/production) database host"
fi

if [[ -n "${FORBIDDEN_DB_NAMES:-}" ]] && in_list "$DB_NAME_VALUE" "$FORBIDDEN_DB_NAMES"; then
  err "DB_DATABASE is a forbidden (UAT/production) database"
fi

# ------------------------------------------------------------
# 7. File permissions
# ------------------------------------------------------------
if [[ -n "$(find "$ENV_FILE" -perm -004 2>/dev/null)" ]]; then
  warn "env file is world-readable; run: chmod 600 <file>"
fi

if (( ERRORS > 0 )); then
  echo "ENV ERROR: $ERRORS problem(s) found - deployment blocked" >&2
  exit 1
fi

echo "Environment validation passed"