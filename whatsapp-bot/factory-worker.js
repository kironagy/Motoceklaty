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
const { downloadHistory, getHistoryMsg, proto } = require('@whiskeysockets/baileys');
const inflate = require('node:util').promisify(require('node:zlib').inflate);
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
    if (s.retired) return;
    fs.mkdirSync(path.join(root, id), { recursive: true, mode: 0o700 });
    const file = path.join(root, id, 'senders.json');
    fs.writeFileSync(file + '.tmp', JSON.stringify(s.contacts.saved()), { mode: 0o600 });
    fs.renameSync(file + '.tmp', file);
    s.updated = new Date().toISOString();
}
function saveSoon(id, s) {
    if (s.retired) return;
    clearTimeout(s.saveTimer);
    s.saveTimer = setTimeout(() => { try { persist(id, s); } catch { s.status = 'storage_error'; } }, 500);
}
async function resolvePhones(id, s) {
    if (!s.sock?.signalRepository?.lidMapping) return;
    await s.contacts.resolve(lid => s.sock.signalRepository.lidMapping.getPNForLID(lid));
    s.contacts.excludeSelf([s.sock?.user?.id, s.sock?.user?.lid]);
    persist(id, s);
}
function readHistoryMappings(id, s, notification) {
    // rc.9 drops phoneNumberToLidMappings from messaging-history.set. Decode
    // this metadata separately; never persist the raw history or message bodies.
    s.historyQueue = (s.historyQueue || Promise.resolve()).then(async () => {
        const raw = notification.initialHistBootstrapInlinePayload
            ? proto.HistorySync.decode(await inflate(notification.initialHistBootstrapInlinePayload))
            : await downloadHistory(notification, {});
        s.contacts.historyMappings(raw);
        await resolvePhones(id, s);
        persist(id, s);
    }).catch(() => { s.mappingError = true; });
}
async function start(id) {
    const s = load(id);
    if (s.sock || s.starting) return;
    s.starting = true; s.stopped = false; s.status = 'starting';
    try {
        const { state, saveCreds } = await useMultiFileAuthState(path.join(root, id, 'auth'));
        if (s.retired) return;
        s.contacts.excludeSelf([state.creds.me?.id, state.creds.me?.lid]);
        persist(id, s);
        const { version } = await fetchLatestBaileysVersion();
        if (s.retired) return;
        const sock = makeWASocket({ version, auth: state, logger: pino({ level: 'silent' }),
            browser: Browsers.macOS('Chrome'), syncFullHistory: true, markOnlineOnConnect: false,
            shouldSyncHistoryMessage: () => true, downloadHistory: true });
        s.sock = sock;
        // Ignore late events from a replaced account, including credential writes.
        const listen = (event, handler) => sock.ev.on(event, (...args) => {
            if (!s.retired && s.sock === sock) return handler(...args);
        });
        listen('creds.update', () => {
            s.credentialQueue = (s.credentialQueue || Promise.resolve()).then(() => s.retired ? undefined : saveCreds()).catch(() => { s.status = 'storage_error'; });
        });
        listen('contacts.upsert', contacts => { contacts.forEach(c => s.contacts.contact(c)); saveSoon(id, s); });
        listen('contacts.update', contacts => { contacts.forEach(c => s.contacts.contact(c)); saveSoon(id, s); });
        listen('lid-mapping.update', mapping => { s.contacts.link(mapping.lid, mapping.pn); saveSoon(id, s); });
        listen('messaging-history.set', data => {
            (data.contacts || []).forEach(c => s.contacts.contact(c));
            (data.messages || []).forEach(m => s.contacts.message(m));
            s.contacts.historyMappings(data);
            resolvePhones(id, s).catch(() => { s.mappingError = true; });
            s.progress = data.progress ?? s.progress;
            saveSoon(id, s);
        });
        listen('messages.upsert', data => {
            for (const m of data.messages) {
                s.contacts.message(m);
                const history = getHistoryMsg(m.message);
                if (history) readHistoryMappings(id, s, history);
            }
            resolvePhones(id, s).catch(() => { s.mappingError = true; });
            saveSoon(id, s);
        });
        listen('connection.update', async update => {
            if (s.sock !== sock) return;
            if (update.qr) { s.qr = await QRCode.toDataURL(update.qr); s.status = 'qr'; }
            if (update.connection === 'open') { s.qr = null; s.status = 'connected'; s.retries = 0; resolvePhones(id, s).catch(() => {}); }
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
    const rows = s.contacts.rows();
    const account = s.sock?.user?.id?.split('@')[0]?.split(':')[0];
    res.json({ status: s.status, qr: s.qr, account: /^\d{7,15}$/.test(account || '') ? account : null, count: rows.filter(r => r.phone).length, unresolved: rows.filter(r => !r.phone).length, updated: s.updated, progress: s.progress });
});
app.post('/sessions/:id/switch', async (req, res) => {
    const id = req.params.id, s = load(id);
    if (s.retired || s.starting) return res.status(409).json({ error: 'انتظر انتهاء محاولة الربط الحالية.' });
    try { persist(id, s); }
    catch { return res.status(503).json({ error: 'تعذر حفظ البيانات الحالية؛ لم يتم تبديل الحساب.' }); }
    s.retired = true; s.stopped = true;
    clearTimeout(s.retryTimer); clearTimeout(s.saveTimer);
    const sock = s.sock; s.sock = null; s.qr = null;
    try {
        // Close locally; the archived credentials are never resumed automatically.
        sock?.end(undefined);
        await s.credentialQueue;
        const folder = path.join(root, id);
        if (fs.existsSync(folder)) {
            const archive = path.join(root, 'archives');
            fs.mkdirSync(archive, { recursive: true, mode: 0o700 });
            fs.renameSync(folder, path.join(archive, id + '-' + Date.now() + '-' + crypto.randomUUID()));
        }
        sessions.delete(id);
        await start(id);
        res.json({ ok: true });
    } catch {
        const current = load(id);
        current.retired = false; current.status = 'error';
        res.status(503).json({ error: 'تعذر تبديل الحساب. البيانات السابقة محفوظة.' });
    }
});
app.post('/sessions/:id/start', async (req, res) => {
    try { await start(req.params.id); res.json({ ok: true }); }
    catch { load(req.params.id).status = 'error'; res.status(503).json({ error: 'تعذر تشغيل الاتصال، حاول مرة أخرى.' }); }
});
app.get('/sessions/:id/contacts', async (req, res) => {
    const s = load(req.params.id);
    try { await resolvePhones(req.params.id, s); await s.historyQueue; }
    catch { return res.status(503).json({ error: 'تعذر مراجعة الأرقام، حاول مرة أخرى.' }); }
    res.json({ rows: s.contacts.rows().filter(r => r.phone).map(r => ({ phone: r.phone })) });
});
app.post('/sessions/:id/logout', async (req, res) => {
    const s = load(req.params.id); s.stopped = true; clearTimeout(s.retryTimer); clearTimeout(s.saveTimer);
    try { await s.sock?.logout(); } catch { s.sock?.end(undefined); }
    s.sock = null; s.qr = null; s.status = 'disconnected';
    // Remove credentials only after explicit disconnect. Keep the extracted sender list.
    fs.rmSync(path.join(root, req.params.id, 'auth'), { recursive: true, force: true });
    persist(req.params.id, s); res.json({ ok: true });
});
app.listen(Number(process.env.FACTORY_PORT || 3081), '127.0.0.1');
// Resume previously linked accounts after deployment without logging them out.
for (const id of fs.readdirSync(root)) {
    if (!/^staff_[1-9]\d*$/.test(id)) continue;
    const creds = path.join(root, id, 'auth', 'creds.json');
    if (fs.existsSync(creds) && JSON.parse(fs.readFileSync(creds, 'utf8')).me?.id) start(id).catch(() => { load(id).status = 'error'; });
}
