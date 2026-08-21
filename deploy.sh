#!/bin/bash
set -e

cd "$(dirname "$0")"

echo "== Pulling latest code =="
git pull github-new main

echo "== Checking for pending migrations (run manually if any) =="
php artisan migrate:status | grep -i pending || echo "No pending migrations."

echo "== Restarting queue workers (graceful, picks up new code) =="
php artisan queue:restart

if command -v pm2 >/dev/null 2>&1; then
    # whatsapp:process-jobs is a custom "while (true)" loop and does NOT
    # check Laravel's queue:restart signal - it must be restarted directly
    # via pm2, or it silently keeps running the old code forever.
    for proc in whatsapp-queue laravel-queue whatsapp-worker; do
        if pm2 describe "$proc" >/dev/null 2>&1; then
            echo "== Restarting $proc =="
            pm2 restart "$proc"
        fi
    done
fi

echo "== Done. If a migration is pending, run: php artisan migrate --force =="
