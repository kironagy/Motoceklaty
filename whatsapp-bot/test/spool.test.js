// WA-002: run with `node --test whatsapp-bot/test/spool.test.js` (no Baileys, no WhatsApp).
const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const axios = require('axios');
const { createSpool } = require('../spool');

const quiet = { log() {}, error() {} };

function fakeLaravel(port, onMessage, status = 200) {
    return new Promise(resolve => {
        const server = http.createServer((req, res) => {
            let body = '';
            req.on('data', chunk => { body += chunk; });
            req.on('end', () => {
                onMessage(JSON.parse(body));
                res.writeHead(status, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: status < 400 }));
            });
        });
        server.listen(port, () => resolve(server));
    });
}

function setup() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'spool-'));
    const port = 39000 + Math.floor(Math.random() * 1000);
    const post = payload => axios.post(`http://127.0.0.1:${port}/webhook`, payload, { timeout: 2000 });

    return { dir, port, post, spool: createSpool({ dir, post, log: quiet }) };
}

test('a message that arrived while Laravel was down is processed after it comes back', async () => {
    const { dir, port, post, spool } = setup();
    const payload = { wa_message_id: '7_ABC', text: 'عايز اقسط' };

    // Laravel is down: the post fails with no response -> spooled
    await assert.rejects(post(payload), error => spool.retryable(error));
    assert.ok(spool.add(payload));
    assert.strictEqual(spool.files().length, 1);

    // still down: replay keeps it
    await spool.replay();
    assert.strictEqual(spool.files().length, 1);

    // Laravel restarted
    const received = [];
    const server = await fakeLaravel(port, message => received.push(message));
    await spool.replay();
    server.close();

    assert.deepStrictEqual(received, [payload]);
    assert.strictEqual(spool.files().length, 0);
    fs.rmSync(dir, { recursive: true });
});

test('spooled messages are replayed in the order they came', async () => {
    const { dir, port, spool } = setup();
    spool.add({ wa_message_id: '7_1', text: 'الاولى' });
    await new Promise(r => setTimeout(r, 5));
    spool.add({ wa_message_id: '7_2', text: 'التانية' });

    const received = [];
    const server = await fakeLaravel(port, message => received.push(message.wa_message_id));
    await spool.replay();
    server.close();

    assert.deepStrictEqual(received, ['7_1', '7_2']);
    fs.rmSync(dir, { recursive: true });
});

test('a message Laravel refuses (4xx) is moved aside, not retried forever', async () => {
    const { dir, port, spool } = setup();
    spool.add({ wa_message_id: '7_BAD' });

    const server = await fakeLaravel(port, () => {}, 422);
    await spool.replay();
    server.close();

    assert.strictEqual(spool.files().length, 0);
    assert.strictEqual(fs.readdirSync(path.join(dir, 'failed')).length, 1);
    fs.rmSync(dir, { recursive: true });
});
