#!/bin/bash
set -e

cd "$(dirname "$0")"

echo "== Pulling latest code =="
git pull github-new main

echo "== Checking for pending migrations (run manually if any) =="
php artisan migrate:status | grep -i pending || echo "No pending migrations."

echo "== Restarting queue workers (graceful, picks up new code) =="
php artisan queue:restart

if command -v pm2 >/dev/null 2>&1 && pm2 describe whatsapp-worker >/dev/null 2>&1; then
    echo "== Restarting Node WhatsApp worker (index.js changes need this explicitly) =="
    pm2 restart whatsapp-worker
fi

echo "== Done. If a migration is pending, run: php artisan migrate --force =="
