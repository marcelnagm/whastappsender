#!/bin/bash

# --- CONFIGURAÇÕES ---
PROJECT_PATH="`pwd`"
LOG_PATH="$PROJECT_PATH/storage/logs/services"

# Cria a pasta de logs se não existir
mkdir -p $LOG_PATH

echo "--- Iniciando Serviços do WhatsApp Sender ---"
cd $PROJECT_PATH

# 1. Limpeza de Caches (Segurança)
echo "[1/4] Limpando caches do Laravel..."
php artisan config:clear
php artisan cache:clear

# 2. Inicia o Servidor (Serve)
echo "[2/4] Iniciando Servidor Web (Porta 8000)..."
nohup php artisan serve --host=0.0.0.0 --port=8000 > "$LOG_PATH/serve.log" 2>&1 &

# 3. Inicia o Worker de Disparos (WhatsApp) - PRIORIDADE ANTI-BAN
echo "[3/4] Iniciando Worker: Fila DISPAROS (1 Processo)..."
nohup php artisan queue:work --queue=disparos --sleep=3 --tries=3 > "$LOG_PATH/worker_disparos.log" 2>&1 &

# 4. Inicia o Worker Default (E-mails, Geral)
echo "[4/4] Iniciando Worker: Fila DEFAULT..."
nohup php artisan queue:work --queue=default --sleep=3 --tries=3 > "$LOG_PATH/worker_default.log" 2>&1 &

# 5. Inicia o Schedule (Rodando em Loop)
# O Laravel Schedule normalmente roda via Cron, mas em dev rodamos o work
echo "[BÔNUS] Iniciando Schedule Runner..."
nohup php artisan schedule:work > "$LOG_PATH/schedule.log" 2>&1 &

echo "------------------------------------------------"
echo "Todos os processos foram iniciados em BACKGROUND."
echo "Logs disponíveis em: $LOG_PATH"
echo "Use 'ps aux | grep php' para monitorar."
echo "------------------------------------------------"