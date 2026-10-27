#!/usr/bin/env bash
#
# SAMA Envios - installer
#
#   ./install.sh local  [options]   development machine (PHP + Composer + MySQL already installed)
#   ./install.sh vps    [options]   fresh Debian/Ubuntu server, run as root (Nginx, PHP-FPM, MySQL, Supervisor, SSL)
#   ./install.sh update [options]   after `git pull`: dependencies, migrations, caches, workers restart
#
# Options (all optional; missing values are asked for, or generated on the VPS):
#   --domain example.com        VPS: public domain (Nginx server_name + APP_URL + SSL)
#   --email you@example.com     VPS: e-mail for the Let's Encrypt certificate
#   --no-ssl                    VPS: serve over http only (e.g. behind another proxy / no DNS yet)
#   --db-host H --db-port P --db-name N --db-user U --db-pass PW
#   --db-root-pass PW           local: MySQL root password, used with --create-db
#   --create-db                 local: create the database/user (needs the mysql client and root access)
#   --admin-email E --admin-password PW   first administrator (password generated when omitted)
#   --evolution-url HOST --evolution-port PORT --evolution-key KEY --evolution-protocol http|https
#   --no-redis                  VPS: do not install Redis; keep the database queue and file cache
#   --groq-key KEY              Groq API key for the AI features
#   --start                     local: start the web server, queue workers and scheduler (./start.sh) at the end
#   --yes                       never ask questions (use defaults / flags)
#   --skip-composer --skip-migrate   (CI / advanced)
#
# Safe to run again: an existing .env keeps its APP_KEY and values you did not pass.

set -Eeuo pipefail
trap 'echo -e "\n\033[31m✗ Failed at line $LINENO. Fix the problem above and run the script again (it is safe to re-run).\033[0m" >&2' ERR

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODE="${1:-}"; [[ $# -gt 0 ]] && shift || true

DOMAIN=""; EMAIL=""; SSL=1
DB_HOST=""; DB_PORT=""; DB_NAME=""; DB_USER=""; DB_PASS=""; DB_ROOT_PASS=""; CREATE_DB=0
ADMIN_EMAIL=""; ADMIN_PASSWORD=""
EVO_URL=""; EVO_PORT=""; EVO_KEY=""; EVO_PROTO=""; GROQ_KEY=""
USE_REDIS=1; START=0; ASSUME_YES=0; SKIP_COMPOSER=0; SKIP_MIGRATE=0

usage() { sed -n '3,28p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2;;
    --email) EMAIL="$2"; shift 2;;
    --no-ssl) SSL=0; shift;;
    --db-host) DB_HOST="$2"; shift 2;;
    --db-port) DB_PORT="$2"; shift 2;;
    --db-name) DB_NAME="$2"; shift 2;;
    --db-user) DB_USER="$2"; shift 2;;
    --db-pass) DB_PASS="$2"; shift 2;;
    --db-root-pass) DB_ROOT_PASS="$2"; shift 2;;
    --create-db) CREATE_DB=1; shift;;
    --admin-email) ADMIN_EMAIL="$2"; shift 2;;
    --admin-password) ADMIN_PASSWORD="$2"; shift 2;;
    --evolution-url) EVO_URL="$2"; shift 2;;
    --evolution-port) EVO_PORT="$2"; shift 2;;
    --evolution-key) EVO_KEY="$2"; shift 2;;
    --evolution-protocol) EVO_PROTO="$2"; shift 2;;
    --groq-key) GROQ_KEY="$2"; shift 2;;
    --no-redis) USE_REDIS=0; shift;;
    --start) START=1; shift;;
    --yes|-y|--non-interactive) ASSUME_YES=1; shift;;
    --skip-composer) SKIP_COMPOSER=1; shift;;
    --skip-migrate) SKIP_MIGRATE=1; shift;;
    -h|--help) usage; exit 0;;
    *) echo "Unknown option: $1" >&2; usage; exit 1;;
  esac
done

# ---------------------------------------------------------------- helpers
c_ok()   { echo -e "\033[32m✓\033[0m $*"; }
c_info() { echo -e "\033[36m➜\033[0m $*"; }
c_warn() { echo -e "\033[33m!\033[0m $*"; }
die()    { echo -e "\033[31m✗ $*\033[0m" >&2; trap - ERR; exit 1; }
step()   { echo -e "\n\033[1m[$1] $2\033[0m"; }

