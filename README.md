# 🚀 SAMA Envios - Enterprise AI & WhatsApp Automation Platform

![PHP Version](https://img.shields.io/badge/php-%5E8.0-777bb4?style=for-the-badge&logo=php&logoColor=white)
![Laravel Version](https://img.shields.io/badge/laravel-%5E9.0-ff2d20?style=for-the-badge&logo=laravel&logoColor=white)
![AI-Powered](https://img.shields.io/badge/AI-Groq_%C2%B7_OpenAI_%C2%B7_Claude_%C2%B7_Gemini_%C2%B7_Ollama-orange?style=for-the-badge&logo=openai)
![Docker](https://img.shields.io/badge/docker-%230db7ed.svg?style=for-the-badge&logo=docker&logoColor=white)
[![GitHub stars](https://img.shields.io/github/stars/marcelnagm/whastappsender?style=for-the-badge&logo=github)](https://github.com/marcelnagm/whastappsender/stargazers)
[![GitHub followers](https://img.shields.io/github/followers/marcelnagm?style=for-the-badge&logo=github&label=Follow)](https://github.com/marcelnagm)

> ⭐ **Cloned or using this project? Please give it a star and/or follow [@marcelnagm](https://github.com/marcelnagm).**
> It's free, takes two seconds and is what keeps the project maintained. Thank you! 🙏

**SAMA Envios** is a mission-critical communication infrastructure that merges the power of **Laravel** with the LLM of your choice (**Groq, OpenAI, Claude, Gemini, DeepSeek, Mistral, OpenRouter, Ollama or any OpenAI-compatible endpoint**). Designed for teams that demand stable bulk messaging and **Intelligent AI Customer Service** on WhatsApp, Telegram, Instagram, Facebook Messenger, TikTok and e-mail in one omnichannel inbox with per-channel statistics, answering through the very number, bot or account the customer wrote to. On top of the inbox sits a complete **sales CRM**: Kanban funnel, team inbox with assignment and labels, follow-up tasks, automations, custom fields and segments, consent register, team performance with goals and an AI-assisted deal forecast.

**Documentation map**

| Section | Topic |
|---------|-------|
| [AI Agents](#-ai-agents-multi-provider) | Providers, model choice, prompt, per-instance agents, working hours |
| [Tool Call Chain API](#-tool-call-chain-api-ai-agents) | REST + AI agent tools |
| [System Architecture](#-system-architecture-queues--warmup) | Queues, Warmup, production requirements |
| [WhatsApp Chat (CRM)](#-whatsapp-chat-crm) | WhatsApp Web-style inbox on top of Evolution API |
| [Telegram](#-telegram) | Connect Telegram bots; same chat, AI agents and plan limits; always answers through the bot the message came in |
| [Instagram](#-instagram-direct) | Instagram DMs in the same omnichannel chat; AI answers through the account that received the message |
| [Facebook](#-facebook-messenger) | Facebook Page (Messenger) conversations in the same chat; replies go through the Page that received the message |
| [TikTok](#-tiktok-business-messaging) | TikTok Business DMs in the same chat, step-by-step setup (developer app, approval, OAuth, webhook) |
| [Email](#️-email-smtp--imap) | Connect a mailbox with SMTP/IMAP credentials: e-mail joins the chat, the AI answers by e-mail and the sender is unified with the same contact |
| [Channel statistics & linked contacts](#-channel-statistics--linked-contacts) | Per-channel numbers, and one person's WhatsApp / Telegram / Instagram / Facebook / TikTok / e-mail conversations tied together |
| [Contact ranking & unresponsive contacts](#-contact-ranking--unresponsive-contacts) | Engagement score with tiers, and automatic deactivation of contacts whose WhatsApp messages are never delivered |
| [Signup welcome](#signup-welcome-message) | Admin chooses the campaign message sent to people who register on the site |
| [Contact forms](#-contact-forms-embeddable) | Build a form, paste one script line on any site; leads land in the CRM and the AI answers on WhatsApp / available channels |
| [Sales pipeline](#-sales-pipeline-kanban) | Kanban funnel of deals: drag cards between stages, values, expected close date, won / lost with reason, win rate |
| [Team inbox](#-team-inbox--deals-from-conversations) | Assign conversations (by hand or balanced), labels, internal notes, filters, and deals opened automatically from new conversations and contact forms |
| [Follow-ups](#-follow-up-tasks--reminders) | Tasks with a due time for a contact or conversation, assigned to a person, with an in-app reminder when they fall due |
| [Consent](#️-consent--opt-out) | Proof of who agreed to be messaged and how, opt-in / opt-out words on every channel, consent box on forms, "campaigns only to contacts with consent" switch, CSV export |
| [Segments](#-custom-fields--segments) | Your own contact fields (text, number, date, choice, yes/no), saved segments with conditions, segment as campaign audience, CSV export |
| [Automations](#-automations) | "When X, do Y" rules: triggers on deals, labels and customer words; actions that create tasks, label, assign, move deals and notify; loop protection and run log |
| [Team performance](#-team-performance--goals) | First-response time, conversations answered, deals won/lost and follow-ups per person, monthly goals with progress |
| [Deal forecast](#-deal-forecast--priorities) | Chance to close for every open deal with the reasons behind it, weighted forecast, priority list with the next action, optional written advice from your AI agent |
| [Opening hours](#-opening-hours-away-message--greeting) | Business hours of the account, automatic away message outside them and greeting on the first message of a new conversation |
| [Products & deal items](#-products--deal-items) | Product catalog and the items of each deal: quantity, price, discount; the deal value becomes the total of its items |
| [Proposals](#-proposals-quotes) | Quote built from a deal's items, public link with online accept / decline, notices and automation triggers, print / save as PDF |
| [Lead sources](#-lead-sources) | Where each deal came from (channel, form + UTM campaign, or typed) and the funnel result per source: created, won, win rate, value, days to win |
| [Quick replies](#-quick-replies) | Canned answers shared by the team: type `/shortcut` in the chat, with variables like the customer's name |
| [Team & roles](#-team--roles-sub-users) | Sub-users working for the contract account (admin, approver, creator, sender, attendant, analyst) |
| [Audience & approval](#-audience--approval-of-campaigns) | Choose the audience by score and channels; approval flow before sending |
| [Fair Send Queue](#️-fair-send-queue) | Balanced batches across users and sessions, with plan priority |
| [Plans & Billing](#-plans--billing) | Freemium + 3 plans, due dates, blocking, Asaas · Efí · AbacatePay · Stone (Pagar.me) · Stripe · PagBank · Mercado Pago (Pix / card, customer chooses) |
| [Environment variables](#-environment-variables) | Every `.env` variable, grouped by area, with what it controls and its default |
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
| `list_contacts` | List contacts (search, pagination, `tier`, `status`, `sort=score`) |
| `get_contact` | Get contact by ID |
| `create_contact` | Create a contact |
| `update_contact` | Update a contact (`score` sets it by hand and locks it; `score_locked=false` hands it back to the automation) |
| `delete_contact` | Delete a contact |
| `bulk_delete_contacts` | Delete multiple contacts |
| `bulk_update_contact_status` | Update status (`ativo`, `inativo`, `no-whatsapp`) |
| `clear_contacts` | Delete all contacts for the current user |
| `sync_contact_photo` | Sync profile photo from WhatsApp |
| `get_contact_ranking` | Contacts ordered by score with tier and breakdown (`tier`, `limit`) |
| `rescore_contact` | Recalculate one contact's automatic score now |
| `get_contact_channels` | A contact's linked Telegram / Instagram / TikTok identities and conversations |
| `link_contacts` / `unlink_contact` | Tie / untie a channel contact to a WhatsApp contact |
| `check_unresponsive_contacts` | Run the "messages never delivered" deactivation for your contacts (`dry_run`, `hours`) |

#### Channels (Telegram · Instagram · TikTok)

| Tool | Description |
|------|-------------|
| `list_channels` | Connected accounts (`channel` filter) |
| `connect_telegram_bot` | Connect a bot with its BotFather `token` |
| `connect_instagram_account` | Connect an Instagram professional account with its access `token` |
| `connect_facebook_page` | Connect a Facebook Page (Messenger) with its Page access `token` |
| `get_tiktok_connect_url` | URL the person opens in a browser to authorise TikTok (OAuth needs a browser) |
| `renew_channel_connection` / `remove_channel_account` | Renew tokens/webhook, or disconnect (`instance_id`) |
| `get_channel_stats` | Per-channel numbers for 7, 30 or 90 days |

#### Omnichannel inbox

| Tool | Description |
|------|-------------|
| `list_conversations` | Conversations of every channel (`channel`, `instance_id`, `unread`, `search`); `window_open` says whether a reply is still allowed |
| `get_conversation_messages` | Messages of a conversation (`chat_id`, `before_id` for older pages) |
| `send_conversation_message` | Send text through the channel the conversation belongs to (`chat_id`, `text`); needs the `chat` plan feature |
| `mark_conversation_read` | Mark as read |
| `pause_conversation_ai` | Pause / resume the AI agent in that conversation (`paused`) |

#### Campaigns

| Tool | Description |
|------|-------------|
| `list_campaigns` | List campaigns |
| `get_campaign` | Get campaign details |
| `create_campaign` | Create a campaign |
| `update_campaign` | Update a campaign |
| `delete_campaign` | Delete a campaign and its items |
| `get_campaign_report` | Delivery report with stats |

#### Team (sub-users)

| Tool | Description |
|------|-------------|
| `list_team_members` | Sub-users and the available roles |
| `add_team_member` | Create a sub-user (`name`, `email`, `password`, `role`) |
| `update_team_member` | Change role / active / name / password (`member_id`) |
| `remove_team_member` | Remove a sub-user |

#### Campaign Items & Sending

| Tool | Description |
|------|-------------|
| `list_campaign_items` | List message templates |
| `get_campaign_item` | Get item details |
| `create_campaign_item` | Create a message template |
| `update_campaign_item` | Update a message template |
| `delete_campaign_item` | Delete a message template |
| `generate_test_send` | Create a test send job |
| `launch_campaign` | Generate send jobs for the message's audience (everyone when none was chosen). Team members can launch only approved messages |
| `preview_campaign_audience` | How many contacts a filter reaches: `tiers`, `min_score`, `max_score`, `channels`; `save=true` stores it on the message |
| `submit_campaign_item_for_approval` | Send the message to the approvers (may suggest the audience) |
| `list_pending_approvals` | Messages waiting for review |
| `approve_campaign_item` / `reject_campaign_item` | Approve with the audience (or reject with a `note`) |

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
| Instances (WhatsApp) | `GET/POST /instances`, `GET /instances/{id}/qr`, `POST /instances/{id}/sync` (Telegram/Instagram/TikTok accounts answer `422` here: use `/channels`) |
| Contact ranking | `GET /contacts/ranking?tier=&limit=`, `POST /contacts/{id}/rescore`, `GET /contacts?tier=&status=&sort=score`, `POST /contacts/check-unresponsive` (`dry_run`, `hours`) |
| Contact links | `GET /contacts/{id}/channels`, `POST /contacts/{id}/link` (`linked_contact_id`), `POST /contacts/{id}/unlink` |
| Channels | `GET /channels`, `GET /channels/stats?days=`, `POST /channels/telegram`, `POST /channels/instagram`, `GET /channels/tiktok/url`, `POST /channels/{id}/renew`, `DELETE /channels/{id}` |
| Team | `GET/POST /team`, `PUT/DELETE /team/{id}` |
| Approval & audience | `GET /approvals`, `POST /campaign-items/{id}/audience` (preview, `save=true`), `/submit`, `/approve`, `/reject` |
| Conversations | `GET /conversations`, `GET/POST /conversations/{chat}/messages`, `POST /conversations/{chat}/read`, `POST /conversations/{chat}/ai-pause` |
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
| `whatsapp:queue` | `WHATSAPP_QUEUE_CRON` (default hourly), only if `WHATSAPP_AUTOSEND=true` | Dispatches pending `WhatsappJob` rows to `disparos` (fair queue + plan priority) |
| `instances:health` | `INSTANCE_HEALTH_CRON` (default every 5 min) | Detects down sessions / lost webhooks / Evolution outages and alerts the owner |
| `queue:monitor` | Every 5 min (only when `QUEUE_CONNECTION=redis`) | Alerts when a queue exceeds `QUEUE_BACKLOG_MAX` pending jobs |
| `contacts:deactivate-unresponsive` | Hourly | Deactivates WhatsApp contacts whose messages are never delivered |
| `tasks:remind` | every minute | Notifies the person responsible for each follow-up task that fell due (once) |
| `email:fetch` | `EMAIL_FETCH_CRON` (default every minute) | Imports new mail from the connected mailboxes (IMAP) and lets the AI answer |
| `contacts:score` | Hourly | Recalculates the engagement score / tier of contacts |
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

## 📘 Facebook (Messenger)

A Facebook **Page** is another channel of the same instance structure: its Messenger conversations appear in the omnichannel **Chat** (badge "Facebook"),
the **AI agents** answer them and **every reply leaves through the Page that received the message**. Messages typed in the Page inbox (echo) also appear in the chat.

```
Customer ──► Messenger ──► POST /api/webhooks/facebook (X-Hub-Signature-256) ──► ProcessFacebookEventJob ──► chats / chat_messages ──► AI agent
Customer ◄── Messenger ◄── graph.facebook.com/{version}/me/messages ◄── SendAiReplyJob · SendChatMessageJob (same Page)
```

**Setup**

1. In [Meta for Developers](https://developers.facebook.com/apps) use the same app as Instagram (or create one) and add the **Messenger** product. Permissions: `pages_messaging`, `pages_manage_metadata`, `pages_show_list`.
2. Put the credentials in `.env`. `FACEBOOK_APP_SECRET` and `FACEBOOK_VERIFY_TOKEN` fall back to the Instagram values, so one Meta app is enough. `APP_URL` must be a **public https** address.
3. In *Messenger > Webhooks* set the **callback URL** to `https://YOUR-DOMAIN/api/webhooks/facebook`, the **verify token** to `FACEBOOK_VERIFY_TOKEN` and subscribe the Page to `messages`, `message_echoes` and `messaging_postbacks`.
4. Run `php artisan migrate`.
5. Generate the **Page access token** (Messenger > Access tokens, or a long-lived Page token) and paste it in **Facebook** in the menu. The app validates it, subscribes the Page and stores the token encrypted and never shows it again.
6. Keep workers on `default` and `disparos`. Assign an AI agent to the Page in **AI** if you want one only for Facebook.

```
FACEBOOK_APP_SECRET=your-meta-app-secret
FACEBOOK_VERIFY_TOKEN=any-long-random-string
#FACEBOOK_API_VERSION=v21.0
#FACEBOOK_WINDOW_HOURS=24
#FACEBOOK_TIMEOUT=30
```

**What works:** text, images, video, audio and files in both directions, typing indicator, opt-out with `/stop` or `#sair`, long answers split (Messenger limits a text to 2000 characters), statistics, contact links to a WhatsApp contact, REST (`POST /channels/facebook`) and MCP (`connect_facebook_page`).

**Limits (Meta, not the app):** replies only within `FACEBOOK_WINDOW_HOURS` (24 h) of the customer's last message; a Page **cannot start a conversation**; campaigns, new chat by phone and warm-up stay **WhatsApp-only** (Facebook contacts have status `facebook`); apps in development only receive messages from people with a role in the app, and going live needs Meta app review.

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

## ✉️ Email (SMTP / IMAP)

Open **Email** in the menu and type the credentials of a mailbox (for example `vendas@yourcompany.com`). It becomes one more channel of the omnichannel chat: customers who write by e-mail show up next to WhatsApp, Telegram, Instagram, Facebook and TikTok, the **AI agent answers by e-mail** and the person is **unified with the same contact** across channels.

* **Sending (SMTP, required):** server, port (`587` STARTTLS, `465` SSL/TLS), username and password. They are tested when you connect (login without sending anything) and stored **encrypted**. Replies go out through that mailbox, inside the same e-mail thread (`Re: subject`, `In-Reply-To` / `References`), whether written by an attendant in the chat (attachments included) or by the AI.
* **Receiving (IMAP, optional):** server, port (`993` SSL/TLS, `143` STARTTLS) and, if different, user/password (they default to the SMTP ones). Without IMAP the channel is send-only. The scheduler runs `email:fetch` every minute (`EMAIL_FETCH_CRON`); run `php artisan email:fetch --now` to check by hand. Only mail that arrives **after** you connect is imported, nothing in your mailbox is marked as read or deleted, and mail is read with the [`webklex/php-imap`](https://github.com/Webklex/php-imap) library (pure PHP: no PHP `imap` extension needed, but `iconv`, `zip`, `fileinfo`, `libxml` and `openssl` are). Sending uses `symfony/mailer`. The app only passes the credentials and maps each message to a chat message (`app/Services/Email/Mailbox.php`).
* **Quick setup buttons** fill the servers of Gmail, Outlook / Microsoft 365, Zoho and Yahoo. Gmail, Outlook and Yahoo require an **app password** (not the normal one).
* **One person, one contact:** an e-mail conversation is the contact `em:<address>`. If a WhatsApp contact (CRM, import, form) has the same address in its *email* field, the two are linked automatically, in either order, so the chat shows the person's conversations in every channel. When two WhatsApp contacts share one address nothing is guessed, and a link made by a person is never overridden. The *Statistics* page and the contact ranking count e-mail like any other channel.
* **The AI and e-mail:** the AI of that mailbox answers each message (working hours, plan quota, `#sair` opt-out and *human handoff* apply). Safety rules for mail: automatic messages (newsletters, `no-reply@`, out-of-office, `List-Unsubscribe`, `Auto-Submitted`) are shown in the chat but never answered; bounces are ignored; AI replies are marked `Auto-Submitted: auto-generated` so other robots do not answer back; and one sender gets at most `EMAIL_AI_MAX_REPLIES_PER_DAY` AI answers per day (anti mail-loop).
* **Contact forms:** a form lead that only leaves an **e-mail** is answered through your mailbox (in the form's *channels the AI may use*, tick Email). If the lead also typed a WhatsApp number, WhatsApp is still used first.
* **Safety of the server address:** the host and port come from the user, so SMTP/IMAP servers on localhost or private networks (and `169.254.*`) are refused unless `EMAIL_ALLOW_PRIVATE_HOSTS=true` (default only in `APP_ENV=local`), and only the usual ports are accepted (`EMAIL_SMTP_PORTS`, `EMAIL_IMAP_PORTS`). Server messages never echo the password.
* **Limits:** attachments above `EMAIL_MAX_ATTACHMENT_BYTES` (10 MB) are not kept, `EMAIL_MAX_PER_POLL` messages are imported per check, plain text is used for the answer (HTML mail is converted to text, quoted history is trimmed). Mailboxes count as channel accounts in the plan limit. E-mail contacts have status `email`, so WhatsApp campaigns never pick them, and campaigns do not send by e-mail (no bulk mailing from here).
* **REST / agent tools:** `POST /api/v1/channels/email` (`connect_email_account`), `GET /api/v1/channels?channel=email`, renew/remove like the other channels. The chat API (`/conversations`, `send_conversation_message`) works with e-mail chats too.
* Run `php artisan migrate` (new columns on `instances` and `chats`).

## 🎯 Contact ranking & unresponsive contacts

### Unresponsive contacts (automatic deactivation)

Sending to numbers that never receive the message wastes queue time and puts the account at risk. Every hour (`contacts:deactivate-unresponsive`, also runnable by hand with `--dry-run` and `--hours=`) the system looks for WhatsApp contacts whose messages stayed **sent without a delivery receipt for more than X hours** and sets them to `inativo` (reason `undelivered`, shown as a tooltip on the status badge).

| Variable | Default | Meaning |
|----------|---------|---------|
| `CONTACT_UNDELIVERED_ENABLED` | `true` | Turns the check on/off |
| `CONTACT_UNDELIVERED_AFTER_HOURS` | `72` | Hours without delivery before a message counts |
| `CONTACT_UNDELIVERED_MIN_MESSAGES` | `1` | How many undelivered messages deactivate the contact |
| `CONTACT_UNDELIVERED_LOOKBACK_DAYS` | `30` | Older messages are ignored |
| `CONTACT_UNDELIVERED_EVIDENCE_DAYS` | `30` | The account must have received delivery/read receipts in this period |
| `CONTACT_UNDELIVERED_REACTIVATE` | `true` | Reactivates when the contact writes or a delivery receipt finally arrives |

Safeguards: accounts that never receive receipts (webhook off, receipts disabled) are skipped, so nobody is deactivated by a broken setup; contacts that wrote to you or got a delivered/read message after the first stale one are kept; only `ativo` WhatsApp contacts are touched (Telegram / Instagram / TikTok are never deactivated). Putting a contact back to `ativo` by hand clears the automatic reason. Needs `php artisan migrate`.

### Score ranking

Each contact has a **0-100 engagement score** (`contacts:score`, hourly, only recalculating contacts whose score is older than `CONTACT_SCORE_REFRESH_HOURS`), looking at the whole person (linked Telegram / Instagram / TikTok identities included):

| Signal | Max | How |
|--------|-----|-----|
| Recency | 30 | last inbound message, decaying over `CONTACT_SCORE_WINDOW_DAYS` |
| Frequency | 20 | messages received (logarithmic, so 200 messages do not drown everyone else) |
| Responsiveness | 20 | share of our messages answered within 48h |
| Delivery / read | 15 | delivered and read receipts |
| Conversation | 5 | two-way conversation exists |
| Channels | 5 | linked channels |
| Profile | 5 | name and email filled in |
| Penalties | up to -100 | opted out / no WhatsApp (-100), deactivated for no delivery (-40), stale undelivered messages (-8 each, max -25) |

Tiers: **hot** ≥ 70, **warm** ≥ 40, **cold** ≥ 15, **frozen** below (`CONTACT_SCORE_HOT/WARM/COLD`). The contact list has ranking filters and sorting by score, and the contact page shows what the score is made of. A score typed by a person is **locked** (the automation never overwrites it); unlock with `score_locked=false`. API: `GET /contacts/ranking`, `POST /contacts/{id}/rescore`; MCP: `get_contact_ranking`, `rescore_contact`.

* * *

## Signup welcome message

The message sent on WhatsApp to people who register on the site is chosen by the **administrator** in **Admin > Signup welcome**: pick any campaign message from the list
(with a preview) or **None** to turn it off. It is stored in the database (`app_settings`, run `php artisan migrate`), so it works with `config:cache` and needs no edit of `.env`.
`WHATSAPP_MESSAGE_UP` in `.env` is only the **fallback** for installs that never saved the setting; once the admin saves a choice (even "None") the panel wins.
The contact is created and the message queued on the `default` queue; a missing or deleted message is logged and never breaks the signup.

* * *

## 📝 Contact forms (embeddable)

Open **Forms** in the menu, build a form and paste **one line** on any website. Every submission becomes a contact and a conversation in the CRM chat, and the AI agent can answer the person right away.

```html
<script src="https://YOUR-DOMAIN/f/FORM-KEY.js" async></script>
<!-- optional: choose where it appears (otherwise it is drawn right after the script) -->
<div data-sama-form="FORM-KEY"></div>
```

* **You choose the fields** (text, e-mail, phone, long text, choice), labels, placeholders and which are required. Each field can be given a *meaning*: contact name, WhatsApp number, e-mail or "message for the AI"; the rest is stored as extra information. A form needs a WhatsApp or e-mail field.
* **What a submission does:** creates (or updates) the contact, opens/updates the conversation in the chat with the answers as the first message, and, if *AI reply* is on, the agent of that WhatsApp number answers (same working hours, plan quota, opt-out and *no WhatsApp* rules as any other conversation).
* **Which channel answers:** WhatsApp first (the number the visitor typed, through the connected number you choose or any connected one). If WhatsApp is not possible, a channel (Telegram, Instagram, Facebook, TikTok) the same person is already linked to is used, but only while its reply window is open. You can restrict the allowed channels per form. With nothing available the lead is simply stored (see the *Submissions* screen).
* **Public endpoint:** `GET/POST /api/public/forms/{key}` (CORS open). Protection: hidden honeypot field, per-IP rate limit (`FORMS_THROTTLE_PER_MINUTE`), optional *allowed websites* list (Origin check, subdomains included) and an *active* switch. The form is drawn in a Shadow DOM, so your site's CSS does not break it.
* Phone numbers typed without a country code receive `FORMS_DEFAULT_COUNTRY_CODE` (default `55`).
* Submissions are processed by the `default` queue worker. Run `php artisan migrate`. Team roles: *Administrator* and *Campaign creator* manage forms.

## 🧲 Sales pipeline (Kanban)

Open **Pipeline** in the menu. Every account starts with a **Sales** funnel (*New lead → Contacted → Proposal → Negotiation → Won / Lost*); stages can be renamed, recolored, added or removed, and you can keep **several pipelines** (e.g. one per product). This is the funnel board that Kommo, Pipedrive, HubSpot and RD Station CRM are built around.

* **Deals** carry a title, a value, an expected close date and (optionally) a contact. Drag a card to another stage or position; dropping it on a **Won** or **Lost** stage closes it (you are asked why it was lost) and dropping it on any other stage reopens it.
* The header shows open deals and their value, won value and the **win rate** (won ÷ closed).
* A stage that still holds deals cannot be removed, and the last pipeline cannot be deleted. Removing a pipeline removes its deals.
* Everything runs on the contract account; team roles **administrator** and **attendant** can work the funnel (permission `deals`), the others cannot.
* Data: tables `pipelines`, `pipeline_stages`, `deals` (run `php artisan migrate`). Deals come from the board for now; creating them from chat conversations and contact forms is the natural next step.

* * *

## 🗂️ Team inbox & deals from conversations

Everything below lives in the **chat side panel** (the *Contact info* button), card **Team**, and works for every channel (WhatsApp, Telegram, Instagram, Facebook, TikTok, e-mail). It follows the helpdesk model of Kommo, Zoko, Trengo and Wati.

* **Handled by:** each conversation has one person responsible (the owner or a team member that uses the chat). Choose it in the panel (or *Assign to me*). The list shows who has it and can be filtered: *All*, *Assigned to me*, *Unassigned*.
* **Automatic distribution** (*Inbox settings*, owner / administrator): *New conversations → Distributed to whoever has fewer open*. When a customer's first message arrives in an unassigned conversation, it goes to the team member with the fewest open (non-archived) conversations; the owner only receives them when there is no member. Imported history never assigns anything.
* **Attendant visibility:** *Attendants see → Only theirs and unassigned* hides other attendants' conversations (list, messages and sending answer `403`); owner and administrators always see everything.
* **Labels:** colored labels shared by the account (up to 50), several per conversation, shown in the list and filterable through the API (`GET /chat/api/chats?tag=ID`). Only owner / administrator delete a label.
* **Internal notes:** notes with author and time, visible to the team only; they are **not messages and are never sent to the customer**. The author, the owner and administrators can delete them.
* **Deals:** the panel shows the person's open deal (link to the funnel) or a **Create deal** button (one open deal per person). With *Open a deal when a new customer writes* on, the first message of a new customer, or of one back after `DEAL_NEW_AFTER_DAYS` (30) days of silence, opens a deal in the first stage of the default pipeline, already assigned to the attendant. With *Open a deal when a contact form arrives* on, every form submission does the same. Both switches are off by default; an open deal is never duplicated.
* Data: columns `chats.assigned_to / assigned_at`, tables `chat_tags`, `chat_tag_links`, `chat_notes`, and the four settings on the account owner (`users.chat_assign_mode`, `chat_agents_see`, `deal_auto_chat`, `deal_auto_form`). Run `php artisan migrate`.

Endpoints (session auth, `chat.*` permission): `GET /chat/api/chats/{id}/crm`, `POST …/assign`, `PUT …/tags`, `POST …/notes`, `POST …/deal`, `POST|DELETE /chat/api/tags`, `DELETE /chat/api/notes/{id}`, `PUT /chat/api/settings`.

* * *

## ⏰ Follow-up tasks & reminders

Open **Follow-ups** in the menu (or use the **Follow-ups** block of the chat side panel, which links the task to that conversation and contact). A task has *what to do*, *when*, *who* (the owner or a team member) and, optionally, a contact. Tabs: *Open*, *Today*, *Overdue*, *Done*; *Mine* / *Everyone* (team members start in *Mine*). Mark as done (and reopen), edit the time or remove.

* **Reminder:** the scheduler runs `php artisan tasks:remind` every minute; when an open task falls due, the person responsible gets an **in-app notification** (the bell) once. Changing the due time asks for a new reminder. A task without an assignee reminds the owner. A task without a time never reminds.
* **Time zone:** the "when" you type is in the application's time zone (`APP_TIMEZONE` / `config/app.php`).
* **Permission:** `tasks` for the administrator and the attendant; the owner always. Tasks, assignees and contacts are always checked against the account, so nobody can attach another account's contact or person.
* Data: table `tasks` (run `php artisan migrate`).

* * *

## ✨ Deal forecast & priorities

Every open deal on the **Pipeline** shows a **chance to close** badge (hover to see why) and the board shows a **Forecast** (value × chance). **Priorities** (link on that card) lists open deals ranked, with the **next action** for each.

* **A transparent heuristic, not a black box:** points come from the stage reached (the further in the funnel, the higher), how recently the customer wrote (or silence for weeks), an active conversation, whether a follow-up is scheduled or overdue, how long the deal has been open, the expected close date and the contact's score. Each deal lists every reason with its points, the result is kept between 1% and 99% and won / lost deals are not scored.
* **Next action:** a customer who is waiting for an answer comes first (and jumps the list), then overdue follow-ups, scheduling a follow-up, setting an expected date, or keep going.
* **Ask AI:** a button on each priority asks your AI agent (the one on the conversation's number, or your first enabled agent) for a short recommendation from the recent conversation and the signals above. It only writes advice; it never changes the number, never sends anything to the customer, and fails softly when no agent is enabled or the provider errors (limited to 20 requests per minute).

## 🏆 Team performance & goals

Open **Team performance** in the menu (administrator / analyst / approver / owner) to see, for the last 7 / 30 / 90 days, this month or a custom range:

* **First response:** how long a customer waited until the next reply (average and median), **conversations answered** and **messages sent** per person. A reply is credited to the person who sent it from the chat; typed on the phone it counts for whoever the conversation is assigned to; replies by the **AI agent** and **campaigns** close the wait but count for nobody (the AI total is shown apart). Messages sent before this feature have no author and fall under the assignee.
* **Deals** won (with value) and lost per assignee, **follow-ups** done and currently overdue.
* **Goals of the month** (administrator / owner set them): deals won, value won, conversations answered or average first response in minutes (lower is better), for the whole team or one person, with a progress bar.

## 🤖 Automations

Open **Automations** in the menu (administrator / owner). Each rule is *when* something happens → *then* one or more actions (up to 5), run on the account's behalf.

* **Triggers:** a deal is created, moves to a stage (any, or a chosen one), is won or lost; a label is applied to a conversation (any, or a chosen one); a customer writes one of the words you list (case-insensitive, anywhere in the message).
* **Actions:** create a follow-up task (title, due in N hours, for the owner, whoever has the conversation or a team member), apply a label, assign the conversation (and its deal), move the deal to a stage, notify someone in the app.
* **Safe by design:** an automation runs once per deal / conversation inside a chain and chains stop at depth 3, so two rules that move a deal back and forth never loop; a failing action is logged (*partial* / *error* in **Latest runs**) and neither stops the other actions nor the original change; rules only ever touch the account's own deals, labels, stages and team.
* Automations do not send messages to customers on their own: sending stays with campaigns (consent and approval rules) and with your team.

## 🧩 Custom fields & segments

Open **Segments** in the menu (administrator / campaign creator / owner).

* **Custom fields:** create your own data for each contact (*city*, *plan*, *birthday*...) with a type: text, number, date, choice list or yes/no (up to 30). They appear on the contact form and profile, and the **import** fills them from a column named like the field.
* **Segments:** a saved list of conditions (all must match) on score, consent, e-mail, creation date and your custom fields, with a live count while you build it (up to 50 segments). Each segment can be exported as CSV.
* **As a campaign audience:** the approval screen gets a **Segment** selector (also `segment_id` in the audience API); it narrows the score/tier/channel filters and the consent and opt-out rules still apply. A deleted segment reaches nobody, so a campaign is never sent to everyone by accident.
* Invalid conditions (unknown field, operator that doesn't fit the type, empty value) are dropped, never guessed.

## 🛡️ Consent & opt-out

Bulk messaging needs permission: Meta's policies and Brazil's LGPD expect you to be able to show **who agreed, when and how**. Open **Consent** in the menu (administrator / campaign creator / owner).

* **Status per contact:** *No record* (the default, as before), *Granted* or *Opted out*, plus an **append-only log** (date, source, evidence, IP, who recorded it). The contact page shows the status and the last records and lets you **Record consent** or **Opt out** by hand.
* **Where consent comes from:** a **contact form with a consent box** (set a *Consent text* on the form: the visitor must tick it and the exact text accepted, form and IP are stored); the **opt-in words** a customer sends (`#entrar`, `#aceito`, `/start`); a manual record on the contact; or the **import** (`POST /contact/import` with `consent=1` when you confirm the list agreed).
* **Opt-out words** (a message that is *exactly* one of them, any case): `#sair`, `/stop`, `#parar`, `#stop`, `parar`, `stop`. On any channel (WhatsApp, Telegram, Instagram, Facebook, TikTok, e-mail) the contact is marked *Opted out*, receives no campaign and no AI reply, and **the same person on their other channels is opted out too**. What an attendant types never counts. A keyword only affects the contact of the account that owns the number (other accounts that message the same person are not touched). Customise the lists with `CONSENT_OPTOUT_KEYWORDS` / `CONSENT_OPTIN_KEYWORDS`.
* **Switch "Campaigns only reach contacts with recorded consent"** (off by default, so nothing changes until you decide): when on, contacts without a *Granted* record are left out of campaign audiences, the send-queue generation and the welcome message; the audience count shown at launch already excludes them. Opted-out contacts are always excluded, switch or not.
* **Proof:** *Export proof (CSV)* downloads every record (cells that could run as a spreadsheet formula are neutralised).
* Contacts that had already opted out before this release were marked *Opted out* by the migration (`php artisan migrate`).
* Not included yet: a confirmation message to the customer after `#sair`, and an unsubscribe footer appended to campaign texts.

* * *

## 🧭 Lead sources

Every deal has an **Origin** (editable in its window, with suggestions from the ones you already used). It is filled automatically:

* **Conversations:** the channel of the number the customer wrote to (WhatsApp, Telegram, Instagram, Facebook, TikTok, Email).
* **Contact forms:** the embed script now sends the UTM parameters of the page (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`) and its path; only those keys are kept, as short plain text, on the submission. The deal's origin becomes `google / cpc / black-friday` (or *Form: name* when the page had no UTM). No change to the pasted snippet is needed.
* **Manual:** whatever you type.

**Pipeline → Lead sources** shows, for deals created in the last 30 / 90 days / 12 months, per origin: created, open, won, lost, **win rate**, **value won** and **average days to win**, best revenue first, with totals; deals without origin are grouped as *Not informed*. The origin also shows on each Kanban card.

## 📄 Proposals (quotes)

In a deal's window (with items) press **Proposal**: a quote is created from a **snapshot** of the items (later edits to the deal do not change what was sent; **Update from the deal** copies them again until the customer answers).

* **Owner page:** edit title, introduction, terms and *valid until* (15 days by default); **copy the link** or open **WhatsApp** with the message ready (both mark it as *sent*); **Preview** shows exactly what the customer sees without counting as an opening. The list under **Pipeline → Proposals** shows waiting / accepted (with value) / declined.
* **Customer page** (`/p/{token}`, no login, 40-character random token, `noindex`): items, total, terms; the customer types his name, ticks the agreement and **accepts**, or **declines** with an optional reason. The answer is final (409 afterwards), stores name, time and IP, and an expired proposal can no longer be answered. The page prints cleanly (**Print or save as PDF** in the browser; no PDF library is required).
* **You are notified** in the app when it is opened (once), accepted or declined, and **automations** gain the triggers *a proposal is accepted* / *declined* (for example: create a "Invoice" task, move the deal to Won). The deal is never closed by the customer alone.
* `CRM_CURRENCY` (default `R$`) is the symbol shown before the totals.

## 📦 Products & deal items

Open **Pipeline → Products** to build the catalog (name, SKU, unit, price; inactive products stop being offered). In a deal's window (pencil on the card) an **Items** block lets you add a catalog product or type a free item, with quantity, price (the catalog price unless you type another) and a discount %.

* **The value follows the items:** while a deal has items its value is their total (and the value field is locked); with no items, the typed value is kept, also after you remove the last item.
* **Snapshots:** an item keeps the name and price it was sold at. Editing or deleting a product later never rewrites deals that already have it.
* Up to 500 products per account and 50 items per deal. Anyone with access to the pipeline can manage them. Items are the base for the proposals described in the next features.

## 🕘 Opening hours, away message & greeting

Open **Opening hours** in the menu (administrator / owner): timezone, days and hours your team is available.

* **Away message:** a customer who writes outside the hours gets your text once (and not again to the same conversation for N hours, default 8).
* **Greeting:** the first message of a brand-new conversation, during opening hours, gets your welcome text.
* **Variables:** `{{name}}`, `{{first_name}}`, `{{company}}`, `{{today}}`, `{{hours}}`; an unknown `{{word}}` is left as typed and a phone number is never used as a name.
* **Safe by design:** they leave through the channel and number the customer wrote to; they are marked *automatic*, so they never count as an attendant's first response in **Team performance**; they are not sent to contacts who opted out, to groups, or when your own message started the conversation; the greeting is skipped when an AI agent is answering, and the away message is skipped when the agent answers outside hours too (its own *business hours only* switch decides).

## ⚡ Quick replies

Open **Quick replies** in the menu to build the team's library of canned answers (shared by the whole account; anyone who uses the chat can add, edit or remove). Each one has a **shortcut**, a **title** and a **text** (up to 200 replies).

* **In the chat:** type `/` at the start of the message; a list shows the matches (shortcut or title), ordered by prefix and then by how often they are used. ↑ ↓ to move, **Enter / Tab / click** to insert, **Esc** to close. The text lands in the message box already filled in, so the attendant can adjust it before sending.
* **Variables** (filled for the open conversation): `{{name}}`, `{{first_name}}`, `{{phone}}`, `{{agent}}` (the attendant's chat signature or name), `{{company}}` (the account name), `{{today}}`. A phone number is never used as a name (the variable stays empty); an unknown `{{word}}` is left as typed.
* Shortcuts are lower case, without `/` or spaces, and unique per account. Data: table `quick_replies` (run `php artisan migrate`).

* * *

## 👥 Team & roles (sub-users)

A **contract user** (the account owner) can add **sub-users** who work for that account. A member signs in with **their own login**, but everything runs on the
owner's account: contacts, campaigns, channels, plan limits and quotas. Members never see or change billing, the plan or the owner's profile.
Open **Team** in the menu, or use the API / MCP tools (`/api/v1/team`, `add_team_member`...). The number of members is a plan limit (Starter 2, Pro 5, Business 20).

The roles follow the model of **Meta / Instagram account roles** (Admin, Content creator, Moderator, Advertiser, Analyst), plus the *agent* of helpdesks such as
Chatwoot, whose permissions are also split by what the person may do (conversations, contacts, reports):

| Role | Instagram / Meta equivalent | Can do |
|------|----------------------------|--------|
| **Administrator** (`admin`) | Admin | Everything (including the sales pipeline) except billing/plan and the owner's profile; manages the team |
| **Approver** (`approver`) | Moderator | Reviews campaign messages, **chooses the audience (score + channels)**, approves or rejects; sees contacts and reports |
| **Campaign creator** (`creator`) | Content creator | Writes campaigns and messages, manages contacts, **submits for approval**; cannot send |
| **Sender** (`sender`) | Advertiser | **Launches approved** campaigns and retries sends; cannot edit the content |
| **Attendant** (`agent`) | Helpdesk agent | Answers conversations (chat, API `/conversations`), reads contacts, works the sales pipeline and the follow-ups; nothing else |
| **Analyst** (`analyst`) | Analyst | Read-only: statistics, ranking, campaign reports, contacts |

How it is enforced:

- **Closed by default**: a screen, API route or MCP tool that is not listed for the role answers `403`. The same table drives the web menu (items the role cannot open are hidden), the REST API and `/api/v1/tools` (`TeamRoles`).
- Members cannot have members, cannot change their own role or access, and a disabled member or owner loses access immediately (API tokens of a member are revoked when the role changes or the member is disabled).
- The **chat signature** is per person: each attendant signs with their own name; the owner's signature is not changed by a member.
- Passwords are set by the owner or an administrator member (there is no member self-service profile yet). Messages sent from the chat are recorded on the account, with the member's signature.

* * *

## 🎯 Audience & approval of campaigns

Before a message is sent, the **audience** is chosen by **score** and **channels** (screen **Approvals** in the menu, or `POST /campaign-items/{id}/audience`):

- **Score:** tiers (hot / warm / cold / frozen) and/or a minimum and maximum score (see [Contact ranking](#-contact-ranking--unresponsive-contacts)). Nothing checked = ignore the ranking.
- **Channels:** WhatsApp, Telegram, Instagram, Facebook, TikTok. A live counter shows how many contacts each channel reaches.
  WhatsApp reaches active numbers; the other channels reach only contacts **who wrote to you and are still inside the reply window** (Instagram/Facebook 24 h, TikTok 48 h), through the same account and with the same limits as a normal reply. They count against the monthly message quota and are spaced `CAMPAIGN_CHANNEL_DELAY` seconds apart.
- A person linked on several channels is reached **once**: when WhatsApp is selected, their linked Telegram/Instagram/Facebook/TikTok contacts are left out.
- Messages without an audience keep the old behaviour (every active WhatsApp number). Welcome campaigns (AI first contact) do not use the audience yet.

**Approval flow** (statuses `pending` → `approved` → `launched`, or `rejected`):

1. The **creator** writes the message and clicks **Submit for approval** (may suggest an audience).
2. The **approver** opens **Approvals**, adjusts the audience, checks the count and **approves** (a note is kept) or **rejects**. Approving with an empty audience is refused.
3. The **sender** launches the approved message. The approval is **used up** by the launch: sending again needs a new approval.
4. The **owner** (and the system administrator) is the final approver: can launch directly, and the **Approve and send** button does both.

Changing the **text or media** of a pending or approved message **takes the approval away** (what was reviewed is what is sent). Approval fields are never mass-assignable from forms or the API.
Needs `php artisan migrate`. `.env`: `CAMPAIGN_CHANNEL_DELAY` (3).

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

### Priority by plan

Every plan has a **queue priority weight** (`limits.queue_priority` in `config/billing.php`: Free 1, Starter 2, Pro 4, Business 8). It multiplies the
user's capacity in the fair share, so in a batch of 50 a Business account gets about **8x the slots of a Free one** and a Pro one about 4x.

- `WHATSAPP_PRIORITY_MODE=weighted` (default): proportional share. Lower plans slow down but **never starve**.
- `WHATSAPP_PRIORITY_MODE=strict`: higher priorities are served first; lower plans only get what is left over.
- `WHATSAPP_PRIORITY=false` turns it off. It also has no effect while `BILLING_ENABLED=false`. Administrators get the highest weight.
- `php artisan whatsapp:queue --dry-run` shows the weight (**Priority** column) next to each user's share.
- A plan change applies at once, even to messages already waiting, and plans saved before the limit existed fall back to the config value.

**Why not a priority column or one Redis queue per plan?** The bottleneck is not the Redis queue: messages only enter it in small batches (50 per run),
and *which* messages enter is decided in the planner. Putting the weight in the planner costs nothing extra: no new column or index on the (large)
`whatsapp_job` table, no extra queues or workers to run and monitor, and the existing fair-share code is reused. A `priority` column would have to be
kept in sync with every plan change; separate queues (`disparos-high` / `disparos-low`) would add workers, queue monitoring and Redis keys without
changing who gets the slots.

Each message is bound to the session chosen by the planner (`whatsapp_job.instance_id`); if that session disconnects before
sending, the job falls back to another connected session of the same user. Users with no connected session are skipped and
their messages stay `pendente`.

```bash
php artisan whatsapp:queue --dry-run          # show the split per user/session without dispatching
php artisan whatsapp:queue --batch=100 --mode=user
```

`.env`: `WHATSAPP_BATCH_SIZE` (50), `WHATSAPP_FAIRNESS` (session|user|fifo), `WHATSAPP_QUEUE_CRON` (`0 * * * *`), `WHATSAPP_PRIORITY` (true), `WHATSAPP_PRIORITY_MODE` (weighted|strict).
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
| Team members (sub-users) | - | 2 | 5 | 20 |
| Send queue priority (weight) | 1 | 2 | 4 | 8 |
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

## 🔧 Environment variables

All variables live in `.env` (copy `.env.example`, which carries the same comments). Unless marked **required**, they are optional and the default shown applies. After changing `.env` in production run `php artisan config:clear` (or restart the workers, which cache config).

### Application and server

| Variable | Default | Controls |
|---|---|---|
| `APP_NAME` | SAMA Envios | Name in the UI, e-mails and mail sender |
| `APP_ENV` | production | `local` zeroes the anti-ban delays and lets AI reach private hosts |
| `APP_KEY` | — **required** | Encryption of sessions and of the API keys users save (`php artisan key:generate`) |
| `APP_DEBUG` | false | Shows stack traces to visitors. Never `true` in production |
| `APP_URL` | — **required** | Public https address; used to build webhook / OAuth callback URLs of every channel and payment gateway |
| `FORCE_HTTPS` | false | Forces https links behind a TLS-terminating proxy |
| `APP_LOCALE` | en | Default UI language (`en`, `pt_BR`, `es`) when the user has not chosen one |
| `LOG_CHANNEL`, `LOG_LEVEL` | stack, debug | Where logs go and the minimum level recorded |

### Database, cache, queue, storage, mail

| Variable | Default | Controls |
|---|---|---|
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | mysql … | MySQL connection (with Docker the host is the service name) |
| `QUEUE_CONNECTION` | database | Queue backend: `database` (zero setup) or `redis` (recommended in production; enables delayed jobs and `queue:monitor`) |
| `CACHE_DRIVER` | file | Cache and locks. Use `redis` when several workers/servers share the per-number send gate |
| `SESSION_DRIVER`, `SESSION_LIFETIME` | file, 120 | Login sessions and their duration (minutes) |
| `BROADCAST_DRIVER` | log | Real-time broadcasting (log = off) |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | predis, redis, 6379 | Redis connection |
| `REDIS_QUEUE_DB`, `REDIS_QUEUE`, `REDIS_QUEUE_RETRY_AFTER` | 2, default, 300 | Redis database and name of the queue; seconds before a running job is considered lost (must exceed the longest job) |
| `FILESYSTEM_DISK` | local | Where uploads go (`local` or `s3`) |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_URL`, `AWS_USE_PATH_STYLE_ENDPOINT` | MinIO | Campaign images and chat media storage; `AWS_ENDPOINT` is the internal address, `AWS_URL` the public one the browser uses |
| `MAIL_*` | smtp / mailhog | Outgoing e-mail (instance alerts, invoices, password reset) |

### WhatsApp (Evolution API)

| Variable | Default | Controls |
|---|---|---|
| `WHATSAPP_PROTOCOL`, `WHATSAPP_URL`, `WHATSAPP_PORT` | http, evolution-api, 8081 | Where Laravel reaches Evolution (internal address) |
| `WHATSAPP_APIKEY` | — **required** | Global Evolution key |
| `WHATSAPP_CONTACT_TEST` | — | Number that receives the "test connection" message |
| `WHATSAPP_MESSAGE_UP` | — | Fallback campaign id for the signup welcome; the real choice is made in **Admin → Signup welcome** |
| `MIX_WHATSAPP_PUBLIC_URL`, `_PORT`, `_PROTOCOL` | — | Public Evolution address as the browser sees it (QR code screen) |

### Send queue, pacing and priority

| Variable | Default | Controls |
|---|---|---|
| `WHATSAPP_AUTOSEND` | off | Schedules `whatsapp:queue`; without it run the command by hand or via cron |
| `WHATSAPP_QUEUE_CRON` | `0 * * * *` | Cron of that run |
| `WHATSAPP_BATCH_SIZE` | 50 | Messages per batch |
| `WHATSAPP_FAIRNESS` | session | How a batch is split: `session`, `user` or `fifo` |
| `WHATSAPP_PRIORITY`, `WHATSAPP_PRIORITY_MODE` | true, weighted | Plan priority: `weighted` (proportional share) or `strict` (higher plans first) |
| `WHATSAPP_INFLIGHT_WINDOW_HOURS` | 24 | How long a queued message counts as "in flight" before being counted again |
| `WHATSAPP_PACING` | true | Enforces a pause between two messages of the same number (anti-ban) |
| `WHATSAPP_COOLDOWN_MIN`, `_MAX` | 25 / 50 (0 in local) | Random seconds between messages of the same number |
| `WHATSAPP_HUMANIZE_MIN`, `_MAX` | 4 / 9 (0 in local) | Random "typing" delay before each send |
| `CAMPAIGN_CHANNEL_DELAY` | 3 | Seconds between campaign messages through Telegram / Instagram / Facebook / TikTok |

### Chat (omnichannel inbox)

| Variable | Default | Controls |
|---|---|---|
| `CHAT_POLL_CHATS_MS`, `CHAT_POLL_MESSAGES_MS` | 6000, 2500 | How often the browser refreshes the chat list / open conversation |
| `CHAT_MAX_UPLOAD_KB` | 16384 | Largest file an attendant can upload |
| `CHAT_PAUSE_AI_ON_REPLY` | false | AI stops answering a chat once a human replies |
| `CHAT_SEND_READ_RECEIPTS` | true | Marks customer messages as read when the attendant replies |
| `CHAT_SIGNATURE_SUFFIX` | `(CRM)` | Text appended to messages typed in the CRM chat |
| `CHAT_HISTORY_MAX_CHATS`, `CHAT_HISTORY_PER_CHAT` | 200, 200 | Limits of the history import |

### AI agents

See the table in [AI Agents](#-ai-agents-multi-provider): `GROQ_API_KEY`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `DEEPSEEK_API_KEY`, `MISTRAL_API_KEY`, `OPENROUTER_API_KEY` (platform keys), `AI_ALLOW_PRIVATE_HOSTS` (SSRF protection), `AI_TIMEOUT`, `AI_BUSINESS_TZ`, `AI_BUSINESS_START`, `AI_BUSINESS_END`.

### Channels

| Variable | Default | Controls |
|---|---|---|
| `TELEGRAM_API_URL`, `TELEGRAM_TIMEOUT`, `TELEGRAM_MAX_DOWNLOAD_BYTES` | api.telegram.org, 30, 20 MB | Bot API address (self-hosted server), timeout, largest file downloaded |
| `INSTAGRAM_APP_SECRET`, `INSTAGRAM_VERIFY_TOKEN` | — | Webhook signature check and the verify token typed in the Meta dashboard |
| `INSTAGRAM_API_VERSION`, `INSTAGRAM_GRAPH_URL`, `INSTAGRAM_TIMEOUT`, `INSTAGRAM_MAX_DOWNLOAD_BYTES` | v21.0 … | Graph API version/address, timeout, download limit |
| `INSTAGRAM_WINDOW_HOURS`, `INSTAGRAM_MEDIA_LINK_MINUTES` | 24, 30 | Reply window after the customer's last message; validity of the public media link |
| `FACEBOOK_APP_SECRET`, `FACEBOOK_VERIFY_TOKEN` | Instagram's | Same as Instagram; default to the Instagram values (one Meta app can serve both) |
| `FACEBOOK_API_VERSION`, `FACEBOOK_GRAPH_URL`, `FACEBOOK_WINDOW_HOURS`, `FACEBOOK_TIMEOUT`, `FACEBOOK_MAX_DOWNLOAD_BYTES` | v21.0, 24 h … | Same meaning as Instagram |
| `TIKTOK_APP_ID`, `TIKTOK_APP_SECRET` | — | Developer-portal app credentials (OAuth and webhook signature) |
| `TIKTOK_WINDOW_HOURS`, `TIKTOK_SIGNATURE_TOLERANCE`, `TIKTOK_TIMEOUT`, `TIKTOK_MAX_DOWNLOAD_BYTES` | 48, 300 s, 30, 25 MB | Reply window, accepted clock skew on signatures, timeout, download limit |
| `TIKTOK_API_URL`, `TIKTOK_AUTH_URL`, `TIKTOK_PATH_SEND`, `_TOKEN`, `_REFRESH`, `_MESSAGES`, `_UPLOAD`, `_DOWNLOAD`, `_WEBHOOK` | TikTok defaults | Endpoint overrides, only if the portal shows different ones for your API version |

### Contacts: score and unresponsive deactivation

| Variable | Default | Controls |
|---|---|---|
| `CONTACT_UNDELIVERED_ENABLED` | true | Turns automatic deactivation on/off |
| `CONTACT_UNDELIVERED_AFTER_HOURS` | 72 | Hours a message may stay "sent" without a receipt before counting as undelivered |
| `CONTACT_UNDELIVERED_MIN_MESSAGES` | 1 | Undelivered messages needed to deactivate |
| `CONTACT_UNDELIVERED_LOOKBACK_DAYS`, `_EVIDENCE_DAYS` | 30, 30 | Period inspected, and period searched for signs of life (replies, receipts) |
| `CONTACT_UNDELIVERED_REACTIVATE` | true | Reactivates when the contact writes or a receipt arrives |
| `CONTACT_SCORE_ENABLED` | true | Turns the score on/off |
| `CONTACT_SCORE_WINDOW_DAYS`, `CONTACT_SCORE_REFRESH_HOURS` | 90, 24 | Activity window and recalculation interval |
| `CONTACT_SCORE_HOT`, `_WARM`, `_COLD` | 70, 40, 15 | Tier thresholds (below cold = frozen) |

### E-mail channel

| Variable | Default | Controls |
|---|---|---|
| `EMAIL_FETCH_CRON` | `* * * * *` | How often the mailboxes (IMAP) are checked for new mail |
| `EMAIL_MAX_PER_POLL` | 20 | Most new messages imported from one mailbox per check |
| `EMAIL_MAX_ATTACHMENT_BYTES` | 10 MB | Bigger attachments are not kept in the chat |
| `EMAIL_TIMEOUT` | 20 | Seconds to wait for an SMTP / IMAP server |
| `EMAIL_ALLOW_PRIVATE_HOSTS` | only `APP_ENV=local` | Allow servers on localhost / private networks (SSRF protection: keep `false` on a shared host) |
| `EMAIL_SMTP_PORTS`, `EMAIL_IMAP_PORTS` | `25,465,587,2525` / `143,993` | Ports users may use (empty = any) |
| `EMAIL_AI_MAX_REPLIES_PER_DAY` | 5 | Loop protection: AI answers per sender per day |

Mailbox credentials are **not** in `.env`: each user types them in the **Email** screen and they are stored encrypted.

### Contact forms

| Variable | Default | Controls |
|---|---|---|
| `FORMS_DEFAULT_COUNTRY_CODE` | 55 | Prefix added to phone numbers typed without a country code (empty = leave as typed) |
| `FORMS_THROTTLE_PER_MINUTE` | 10 | Submissions accepted per minute from one IP address (spam protection of the public endpoint) |

### Consent

| Variable | Default | Controls |
|---|---|---|
| `CONSENT_OPTOUT_KEYWORDS` | `#sair,/stop,#parar,#stop,parar,stop` | Messages (whole text, any case, comma separated) that opt the customer out on every channel |
| `CONSENT_OPTIN_KEYWORDS` | `#entrar,#aceito,/start` | Messages that record the customer's consent |

### Deals (sales pipeline)

| Variable | Default | Controls |
|---|---|---|
| `DEAL_NEW_AFTER_DAYS` | 30 | With *open a deal automatically* on: a customer who writes again after this many days of silence counts as a new lead (a deal still open is never duplicated) |
| `CRM_CURRENCY` | `R$` | Symbol shown before the totals of proposals |

### Plans and billing

| Variable | Default | Controls |
|---|---|---|
| `BILLING_ENABLED` | false | `false` = no limits or blocking; `true` = plans apply |
| `BILLING_CURRENCY` | BRL | Currency of plans and invoices |
| `BILLING_TRIAL_DAYS` | 7 | Free trial of paid plans |
| `BILLING_INVOICE_DAYS_BEFORE` | 5 | Days before the due date that the renewal invoice is issued |
| `BILLING_GRACE_DAYS` | 3 | Days after the due date before the account turns read-only |
| `BILLING_SUSPENSION_DAYS` | 30 | Days read-only before suspension |
| `BILLING_PENDING_DAYS` | 3 | A paid plan without trial must be paid within N days or the request expires |

Gateway credentials (Asaas, Efí, AbacatePay, Stone, Stripe, PagBank, Mercado Pago) are **not** in `.env`: they are entered in **Admin → Payment gateway** and stored encrypted.

### Alerts and monitoring

| Variable | Default | Controls |
|---|---|---|
| `INSTANCE_ALERT_AFTER_MINUTES` | 3 | Minutes a session may stay down before the owner is alerted |
| `INSTANCE_FAILURE_THRESHOLD` | 3 | Consecutive failed sends that mark a session as down |
| `INSTANCE_HEALTH_CRON` | `*/5 * * * *` | Cron of `instances:health` |
| `INSTANCE_REALERT_AFTER_HOURS` | 12 | Repeats the alert if the problem persists |
| `INSTANCE_RECENT_SEND_HOURS` | 24 | Only sessions that sent something in this period are monitored |
| `QUEUE_BACKLOG_MAX` | 500 | Pending jobs in a queue that trigger an alert (Redis only) |

### Debug flags

`DEBUG_WEBHOOK_STATUS`, `AI_DEBUG_FLOW`, `AI_DEBUG_WEBHOOK`, `DEBUG_WHATSAPP_QR` (all `false`): write detailed traces of Evolution status webhooks, AI decisions, AI webhooks and QR code responses to the log. Use briefly while diagnosing; they are verbose.

> Variables not listed here (Telescope, Memcached, Mailgun, Postmark, Pusher, SQS, DynamoDB, session cookie options…) are standard Laravel and documented in the [Laravel docs](https://laravel.com/docs/9.x/configuration).

## 🧪 Tests

`vendor/bin/phpunit` (in-memory SQLite, no external services): fair queue planner and command, chat backend, Telegram, Instagram and TikTok channels (webhook secret/signature, OAuth, token refresh, inbound, AI reply through the same bot/account, reply window, settings), channel statistics and cross-channel contact links, unresponsive-contact deactivation, contact score ranking, Facebook Messenger, team roles and approval, audience by score and channel, plan queue priority, quick replies, consent register (opt-in / opt-out proof, keywords, forms), custom contact fields and segments, automations (triggers, actions, loops), follow-up tasks and reminders, team inbox (balanced assignment, visibility, labels, internal notes) and deals opened from conversations and forms, sales pipeline, e-mail channel (SMTP send, IMAP mailbox through webklex/php-imap with a scripted server, message mapping, threading, AI reply, loop protection, contact unification), embeddable contact forms (public script/endpoint, spam protection, contact + chat creation, AI reply channel choice), the new channel / inbox / ranking API endpoints and MCP tools, avatar caching, billing lifecycle and plan enforcement, the Mercado Pago gateway (admin settings, Pix, card, recurrence, webhook) and the Asaas / Efí / AbacatePay / Stone (Pagar.me) / Stripe / PagBank gateways (HTTP faked).

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
* ✅ **Omnichannel inbox:** WhatsApp, Telegram, Instagram, Facebook Messenger, TikTok and e-mail (your own SMTP/IMAP mailbox) in one chat, with per-channel statistics and linked contacts.
* ✅ **Embeddable contact forms:** one `<script>` line on any site; leads become contacts and conversations in the CRM and the AI replies.
* ✅ **Team & roles:** sub-users (admin, approver, creator, sender, attendant, analyst) working for the contract account.
* ✅ **Audience & approval:** pick the audience by score tier and channels, and require approval before a campaign is sent.
* ✅ **Plan priority queue:** higher plans get a larger share of every batch (weighted or strict), with no extra storage.
* ✅ **Contact score & unresponsive contacts:** 0-100 engagement ranking and automatic deactivation of numbers that never receive messages.
* ✅ **Signup welcome:** the admin chooses the campaign message sent to people who register.
* ✅ **Sales pipeline (Kanban):** drag deals between stages, values, expected close date, won / lost with reason, win rate.
* ✅ **Team inbox:** assign conversations by hand or balanced, labels, internal notes, filters; deals opened automatically from new conversations and forms.
* ✅ **Follow-up tasks:** due time, owner and in-app reminder, linked to a contact, conversation or deal.
* ✅ **Automations:** "when X happens, do Y" (deal created / moved / won / lost, label applied, customer writes a word) with loop protection and a run log.
* ✅ **Custom fields & segments:** your own contact fields, saved segments and segment as campaign audience.
* ✅ **Consent & opt-out:** proof of opt-in / opt-out on every channel, consent box on forms, "only contacts with consent" switch.
* ✅ **Quick replies:** shared canned answers typed with `/shortcut`, with variables.
* ✅ **Team performance & goals:** first-response time, answered conversations, deals and follow-ups per person, monthly goals.
* ✅ **Deal forecast & priorities:** chance to close with its reasons, weighted forecast, next action and optional AI advice.
* ✅ **E-mail channel on a ready-made library:** IMAP through `webklex/php-imap`, SMTP through Symfony Mailer (nothing reimplemented).
* ✅ **Audit Logs:** Full history of every AI interaction and every broadcast attempt.

---

## 💻 Technical Stack

* **Backend:** Laravel 9 (Clean Code Architecture)
* **AI Engine:** multi-provider (Groq, OpenAI, Claude, Gemini, DeepSeek, Mistral, OpenRouter, Ollama, custom OpenAI-compatible)
* **Channels:** Evolution API (WhatsApp), Telegram Bot API, Instagram / Facebook Graph API, TikTok Business Messaging, e-mail (`webklex/php-imap` + Symfony Mailer)
* **Queues / cache:** database or Redis (Predis)
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

### Upgrading from an older version (CRM features)

After `git pull` (or `./install.sh update`, which does the first two steps for you):

1. `composer install --no-dev --optimize-autoloader` (new dependency: `webklex/php-imap`, pure PHP: the `imap` extension is **not** needed, but `iconv`, `mbstring` and `openssl` are).
2. `php artisan migrate --force` — creates the tables of the sales pipeline, team inbox (labels, notes, assignment), tasks, consent register, quick replies, custom fields and segments, automations, message authorship and team goals. Nothing changes for existing data: every feature is opt-in (for instance campaigns only require consent after you switch it on in **Consent**).
3. Make sure the **scheduler** runs (`* * * * * php artisan schedule:run`, already set up by `install.sh vps` and the Docker stack): it sends the follow-up reminders (`tasks:remind`, every minute) and fetches the mailboxes (`email:fetch`).
4. `php artisan billing:sync-plans --force` if you use plans and billing, so the plan limits are refreshed.
5. Optional `.env`: `DEAL_NEW_AFTER_DAYS` (silence after which a returning customer counts as a new lead, default 30) and `CONSENT_OPTIN_KEYWORDS` (see [Environment variables](#-environment-variables)).
6. Restart the queue workers (`php artisan queue:restart`; the VPS script does it).

Where to find each feature in the menu: **Pipeline** (funnel, forecast, priorities), **Chat** (assignment, labels, notes, quick replies with `/`), **Follow-ups**, **Automations**, **Segments**, **Consent**, **Quick replies**, **Team performance**. Team members only see what their role allows (see [Team & roles](#-team--roles-sub-users)).

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
would hurt: a `DB_HOST` that is not your machine (a `DB_HOST` copied from an old `.env` could point to another server, so migrations would run
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
DB_USERNAME=sama
DB_PASSWORD=<strong password>
DB_ROOT_PASSWORD=<another strong password>   # used by the MySQL container
POSTGRES_PASSWORD=<strong password>          # Evolution API database

REDIS_HOST=redis
REDIS_PASSWORD=<strong password>

AWS_ENDPOINT=http://minio:9000
AWS_ACCESS_KEY_ID=admin
AWS_SECRET_ACCESS_KEY=<strong password>     # also the MinIO admin password
AWS_BUCKET=ads

WHATSAPP_URL=evolution-api
WHATSAPP_PORT=8080
WHATSAPP_PROTOCOL=http
WHATSAPP_APIKEY=<long random key>         # the Evolution API container receives this same key
```

**3. Configure Evolution API (`.docker/.env.docker`)**

Edit `.env.docker` with your domain and webhook URL:

```env
SERVER_URL=https://your-domain.com:8080
WEBHOOK_GLOBAL_URL=https://your-domain.com/api/webhook/whatsapp
```

The Evolution API key is **not** in `.env.docker`: `docker-compose.yml` passes `WHATSAPP_APIKEY` from the Laravel `.env` as `AUTHENTICATION_API_KEY` (and builds the Evolution database / Redis URIs from `POSTGRES_PASSWORD` / `REDIS_PASSWORD`), so no secret is stored in a tracked file.

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
| `laravel-schedule` | — | `schedule:work` (autosend, `warmup:generate`, `billing:run`, `instances:health`, `queue:monitor`, contact score / deactivation) |

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

#### Credentials (none ship with the repository)

The Compose files refuse to start until these variables exist in `.env` (next to `docker-compose.yml`), so no password is stored in git:

| Variable in `.env` | Used by |
|---------|----------|
| `DB_PASSWORD` (user `DB_USERNAME`, default `sama`) | MySQL (Laravel) |
| `DB_ROOT_PASSWORD` | MySQL root |
| `POSTGRES_PASSWORD` (user `admin`) | PostgreSQL (Evolution) |
| `REDIS_PASSWORD` | Redis (`.docker/` stack) |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | MinIO user / password |
| `WHATSAPP_APIKEY` | Evolution API key |

Generate each one with `openssl rand -base64 24 | tr -d '/+='`.

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
* 📂 **`.env.example`:** every variable commented with the aspect it controls (also in [Environment variables](#-environment-variables)).
* 📂 **Tool Call API:** `app/Services/Api/ToolRegistry.php` — tool definitions for AI agents.

---

**Legal Disclaimer:** SAMA Envios is a productivity tool. Its use must comply with Meta's guidelines and local data protection laws. We do not encourage SPAM.

© SAMA Envios — Where Software Engineering meets Artificial Intelligence.
