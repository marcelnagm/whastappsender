# 🚀 SAMA Envios - Enterprise AI & WhatsApp Automation Platform

![PHP Version](https://img.shields.io/badge/php-%5E8.0-777bb4?style=for-the-badge&logo=php&logoColor=white)
![Laravel Version](https://img.shields.io/badge/laravel-%5E9.0-ff2d20?style=for-the-badge&logo=laravel&logoColor=white)
![AI-Powered](https://img.shields.io/badge/AI-Powered_by_GROQ_LPU-orange?style=for-the-badge&logo=openai)
![Docker](https://img.shields.io/badge/docker-%230db7ed.svg?style=for-the-badge&logo=docker&logoColor=white)

**SAMA Envios** is a mission-critical communication infrastructure that merges the power of **Laravel** with the ultra-fast **GROQ LPU** inference. Designed for enterprises that demand stable bulk messaging and **Intelligent AI Customer Service** that mimics human response speeds.

---

## 🤖 The New Era: AI Employee Powered by GROQ (Included)

Forget slow and rigid bots. SAMA natively integrates with the **GROQ** infrastructure, allowing you to create virtual agents that:

* **Respond Instantly:** Natural Language Processing in milliseconds (LPU technology), eliminating typical AI delays.
* **Consultative Sales:** Configure *System Prompts* to make the AI act as your Sales Director, handling objections and qualifying leads 24/7.
* **Brand Context:** The AI understands your company, products, and offers, maintaining a consistent brand voice on WhatsApp.

---

## 🔗 Tool Call Chain API (AI Agents)

SAMA exposes a **Tool Call Chain API** so external AI agents (Cursor, OpenAI, Claude, custom bots) can operate the platform programmatically. Every user-level operation available in the web UI can be invoked as a **tool** or via **REST endpoints**.

### Base URL

```
https://your-domain.com/api/v1
```

### Authentication (Laravel Sanctum)

**Option A — API login:**

```bash
curl -X POST https://your-domain.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "your_username",
    "password": "your_password",
    "token_name": "my-agent"
  }'
```

Response:

```json
{
  "success": true,
  "message": "Authenticated successfully.",
  "data": {
    "token": "1|xxxxxxxxxxxxxxxx",
    "token_type": "Bearer",
    "user": { "id": 1, "name": "...", "role": "user" }
  }
}
```

**Option B — Web UI:** go to **Profile → API para agentes de IA** and generate a token from the panel.

Use the token in all requests:

```
Authorization: Bearer 1|xxxxxxxxxxxxxxxx
```

### Tool Call Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/tools` | List all available tools (OpenAI Functions format) |
| `POST` | `/api/v1/tools/call` | Execute a single tool |
| `POST` | `/api/v1/tools/chain` | Execute a chain of tools sequentially |

#### List tools

```bash
curl https://your-domain.com/api/v1/tools \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Returns an array of tool definitions compatible with OpenAI Function Calling / most agent frameworks.

#### Call a single tool

```bash
curl -X POST https://your-domain.com/api/v1/tools/call \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "list_contacts",
    "arguments": {
      "search": "João",
      "per_page": 10
    }
  }'
```

Response:

```json
{
  "success": true,
  "tool": "list_contacts",
  "result": {
    "success": true,
    "message": "OK",
    "data": [ ... ],
    "meta": { "current_page": 1, "total": 42 }
  }
}
```

#### Call a tool chain

Execute multiple tools in sequence (stops on first failure by default):

```bash
curl -X POST https://your-domain.com/api/v1/tools/chain \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "calls": [
      { "name": "create_campaign", "arguments": { "name": "June Promo" } },
      { "name": "create_campaign_item", "arguments": { "campaign_id": 1, "name": "Msg 1", "text": "Hello!" } },
      { "name": "launch_campaign", "arguments": { "campaign_item_id": 5 } }
    ],
    "continue_on_error": false
  }'
```

### Available Tools

#### Dashboard & Profile

| Tool | Description |
|------|-------------|
| `get_dashboard` | Contacts count, delivery rate, error rate, connected instances |
| `get_profile` | Current user profile and AI settings |
| `update_profile` | Update profile and AI agent configuration |

#### Contacts

| Tool | Description |
|------|-------------|
| `list_contacts` | List contacts (search, pagination) |
| `get_contact` | Get contact by ID |
| `create_contact` | Create a contact |
| `update_contact` | Update a contact |
| `delete_contact` | Delete a contact |
| `bulk_delete_contacts` | Delete multiple contacts |
| `bulk_update_contact_status` | Update status (`ativo`, `inativo`, `no-whatsapp`) |
| `clear_contacts` | Delete all contacts for the current user |
| `sync_contact_photo` | Sync profile photo from WhatsApp |

#### Campaigns

| Tool | Description |
|------|-------------|
| `list_campaigns` | List campaigns |
| `get_campaign` | Get campaign details |
| `create_campaign` | Create a campaign |
| `update_campaign` | Update a campaign |
| `delete_campaign` | Delete a campaign and its items |
| `get_campaign_report` | Delivery report with stats |

#### Campaign Items & Sending

| Tool | Description |
|------|-------------|
| `list_campaign_items` | List message templates |
| `get_campaign_item` | Get item details |
| `create_campaign_item` | Create a message template |
| `update_campaign_item` | Update a message template |
| `delete_campaign_item` | Delete a message template |
| `generate_test_send` | Create a test send job |
| `launch_campaign` | Generate send jobs for all validated contacts |

#### Send Jobs

| Tool | Description |
|------|-------------|
| `list_send_jobs` | List jobs for a campaign item (filters available) |
| `retry_send_job` | Retry a failed job |
| `bulk_retry_send_jobs` | Retry multiple failed jobs |
| `bulk_delete_send_jobs` | Delete multiple jobs |

#### WhatsApp Instances

| Tool | Description |
|------|-------------|
| `list_instances` | List WhatsApp instances |
| `get_instance` | Instance details and connection status |
| `create_instance` | Register a new instance |
| `delete_instance` | Delete an instance |
| `get_instance_qr` | Get QR code for pairing |
| `check_instance_connection` | Check connection state |
| `toggle_instance_warmup` | Enable/disable warmup |
| `sync_instance_contacts` | Sync contacts from WhatsApp |

#### Notifications

| Tool | Description |
|------|-------------|
| `list_notifications` | List user notifications |
| `mark_notifications_read` | Mark all as read |

#### Admin only (requires `role = admin`)

| Tool | Description |
|------|-------------|
| `admin_list_users` | List all users |
| `admin_get_user` | Get user details |
| `admin_update_user` | Update a user |
| `admin_delete_user` | Delete a user |
| `admin_toggle_user_active` | Enable/disable user |
| `admin_toggle_user_admin` | Toggle admin role |
| `admin_panic_status` | Get panic mode status |
| `admin_toggle_panic` | Pause/resume all sending |
| `admin_clear_send_queue` | Clear the `disparos` queue |
| `admin_clear_warmup_queue` | Clear the `warmup` queue |

### REST API (alternative to tools)

All operations are also available as standard REST endpoints under `/api/v1/`:

| Resource | Endpoints |
|----------|-----------|
| Auth | `POST /auth/login`, `GET /auth/me`, `POST /auth/logout`, `GET/POST/DELETE /auth/tokens` |
| Dashboard | `GET /dashboard` |
| Profile | `GET/PUT /profile` |
| Contacts | `GET/POST /contacts`, `GET/PUT/DELETE /contacts/{id}`, `POST /contacts/import`, `POST /contacts/bulk-delete`, etc. |
| Campaigns | `GET/POST /campaigns`, `GET/PUT/DELETE /campaigns/{id}`, `GET /campaigns/{id}/report` |
| Campaign Items | `GET/POST /campaign-items`, `POST /campaign-items/{id}/generate-all`, `GET /campaign-items/{id}/jobs` |
| Instances | `GET/POST /instances`, `GET /instances/{id}/qr`, `POST /instances/{id}/sync` |
| Jobs | `POST /whatsapp-jobs/{id}/retry`, `POST /whatsapp-jobs/bulk-retry` |
| Admin | `GET /admin/users`, `POST /admin/panic/toggle`, etc. |

### Security notes

* Tokens are scoped to the owning user — agents only access that user's data.
* Admin tools require an account with `role = admin`.
* Disabled accounts cannot authenticate (`403`).
* Rate limit: 60 requests/minute per user (Laravel default API throttle).

---

## 💎 Senior Software Engineering

While amateur scripts crash with 100 messages, SAMA was built under **SOLID** patterns for massive scale:

* **Zero-Loss Database Queues:** Async dispatch management via database queues. No message is lost due to network fluctuations.
* **Hybrid Architecture (Supervisor Ready):** Background processing via **Workers**, ensuring the admin panel remains lightning-fast even during 50,000+ message broadcasts.
* **Anti-Block Smart Cadence:** Advanced protection algorithm with random delays and scheduled pauses to mimic human behavior and save your numbers (chips).
* **Resilient Bulk Retry:** A dedicated control panel to monitor API failures and reinject corrupted batches with a single click.

---

## 🛠️ Elite Features

* ✅ **AI Agent Manager:** Full control over AI prompts and personality per instance.
* ✅ **Tool Call Chain API:** External AI agents can manage contacts, campaigns, instances and sending via tools or REST.
* ✅ **Multi-Instance Management:** Manage multiple WhatsApp numbers and departments within a single platform.
* ✅ **Performance Dashboard:** Track success rates, errors, and AI token consumption in real-time.
* ✅ **Smart Lead Management:** Bulk import via Excel/CSV with dynamic column mapping.
* ✅ **High-Fidelity Multimedia:** Support for Images, PDFs, Videos, and Audio (OGG format to simulate human recording).
* ✅ **Audit Logs:** Full history of every AI interaction and every broadcast attempt.

---

## 💻 Technical Stack

* **Backend:** Laravel 9 (Clean Code Architecture)
* **AI Engine:** GROQ Cloud API (LPU Inference for ultra-low latency)
* **Agent API:** Laravel Sanctum + OpenAI Functions-compatible Tool Registry
* **Frontend:** Bootstrap & Blade Templates
* **API Bridge:** Optimized for Evolution API
* **Infra:** Docker Ready — production stack in `.docker/` (Dockerfile, Compose, Nginx, Supervisor)

---

## ⚙️ Installation & Activation

### Option A — Local development (without Docker)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan queue:work --queue=disparos,atendimento-ia --sleep=3 --tries=3
```

Add your `GROQ_API_KEY` to `.env` before running migrations.

### Option B — Production with Docker (`.docker/`)

The `.docker/` folder contains the **full production stack**: PHP-FPM, Nginx (with SSL), MySQL, PostgreSQL, Redis, Evolution API, MinIO and phpMyAdmin. Use it to deploy the entire platform on a VPS.

> **Note:** There is also a `docker-compose.yml` in the project root (simpler variant with Portainer). The `.docker/` folder is the **recommended production setup** with Redis, SSL reverse proxy and the Evolution API v2.3 image.

#### Folder structure

```
.docker/
├── Dockerfile                 # PHP 8.3-FPM image (pdo_mysql, redis, gd, opcache…)
├── docker-compose.yml         # Orchestration of all services
├── .env.docker                # Environment variables for Evolution API
├── nginx/
│   ├── nginx.conf             # Active config: Laravel + SSL + reverse proxies
│   ├── nginx.conf.ssl         # Alternative SSL template (DuckDNS example)
│   └── nginx.bk.conf          # Backup of previous Nginx config
├── supervisor/
│   ├── supervisord.conf       # Supervisor main config
│   └── conf.d/
│       └── laravel-worker.conf  # Queue workers (disparos, default) + scheduler
└── data/                      # Persistent volumes (created on first run)
    ├── mysql/
    ├── postgres/
    ├── redis/
    ├── minio/
    └── evolution_instances/
```

#### Services and ports

| Service | Container | Description | Exposed ports |
|---------|-----------|-------------|---------------|
| `app` | `whatsapp_app_php` | Laravel (PHP 8.3-FPM) | `9000` (internal) |
| `nginx` | `whatsapp_app_nginx` | Web server + SSL + reverse proxy | `80`, `443`, `8080`, `8081`, `9000` |
| `mysql` | `whatsapp_app_db` | Laravel database | `3306` |
| `postgres` | `evolution_db` | Evolution API database | internal |
| `redis` | `whatsapp_redis` | Cache and Evolution queues | `6379` |
| `evolution-api` | `evolution_api` | WhatsApp bridge (Evolution v2.3) | `8080` (via Nginx) |
| `minio` | `minio_storage` | S3-compatible storage (campaign media) | `9001` (console) |
| `phpmyadmin` | `whatsapp_phpmyadmin` | MySQL web UI | `8082` |

**Nginx routing** (configured in `nginx/nginx.conf`):

| Port | Route | Target |
|------|-------|--------|
| `443` | `/` | Laravel (`app:9000` via FastCGI) |
| `443` on port `9000` | `/` | MinIO (`minio:9000`) |
| `443` on port `8080` | `/` | Evolution API (`evolution-api:8080`) |
| `8081` | `/` | Evolution API (HTTP, no SSL) |

#### Step-by-step deployment

**1. Place the Laravel source inside `.docker/app`**

The `docker-compose.yml` mounts `./app` as `/var/www`. Copy or symlink the project:

```bash
# From the repository root
ln -s .. .docker/app
# or
cp -r . .docker/app
```

**2. Configure Laravel `.env` (inside `.docker/app`)**

Use Docker service names as hosts:

```env
DB_HOST=mysql
DB_DATABASE=whatsapp_sender
DB_USERNAME=marcel
DB_PASSWORD=password_mysql_123

REDIS_HOST=redis
REDIS_PASSWORD=password_redis_123

AWS_ENDPOINT=http://minio:9000
AWS_ACCESS_KEY_ID=admin
AWS_SECRET_ACCESS_KEY=sua_senha_forte_123
AWS_BUCKET=ads

WHATSAPP_URL=evolution-api
WHATSAPP_PORT=8080
WHATSAPP_PROTOCOL=http
WHATSAPP_APIKEY=BQYHJGJHJ
```

**3. Configure Evolution API (`.docker/.env.docker`)**

Edit `.env.docker` with your domain and webhook URL:

```env
SERVER_URL=https://your-domain.com:8080
WEBHOOK_GLOBAL_URL=https://your-domain.com/api/webhook/whatsapp
AUTHENTICATION_API_KEY=your_api_key_here
```

The `AUTHENTICATION_API_KEY` must match `WHATSAPP_APIKEY` in the Laravel `.env`.

**4. Configure Nginx SSL (`nginx/nginx.conf`)**

Update `server_name` and Let's Encrypt certificate paths:

```nginx
server_name your-domain.com;
ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
```

Certificates are mounted from the host via `/etc/letsencrypt:/etc/letsencrypt:ro` in `docker-compose.yml`. Generate them on the host with Certbot before starting Nginx.

For a quick test without SSL, use `nginx/nginx.bk.conf` or adapt `nginx.conf.ssl`.

**5. Start the stack**

```bash
cd .docker
docker compose up -d --build
```

**6. Run Laravel setup inside the container**

```bash
docker exec -it whatsapp_app_php bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
exit
```

**7. Start queue workers**

**Option A — Supervisor** (recommended for production). The configs in `supervisor/conf.d/laravel-worker.conf` define:

| Program | Queue | Purpose |
|---------|-------|---------|
| `worker-disparos` | `disparos` | Outbound WhatsApp message sending |
| `worker-default` | `default` | Campaign job generation, webhooks |
| `laravel-schedule` | — | `schedule:work` (autosend, warmup) |

Install Supervisor on the host or in a sidecar container and point it to these configs, with `/var/www` mapped to `.docker/app`.

**Option B — Manual worker** inside the PHP container:

```bash
docker exec -it whatsapp_app_php php artisan queue:work --queue=disparos,default,atendimento-ia --sleep=3 --tries=3
```

#### Useful commands

```bash
# View logs
docker compose -f .docker/docker-compose.yml logs -f app nginx evolution-api

# Restart a service
docker compose -f .docker/docker-compose.yml restart evolution-api

# Stop everything
docker compose -f .docker/docker-compose.yml down

# Rebuild PHP image after Dockerfile changes
docker compose -f .docker/docker-compose.yml up -d --build app
```

#### Default credentials (change in production!)

| Service | User | Password |
|---------|------|----------|
| MySQL | `marcel` | `password_mysql_123` |
| MySQL root | `root` | `root_password_456` |
| PostgreSQL (Evolution) | `admin` | `password123` |
| Redis | — | `password_redis_123` |
| MinIO | `admin` | `sua_senha_forte_123` |
| Evolution API key | — | `BQYHJGJHJ` (set in `.env.docker`) |

#### Persistent data

All data is stored under `.docker/data/`. Back up these folders before redeploying:

- `data/mysql` — Laravel database
- `data/postgres` — Evolution database
- `data/redis` — Redis AOF
- `data/minio` — Uploaded campaign media
- `data/evolution_instances` — WhatsApp session data

---

## 📁 What's in the Box?

* 📂 **Source_Code:** Fully modularized and documented Laravel source code.
* 📂 **`.docker/`:** Production Docker stack (PHP, Nginx, MySQL, Redis, Evolution API, MinIO).
* 📂 **Tool Call API:** `app/Services/Api/ToolRegistry.php` — tool definitions for AI agents.

---

**Legal Disclaimer:** SAMA Envios is a productivity tool. Its use must comply with Meta's guidelines and local data protection laws. We do not encourage SPAM.

© SAMA Envios — Where Software Engineering meets Artificial Intelligence.
