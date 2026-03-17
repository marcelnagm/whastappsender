#!/bin/bash
echo "Parando todos os processos PHP (artisan)..."
pkill -f "php artisan"
echo "Serviços finalizados."