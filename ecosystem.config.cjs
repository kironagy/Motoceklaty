// Optional pm2 process supervisor config for the long-running WhatsApp
// pipeline processes (B2/B7 in PROJECT-ANALYSIS-AND-TASKS.md). Not required
// for the app to run — the custom workers already bound how many copies of
// themselves may run (slot flocks on the Laravel worker, a PID lockfile on
// the Node bot) — but pm2 additionally restarts a process that crashes, which
// neither lock mechanism does on its own.
//
// Usage (not run automatically by anything in this repo):
//   npm install -g pm2
//   pm2 start ecosystem.config.js
//   pm2 save && pm2 startup   # optional: survive a machine reboot

module.exports = {
  apps: [
    {
      // Local web server only; production is served by nginx/php-fpm.
      name: 'laravel-web',
      cwd: __dirname,
      script: 'artisan',
      interpreter: 'php',
      args: 'serve --host=127.0.0.1 --port=8000',
      env: { PHP_CLI_SERVER_WORKERS: 8 },
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
      // Owner 2026-11-22: one supervisor that keeps one reply worker per active bot
      // (whatsapp:process-jobs --bot=ID), so a busy number never holds up another
      // number's customers. Bots switched on/off are picked up within seconds.
      args: 'whatsapp:supervise-jobs',
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
