#!/bin/bash
set -e

cd "$(dirname "$0")"

# git pull rewrites this very file mid-run. Bash can read stale, buffered
# bytes for everything after that point in the same process, so the pull
# step re-execs a fresh `bash` process on the updated file instead of just
# falling through - that's the only reliable way to guarantee the rest of
# this script (below) is the version that was just pulled.
if [ -z "$DEPLOY_REEXECED" ]; then
    # The server keeps its real repo under the remote name "github-new"
    # (alongside an unrelated "origin"); this project's other clones just
    # use "origin". Prefer github-new when it exists so this one script
    # works unmodified in both places.
    REMOTE="origin"
    if git remote get-url github-new >/dev/null 2>&1; then
        REMOTE="github-new"
    fi

    echo "== Pulling latest code (remote: $REMOTE) =="
    git pull "$REMOTE" main

    export DEPLOY_REEXECED=1
    exec bash "$0" "$@"
fi

echo "== Checking for pending migrations (run manually if any) =="
php artisan migrate:status | grep -i pending || echo "No pending migrations."

echo "== Restarting queue workers (graceful, picks up new code) =="
php artisan queue:restart

SERVER_PROC_NAMES="whatsapp-queue whatsapp-worker"

# "whatsapp-queue" only exists as a pm2 process name on the production
# server's pm2 setup. A local pm2 (if any) uses different process names
# for unrelated things - matching on this one avoids ever touching a local
# pm2 setup by accident (this bit us once already).
if command -v pm2 >/dev/null 2>&1 && pm2 describe whatsapp-queue >/dev/null 2>&1; then
    # whatsapp:process-jobs is a custom "while (true)" loop and does NOT
    # check Laravel's queue:restart signal - it must be restarted directly
    # via pm2, or it silently keeps running the old code forever.
    for proc in $SERVER_PROC_NAMES; do
        echo "== Restarting $proc =="
        pm2 restart "$proc"
    done
fi

echo "== Done. If a migration is pending, run: php artisan migrate --force =="