interactive() { [[ $ASSUME_YES -eq 0 && -t 0 ]]; }

# ask VAR "Question" "default"  (keeps a value already set by a flag)
ask() {
  local var="$1" q="$2" def="${3:-}" cur="${!1:-}"
  [[ -n "$cur" ]] && return 0
  if interactive; then
    read -r -p "$q${def:+ [$def]}: " cur || true
  fi
  printf -v "$var" '%s' "${cur:-$def}"
}

rand() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "${1:-24}" || true; }

have() { command -v "$1" >/dev/null 2>&1; }

# set_env KEY VALUE  -> writes KEY=VALUE in .env (value quoted when needed); adds the key if missing
set_env() {
  local key="$1" val="$2" file="$APP_DIR/.env" out
  [[ "$val" =~ [[:space:]\#\"\'\$] ]] && out="\"${val//\"/\\\"}\"" || out="$val"
  if grep -qE "^${key}=" "$file"; then
    local tmp; tmp="$(mktemp)"
    KEY="$key" OUT="$out" awk 'BEGIN{k=ENVIRON["KEY"]; o=ENVIRON["OUT"]} index($0,k"=")==1{print k"="o; next} {print}' "$file" >"$tmp"
    cat "$tmp" >"$file"; rm -f "$tmp"
  else
    printf '%s=%s\n' "$key" "$out" >>"$file"
  fi
}

# env_get KEY -> current value in .env (quotes stripped), empty if missing
env_get() {
  [[ -f "$APP_DIR/.env" ]] || return 0
  local v; v="$(grep -E "^${1}=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- || true)"
  v="${v%\"}"; v="${v#\"}"
  printf '%s' "${v//\\\"/\"}"
}

# On a re-run, database settings that were not passed as flags come from the existing .env
prefill_from_env() {
  [[ -f "$APP_DIR/.env" ]] || return 0
  [[ -n "$DB_HOST" ]] || DB_HOST="$(env_get DB_HOST)"
  [[ -n "$DB_PORT" ]] || DB_PORT="$(env_get DB_PORT)"
  [[ -n "$DB_NAME" ]] || DB_NAME="$(env_get DB_DATABASE)"
  [[ -n "$DB_USER" ]] || DB_USER="$(env_get DB_USERNAME)"
  [[ -n "$DB_PASS" ]] || DB_PASS="$(env_get DB_PASSWORD)"
  return 0
}

artisan() { (cd "$APP_DIR" && php artisan "$@"); }

# ---------------------------------------------------------------- shared steps
php_check() {
  have php || die "PHP is not installed. Install PHP 8.1+ (8.3 recommended) with the extensions: mbstring, xml, curl, zip, gd, bcmath, mysql."
  php -r 'exit(version_compare(PHP_VERSION, "8.0.2", ">=") ? 0 : 1);' || die "PHP 8.0.2 or newer is required (found $(php -r 'echo PHP_VERSION;'))."
  local missing=()
  for ext in mbstring xml curl zip gd pdo_mysql fileinfo; do
    php -m | grep -qi "^${ext}$" || missing+=("$ext")
  done
  [[ ${#missing[@]} -eq 0 ]] || die "Missing PHP extensions: ${missing[*]}"
  php -m | grep -qi '^bcmath$' || c_warn "PHP extension bcmath is not installed (recommended; the Docker image includes it)."
  c_ok "PHP $(php -r 'echo PHP_VERSION;') with the required extensions"
}

ensure_env_file() {
  cd "$APP_DIR"
  if [[ ! -f .env ]]; then
    [[ -f .env.example ]] || die ".env.example not found in $APP_DIR"
    cp .env.example .env
    # .env.example ships sample values (including a sample APP_KEY): a new install always gets its own key
    NEW_ENV=1
  else
    NEW_ENV=0
    c_info ".env already exists: keeping APP_KEY and the values you did not pass."
  fi
}

generate_key() {
  if [[ $NEW_ENV -eq 1 ]] || ! grep -qE '^APP_KEY=base64:.{20,}' "$APP_DIR/.env"; then
    artisan key:generate --force >/dev/null
    c_ok "New APP_KEY generated"
  fi
}

apply_integrations() {
  [[ -n "$EVO_PROTO" ]] && set_env WHATSAPP_PROTOCOL "$EVO_PROTO"
  [[ -n "$EVO_URL"   ]] && set_env WHATSAPP_URL "$EVO_URL"
  [[ -n "$EVO_PORT"  ]] && set_env WHATSAPP_PORT "$EVO_PORT"
  [[ -n "$EVO_KEY"   ]] && set_env WHATSAPP_APIKEY "$EVO_KEY"
  [[ -n "$GROQ_KEY"  ]] && set_env GROQ_API_KEY "$GROQ_KEY"
  return 0
}

composer_install() {
  [[ $SKIP_COMPOSER -eq 1 ]] && { c_warn "Skipping composer install"; return; }
  have composer || die "Composer not found. Install it from https://getcomposer.org/download/"
  (cd "$APP_DIR" && COMPOSER_ALLOW_SUPERUSER=1 composer install "$@" --no-interaction --prefer-dist)
  c_ok "PHP dependencies installed"
}

migrate_db() {
  [[ $SKIP_MIGRATE -eq 1 ]] && { c_warn "Skipping migrations"; return; }
  artisan migrate --force
  c_ok "Database migrated (plans are seeded by the billing migration)"
}

create_admin() {
  [[ $SKIP_MIGRATE -eq 1 ]] && return 0
  ask ADMIN_EMAIL "Administrator e-mail (empty = skip)" ""
  [[ -z "$ADMIN_EMAIL" ]] && { c_warn "No administrator created. Create one later: php artisan user:admin you@example.com"; return 0; }
  local args=("$ADMIN_EMAIL")
  [[ -n "$ADMIN_PASSWORD" ]] && args+=(--password="$ADMIN_PASSWORD")
  artisan user:admin "${args[@]}"
}

# ================================================================= LOCAL
install_local() {
  echo -e "\033[1mSAMA Envios - local install\033[0m  ($APP_DIR)"
  step 1/6 "Checking requirements"
  php_check
  have composer || die "Composer not found. Install it from https://getcomposer.org/download/"
  have mysql || c_warn "mysql client not found: create the database yourself (or pass --db-* of an existing server)."

  step 2/6 "Dependencies"
  composer_install

  step 3/6 "Configuration (.env)"
  prefill_from_env
  ensure_env_file
  ask DB_HOST "MySQL host" "127.0.0.1"
  ask DB_PORT "MySQL port" "3306"
  ask DB_NAME "Database name" "whatsapp_sender"
  ask DB_USER "Database user" "root"
  if [[ -z "$DB_PASS" ]] && interactive; then read -r -s -p "Database password (empty = none): " DB_PASS; echo; fi
  set_env APP_ENV local
  set_env APP_DEBUG true
  set_env APP_URL "http://localhost:8000"
  set_env FORCE_HTTPS false
  set_env DB_CONNECTION mysql
  set_env DB_HOST "${DB_HOST:-127.0.0.1}"
  set_env DB_PORT "${DB_PORT:-3306}"
  set_env DB_DATABASE "${DB_NAME:-whatsapp_sender}"
  set_env DB_USERNAME "${DB_USER:-root}"
  set_env DB_PASSWORD "$DB_PASS"
  set_env QUEUE_CONNECTION database
  set_env MAIL_MAILER log
  apply_integrations
  generate_key
  c_ok ".env written"

  step 4/6 "Database"
  if [[ $CREATE_DB -eq 1 ]]; then
    have mysql || die "--create-db needs the mysql client."
    local rootpass="${DB_ROOT_PASS:-}"
    if [[ -z "$rootpass" ]] && interactive; then read -r -s -p "MySQL root password (empty = none): " rootpass; echo; fi
    local root=(mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u root ${rootpass:+-p"$rootpass"})
    "${root[@]}" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    if [[ "${DB_USER}" != "root" ]]; then
      "${root[@]}" -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}'; GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;"
    fi
    c_ok "Database ${DB_NAME} ready"
  fi
  migrate_db
  artisan storage:link >/dev/null 2>&1 || true

  step 5/6 "Administrator"
  create_admin

  step 6/6 "Done"
  chmod +x "$APP_DIR"/*.sh 2>/dev/null || true
  echo
  c_ok "Local install finished."
  cat <<EOM

  Start everything (web on :8000, queue workers, scheduler):   ./start.sh      (stop: ./stop.sh)
  Open:  http://localhost:8000

  Still to do by hand:
    • Evolution API: set WHATSAPP_URL / WHATSAPP_PORT / WHATSAPP_APIKEY in .env (or re-run with --evolution-* flags)
    • GROQ_API_KEY in .env for the AI features
    • Plans and payments (optional): BILLING_ENABLED=true, then Admin → Payment gateway
EOM
  [[ $START -eq 1 ]] && { echo; c_info "Starting services..."; (cd "$APP_DIR" && bash ./start.sh); }
  return 0
}

# ================================================================= VPS
APT_PKGS=(nginx mysql-server supervisor git unzip curl ca-certificates cron openssl
          php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-zip php-gd php-bcmath php-intl)

install_composer_vps() {
  have composer && return 0
  c_info "Installing Composer"
  local tmp; tmp="$(mktemp -d)"
  local expected actual
  expected="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php"
  actual="$(php -r "echo hash_file('sha384', '$tmp/composer-setup.php');")"
  [[ "$expected" == "$actual" ]] || die "Composer installer signature mismatch; aborting."
  php "$tmp/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
  rm -rf "$tmp"
  c_ok "Composer installed"
}

php_fpm_socket() {
  local s
  s="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)"
  [[ -n "$s" ]] || die "PHP-FPM socket not found in /run/php. Is php-fpm running?"
  echo "$s"
}

php_fpm_service() {
  systemctl list-units --type=service --all 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}' | head -n1
}

install_vps() {
  [[ $EUID -eq 0 ]] || die "Run the VPS install as root:  sudo ./install.sh vps --domain example.com --email you@example.com"
  have apt-get || die "The VPS installer supports Debian/Ubuntu (apt). On other systems use the Docker stack in .docker/ (see README)."
  echo -e "\033[1mSAMA Envios - VPS install\033[0m  ($APP_DIR)"
  case "$APP_DIR" in
    /root/*|/home/*) c_warn "The project is under ${APP_DIR}: Nginx (www-data) may not be able to read it. Recommended: clone into /var/www/sama and run the script from there." ;;
  esac

  prefill_from_env
  ask DOMAIN "Domain pointing to this server (empty = access by IP, http only)" ""
  if [[ -n "$DOMAIN" && $SSL -eq 1 ]]; then ask EMAIL "E-mail for the SSL certificate (Let's Encrypt)" ""; fi
  [[ -n "$DOMAIN" && $SSL -eq 1 && -z "$EMAIL" ]] && { c_warn "No e-mail given: SSL will be skipped (add it with certbot later)."; SSL=0; }
  [[ -z "$DOMAIN" ]] && SSL=0

  step 1/9 "System packages"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  local pkgs=("${APT_PKGS[@]}")
  [[ $SSL -eq 1 ]] && pkgs+=(certbot python3-certbot-nginx)
  [[ $USE_REDIS -eq 1 ]] && pkgs+=(redis-server)
  # MySQL is only installed when no external database host was given
  if [[ -n "$DB_HOST" && "$DB_HOST" != "127.0.0.1" && "$DB_HOST" != "localhost" ]]; then
    pkgs=("${pkgs[@]/mysql-server/default-mysql-client}")
  fi
  apt-get install -y -qq "${pkgs[@]}" >/dev/null
  systemctl enable --now nginx supervisor cron >/dev/null 2>&1 || true
  [[ $USE_REDIS -eq 1 ]] && { systemctl enable --now redis-server >/dev/null 2>&1 || true; }
  have mysqld && systemctl enable --now mysql >/dev/null 2>&1 || true
  php_check
  install_composer_vps

  step 2/9 "PHP settings (uploads, memory)"
  local phpver; phpver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  for sapi in fpm cli; do
    if [[ -d "/etc/php/$phpver/$sapi/conf.d" ]]; then
      cat >"/etc/php/$phpver/$sapi/conf.d/99-sama.ini" <<'INI'
memory_limit=512M
upload_max_filesize=100M
post_max_size=100M
max_execution_time=600
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
INI
    fi
  done
  local fpm_svc; fpm_svc="$(php_fpm_service)"
  [[ -n "$fpm_svc" ]] && systemctl restart "$fpm_svc"
  c_ok "PHP $phpver configured"

  step 3/9 "Database"
  DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-whatsapp_sender}"; DB_USER="${DB_USER:-sama}"
  GENERATED_DB_PASS=0
  if [[ "$DB_HOST" == "127.0.0.1" || "$DB_HOST" == "localhost" ]]; then
    if [[ -z "$DB_PASS" ]]; then
      # Re-runs reuse the password already in .env instead of rotating it
      if [[ -f "$APP_DIR/.env" ]] && grep -qE '^DB_HOST=(127\.0\.0\.1|localhost)$' "$APP_DIR/.env"; then
        DB_PASS="$(grep -E '^DB_PASSWORD=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"')"
      fi
      [[ -z "$DB_PASS" ]] && { DB_PASS="$(rand 24)"; GENERATED_DB_PASS=1; }
    fi
    mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
    c_ok "Database ${DB_NAME} and user ${DB_USER} ready"
  else
    [[ -n "$DB_PASS" ]] || die "External database: pass --db-pass (and --db-user / --db-name)."
    c_info "Using the external database at ${DB_HOST}:${DB_PORT}"
  fi

  step 4/9 "Application (.env, dependencies)"
  ensure_env_file
  local scheme="http"; [[ $SSL -eq 1 ]] && scheme="https"
  local url="${scheme}://${DOMAIN:-$(curl -fsS -m 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')}"
  set_env APP_ENV production
  set_env APP_DEBUG false
  set_env LOG_LEVEL warning
  set_env APP_URL "$url"
  set_env FORCE_HTTPS "$([[ $SSL -eq 1 ]] && echo true || echo false)"
  set_env DB_CONNECTION mysql
  set_env DB_HOST "$DB_HOST"
  set_env DB_PORT "$DB_PORT"
  set_env DB_DATABASE "$DB_NAME"
  set_env DB_USERNAME "$DB_USER"
  set_env DB_PASSWORD "$DB_PASS"
  if [[ $USE_REDIS -eq 1 ]]; then
    # queues, cache and the per-session send locks in Redis (predis client: no PHP extension needed)
    set_env QUEUE_CONNECTION redis
    set_env CACHE_DRIVER redis
    set_env REDIS_CLIENT predis
    set_env REDIS_HOST 127.0.0.1
    set_env REDIS_PASSWORD null
    set_env REDIS_PORT 6379
  else
    set_env QUEUE_CONNECTION database
    set_env CACHE_DRIVER file
  fi
  set_env SESSION_DRIVER file
  apply_integrations
  generate_key
  chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
  chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
  composer_install --no-dev --optimize-autoloader

  step 5/9 "Migrations and first administrator"
  migrate_db
  artisan storage:link >/dev/null 2>&1 || true
  create_admin
  artisan config:cache >/dev/null
  artisan view:cache >/dev/null
  chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

  step 6/9 "Web server (Nginx)"
  local sock site="/etc/nginx/sites-available/sama"; sock="$(php_fpm_socket)"
  cat >"$site" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN:-_};
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size 100M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${sock};
        fastcgi_read_timeout 300;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\.(?!well-known) { deny all; }
}
NGINX
  ln -sf "$site" /etc/nginx/sites-enabled/sama
  # the distribution's placeholder site would otherwise answer for unknown hosts / the bare IP
  [[ -z "$DOMAIN" ]] && rm -f /etc/nginx/sites-enabled/default
  nginx -t
  systemctl reload nginx
  c_ok "Nginx serving ${APP_DIR}/public"

  step 7/9 "Queue workers and scheduler (Supervisor)"
  cat >/etc/supervisor/conf.d/sama.conf <<SUP
[program:sama-disparos]
command=php ${APP_DIR}/artisan queue:work --queue=disparos --sleep=3 --tries=3
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=${APP_DIR}/storage/logs/worker_disparos.log
stderr_logfile=${APP_DIR}/storage/logs/worker_disparos.err.log

[program:sama-default]
command=php ${APP_DIR}/artisan queue:work --queue=default --sleep=3 --tries=3
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=${APP_DIR}/storage/logs/worker_default.log
stderr_logfile=${APP_DIR}/storage/logs/worker_default.err.log

[program:sama-warmup]
command=php ${APP_DIR}/artisan queue:work --queue=warmup --sleep=3 --tries=3
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=${APP_DIR}/storage/logs/worker_warmup.log
stderr_logfile=${APP_DIR}/storage/logs/worker_warmup.err.log

[program:sama-schedule]
command=php ${APP_DIR}/artisan schedule:work
user=www-data
autostart=true
autorestart=true
stdout_logfile=${APP_DIR}/storage/logs/schedule.log
stderr_logfile=${APP_DIR}/storage/logs/schedule.err.log

[group:sama]
programs=sama-disparos,sama-default,sama-warmup,sama-schedule
SUP
  supervisorctl reread >/dev/null
  supervisorctl update >/dev/null
  supervisorctl restart 'sama:*' >/dev/null 2>&1 || true
  c_ok "Workers: disparos, default, warmup, schedule"

  step 8/9 "SSL"
  if [[ $SSL -eq 1 ]]; then
    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect; then
      c_ok "HTTPS enabled for $DOMAIN (auto-renewal is installed by certbot)"
    else
      c_warn "Certbot failed (does the DNS of $DOMAIN point to this server, and are ports 80/443 open?). The site works over http; retry:  certbot --nginx -d $DOMAIN"
      set_env APP_URL "http://$DOMAIN"; set_env FORCE_HTTPS false; artisan config:cache >/dev/null
    fi
  else
    c_info "SSL skipped"
  fi

  step 9/9 "Firewall"
  if have ufw && ufw status | grep -q "Status: active"; then
    ufw allow OpenSSH >/dev/null; ufw allow 'Nginx Full' >/dev/null
    c_ok "ufw: SSH, 80 and 443 allowed"
  else
    c_info "ufw not active: nothing changed (make sure ports 80/443 are open at your provider)"
  fi

  echo
  c_ok "VPS install finished:  ${url}"
  if [[ ${GENERATED_DB_PASS:-0} -eq 1 ]]; then
    c_warn "A database password was generated and stored in ${APP_DIR}/.env (DB_PASSWORD)."
  fi
  cat <<EOM

  Still to do:
    • Evolution API: point WHATSAPP_PROTOCOL / WHATSAPP_URL / WHATSAPP_PORT / WHATSAPP_APIKEY in ${APP_DIR}/.env to your instance
      (then:  php artisan config:cache && supervisorctl restart 'sama:*'). The Docker stack in .docker/ can host it.
    • GROQ_API_KEY in .env for the AI features, and the SMTP settings (MAIL_*) for e-mails.
    • Plans and payments (optional): BILLING_ENABLED=true, then Admin → Payment gateway; register the webhook
      ${url}/api/webhooks/mercadopago
    • Updating later:  git pull && sudo ./install.sh update
EOM
}

# ================================================================= UPDATE
update_app() {
  echo -e "\033[1mSAMA Envios - update\033[0m  ($APP_DIR)"
  [[ -f "$APP_DIR/.env" ]] || die "No .env found: run './install.sh local' or './install.sh vps' first."
  php_check
  local env; env="$(grep -E '^APP_ENV=' "$APP_DIR/.env" | cut -d= -f2-)"
  if [[ "$env" == "production" ]]; then composer_install --no-dev --optimize-autoloader; else composer_install; fi
  migrate_db
  artisan billing:sync-plans >/dev/null 2>&1 || true
  artisan optimize:clear >/dev/null
  if [[ "$env" == "production" ]]; then artisan config:cache >/dev/null; artisan view:cache >/dev/null; fi
  artisan queue:restart >/dev/null 2>&1 || true
  if have supervisorctl && [[ $EUID -eq 0 ]] && [[ -f /etc/supervisor/conf.d/sama.conf ]]; then
    supervisorctl restart 'sama:*' >/dev/null 2>&1 || true
  fi
  [[ $EUID -eq 0 ]] && chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
  c_ok "Updated. (New plan features need:  php artisan billing:sync-plans --force  if you edited plans in config/billing.php)"
}

case "$MODE" in
  local)  install_local;;
  vps)    install_vps;;
  update) update_app;;
  -h|--help|help|"") usage; [[ -z "$MODE" ]] && exit 1 || exit 0;;
  *) echo "Unknown mode: $MODE" >&2; usage; exit 1;;
esac
