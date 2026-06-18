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
* **Infra:** Docker Ready (Includes Dockerfile & Docker Compose)

---

## ⚙️ Installation & Activation

### 1. Initial Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

### 2. AI Configuration

Add your `GROQ_API_KEY` to the `.env` file and run migrations:

```bash
php artisan migrate --seed
```

### 3. Start the Engines (Supervisor)

To ensure real-time AI response and stable bulk sending, activate the Workers:

```bash
php artisan queue:work --queue=disparos,atendimento-ia --sleep=3 --tries=3
```

---

## 📁 What's in the Box?

* 📂 **Source_Code:** Fully modularized and documented Laravel source code.
* 📂 **Documentation:** Definitive guide for Docker installation and Supervisor setup.
* 📂 **AI_Templates:** High-conversion prompts pre-configured for various niches.
* 📂 **Tool Call API:** `app/Services/Api/ToolRegistry.php` — tool definitions for AI agents.

---

**Legal Disclaimer:** SAMA Envios is a productivity tool. Its use must comply with Meta's guidelines and local data protection laws. We do not encourage SPAM.

© SAMA Envios — Where Software Engineering meets Artificial Intelligence.
