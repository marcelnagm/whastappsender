#!/usr/bin/env bash
#
# SAMA Envios - local development: starts the infrastructure in Docker and checks it.
#
# PHP runs on YOUR machine (php artisan serve, queue workers, scheduler); Docker only provides
# MySQL, Evolution API (+ Postgres), MinIO (S3) and Mailpit (e-mail) - see docker-compose.local.yml.
#
#   ./start-local.sh              check .env credentials, start the services, verify them, migrate
#   ./start-local.sh check        only verify (services must already be running)
#   ./start-local.sh status       containers + addresses
#   ./start-local.sh logs [svc]   follow the logs (mysql | evolution-api | minio | mailpit)
#   ./start-local.sh down         stop the services (data is kept) and any PHP started with --php
#   ./start-local.sh reset        stop AND DELETE all local data (database, WhatsApp sessions, files)
#
# Options:
#   --fix-env            rewrite the .env values that do not fit the local stack (a backup is kept), without asking
#   --yes                answer yes to every question
#   --no-migrate         do not run the migrations
#   --admin-email E      create / promote this administrator after migrating (password is printed)
#   --no-redis           keep the database queue / file cache (Redis container is still started, just unused)
#   --tools              also start phpMyAdmin (http://localhost:8082)
#   --php                also start `php artisan serve`, the queue workers and the scheduler in the background
#
# The credentials live in the Laravel .env (docker compose reads the same file), so there is one source of truth.

set -Eeuo pipefail
trap 'echo -e "\n\033[31m✗ Failed at line $LINENO. See the message above; the script is safe to run again.\033[0m" >&2' ERR

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_DIR"
COMPOSE_FILE="docker-compose.local.yml"
ENV_FILE="$APP_DIR/.env"
PID_FILE="$APP_DIR/storage/local-dev.pids"
LOG_DIR="$APP_DIR/storage/logs/local"

CMD="up"
if [[ "${1:-}" =~ ^(up|check|status|logs|down|reset)$ ]]; then CMD="$1"; shift; fi
LOG_SVC=""; [[ "$CMD" == "logs" && "${1:-}" != --* && -n "${1:-}" ]] && { LOG_SVC="$1"; shift; }

FIX_ENV=0; ASSUME_YES=0; MIGRATE=1; ADMIN_EMAIL=""; TOOLS=0; START_PHP=0; USE_REDIS=1
while [[ $# -gt 0 ]]; do
  case "$1" in
    --fix-env) FIX_ENV=1; shift;;
    --yes|-y) ASSUME_YES=1; shift;;
    --no-migrate) MIGRATE=0; shift;;
    --admin-email) ADMIN_EMAIL="$2"; shift 2;;
    --no-redis) USE_REDIS=0; shift;;
    --tools) TOOLS=1; shift;;
    --php) START_PHP=1; shift;;
    -h|--help) sed -n '3,25p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0;;
    *) echo "Unknown option: $1 (try --help)" >&2; exit 1;;
  esac
done

# ---------------------------------------------------------------- helpers
ok()   { echo -e "  \033[32m✓\033[0m $*"; }
info() { echo -e "  \033[36m➜\033[0m $*"; }
warn() { echo -e "  \033[33m!\033[0m $*"; }
bad()  { echo -e "  \033[31m✗\033[0m $*"; }
die()  { echo -e "\n\033[31m✗ $*\033[0m" >&2; trap - ERR; exit 1; }
step() { echo -e "\n\033[1m$*\033[0m"; }
have() { command -v "$1" >/dev/null 2>&1; }
interactive() { [[ $ASSUME_YES -eq 0 && -t 0 ]]; }
confirm() { # confirm "question"  -> 0 = yes
  [[ $ASSUME_YES -eq 1 || $FIX_ENV -eq 1 ]] && return 0
  interactive || return 1
  local a; read -r -p "$1 [y/N] " a || true
  [[ "$a" =~ ^[yYsS] ]]
}

rand() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "${1:-24}" || true; }

