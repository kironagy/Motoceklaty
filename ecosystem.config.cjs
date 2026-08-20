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
      name: 'whatsapp-bot',
      cwd: __dirname + '/whatsapp-bot',
      script: 'index.js',
      interpreter: 'node',
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
  ],
};
