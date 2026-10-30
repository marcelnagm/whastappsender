# 🚀 SAMA Envios - Enterprise AI & WhatsApp Automation Platform

![PHP Version](https://img.shields.io/badge/php-%5E8.0-777bb4?style=for-the-badge&logo=php&logoColor=white)
![Laravel Version](https://img.shields.io/badge/laravel-%5E9.0-ff2d20?style=for-the-badge&logo=laravel&logoColor=white)
![AI-Powered](https://img.shields.io/badge/AI-Groq_%C2%B7_OpenAI_%C2%B7_Claude_%C2%B7_Gemini_%C2%B7_Ollama-orange?style=for-the-badge&logo=openai)
![Docker](https://img.shields.io/badge/docker-%230db7ed.svg?style=for-the-badge&logo=docker&logoColor=white)
[![GitHub stars](https://img.shields.io/github/stars/marcelnagm/whastappsender?style=for-the-badge&logo=github)](https://github.com/marcelnagm/whastappsender/stargazers)
[![GitHub followers](https://img.shields.io/github/followers/marcelnagm?style=for-the-badge&logo=github&label=Follow)](https://github.com/marcelnagm)

> ⭐ **Cloned or using this project? Please give it a star and/or follow [@marcelnagm](https://github.com/marcelnagm).**
> It's free, takes two seconds and is what keeps the project maintained. Thank you! 🙏

**SAMA Envios** is a mission-critical communication infrastructure that merges the power of **Laravel** with the LLM of your choice (**Groq, OpenAI, Claude, Gemini, DeepSeek, Mistral, OpenRouter, Ollama or any OpenAI-compatible endpoint**). Designed for teams that demand stable bulk messaging and **Intelligent AI Customer Service** on WhatsApp, Telegram, Instagram and TikTok in one omnichannel inbox with per-channel statistics, answering through the very number, bot or account the customer wrote to.

**Documentation map**

| Section | Topic |
|---------|-------|
| [AI Agents](#-ai-agents-multi-provider) | Providers, model choice, prompt, per-instance agents, working hours |
| [Tool Call Chain API](#-tool-call-chain-api-ai-agents) | REST + AI agent tools |
| [System Architecture](#-system-architecture-queues--warmup) | Queues, Warmup, production requirements |
| [WhatsApp Chat (CRM)](#-whatsapp-chat-crm) | WhatsApp Web-style inbox on top of Evolution API |
| [Telegram](#-telegram) | Connect Telegram bots; same chat, AI agents and plan limits; always answers through the bot the message came in |
| [Instagram](#-instagram-direct) | Instagram DMs in the same omnichannel chat; AI answers through the account that received the message |
| [TikTok](#-tiktok-business-messaging) | TikTok Business DMs in the same chat, step-by-step setup (developer app, approval, OAuth, webhook) |
| [Channel statistics & linked contacts](#-channel-statistics--linked-contacts) | Per-channel numbers, and one person's WhatsApp / Telegram / Instagram / TikTok conversations tied together |
| [Fair Send Queue](#️-fair-send-queue) | Balanced batches of 50 across users and sessions |
| [Plans & Billing](#-plans--billing) | Freemium + 3 plans, due dates, blocking, Asaas · Efí · AbacatePay · Stone (Pagar.me) · Stripe · PagBank · Mercado Pago (Pix / card, customer chooses) |
| [Tests](#-tests) | PHPUnit suite |
| [Installation](#️-installation--activation) | `install.sh` (local / VPS / update), `start-local.sh` (Docker infra for local dev), manual steps, production Docker (`.docker/`) |

---

## 🤖 AI Agents (multi-provider)

Open the **AI** menu to create one or more agents. Each agent defines:

* **Provider and model** — Groq, OpenAI (ChatGPT), Anthropic (Claude), Google Gemini, DeepSeek, Mistral, OpenRouter, **Ollama** and **Custom** (any OpenAI-compatible URL). The model lists are only suggestions: type any model id your provider supports.
* **Your own API key** (stored encrypted, never shown again) — or leave it empty to use a platform key set in `.env`. Ollama needs no key. A **Test connection** button checks provider, model and key before saving.
* **Behaviour prompt** — the system prompt that shapes how the agent talks and sells.
* **Which number it answers for** — one specific instance, or *any instance*. An instance-specific agent wins over the "any instance" agent; a disabled instance agent keeps the AI silent on that number.
* **Working hours** — optional days, start/end time and timezone; outside the window the AI stays silent. Set per agent, so the same rule covers every instance the agent covers.
* **Reply path** — the answer always leaves through the **instance that received the message**.

Failures (bad key, quota, timeout) never send anything to the customer; they appear under *Recent AI errors* in the AI section.

| `.env` (all optional) | Purpose |
|---|---|
| `GROQ_API_KEY`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `DEEPSEEK_API_KEY`, `MISTRAL_API_KEY`, `OPENROUTER_API_KEY` | Platform keys used when an agent has no key of its own |
| `AI_ALLOW_PRIVATE_HOSTS` | Let Ollama/custom point to localhost or LAN hosts (default: only when `APP_ENV=local`). Keep it `false` on a shared host (SSRF protection; `169.254.*` is always blocked) |
| `AI_TIMEOUT` | Provider timeout in seconds (default 40) |
| `AI_BUSINESS_TZ`, `AI_BUSINESS_START`, `AI_BUSINESS_END` | Default working hours for agents that do not set their own |

> After updating run `php artisan migrate`: existing AI settings are copied into a default "any instance" Groq agent, so nothing changes until you edit it. The legacy `ai_*` API fields keep working.

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

## 🏗️ System Architecture: Queues & Warmup

The **SAMA Envios** architecture is designed for high concurrency and to mitigate the main risk of WhatsApp operations: **account bans (chips)**. Below is the technical breakdown of how **Queues** and the **Warmup** module are structured, plus the server requirements to run them in production.

### Overview

```
                  ┌────────► [Fila: default] ────────► Worker 1 (Webhooks, geração de jobs, welcome)
                  │
[API / Painel] ───┼────────► [Fila: disparos] ──────► Worker 2 (Campanhas + respostas IA)
                  │
                  └────────► [Fila: warmup] ─────────► Worker 3 (Aquecimento isolado)
```

> Laravel processes queues **left to right**. A worker started with `--queue=default,disparos,warmup` always drains `default` before touching `disparos` or `warmup`.

---

### 1. Queue Architecture

To guarantee resilience and avoid bottlenecks (where massive campaigns block immediate AI replies), the system uses the **`database` driver** (`QUEUE_CONNECTION=database`) split into multiple isolated queues.

#### Queue division and prioritization

| Queue | Jobs | Priority | Purpose |
|-------|------|----------|---------|
| **`default`** | `ProcessEvolutionWebhookJob`, `GenerateCampaignItemJobsJob`, `OrchestrateWelcomeCampaignJob`, registration sends | **Highest** | Inbound webhooks, campaign job materialization, welcome flows. Must stay fast so the panel and AI pipeline are not blocked. |
| **`disparos`** | `EnviarMensagemJob`, `SendAiReplyJob` | **Medium** | Bulk campaign sends and GROQ-generated replies. Runs in background without starving webhooks. |
| **`warmup`** | `OrchestrateWarmupJob`, `SendWarmupMessageJob` | **Isolated** | Chip maturation only. Strict throttling; never competes with customer-facing traffic when workers are split. |

#### Recommended worker setup

**Single process (small VPS):**

```bash
php artisan queue:work --queue=default,disparos,warmup --sleep=3 --tries=3 --max-time=3600
```

If jobs exist on `default`, Laravel ignores `disparos` and `warmup` until the high-priority queue is empty.

**Multiple processes (production — recommended):**

Run dedicated workers per queue via Supervisor (see [Production requirements](#3-production-requirements) and [`.docker/supervisor`](#option-b--production-with-docker-docker)):

| Worker | Command |
|--------|---------|
| Webhooks & orchestration | `queue:work --queue=default` |
| Campaigns & AI replies | `queue:work --queue=disparos` |
| Warmup only | `queue:work --queue=warmup` |

The `.docker/supervisor/conf.d/laravel-worker.conf` file ships with `worker-disparos` and `worker-default` pre-configured. Add a third program for `warmup` when enabling chip maturation at scale.

#### Scheduled tasks (Laravel Scheduler)

| Command | Schedule | Purpose |
|---------|----------|---------|
| `whatsapp:queue` | Hourly (if `WHATSAPP_AUTOSEND=true`) | Dispatches pending `WhatsappJob` rows to `disparos` |
| `warmup:generate` | Daily at 08:00 (`America/Boa_Vista`) | Starts cross-chip warmup conversations |
| `billing:run` | Hourly | Moves subscriptions through trial / past due / suspended / expired and issues renewal invoices |

---

### 2. Warmup Model

The Warmup module increases the reputation of new chips with Meta's algorithms by simulating **strictly human, bilateral** conversations.

#### Database model

Warmup is controlled per WhatsApp instance:

| Field / table | Description |
|---------------|-------------|
| `instances.warmup` | Boolean flag — enable warmup for this chip (toggle in **Instances** panel or API `toggle_instance_warmup`) |
| `instances.status` | Must be `connected` to participate |
| Admin instances | Source chips (`users.role = admin`) used as conversation partners |

Volume and cadence are enforced in `OrchestrateWarmupJob`:

- ~**40 messages** per admin↔target pair per daily run
- **Alternating senders** (even index: admin → target, odd: target → admin)
- **Cumulative delay** between messages: `rand(60, 240)` seconds per line
- Phrases generated by GROQ (`GroqConversationGenerator`) — day-to-day / technology topics

> **Roadmap:** per-chip evolution parameters (`current_volume`, `increment_per_day`, `max_daily_volume`, `interval_min`, `interval_max`) via a `warmup_configs` table can be added on top of this orchestrator without changing the queue isolation model.

#### Cross-fire algorithm (bilateral interaction)

One-way blasting triggers spam alerts. SAMA warmup uses a **cross matrix**:

1. `warmup:generate` selects **admin instances** (connected) and **target instances** (`warmup = 1`, non-admin users).
2. For each target, a random admin instance is paired.
3. `OrchestrateWarmupJob` builds a ~40-line script and dispatches `SendWarmupMessageJob` on the **`warmup`** queue.
4. **Chip A** sends a conversational phrase to **Chip B**; on the next line, **Chip B** replies to **Chip A**.
5. `SendWarmupMessageJob` simulates human behavior: random `composing` / `recording` presence, pause, then `sendText` with API-side delay.

To Meta, this looks like a real two-person thread, warming the number organically.

#### Dynamic delay in the job loop

```php
// app/Jobs/OrchestrateWarmupJob.php
foreach ($script as $index => $frase) {
    $senderName    = ($index % 2 === 0) ? $admin->instance_name : $target->instance_name;
    $receiverPhone = ($index % 2 === 0) ? $target->instance_name : $admin->instance_name;

    $delayAcumulado += rand(60, 240);

    SendWarmupMessageJob::dispatch($senderName, $receiverPhone, $frase)
        ->delay(now()->addSeconds($delayAcumulado))
        ->onQueue('warmup');
}
```

#### Panic controls

Admins can pause all processing (`system_panic_mode` cache key) or clear queues from **Admin → Panic** (web) or API tools `admin_toggle_panic`, `admin_clear_send_queue`, `admin_clear_warmup_queue`.

---

### 3. Production Requirements

To run this stack on a production server (VPS or Docker), four components are required:

#### A. Database prepared for queues

Ensure queue tables exist:

```bash
php artisan queue:table
php artisan queue:failed-table
php artisan migrate
```

Set in `.env`:

```env
QUEUE_CONNECTION=database
```

#### B. Process manager (Supervisor)

PHP is synchronous. Workers must run continuously under **Supervisor** on Linux (auto-restart on crash).

**VPS (Ubuntu) — `/etc/supervisor/conf.d/sama-worker.conf`:**

```ini
[program:sama-worker-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/sama-envios/artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/sama-envios/storage/logs/worker-default.log

[program:sama-worker-disparos]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/sama-envios/artisan queue:work --queue=disparos --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/sama-envios/storage/logs/worker-disparos.log

[program:sama-worker-warmup]
command=php /var/www/sama-envios/artisan queue:work --queue=warmup --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=1
stdout_logfile=/var/www/sama-envios/storage/logs/worker-warmup.log

[program:sama-schedule]
command=php /var/www/sama-envios/artisan schedule:work
autostart=true
autorestart=true
user=www-data
numprocs=1
stdout_logfile=/var/www/sama-envios/storage/logs/schedule.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start all
```

**Docker:** use the configs in `.docker/supervisor/conf.d/laravel-worker.conf` (mount into a Supervisor sidecar or the PHP container).

> **Docker vs VPS:** both paths are documented. For **CodeCanyon / buyers**, the Docker + Supervisor bundle in `.docker/` is the recommended one-click flow (`docker compose up` + Supervisor configs included). For **traditional VPS (Ubuntu)**, use the Supervisor blocks above and host Cron. You do not need both — pick one deployment model.

#### C. Laravel Scheduler (Cron)

Daily warmup and optional autosend depend on the scheduler.

**VPS — add to `crontab -e`:**

```bash
* * * * * cd /var/www/sama-envios && php artisan schedule:run >> /dev/null 2>&1
```

**Docker:** `laravel-schedule` in `.docker/supervisor/conf.d/laravel-worker.conf` runs `schedule:work` (no host cron needed).

#### D. Stable Evolution API instance

SAMA is fully asynchronous. Evolution API must have **webhooks** pointing back to Laravel:

```env
# .docker/.env.docker
WEBHOOK_GLOBAL_URL=https://your-domain.com/api/webhook/whatsapp
WEBHOOK_EVENTS_MESSAGES_UPSERT=true
WEBHOOK_EVENTS_MESSAGES_UPDATE=true
```

When Evolution reports delivery errors (e.g. number not on WhatsApp), `ProcessEvolutionWebhookJob` updates `WhatsappJob` status, enabling **Bulk Retry** from the panel or Tool Call API.

---

## 💎 Senior Software Engineering

Built for massive scale under **SOLID** patterns:

* **Zero-Loss Database Queues:** Async dispatch via `database` driver — no message lost on network blips.
* **Isolated Queue Lanes:** `default` / `disparos` / `warmup` — campaigns never block webhooks or AI.
* **Anti-Block Smart Cadence:** Random delays, presence simulation, business-hours gates in `EnviarMensagemJob`.
* **Resilient Bulk Retry:** Monitor failures and re-inject batches from the panel or API.
* **Warmup Cross-Matrix:** Bilateral GROQ conversations between admin and user chips.

---

## 💬 WhatsApp Chat (CRM)

A WhatsApp Web clone available at `/chat`. The browser only talks to Laravel; Laravel talks to the **Evolution API v2.3**
and receives events back through the webhook. Each user works with their own instance, and the instance name is the
WhatsApp session number (`instances.instance_name`).

```
Browser ──► Laravel (/chat/api/*) ──► SendChatMessageJob ──► Evolution API ──► WhatsApp
Browser ◄── polling (since=...) ◄── chats / chat_messages ◄── ProcessEvolutionWebhookJob ◄── Evolution webhook
```

**Features:** conversation list with unread badges and search, text / image / video / audio / document / sticker,
voice notes (recorded in the browser), replies, reactions, delete for everyone, delivery and read ticks, typing
presence, history import, new chat by phone number (Brazilian 9th digit handled by Evolution), and a CRM panel
(save the person as a contact, opt-out status, pause the AI auto-reply for that contact).

**Setup**

1. Run the migration: `php artisan migrate` (creates `chats` and `chat_messages`).
2. In Evolution, enable these webhook events (already set in `.docker/.env.docker`):
   `MESSAGES_UPSERT`, `MESSAGES_UPDATE` and `SEND_MESSAGE`. The last one makes messages sent by campaigns and the AI
   also appear in the chat.
3. Keep a worker on the `default` queue running (sends and history imports use it).
4. Open **Chat** in the menu and click the import button to load existing conversations.

**Optional `.env` settings** (defaults in `config/chat.php`): `CHAT_POLL_MESSAGES_MS`, `CHAT_POLL_CHATS_MS`,
`CHAT_MAX_UPLOAD_KB`, `CHAT_PAUSE_AI_ON_REPLY`, `CHAT_SEND_READ_RECEIPTS`.

**Profile pictures.** WhatsApp CDN links are signed and expire, so the picture is downloaded in the background
(`FetchChatAvatarJob`, `default` queue), stored in `storage/app/chat-avatars/` and served by Laravel at `/chat/avatar/{chat}`
(owner only). A contact without a picture is checked again after 7 days; a temporary failure (instance offline, timeout) is
retried after 30 minutes. Run `php artisan migrate` to add the cache columns. Without a picture the UI shows the initials.

> Groups, broadcasts and channels are intentionally ignored; the chat only handles one-to-one conversations.

* * *

## ✈️ Telegram

A Telegram bot is a **channel of the same instance structure** as a WhatsApp number: its conversations show up in the
**Chat** inbox, the **AI agents** answer them (per-instance agent, working hours and the "pause AI when a human replies"
rule all apply), contacts land in the CRM and every bot counts toward the plan's *instances* limit.
**Every reply leaves through the bot that received the message**: the AI, the chat UI and retries never switch bot or channel.

```
Customer ──► Telegram ──► POST /api/webhooks/telegram/{instance} ──► ProcessTelegramUpdateJob ──► chats / chat_messages ──► AI agent
Customer ◄── Telegram ◄── Bot API sendMessage / sendPhoto / ... ◄── SendAiReplyJob · SendChatMessageJob (same bot)
```

**Setup**

1. In Telegram open **@BotFather**, send `/newbot` and copy the token (`123456789:AAH...`).
2. Make sure `APP_URL` is your **public https** address (Telegram only delivers webhooks to https; local development needs a tunnel such as ngrok or Cloudflare Tunnel).
3. Run `php artisan migrate` (adds `channel`, `telegram_token`, `telegram_username`, `telegram_secret` to `instances`).
4. Open **Telegram** in the menu, paste the token and click **Connect**. The app calls `getMe`, then `setWebhook` with a random secret per bot.
   If the webhook cannot be registered the bot is not saved. Use **Register webhook again** after changing `APP_URL`.
5. Keep a worker on the `default` queue (incoming updates, chat sends) and on `disparos` (AI replies) running.
6. In **AI**, enable automatic replies and, if you want, assign an agent to the bot (it appears in the instance list like any number).

**Optional `.env` settings** (defaults in `config/telegram.php`): `TELEGRAM_API_URL` (default `https://api.telegram.org`, useful for a self-hosted Bot API server),
`TELEGRAM_TIMEOUT` (seconds, default 30) and `TELEGRAM_MAX_DOWNLOAD_BYTES` (incoming media limit, default 20 MB, the Bot API maximum).

**What works:** text, photos, video, voice notes, audio and documents in both directions (media sent from the chat UI uses `sendPhoto` / `sendVideo` / `sendVoice` / `sendDocument`),
replies to a specific message, edited messages, typing indicator, opt-out with `/stop` or `#sair`, `/start` treated as a greeting for the AI.

**Limits (Telegram, not the app):**

* A bot **cannot start a conversation**: the customer must message it first. Number-based bulk campaigns, new chat by phone and warm-up remain **WhatsApp-only** (Telegram contacts have status `telegram` and are never picked by campaigns).
* Only one-to-one chats; groups, channels and other bots are ignored.
* No history import, reactions or "delete for everyone", and no read receipts.
* The token is stored encrypted and never shown again; a bot can be connected to only one account.

* * *

## 📸 Instagram (Direct)

An Instagram professional account is a **channel of the same instance structure** as WhatsApp numbers and Telegram bots.
All conversations land in the same **Chat**, which is **omnichannel**: each conversation shows a channel badge
(WhatsApp / Telegram / Instagram) in the list and in the conversation header, and the instance selector is labelled by channel.
The **AI agents** answer Instagram DMs like any other channel (per-instance agent, working hours, pause on human reply) and
**every reply leaves through the account that received the message**. Messages you send from the Instagram app itself (echo events) also appear in the chat.

```
Customer ──► Instagram ──► POST /api/webhooks/instagram (X-Hub-Signature-256) ──► ProcessInstagramEventJob ──► chats / chat_messages ──► AI agent
Customer ◄── Instagram ◄── graph.instagram.com/{version}/me/messages ◄── SendAiReplyJob · SendChatMessageJob (same account)
```

**Setup**

1. In [Meta for Developers](https://developers.facebook.com/apps) create an app with the **Instagram** product (*Instagram API with Instagram login*, permission `instagram_business_manage_messages`). The Instagram account must be **Business or Creator**.
2. Put the app credentials in `.env` (see below) and make sure `APP_URL` is your **public https** address.
3. In the app dashboard (*Instagram > API setup > Webhooks*) set the **callback URL** to `https://YOUR-DOMAIN/api/webhooks/instagram` and the **verify token** to the value of `INSTAGRAM_VERIFY_TOKEN`, then subscribe to the `messages` field. The Instagram menu in the app shows the exact callback URL.
4. Run `php artisan migrate` (adds `instagram_token`, `instagram_id`, `instagram_username` to `instances`).
5. Generate the account's access token in the dashboard, open **Instagram** in the menu and paste it. The app validates it, subscribes the account to messages and stores the token encrypted. The token lasts 60 days: use **Renew connection** before it expires.
6. Keep workers on `default` (incoming events, chat sends) and `disparos` (AI replies) running. Assign an AI agent to the account in **AI** if you want one just for Instagram.

**`.env` example** (defaults in `config/instagram.php`):

```
INSTAGRAM_APP_SECRET=your-meta-app-secret
INSTAGRAM_VERIFY_TOKEN=any-long-random-string-you-also-type-in-the-dashboard
#INSTAGRAM_API_VERSION=v21.0
#INSTAGRAM_WINDOW_HOURS=24
#INSTAGRAM_TIMEOUT=30
#INSTAGRAM_MAX_DOWNLOAD_BYTES=26214400
#INSTAGRAM_MEDIA_LINK_MINUTES=30
```

**What works:** text, images, video, audio and files in both directions, replies/quotes received, shared posts and reels as links, typing indicator,
opt-out with `/stop` or `#sair`, long answers split automatically (Instagram limits a text to 1000 characters). Files sent from the chat are fetched by Instagram through a temporary signed link (`/instagram-media/...`).

**Limits (Meta, not the app):**

* **Reply window:** Instagram only accepts a reply within `INSTAGRAM_WINDOW_HOURS` (24 h) of the customer's **last** message. Outside the window the chat refuses to send and the AI records the error.
* The account **cannot start a conversation** or message a list of contacts: the customer writes first. Number-based bulk campaigns, new chat by phone, history import and warm-up remain **WhatsApp-only** (Instagram contacts have status `instagram` and are never picked by campaigns).
* No reactions or "delete for everyone" from the chat. Only direct messages (no comments or story replies sending).
* Apps in development mode only receive messages from accounts with a role in the app; going live needs Meta app review for the messaging permission.

* * *

## 🎵 TikTok (Business Messaging)

A TikTok Business Account is a **channel of the same instance structure** as WhatsApp, Telegram and Instagram: its DMs appear in the
omnichannel **Chat** (badge "TikTok"), the **AI agents** answer them and **every reply leaves through the account that received the message**.
Messages the business sends from the TikTok app itself also show up in the chat.

```
Customer ──► TikTok ──► POST /api/webhooks/tiktok (Tiktok-Signature) ──► ProcessTikTokEventJob ──► chats / chat_messages ──► AI agent
Customer ◄── TikTok ◄── Business Messaging API (message send) ◄── SendAiReplyJob · SendChatMessageJob (same account)
```

### Step by step

> TikTok only gives this API to **approved apps**. Plan for a few days of review (it can take longer) before step 4.

1. **Check the account.** It must be a **TikTok Business Account** (personal accounts do not work), signed up in an eligible region (**not** the EEA, UK or Switzerland; US accounts also need the platform to pass TikTok's extra data-security review), and it must accept direct messages from everyone.
2. **Create the developer app.** Sign up at [developers.tiktok.com](https://developers.tiktok.com), then create an app at [business-api.tiktok.com/portal/apps](https://business-api.tiktok.com/portal/apps) with a name, description, icon, **Terms of Service URL** and **Privacy Policy URL**. Copy the **App ID** (client key) and **App Secret**.
3. **Apply for the Business Messaging API.** In the developer portal open the *Business Messaging API* product and submit your use case, how you handle data and your organization details. Wait for approval; you cannot continue before it.
4. **Set permissions and redirect URL.** Enable the **TikTok Accounts** permission under *Scope of permission* and set the authorization **Redirect URL** to exactly `https://YOUR-DOMAIN/tiktok/callback` (the TikTok menu in the app shows the exact value).
5. **Configure `.env`** and make sure `APP_URL` is your **public https** address:

   ```
   TIKTOK_APP_ID=your-app-id
   TIKTOK_APP_SECRET=your-app-secret
   #TIKTOK_WINDOW_HOURS=48
   #TIKTOK_SIGNATURE_TOLERANCE=300
   #TIKTOK_TIMEOUT=30
   #TIKTOK_MAX_DOWNLOAD_BYTES=26214400
   ```

6. **Register the webhook (once per app).** Set the callback URL to `https://YOUR-DOMAIN/api/webhooks/tiktok` in the developer portal, or run `php artisan tiktok:register-webhook`. Re-run it if the domain changes. The endpoint answers HTTP 200 fast, as TikTok requires; it retries failed deliveries, and duplicates are ignored.
7. **Migrate:** `php artisan migrate` (adds the `tiktok_*` columns to `instances`).
8. **Connect the account.** Open **TikTok** in the menu, click **Connect with TikTok** and authorize. The account becomes an instance (counts toward the plan's *instances* limit).
9. **Keep workers running** on `default` (incoming events, chat sends) and `disparos` (AI replies), and keep the **server clock synced** (webhook signatures are time-checked).
10. In **AI** you can assign an agent just to the account, like to any other instance.

**Tokens.** The access token lasts about a day and is renewed automatically (the refresh token rotates and is stored encrypted); the refresh token lasts about 30 days. If the account stays inactive until both expire, the TikTok page shows **Expired: connect again**: click *Connect with TikTok* once more. **Renew connection** forces a refresh.

**Limits (TikTok, not the app):**

* **Reply window:** replies are accepted for `TIKTOK_WINDOW_HOURS` (48 h) after the customer's last message. For people who do not follow each other with the business, TikTok also caps how many messages you can send before the customer replies (up to 10 within 48 h after their first message, then up to 3 more after the window until they write again).
* The account **cannot start a conversation**; the customer writes first. Number-based bulk campaigns, new chat by phone, history import and warm-up remain **WhatsApp-only** (TikTok contacts have status `tiktok`).
* **Outgoing messages are text (up to 6000 characters) or one image** (JPG/PNG up to 3 MB); video, audio and documents are refused with a clear error. Incoming text, images, video, shared posts and stickers are stored.
* Not available for accounts signed up in the EEA, UK or Switzerland. No reactions or deleting from the chat.

> **Verify in the TikTok sandbox before production.** TikTok publishes the full API reference only to approved developers. The endpoint paths, the webhook signature scheme (`t=<time>,s=<HMAC-SHA256 of "t.body" with the app secret>`) and the event field names were assembled from public documentation and partner implementations, are covered by tests against that contract, and every path can be overridden in `.env` (`TIKTOK_PATH_TOKEN`, `TIKTOK_PATH_REFRESH`, `TIKTOK_PATH_SEND`, `TIKTOK_PATH_MESSAGES`, `TIKTOK_PATH_UPLOAD`, `TIKTOK_PATH_DOWNLOAD`, `TIKTOK_PATH_WEBHOOK`, `TIKTOK_API_URL`, `TIKTOK_AUTH_URL`). If TikTok's portal shows something different, adjust there; the code should not need changes for a path or URL difference.

* * *

## 📊 Channel statistics & linked contacts

### Statistics

**Statistics** in the menu shows, per channel (WhatsApp, Telegram, Instagram, TikTok) over the last 7, 30 or 90 days: connections, conversations (total, new, active, **waiting for reply**), messages received and sent, **AI replies**, **average first-response time** of conversations started in the period, and contacts (with how many are linked). It also has a **messages-per-day chart** (last 30 days at most, stacked by channel) and a table **by connection**. Numbers only include the signed-in account.

### Linking a person's contacts across channels

The same person often writes on several channels. In the **Chat**, open the contact panel (click the name) → **Same person on other channels**:

* **Link:** search the person's Telegram / Instagram / TikTok contact (from the WhatsApp conversation) or their WhatsApp contact (from a Telegram / Instagram / TikTok conversation). A channel contact is linked to **one WhatsApp contact**; the person must already be saved as a contact in the CRM.
* **See everything together:** the panel lists the person's conversations in every channel; **Open** jumps to the conversation (switching the connection for you).
* **Send through any channel:** pick the channel and write; the message goes through **that channel's own account** (never through another one), using the same limits as a normal reply: Instagram and TikTok need the customer's last message inside the reply window (the option shows *reply window closed* otherwise), Telegram works as long as the person wrote to the bot once.
* **Unlink** at any time. Links live in `contacts.linked_contact_id` (run `php artisan migrate`).

Sending still needs the person to have written first on that channel: linking does not let you start a conversation on Telegram, Instagram or TikTok.

* * *

## ⚖️ Fair Send Queue

`php artisan whatsapp:queue` (scheduled hourly) used to take the oldest pending rows of **all** users, so one big campaign
could take every slot. Each run now takes a **batch of 50** messages (`WHATSAPP_BATCH_SIZE`) shared fairly:

- **Between users** (max-min fairness): every slot goes to the user with the smallest share so far. A user with a small
  backlog gets all of it; the leftover goes to the others.
- **By session** (`WHATSAPP_FAIRNESS=session`, default): a user's weight is the number of connected sessions, so 3 numbers
  get about 3x the slots of 1 number. Use `user` for equal shares per user, or `fifo` for the legacy behaviour.
- **Already queued messages count**: what a user still has waiting in the queue (last 24h) is subtracted from their share.
- **Between a user's sessions**: slots go to the session with the shortest queue, so numbers alternate.
- **Order**: the batch is interleaved (A, B, A, B...), so nobody waits behind a long run of one user.

Each message is bound to the session chosen by the planner (`whatsapp_job.instance_id`); if that session disconnects before
sending, the job falls back to another connected session of the same user. Users with no connected session are skipped and
their messages stay `pendente`.

```bash
php artisan whatsapp:queue --dry-run          # show the split per user/session without dispatching
php artisan whatsapp:queue --batch=100 --mode=user
```

`.env`: `WHATSAPP_BATCH_SIZE` (50), `WHATSAPP_FAIRNESS` (session|user|fifo), `WHATSAPP_QUEUE_CRON` (`0 * * * *`).
Manual retries from the logs screen still dispatch directly and bypass the planner.

* * *

## 💳 Plans & Billing

Freemium + three paid plans. The core (plans, subscriptions, invoices, dates, blocking) is gateway-agnostic: any payment
source only has to call `SubscriptionService::markInvoicePaid()` when money arrives. Mercado Pago (Pix and card, with
automatic renewal) is the integrated gateway, see [Payment gateway](#payment-gateway-mercado-pago).

**Master switch:** `BILLING_ENABLED=false` (default) keeps today's behaviour: nobody is limited or blocked. Turn it on when the plans are announced.
Before that, give existing customers time with `php artisan billing:grant all pro --days=30` (skips users who already have a subscription).

| | Free | Starter (R$ 49) | Pro (R$ 129) | Business (R$ 299) |
|---|---|---|---|---|
| Connected numbers | 1 | 2 | 5 | 15 |
| Contacts | 100 | 2,000 | 20,000 | unlimited |
| Messages / month | 100 | 2,000 | 10,000 | 50,000 |
| AI auto-replies / month | - | - | 1,000 | 10,000 |
| WhatsApp Chat (CRM) | - | yes | yes | yes |
| Welcome campaign (AI first contact) | - | - | yes | yes |
| Chip warmup | - | - | yes | yes |
| REST API | - | - | yes | yes |
| MCP / AI agent tools (`/api/v1/tools`) | - | - | - | yes |
| Priority support | - | - | - | yes |

Prices, limits and features live in `config/billing.php` and are copied to the `plans` table (`php artisan billing:sync-plans`,
add `--force` to overwrite plans that were edited). Yearly = 10 monthly payments. The first paid subscription of a user gets a
7-day free trial (`BILLING_TRIAL_DAYS`).

**Subscription dates and states**

```
pending ──paid──► active ◄──paid── past_due ◄── period ends unpaid ── active / trialing
trialing ─paid─►                      │ BILLING_GRACE_DAYS (3): full access, warning banner
                                      ▼
                                  suspended: BLOCKED (read-only) ── BILLING_SUSPENSION_DAYS (30) ──► expired = Free
active + cancel_at_period_end ── period ends ──► canceled = Free
```

- The renewal invoice is issued `BILLING_INVOICE_DAYS_BEFORE` (5) days before the period ends and is due on the period end.
- **Blocked** accounts (overdue past the grace days) are read-only: they can browse data and open the billing screens, but
  creating/changing anything, sending messages, AI replies and the API are refused (HTTP 402 for JSON, redirect to `/billing` on the web).
- Paying an invoice always restores access. After a suspension the new period starts on the payment date.
- Upgrades are charged immediately with credit for the unused time; downgrades and cancellation apply at the end of the paid period.
- `php artisan billing:run` (scheduled hourly) moves every subscription through these states and issues renewal invoices.
- Every transition is stored in `subscription_events`; users get an e-mail and an in-app notice for new invoices, overdue, blocked and paid.

**Where the plan is enforced:** instances (number limit, warmup), contacts (create and spreadsheet import), the send queue (monthly
quota is part of the fair queue, blocked users get no slots), `EnviarMensagemJob`, AI auto-replies, welcome campaigns, the chat
(`plan.feature:chat`), the API/tokens (`plan.feature:api_access`) and the AI agent tools / MCP endpoints (`plan.feature:mcp_access`, top plan only). Administrators are never limited.

**Screens:** `/billing` (plan, usage, plans, invoices; cancel/resume), `/billing/invoices/{id}`, the dashboard card and the pricing
section of the public landing page; admins get **Admin → Billing** to see subscriptions, confirm a payment by hand (e.g. bank transfer) and grant a plan, and **Admin → Payment gateway** for the checkout settings.

### Payment gateways (choose and combine)

Seven providers are built in, picked for their low public fees or for being the best known (Sep/2026; list prices, **confirm them with each provider**, they change and can be negotiated):

| Provider | Pix | Card (one payment) | Card checkout | Card recurrence |
|---|---|---|---|---|
| **Asaas** | R$ 1,99 | 2,99% + R$ 0,49 | provider's hosted page | no (Pix renewal) |
| **Efí** | 1,19% | 3,49% | provider's hosted page (payment link) | no (Pix renewal) |
| **AbacatePay** | R$ 0,80 | 3,50% + R$ 0,60 | provider's hosted page | no (Pix renewal) |
| **Stone (Pagar.me)** | under negotiation | ~3,19% (list) / negotiable | hosted checkout (order with `checkouts`) | no (Pix renewal) |
| **Stripe** | 1,19% (invite only) | 3,99% + R$ 0,39 | Stripe Checkout page (card **and** Pix) | no |
| **PagBank** | up to 1,89% | 4,99% + R$ 0,40 (14 days) · 3,99% + R$ 0,40 (30 days) | hosted Checkout | no (Pix renewal) |
| **Mercado Pago** | 0,99% | 4,98% (immediate) · 3,98% (30 days) | card tokenised in the browser, paid here | yes (`/preapproval`) |

Enable one or several at **Admin → Payment gateway** (one tab each). With more than one active the customer picks the provider on the invoice page. With Asaas / Efí / AbacatePay the card is always entered on the provider's secure page (card data never touches this server); Pix shows a QR code on the invoice and "renew automatically" generates a new Pix per renewal invoice. Webhooks: `/api/webhooks/{asaas|efi|abacatepay|pagarme|stripe|pagbank}` — `/api/webhooks/asaas` (header `asaas-access-token` = the webhook secret), `/api/webhooks/efi` (Efí appends `/pix`; the secret travels as `?secret=`; the admin button registers the Pix URL, the card URL is sent per charge) and `/api/webhooks/abacatepay?secret=…`. Every notification is stored, then the resource is **re-read from the provider's API** before an invoice is settled, and the amount must cover the invoice. Efí Pix needs the account's mTLS certificate converted to `.pem` on the server plus the receiving Pix key. These three integrations were written from the providers' public docs and tested with faked HTTP only: try each in its sandbox before going live.

#### Before going live: setup checklist per provider

Common to all: `APP_URL` must be a public **https** address (providers only accept such notification URLs), a **queue worker** must be running (webhooks are applied on the queue), and each provider should be tried first in its **sandbox / test account**.

* **Asaas** — Admin → Payment gateway → *Asaas*: paste the **API key**, tick *Test mode* only for the sandbox key. Click *Generate* in *Webhook secret* (32+ characters) and save. In Asaas → Integrations → Webhooks create a webhook with the URL shown in the admin, paste **the same value** as its *Auth token*, and select the events `PAYMENT_CONFIRMED` and `PAYMENT_RECEIVED`.
* **Efí** — create an application in Efí with the Pix scopes (`cob.write`, `cob.read`, webhook scopes) and the Cobranças API enabled. In the admin fill the **Client ID**, **Client secret**, the **Pix key** that receives the money and the **certificate path**. Convert the certificate downloaded from Efí (it can only be downloaded once) with `openssl pkcs12 -in cert.p12 -out efi.pem -nodes`, store the `.pem` outside the public folder and readable by the PHP user. Save, then click **Register Pix webhook** (the card notification URL is sent with every charge automatically). Without certificate and Pix key only card is offered.
* **AbacatePay** — paste the **API key** (a dev-mode key creates test charges). Generate a *Webhook secret*, save, and register in the AbacatePay dashboard the URL shown in the admin with `?secret=<the secret>` appended. This is the integration most likely to need small adjustments (status-check endpoints and webhook payload were written from the public docs): watch *Latest notifications* in the admin while testing.
* **Stone (Pagar.me)** — Stone's online gateway (API v5). Paste the **secret key** (`sk_…`; a `sk_test_…` key is the test environment). Generate a *Webhook secret*, save, and in Pagar.me → Settings → Webhooks register the URL shown with `?secret=<the secret>` appended and the events `order.paid` and `charge.paid`. Pix is created through `POST /orders` (QR code on the invoice); the card opens the hosted checkout (`checkouts` in the order). Fees are negotiated with Stone, so the figures shown are only a reference. The hosted-checkout request shape was written from partial documentation: test the card flow in the test environment first.
* **Stripe** — paste the **secret key** (`sk_live_…`/`sk_test_…`) and, after creating a webhook endpoint in Stripe → Developers → Webhooks with the URL shown and the events `checkout.session.completed` and `checkout.session.async_payment_succeeded`, its **signing secret** (`whsec_…`). Card and Pix both go to Stripe's Checkout page (Pix must be enabled in Stripe → Settings → Payment methods; Pix needs BRL). Every notification must carry a valid `Stripe-Signature` (5 min tolerance); without the signing secret all are rejected. Automatic renewal is not offered with Stripe (Pix is paid on Stripe's page, so there is no code to email).
* **PagBank** — paste the **token** (Bearer) and tick *Test mode* for the sandbox token. Generate a *Webhook secret* and save: the notification URL (with `?secret=`) is sent with every Pix order and card checkout. Pix uses `POST /orders` with a QR code; card uses the hosted Checkout. The payment-page link of the card checkout is read from the response `links` (`rel: PAY`), which was not confirmed in the docs I could read: test it in the sandbox.
* **Mercado Pago** — see below.

Every notification appears under *Latest notifications* in each provider's tab (`processed`, `ignored`, `rejected`, `failed`) with a note explaining why, which is the first place to look when a payment does not release access.

#### Mercado Pago

Configure it at **Admin → Payment gateway** (`/admin/payment-gateway`): enable it, paste the **access token** (stored encrypted, shown masked), the **public key** (needed for cards), and optionally the **webhook secret**. Choose which methods are offered (card / Pix), the Pix validity, the card statement name and whether customers can pick **automatic renewal** (and whether the box starts checked). "Test connection" calls Mercado Pago with the saved token.

In Mercado Pago → Your integrations → Webhooks register `https://<APP_URL>/api/webhooks/mercadopago` with the topics `payment`, `subscription_authorized_payment` and `subscription_preapproval`. Notifications are stored in `webhook_events`, answered with 200 immediately and processed by the queue (`ProcessMercadoPagoEventJob`, retried with backoff). The resource is always re-fetched from the API, and a payment only settles an invoice when its `external_reference` is the invoice number and the amount covers it. With a webhook secret set, the `x-signature` header is verified too.

How customers pay (`/billing/invoices/{id}`):

| Method | One-time | "Renew automatically" |
|---|---|---|
| Credit card | card token created in the browser (MP.js, the card data never reaches this server) → `POST /v1/payments` | `POST /preapproval` (Mercado Pago charges every cycle; only when the invoice is the full plan price). The amount is kept in sync on plan changes; cancelling the subscription or turning renewal off cancels it |
| Pix | `POST /v1/payments` → QR code + copy-and-paste, page polls until paid | **Mercado Pago has no automatic Pix debit**, so each renewal invoice gets a new Pix generated automatically and emailed to the customer |

Access is only granted by `SubscriptionService::markInvoicePaid`, called from the webhook (or the immediate card response). Tests: `tests/Feature/PaymentGatewayTest.php` (all HTTP faked; the integration has not been exercised against the real Mercado Pago API yet, so try it with `TEST-` credentials first).

Requirements: `APP_URL` must be public https (Mercado Pago only accepts such notification URLs), a queue worker must be running, and after pulling these changes run `php artisan migrate` and `php artisan billing:sync-plans --force` (adds the `mcp_access` feature to existing plans).

* * *

## 🔴 Redis & Instance Alerts

### Where Redis is used
| Area | What | Redis DB |
|---|---|---|
| Queues (`disparos`, `default`, `warmup`) | `QUEUE_CONNECTION=redis` – atomic pop, delayed jobs, `retry_after` | 2 (`queue`) |
| Per-number send gate | one message at a time per WhatsApp number + pause between sends (`Cache::lock` + `next_send_at`), shared by all workers/servers; a job that must wait is rescheduled, the worker never sleeps | 0 / 1 |
| Dispatch lock | `whatsapp:queue` cannot run twice at once; scheduler tasks use `onOneServer()` | 0 |
| Health state | outage debounce, one alert per outage, failure streak counters | 1 |
| Welcome campaigns | reply tracking and message pool | 0 |

Redis is optional: the default stays `QUEUE_CONNECTION=database` / `CACHE_DRIVER=file`. To switch set in `.env`:
```
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
REDIS_CLIENT=predis   # pure PHP, no extension
REDIS_HOST=127.0.0.1
```
then `composer install`, `php artisan config:clear` and restart the workers. Jobs still in the old database queue are not moved: drain them once with `php artisan queue:work database --stop-when-empty`.
`./start-local.sh` and `./install.sh vps` already configure this (`--no-redis` to opt out).

### Instance-down notification
When a number goes down **and sending was affected**, its owner gets an in-app notification (bell) and an e-mail:
1. A disconnect arrives (Evolution webhook – which alone never changes the status –, 3 consecutive failed sends, or the `instances:health` check every 5 min that also catches lost webhooks).
2. After `INSTANCE_ALERT_AFTER_MINUTES` (3) Evolution is asked again – short flaps are ignored.
3. Alert only if messages are waiting for that number (or it delivered in the last 24 h); an idle number is not reported. If the user has another connected number, only this number's share counts.
4. One alert per outage, a "connected again" notice on recovery.

Admins are also notified when the Evolution API stops answering and when a queue grows beyond `queue_max_pending` (`queue:monitor`, Redis only). Settings: `config/instance_health.php`. Run `php artisan instances:health` by hand to check now.

### Agent signature
In the chat header (badge icon) each user can set a name. Anything sent from the CRM then goes out as `*Name (CRM):*` + new line + the message (text, and the caption of image/video/document; voice notes carry no text). An empty name or only spaces turns it off; `*`, `_`, `~` and line breaks are stripped. The suffix is `CHAT_SIGNATURE_SUFFIX` (default `(CRM)`). Run `php artisan migrate`.

### Chat history and `@lid` contacts
- The chat imports the conversations Evolution already stores the first time a number is opened (and with the "Import history" button): newest chats first, up to `CHAT_HISTORY_MAX_CHATS` chats and `CHAT_HISTORY_PER_CHAT` messages each, page by page. Evolution can only return what it stored: keep `DATABASE_SAVE_DATA_HISTORIC=true` and the `MESSAGES_SET` / `MESSAGING_HISTORY_SET` webhooks on (already set in `docker-compose.local.yml` and `.docker/`). Messages from before the number was paired only exist if WhatsApp delivered them during pairing (`syncFullHistory`).
- WhatsApp sometimes identifies a person by an opaque `…@lid` id. The app looks up the real phone (message key, CRM contact, Evolution), turns the chat into `<phone>@s.whatsapp.net`, merges it into the chat of that phone if there is one, and stores the lid on the contact. Diagnose/run an import without the queue with `php artisan chat:sync-history <id|instance_name>` (prints what Evolution returns; when it has no chat list the conversations are rebuilt from the stored messages). WhatsApp resolves phone → LID but generally **not** LID → phone, so the app learns each pair from the message key (`remoteJidAlt`), from the echo of messages it sent (chat or campaign), from CRM contacts and from Evolution, and keeps it in the `lid_mappings` table (run `php artisan migrate`). When none of these reveals the number, open the chat's info panel and use "Link to this number": the chats are merged and every later message follows. The import button lets you choose which of your numbers to import, and messages always leave through the number of the open chat. Repair existing chats with `php artisan chat:fix-lids` (`--no-http` uses local data only).

## 🧪 Tests

`vendor/bin/phpunit` (in-memory SQLite, no external services): fair queue planner and command, chat backend, Telegram, Instagram and TikTok channels (webhook secret/signature, OAuth, token refresh, inbound, AI reply through the same bot/account, reply window, settings), channel statistics and cross-channel contact links, avatar caching, billing lifecycle and plan enforcement, the Mercado Pago gateway (admin settings, Pix, card, recurrence, webhook) and the Asaas / Efí / AbacatePay / Stone (Pagar.me) / Stripe / PagBank gateways (HTTP faked).

* * *

## 🛠️ Elite Features

* ✅ **AI Agent Manager:** Full control over AI prompts and personality per instance.
* ✅ **Tool Call Chain API:** External AI agents can manage contacts, campaigns, instances and sending via tools or REST.
* ✅ **Multi-Instance Management:** Manage multiple WhatsApp numbers and departments within a single platform.
* ✅ **Performance Dashboard:** Track success rates, errors, and AI token consumption in real-time.
* ✅ **Smart Lead Management:** Bulk import via Excel/CSV with dynamic column mapping.
* ✅ **High-Fidelity Multimedia:** Support for Images, PDFs, Videos, and Audio (OGG format to simulate human recording).
* ✅ **WhatsApp Chat (CRM):** WhatsApp Web-style inbox with text, media, voice notes, replies, reactions and a CRM side panel.
* ✅ **Multi-language UI:** English, Portuguese (BR) and Spanish with a language selector (`config/languages.php`).
* ✅ **Fair Send Queue:** every batch of 50 messages is balanced across users and sessions, so no campaign starves the others.
* ✅ **Plans & Billing:** freemium + 3 plans, trial, due dates, grace period and read-only blocking for non-payment.
* ✅ **Payments (Asaas, Efí, AbacatePay, Stone, Stripe, PagBank, Mercado Pago):** Pix and credit card, one-time or automatic renewal, several providers at once, configured from the admin area.
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

### Quick install (`install.sh`)

```bash
git clone https://github.com/marcelnagm/whastappsender.git && cd whastappsender   # ⭐ star + follow @marcelnagm, thanks!

# Local machine (PHP 8.1+, Composer and MySQL already installed)
./install.sh local --create-db --admin-email you@example.com --start

# Fresh Debian/Ubuntu VPS (as root): Nginx + PHP-FPM + MySQL + Supervisor workers + scheduler + Let's Encrypt SSL
sudo ./install.sh vps --domain sama.example.com --email you@example.com --admin-email you@example.com

# After every `git pull`
./install.sh update          # use sudo on the VPS (it also restarts the workers)
```

Run `./install.sh --help` for every option (`--db-*`, `--evolution-*`, `--groq-key`, `--no-ssl`, `--yes` for no questions...).
What it does: checks PHP and extensions, installs dependencies, writes `.env` (a **new `APP_KEY` on every fresh install**; production
settings and a generated database password on the VPS), migrates the database, creates the first administrator
(`php artisan user:admin you@example.com`, password generated when omitted), and on the VPS configures Nginx, PHP limits, the four
Supervisor processes (`disparos`, `default`, `warmup`, `schedule`) and HTTPS. It is safe to run again: an existing `.env` keeps its key and
values. The Evolution API is **not** installed by the script: point `WHATSAPP_*` at your instance (or use the Docker stack below).
On a VPS clone the project somewhere Nginx can read, e.g. `/var/www/sama`, and point the domain's DNS to the server before running.

The manual steps below do the same by hand.

### Local development with Docker (`start-local.sh`)

PHP runs on your machine (`php artisan serve`, queue workers, scheduler, no Supervisor); Docker only provides the infrastructure
described in `docker-compose.local.yml`: **MySQL 8**, **Evolution API v2.3** (+ its Postgres), **MinIO** (S3 for campaign media) and
**Mailpit** (catches every e-mail, inbox at http://localhost:8025). Everything is bound to `127.0.0.1`.

```bash
./start-local.sh                      # checks .env, starts the services, verifies them, migrates
./start-local.sh --fix-env --admin-email you@example.com   # also rewrites .env values that don't fit the local stack
php artisan serve --host=0.0.0.0 --port=8000              # 0.0.0.0 so Evolution (in Docker) can post webhooks to your PHP
php artisan queue:work --queue=default,disparos,warmup --sleep=3 --tries=3
php artisan schedule:work
```

`./start-local.sh --php` starts those three for you in the background. Other commands: `check` (verify only), `status`, `logs [service]`,
`down` (stop, keep data), `reset` (stop **and delete** the local data), `--tools` adds phpMyAdmin (http://localhost:8082).

The credentials live in the Laravel `.env` (docker compose reads the same file). Before starting anything the script refuses values that
would hurt: a `DB_HOST` that is not your machine (the sample `.env.example` points to the author's server, so migrations would run
there), sample passwords/API keys, the shared sample `APP_KEY`, and endpoints that don't match the local stack; `--fix-env` repairs them
(keeping a backup, `.env.backup-*`, which is git-ignored). After starting it **tests the credentials for real**: the MySQL login (inside
the container and from your PHP), the Evolution API key (and that a wrong key is rejected), the MinIO login/bucket and Mailpit.

Notes: MySQL only reads its user/password the first time its volume is created (if you change them later, run `./start-local.sh reset`).
Media URLs use `host.docker.internal` so both your browser and the Evolution container can fetch uploads; on Linux add
`127.0.0.1 host.docker.internal` to `/etc/hosts` if the script warns about it. The production stack (`.docker/`, `install.sh vps`) is separate.

### Option A — Local development (without Docker)

```bash
git clone https://github.com/marcelnagm/whastappsender.git && cd whastappsender   # ⭐ star + follow @marcelnagm, thanks!
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan queue:work --queue=default,disparos,warmup --sleep=3 --tries=3
```

Add your `GROQ_API_KEY` to `.env` before running migrations. Billing is off by default (`BILLING_ENABLED=false`); the payment gateway is configured in the admin area after logging in (**Admin → Payment gateway**). For the scheduled tasks (queue dispatch, warmup, billing) run `php artisan schedule:work` locally or the cron entry described in [Laravel Scheduler](#c-laravel-scheduler-cron).

> ⭐ Found it useful? Star the repo and/or follow [@marcelnagm](https://github.com/marcelnagm) on GitHub.

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

**Option A — Supervisor** (recommended). Configs in `.docker/supervisor/conf.d/laravel-worker.conf`:

| Program | Queue | Purpose |
|---------|-------|---------|
| `worker-default` | `default` | Webhooks, campaign job generation, welcome flows |
| `worker-disparos` | `disparos` | Bulk sends + AI replies |
| `laravel-schedule` | — | `schedule:work` (autosend + `warmup:generate`) |

Add `worker-warmup` for the `warmup` queue when running chip maturation (see [Warmup Model](#2-warmup-model)).

**Option B — Manual worker** inside the PHP container:

```bash
docker exec -it whatsapp_app_php php artisan queue:work --queue=default,disparos,warmup --sleep=3 --tries=3
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
* 📂 **`.docker/`:** Production Docker stack with Supervisor configs for workers and scheduler.
* 📂 **Architecture:** Queue isolation (`default` / `disparos` / `warmup`) + bilateral Warmup model.
* 📂 **Tool Call API:** `app/Services/Api/ToolRegistry.php` — tool definitions for AI agents.

---

**Legal Disclaimer:** SAMA Envios is a productivity tool. Its use must comply with Meta's guidelines and local data protection laws. We do not encourage SPAM.

© SAMA Envios — Where Software Engineering meets Artificial Intelligence.
