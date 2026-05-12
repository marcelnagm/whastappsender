# 🚀 SAMA Envios - Enterprise WhatsApp Marketing & Automation Platform

![PHP Version](https://img.shields.io/badge/php-%5E8.2-777bb4?style=for-the-badge&logo=php&logoColor=white)
![Laravel Version](https://img.shields.io/badge/laravel-%5E11.0-ff2d20?style=for-the-badge&logo=laravel&logoColor=white)
![Tailwind](https://img.shields.io/badge/tailwind-%2338B2AC.svg?style=for-the-badge&logo=tailwind-css&logoColor=white)
![Docker](https://img.shields.io/badge/docker-%230db7ed.svg?style=for-the-badge&logo=docker&logoColor=white)

**SAMA Envios** é uma solução de nível empresarial para disparos em massa e automação de WhatsApp. Projetada para desenvolvedores e agências que não podem permitir perda de dados ou instabilidade de servidor durante grandes campanhas.

---

## 💎 O Diferencial Técnico (Engine Sênior)

Diferente de scripts amadores, a SAMA foi construída com foco em **processamento assíncrono**. Enquanto outros scripts travam o navegador, a SAMA gerencia tudo via **Workers em Background**.

* **Zero Loss Architecture:** Todas as mensagens são salvas em filas (Database Queues). Se o servidor oscilar, o sistema retoma exatamente de onde parou.
* **Supervisor Ready:** Gerenciamento de processos via Supervisor, garantindo que o motor de envio esteja sempre online (24/7).
* **Anti-Block Smart Cadence:** Algoritmo humanizado que aplica delays aleatórios e pausas entre lotes, protegendo a reputação dos seus chips.
* **Resiliência em Lote (Bulk Retry):** Painel exclusivo para monitorar erros. Se a API falhar, você reinfiltra os jobs corrompidos com um clique.

---

## 🛠️ Funcionalidades Principais

* ✅ **Multi-Instâncias:** Gerencie múltiplos números e conexões QR Code simultaneamente.
* ✅ **Dashboard Analítico:** Acompanhe em tempo real a taxa de sucesso e erros dos seus disparos.
* ✅ **Gestão de Leads Inteligente:** Importação massiva via Excel/CSV com mapeamento dinâmico.
* ✅ **Templates Personalizados:** Use variáveis dinâmicas (Ex: `Olá, {{nome}}`) para aumentar a conversão.
* ✅ **Suporte Multi-Mídia:** Envie textos, imagens, PDFs e áudios (formato OGG para simular gravação na hora).
* ✅ **API de Alta Performance:** Integração nativa e otimizada com Evolution API.

---

## 💻 Stack Técnica

* **Backend:** Laravel 11.x (Clean Code & SOLID Principles)
* **Frontend:** Tailwind CSS & Blade Templates
* **Database:** MySQL / PostgreSQL / SQLite
* **Queue Driver:** Database (Otimizado para visibilidade e estabilidade)
* **Infra:** Docker Ready (Includes Dockerfile & Docker Compose)

---

## ⚙️ Instalação em 3 Passos

### 1. Preparação
```bash
composer install
cp .env.example .env
php artisan key:generate