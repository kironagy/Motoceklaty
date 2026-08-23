// Optional pm2 process supervisor config for the long-running WhatsApp
// pipeline processes (B2/B7 in PROJECT-ANALYSIS-AND-TASKS.md). Not required
// for the app to run — the custom workers already refuse to start a second
// instance of themselves (flock on the Laravel worker, a PID lockfile on the
// Node bot) — but pm2 additionally restarts a process that crashes, which
// neither lock mechanism does on its own.
//
// Usage (not run automatically by anything in this repo):
//   npm install -g pm2
//   pm2 start ecosystem.config.js
//   pm2 save && pm2 startup   # optional: survive a machine reboot

module.exports = {
  apps: [
    {
      // Serves the actual Laravel webhook the Node bot posts every
      // incoming WhatsApp message to (LARAVEL_WEBHOOK_URL in
      // whatsapp-bot/.env, http://127.0.0.1:8000/api/whatsapp/incoming-message).
      // WhatsappIntentRouter::handle() runs synchronously inside this
      // process on every message - it is NOT one of the queue/worker
      // processes below. Was previously started by hand outside pm2,
      // which meant `pm2 restart` never actually picked up new code here.
      name: 'laravel-server',
      cwd: __dirname,
      script: 'artisan',
      interpreter: 'php',
      args: 'serve --port=8000',
      autorestart: true,
      max_restarts: 20,
      restart_delay: 3000,
    },
    {
      name: 'whatsapp-bot',
      cwd: __dirname + '/whatsapp-bot',
      script: 'index.js',
      interpreter: 'node',
      env: { PORT: 3080 },
      autorestart: true,
      max_restarts: 20,
      restart_delay: 3000,
    },
    {
      name: 'whatsapp-worker',
      cwd: __dirname,
      script: 'artisan',
      interpreter: 'php',
      args: 'whatsapp:process-jobs --sleep=2',
      autorestart: true,
      max_restarts: 20,
      restart_delay: 3000,
    },
    {
      name: 'laravel-queue',
      cwd: __dirname,
      script: 'artisan',
      interpreter: 'php',
      args: 'queue:work database --sleep=2 --tries=3 --timeout=90',
      autorestart: true,
      max_restarts: 20,
      restart_delay: 3000,
    },
    {
      // schedule:work loops internally and fires every scheduled task
      // (gemini:reset-usage, gemini-alerts-repeat, ...) every minute on its
      // own - no system cron needed. autorestart brings it back if it dies.
      name: 'laravel-scheduler',
      cwd: __dirname,
      script: 'artisan',
      interpreter: 'php',
      args: 'schedule:work',
      autorestart: true,
      max_restarts: 20,
      restart_delay: 3000,
    },
  ],
};
