// Independent, loopback-only collector. No AI webhook, send, or read-receipt handlers.
require('dotenv').config();
process.umask(0o077);
const { default: makeWASocket, useMultiFileAuthState, fetchLatestBaileysVersion, Browsers, DisconnectReason } = require('@whiskeysockets/baileys');
const express = require('express');
const pino = require('pino');
const QRCode = require('qrcode');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { createContacts } = require('./factory-contacts');
const root = process.env.FACTORY_DATA_DIR;
if (!root || !process.env.BOT_TOKEN) throw new Error('FACTORY_DATA_DIR and BOT_TOKEN are required');
fs.mkdirSync(root, { recursive: true, mode: 0o700 });
const sessions = new Map();
function load(id) {
    if (!sessions.has(id)) {
        const file = path.join(root, id, 'senders.json');
        const saved = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : [];
        sessions.set(id, { contacts: createContacts(saved), status: 'disconnected', qr: null, updated: null, progress: null, retries: 0 });
    }
    return sessions.get(id);
}
function persist(id, s) {
    fs.mkdirSync(path.join(root, id), { recursive: true, mode: 0o700 });
    const file = path.join(root, id, 'senders.json');
    fs.writeFileSync(file + '.tmp', JSON.stringify(s.contacts.saved()), { mode: 0o600 });
    fs.renameSync(file + '.tmp', file);
    s.updated = new Date().toISOString();
}
function saveSoon(id, s) {
    clearTimeout(s.saveTimer);
    s.saveTimer = setTimeout(() => { try { persist(id, s); } catch { s.status = 'storage_error'; } }, 500);
}
async function start(id) {
    const s = load(id);
    if (s.sock || s.starting) return;
    s.starting = true; s.stopped = false; s.status = 'starting';
    try {
        const { state, saveCreds } = await useMultiFileAuthState(path.join(root, id, 'auth'));
        const { version } = await fetchLatestBaileysVersion();
        const sock = makeWASocket({ version, auth: state, logger: pino({ level: 'silent' }),
            browser: Browsers.macOS('Chrome'), syncFullHistory: true, markOnlineOnConnect: false,
            shouldSyncHistoryMessage: () => true, downloadHistory: true });
        s.sock = sock;
        sock.ev.on('creds.update', saveCreds);
        sock.ev.on('contacts.upsert', contacts => { contacts.forEach(c => s.contacts.contact(c)); saveSoon(id, s); });
        sock.ev.on('contacts.update', contacts => { contacts.forEach(c => s.contacts.contact(c)); saveSoon(id, s); });
        sock.ev.on('lid-mapping.update', mapping => { s.contacts.link(mapping.lid, mapping.pn); saveSoon(id, s); });
        sock.ev.on('messaging-history.set', data => {
            (data.contacts || []).forEach(c => s.contacts.contact(c));
            (data.messages || []).forEach(m => s.contacts.message(m));
            for (const c of data.chats || []) if (c.pnJid && c.lidJid) s.contacts.link(c.lidJid, c.pnJid);
            for (const m of data.lidPnMappings || []) s.contacts.link(m.lid, m.pn);
            s.progress = data.progress ?? s.progress;
            saveSoon(id, s);
        });
        sock.ev.on('messages.upsert', data => { data.messages.forEach(m => s.contacts.message(m)); saveSoon(id, s); });
        sock.ev.on('connection.update', async update => {
            if (s.sock !== sock) return;
            if (update.qr) { s.qr = await QRCode.toDataURL(update.qr); s.status = 'qr'; }
            if (update.connection === 'open') { s.qr = null; s.status = 'connected'; s.retries = 0; }
            if (update.connection === 'close') {
                s.sock = null; s.qr = null;
                console.warn('Factory connection closed', { code: update.lastDisconnect?.error?.output?.statusCode,
                    reason: update.lastDisconnect?.error?.message });
                const loggedOut = update.lastDisconnect?.error?.output?.statusCode === DisconnectReason.loggedOut;
                s.status = loggedOut ? 'logged_out' : (!s.stopped && s.retries < 10 ? 'reconnecting' : 'disconnected');
                if (!loggedOut && !s.stopped && s.retries++ < 10) s.retryTimer = setTimeout(() => start(id).catch(() => { s.status = 'error'; }), 3000);
            }
        });
    } finally { s.starting = false; }
}
const app = express();
app.use((req, res, next) => {
    const actual = Buffer.from(String(req.headers['x-bot-token'] || ''));
    const expected = Buffer.from(process.env.BOT_TOKEN);
    if (actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) return res.sendStatus(401);
    if (req.params) res.set('Cache-Control', 'no-store');
    next();
});
app.param('id', (req, res, next, id) => /^staff_[1-9]\d*$/.test(id) ? next() : res.sendStatus(400));
app.get('/sessions/:id', (req, res) => {
    const s = load(req.params.id);
    res.json({ status: s.status, qr: s.qr, count: s.contacts.rows().length, updated: s.updated, progress: s.progress });
});
app.post('/sessions/:id/start', async (req, res) => {
    try { await start(req.params.id); res.json({ ok: true }); }
    catch { load(req.params.id).status = 'error'; res.status(503).json({ error: 'تعذر تشغيل الاتصال، حاول مرة أخرى.' }); }
});
app.get('/sessions/:id/contacts', (req, res) => res.json({ rows: load(req.params.id).contacts.rows() }));
app.post('/sessions/:id/logout', async (req, res) => {
    const s = load(req.params.id); s.stopped = true; clearTimeout(s.retryTimer); clearTimeout(s.saveTimer);
    try { await s.sock?.logout(); } catch { s.sock?.end(undefined); }
    s.sock = null; s.qr = null; s.status = 'disconnected';
    // Remove credentials only after explicit disconnect. Keep the extracted sender list.
    fs.rmSync(path.join(root, req.params.id, 'auth'), { recursive: true, force: true });
    persist(req.params.id, s); res.json({ ok: true });
});
app.listen(Number(process.env.FACTORY_PORT || 3081), '127.0.0.1');