env_get() { # KEY -> value without quotes ('' if missing)
  [[ -f "$ENV_FILE" ]] || return 0
  local v; v="$(grep -E "^${1}=" "$ENV_FILE" | tail -n1 | cut -d= -f2- || true)"
  v="${v%\"}"; v="${v#\"}"
  printf '%s' "${v//\\\"/\"}"
}

set_env() { # KEY VALUE
  local key="$1" val="$2" out tmp
  [[ "$val" =~ [[:space:]\#\"\'\$] ]] && out="\"${val//\"/\\\"}\"" || out="$val"
  if grep -qE "^${key}=" "$ENV_FILE"; then
    tmp="$(mktemp)"
    KEY="$key" OUT="$out" awk 'BEGIN{k=ENVIRON["KEY"]; o=ENVIRON["OUT"]} index($0,k"=")==1{print k"="o; next} {print}' "$ENV_FILE" >"$tmp"
    cat "$tmp" >"$ENV_FILE"; rm -f "$tmp"
  else
    printf '%s=%s\n' "$key" "$out" >>"$ENV_FILE"
  fi
}

dc() { docker compose -f "$COMPOSE_FILE" "$@"; }

port_in_use() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null && { exec 3>&- 3<&-; return 0; } || return 1; }

stack_running() { [[ -n "$(dc ps -q 2>/dev/null | head -n1)" ]]; }

APP_PORT_DEFAULT=8000
app_port() { local p; p="$(env_get APP_PORT)"; echo "${p:-$APP_PORT_DEFAULT}"; }

# ---------------------------------------------------------------- preflight
preflight() {
  step "1/6  Requirements"
  have docker || die "Docker is not installed. Install Docker Desktop (Mac/Windows) or Docker Engine + the compose plugin (Linux)."
  docker compose version >/dev/null 2>&1 || die "'docker compose' (v2 plugin) not found. Update Docker or install docker-compose-plugin."
  docker info >/dev/null 2>&1 || die "The Docker daemon is not running (or your user cannot reach it). Start Docker; on Linux: sudo systemctl start docker, and add yourself to the 'docker' group."
  ok "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo ok), compose $(docker compose version --short 2>/dev/null || echo ok)"
  have curl || die "curl is required for the health checks."
  if have php; then ok "PHP $(php -r 'echo PHP_VERSION;') on this machine"; else warn "php not found: migrations and the PHP checks are skipped (install PHP 8.1+ to run the app)."; fi
  [[ -d "$APP_DIR/vendor" ]] || warn "vendor/ missing: run 'composer install' before starting PHP."
  [[ -f "$APP_DIR/$COMPOSE_FILE" ]] || die "$COMPOSE_FILE not found next to this script."
}

# ---------------------------------------------------------------- .env
SAMPLE_VALUES=("password_mysql_123" "BQYHJGJHJ" "sua_senha_forte_123" "root_password_456")
ISSUES=()

is_sample() { local x; for x in "${SAMPLE_VALUES[@]}"; do [[ "$1" == "$x" ]] && return 0; done; return 1; }
is_local_host() { [[ "$1" == "127.0.0.1" || "$1" == "localhost" ]]; }

ensure_env_exists() {
  if [[ ! -f "$ENV_FILE" ]]; then
    [[ -f "$APP_DIR/.env.example" ]] || die ".env and .env.example not found."
    cp "$APP_DIR/.env.example" "$ENV_FILE"
    warn ".env created from .env.example (its sample values are replaced by local ones below)."
    NEW_ENV=1
  else
    NEW_ENV=0
  fi
}

collect_issues() {
  ISSUES=()
  local v
  [[ "$(env_get APP_KEY)" == base64:?* ]] || ISSUES+=("APP_KEY is empty")
  [[ "$NEW_ENV" -eq 1 ]] && ISSUES+=("APP_KEY comes from .env.example (shared by everyone who cloned the repo)")

  v="$(env_get DB_HOST)"; is_local_host "$v" || ISSUES+=("DB_HOST='$v' is not this machine: the migrations would run against that database")
  v="$(env_get DB_USERNAME)"; [[ -n "$v" && "$v" != "root" ]] || ISSUES+=("DB_USERNAME must be a normal user (not empty / root): the MySQL image creates it for you")
  v="$(env_get DB_PASSWORD)"; { [[ -n "$v" ]] && ! is_sample "$v"; } || ISSUES+=("DB_PASSWORD is empty or a sample value from .env.example")
  [[ -n "$(env_get DB_DATABASE)" ]] || ISSUES+=("DB_DATABASE is empty")

  v="$(env_get WHATSAPP_URL)"; is_local_host "$v" || ISSUES+=("WHATSAPP_URL='$v' does not point to the local Evolution API (use localhost)")
  [[ "$(env_get WHATSAPP_PROTOCOL)" == "http" ]] || ISSUES+=("WHATSAPP_PROTOCOL must be http for the local Evolution API")
  v="$(env_get WHATSAPP_APIKEY)"; { [[ ${#v} -ge 8 ]] && ! is_sample "$v"; } || ISSUES+=("WHATSAPP_APIKEY is empty / too short / a sample value")

  [[ -n "$(env_get AWS_ACCESS_KEY_ID)" ]] || ISSUES+=("AWS_ACCESS_KEY_ID (MinIO user) is empty")
  v="$(env_get AWS_SECRET_ACCESS_KEY)"; { [[ ${#v} -ge 8 ]] && ! is_sample "$v"; } || ISSUES+=("AWS_SECRET_ACCESS_KEY (MinIO password) must have 8+ characters and not be a sample value")
  v="$(env_get AWS_ENDPOINT)"; [[ "$v" == http://localhost:* || "$v" == http://127.0.0.1:* ]] || ISSUES+=("AWS_ENDPOINT='$v' is not the local MinIO (http://localhost:9000)")
  [[ -n "$(env_get AWS_BUCKET)" ]] || ISSUES+=("AWS_BUCKET is empty")

  v="$(env_get MAIL_HOST)"; is_local_host "$v" || ISSUES+=("MAIL_HOST='$v': e-mails would not reach Mailpit (use 127.0.0.1)")
  return 0
}

apply_local_env() {
  local backup="$ENV_FILE.backup-$(date +%Y%m%d%H%M%S)"
  cp "$ENV_FILE" "$backup"
  info "Backup of the old .env: $(basename "$backup")"

  local port; port="$(app_port)"
  set_env APP_ENV local
  set_env APP_DEBUG true
  set_env APP_URL "http://localhost:${port}"
  set_env FORCE_HTTPS false
  if [[ $USE_REDIS -eq 1 ]]; then
    # queues, cache and the per-session send locks live in Redis (container 'redis')
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

  if [[ "$(env_get APP_KEY)" != base64:?* || "$NEW_ENV" -eq 1 ]]; then
    have php && [[ -d vendor ]] && php artisan key:generate --force >/dev/null || warn "Could not generate APP_KEY (php/vendor missing): run 'php artisan key:generate'."
  fi

  if [[ "$NEW_ENV" -eq 1 ]]; then   # values copied from .env.example describe the author's server, not this machine
    set_env DB_DATABASE whatsapp_sender; set_env DB_USERNAME sama; set_env DB_PORT 3306
    set_env WHATSAPP_PORT 8080; set_env AWS_ACCESS_KEY_ID sama_local
  fi
  local v
  is_local_host "$(env_get DB_HOST)" || set_env DB_HOST 127.0.0.1
  set_env DB_CONNECTION mysql
  [[ -n "$(env_get DB_PORT)" && "$(env_get DB_HOST)" == "127.0.0.1" ]] || set_env DB_PORT 3306
  [[ -n "$(env_get DB_DATABASE)" ]] || set_env DB_DATABASE whatsapp_sender
  v="$(env_get DB_USERNAME)"; [[ -n "$v" && "$v" != "root" ]] || set_env DB_USERNAME sama
  v="$(env_get DB_PASSWORD)"; { [[ -n "$v" ]] && ! is_sample "$v"; } || set_env DB_PASSWORD "$(rand 20)"

  set_env WHATSAPP_PROTOCOL http
  set_env WHATSAPP_URL localhost
  [[ -n "$(env_get WHATSAPP_PORT)" ]] || set_env WHATSAPP_PORT 8080
  set_env MIX_WHATSAPP_PUBLIC_PROTOCOL http
  set_env MIX_WHATSAPP_PUBLIC_URL localhost
  set_env MIX_WHATSAPP_PUBLIC_PORT "$(env_get WHATSAPP_PORT)"
  v="$(env_get WHATSAPP_APIKEY)"; { [[ ${#v} -ge 8 ]] && ! is_sample "$v"; } || set_env WHATSAPP_APIKEY "$(rand 32)"

  [[ -n "$(env_get AWS_ACCESS_KEY_ID)" ]] && ! is_sample "$(env_get AWS_ACCESS_KEY_ID)" || set_env AWS_ACCESS_KEY_ID sama_local
  v="$(env_get AWS_SECRET_ACCESS_KEY)"; { [[ ${#v} -ge 8 ]] && ! is_sample "$v"; } || set_env AWS_SECRET_ACCESS_KEY "$(rand 24)"
  [[ -n "$(env_get AWS_BUCKET)" ]] || set_env AWS_BUCKET ads
  set_env AWS_DEFAULT_REGION us-east-1
  set_env AWS_USE_PATH_STYLE_ENDPOINT true
  # Laravel (on the host) uploads through localhost; the public URL must also be reachable from the Evolution container,
  # where "localhost" is the container itself, so it goes through host.docker.internal.
  set_env AWS_ENDPOINT "http://localhost:9000"
  set_env AWS_URL "http://host.docker.internal:9000/$(env_get AWS_BUCKET)"

  set_env MAIL_MAILER smtp
  set_env MAIL_HOST 127.0.0.1
  set_env MAIL_PORT 1025
  set_env MAIL_USERNAME null
  set_env MAIL_PASSWORD null
  set_env MAIL_ENCRYPTION null
  ok ".env now matches the local stack"
}

check_env() {
  step "2/6  Credentials in .env"
  ensure_env_exists
  collect_issues
  if [[ ${#ISSUES[@]} -eq 0 ]]; then ok ".env is consistent with the local stack"; return; fi

  for i in "${ISSUES[@]}"; do bad "$i"; done
  echo
  if confirm "Fix these values in .env now (a backup is kept)?"; then
    apply_local_env
    NEW_ENV=0   # the shared sample key was replaced
    collect_issues
    [[ ${#ISSUES[@]} -eq 0 ]] || { for i in "${ISSUES[@]}"; do bad "$i"; done; die "Some values still need your attention in .env."; }
  else
    die "Fix the values above in .env (or run again with --fix-env to let the script do it)."
  fi
}

# host.docker.internal must resolve on the host too, or media previews (AWS_URL) break in the browser
check_host_alias() {
  if getent hosts host.docker.internal >/dev/null 2>&1 || [[ "$(uname -s)" != "Linux" ]]; then
    ok "host.docker.internal resolves"
  else
    warn "host.docker.internal does not resolve on this Linux host, so uploaded media previews will not load in the browser."
    warn "Fix once:  echo '127.0.0.1 host.docker.internal' | sudo tee -a /etc/hosts"
  fi
}

# ---------------------------------------------------------------- start
check_ports() {
  step "3/6  Ports"
  if stack_running; then ok "The local stack is already running: ports are ours"; return; fi
  local busy=0 p name
  for pair in "$(env_get DB_PORT):MySQL (DB_PORT)" "$(env_get WHATSAPP_PORT):Evolution API (WHATSAPP_PORT)" "9000:MinIO" "9001:MinIO console" "${REDIS_PORT_CHECK:-6379}:Redis" "1025:Mailpit SMTP" "8025:Mailpit inbox"; do
    p="${pair%%:*}"; name="${pair#*:}"; p="${p:-}"
    [[ -z "$p" ]] && continue
    if port_in_use "$p"; then bad "port $p ($name) is already in use on this machine (a local MySQL / another stack?)"; busy=1; else ok "port $p free ($name)"; fi
  done
  [[ $busy -eq 0 ]] || die "Free those ports (or change DB_PORT / WHATSAPP_PORT in .env) and run again."
}

start_stack() {
  step "4/6  Starting the services"
  local profile=(); [[ $TOOLS -eq 1 ]] && profile=(--profile tools)
  dc "${profile[@]}" up -d
  ok "Containers started"
}

# ---------------------------------------------------------------- verification
wait_healthy() { # container seconds
  local c="$1" t="$2" i s
  for ((i = 0; i < t; i += 2)); do
    s="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$c" 2>/dev/null || echo missing)"
    [[ "$s" == "healthy" || "$s" == "running" ]] && return 0
    sleep 2
  done
  return 1
}

http_code() { curl -s -o /dev/null -m 5 -w '%{http_code}' "$@" 2>/dev/null || echo 000; }

FAILED=0
verify_mysql() {
  local db user pass host port
  db="$(env_get DB_DATABASE)"; user="$(env_get DB_USERNAME)"; pass="$(env_get DB_PASSWORD)"; host="$(env_get DB_HOST)"; port="$(env_get DB_PORT)"; port="${port:-3306}"
  if ! wait_healthy sama_local_mysql 120; then
    bad "MySQL did not become healthy in 2 minutes. Look at:  ./start-local.sh logs mysql"
    info "If you changed DB_USERNAME / DB_PASSWORD / DB_DATABASE after the first start, MySQL still has the old ones: ./start-local.sh reset"
    FAILED=1; return
  fi
  if dc exec -T -e "MYSQL_PWD=$pass" mysql mysql -u"$user" -e "SELECT 1" "$db" >/dev/null 2>&1; then
    ok "MySQL: user '$user' can open database '$db'"
  else
    bad "MySQL rejected '$user' / the password from .env for database '$db'."
    info "MySQL only reads these values the FIRST time its data volume is created. If you changed them since, run:  ./start-local.sh reset  (deletes local data)"
    FAILED=1; return
  fi
  if have php; then
    if DBH="$host" DBP="$port" DBN="$db" DBU="$user" DBW="$pass" php -r 'try { new PDO("mysql:host=".getenv("DBH").";port=".getenv("DBP").";dbname=".getenv("DBN"), getenv("DBU"), getenv("DBW"), [PDO::ATTR_TIMEOUT=>5]); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }' 2>/tmp/sama_pdo.err; then
      ok "PHP on this machine reaches MySQL at ${host}:${port}"
    else
      bad "PHP cannot connect to ${host}:${port}: $(head -c 200 /tmp/sama_pdo.err 2>/dev/null) (is pdo_mysql installed?)"
      FAILED=1
    fi
  fi
}

verify_evolution() {
  local key port code i; key="$(env_get WHATSAPP_APIKEY)"; port="$(env_get WHATSAPP_PORT)"; port="${port:-8080}"
  for ((i = 0; i < 90; i += 3)); do
    code="$(http_code -H "apikey: $key" "http://localhost:${port}/instance/fetchInstances")"
    [[ "$code" != "000" && "$code" != "502" && "$code" != "503" ]] && break
    sleep 3
  done
  case "$code" in
    200) ok "Evolution API answers on :${port} and accepts WHATSAPP_APIKEY" ;;
    401|403) bad "Evolution API is up but rejected WHATSAPP_APIKEY (HTTP $code). Run './start-local.sh' again so the container is recreated with the .env value."; FAILED=1 ;;
    *) bad "Evolution API did not answer on :${port} (HTTP $code). See:  ./start-local.sh logs evolution-api"; FAILED=1 ;;
  esac
  # authentication really is enforced (a wrong key must not work)
  if [[ "$code" == "200" ]]; then
    [[ "$(http_code -H 'apikey: wrong-key' "http://localhost:${port}/instance/fetchInstances")" =~ ^40[13]$ ]] && ok "Evolution API rejects a wrong key" || warn "Evolution API accepted a wrong key: authentication seems disabled"
  fi
}

verify_minio() {
  local i s code
  for ((i = 0; i < 90; i += 2)); do
    s="$(docker inspect -f '{{.State.Status}}' sama_local_minio_setup 2>/dev/null || echo missing)"
    [[ "$s" == "exited" ]] && break
    sleep 2
  done
  code="$(docker inspect -f '{{.State.ExitCode}}' sama_local_minio_setup 2>/dev/null || echo 1)"
  if [[ "$s" == "exited" && "$code" == "0" ]]; then
    ok "MinIO: credentials accepted, bucket '$(env_get AWS_BUCKET)' ready and public-read"
  else
    bad "MinIO setup failed (bucket / credentials). Output:"
    dc logs --no-log-prefix --tail 8 minio-setup 2>/dev/null | sed 's/^/        /' || true
    FAILED=1; return
  fi
  [[ "$(http_code "http://localhost:9000/minio/health/live")" == "200" ]] && ok "MinIO S3 endpoint answers on :9000 (console: http://localhost:9001)" || { bad "MinIO endpoint :9000 does not answer"; FAILED=1; }
}

verify_redis() {
  local out
  out="$(docker exec sama_local_redis redis-cli ping 2>/dev/null || true)"
  if [[ "$out" == "PONG" ]]; then
    ok "Redis answers (:6379). Queue driver: $(env_get QUEUE_CONNECTION), cache: $(env_get CACHE_DRIVER)"
  else
    bad "Redis does not answer (container sama_local_redis)"; FAILED=1; return
  fi
  if [[ "$(env_get QUEUE_CONNECTION)" == "redis" && -d vendor && ! -d vendor/predis/predis ]]; then
    bad "QUEUE_CONNECTION=redis but predis is not installed: run 'composer install'"; FAILED=1
  fi
  if [[ "$(env_get QUEUE_CONNECTION)" == "redis" ]] && have php && [[ -d vendor ]]; then
    local pending; pending="$(php artisan tinker --execute='echo \DB::table("jobs")->count();' 2>/dev/null | tail -n1 || true)"
    [[ "$pending" =~ ^[0-9]+$ && "$pending" -gt 0 ]] && warn "$pending job(s) are still in the OLD database queue. Drain them once: php artisan queue:work database --stop-when-empty"
  fi
  return 0
}

verify_mailpit() {
  [[ "$(http_code "http://localhost:8025/api/v1/info")" == "200" ]] && ok "Mailpit: SMTP :1025, inbox http://localhost:8025" || { bad "Mailpit does not answer on :8025"; FAILED=1; }
}

verify_all() {
  step "5/6  Verifying the services with your credentials"
  FAILED=0
  verify_mysql
  verify_redis
  verify_evolution
  verify_minio
  verify_mailpit
  check_host_alias
  if [[ $FAILED -ne 0 ]]; then
    echo
    die "Some checks failed (see ✗ above). Nothing else was started."
  fi
}

# ---------------------------------------------------------------- app
migrate_app() {
  step "6/6  Application"
  if [[ $MIGRATE -eq 0 ]]; then info "Migrations skipped (--no-migrate)"; return; fi
  if ! have php || [[ ! -d vendor ]]; then warn "php or vendor/ missing: run 'composer install' and 'php artisan migrate' yourself."; return; fi
  # safe: DB_HOST was verified to be this machine
  php artisan migrate --force
  ok "Database migrated"
  if [[ -n "$ADMIN_EMAIL" ]]; then php artisan user:admin "$ADMIN_EMAIL"; fi
  php artisan storage:link >/dev/null 2>&1 || true
}

start_php() {
  have php && [[ -d vendor ]] || die "--php needs php and vendor/ (composer install)."
  mkdir -p "$LOG_DIR"; : >"$PID_FILE"
  local port; port="$(app_port)"
  port_in_use "$port" && die "Port $port is already in use: stop the other 'php artisan serve' first."
  nohup php artisan serve --host=0.0.0.0 --port="$port" >"$LOG_DIR/serve.log" 2>&1 & echo $! >>"$PID_FILE"
  nohup php artisan queue:work --queue=default,disparos,warmup --sleep=3 --tries=3 >"$LOG_DIR/queue.log" 2>&1 & echo $! >>"$PID_FILE"
  nohup php artisan schedule:work >"$LOG_DIR/schedule.log" 2>&1 & echo $! >>"$PID_FILE"
  ok "PHP started in the background (logs: storage/logs/local/, stop with ./start-local.sh down)"
}

stop_php() {
  [[ -f "$PID_FILE" ]] || return 0
  local pid
  while read -r pid; do [[ -n "$pid" ]] && kill "$pid" 2>/dev/null || true; done <"$PID_FILE"
  rm -f "$PID_FILE"
  ok "PHP processes started by --php stopped"
}

summary() {
  local port; port="$(app_port)"
  echo -e "\n\033[32m✓ Local stack is up and verified.\033[0m"
  cat <<EOM

  Services
    MySQL            127.0.0.1:$(env_get DB_PORT)   db $(env_get DB_DATABASE)   user $(env_get DB_USERNAME)
    Evolution API    http://localhost:$(env_get WHATSAPP_PORT)   (apikey in .env: WHATSAPP_APIKEY)
    MinIO console    http://localhost:9001   user $(env_get AWS_ACCESS_KEY_ID)   (S3: http://localhost:9000, bucket $(env_get AWS_BUCKET))
    Mailpit inbox    http://localhost:8025
EOM
  [[ $TOOLS -eq 1 ]] && echo "    phpMyAdmin       http://localhost:8082"
  cat <<EOM

  Run PHP yourself (3 terminals) - serve MUST listen on 0.0.0.0 so Evolution (in Docker) can post webhooks to it:
    php artisan serve --host=0.0.0.0 --port=${port}
    php artisan queue:work --queue=default,disparos,warmup --sleep=3 --tries=3
    php artisan schedule:work
  ...or let this script start them:  ./start-local.sh --php

  Open http://localhost:${port}, connect a WhatsApp number from Instances (the QR code comes from the local Evolution API).
  Useful:  ./start-local.sh status | logs evolution-api | down | reset
EOM
}

# ---------------------------------------------------------------- commands
case "$CMD" in
  up)
    preflight
    check_env
    check_ports
    start_stack
    verify_all
    migrate_app
    [[ $START_PHP -eq 1 ]] && start_php
    summary
    ;;
  check)
    preflight
    check_env
    stack_running || die "The local stack is not running: start it with ./start-local.sh"
    verify_all
    ok "Everything checked out"
    ;;
  status)
    have docker || die "Docker not found."
    dc ps -a
    ;;
  logs)
    dc logs -f --tail 100 ${LOG_SVC:+"$LOG_SVC"}
    ;;
  down)
    stop_php
    dc --profile tools down
    ok "Services stopped (data kept). Start again with ./start-local.sh"
    ;;
  reset)
    echo "This DELETES the local database, the WhatsApp sessions (Evolution) and the uploaded files."
    if [[ $ASSUME_YES -ne 1 ]]; then   # --fix-env must never confirm a data wipe
      interactive || die "Cancelled (run it in a terminal, or pass --yes)."
      read -r -p "Continue? [y/N] " _a || true
      [[ "${_a:-}" =~ ^[yYsS] ]] || die "Cancelled."
    fi
    stop_php
    dc --profile tools down -v
    ok "Everything removed. Run ./start-local.sh to start from scratch."
    ;;
esac
