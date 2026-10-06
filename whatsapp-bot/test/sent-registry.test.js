// ERR-003: run with `node --test whatsapp-bot/test/sent-registry.test.js`.
const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { createSentRegistry } = require('../sent-registry');

const file = () => path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'sent-')), 'sent.json');

test('an item resent after it was sent is not sent again', async () => {
    const registry = createSentRegistry({ file: file() });
    let sends = 0;
    const send = async () => ({ status: 200, body: { ok: true, wa_message_id: `w${++sends}` } });

    const first = await registry.once('out_7', send);
    const again = await registry.once('out_7', send);

    assert.strictEqual(sends, 1);
    assert.strictEqual(again.body.wa_message_id, first.body.wa_message_id);
    assert.strictEqual(again.body.duplicate, true);
});

test('a resend while the first send is still waiting joins it', async () => {
    const registry = createSentRegistry({ file: file() });
    let sends = 0;
    const slow = () => new Promise(resolve => setTimeout(() => resolve({ status: 200, body: { ok: true, n: ++sends } }), 30));

    const [a, b] = await Promise.all([registry.once('out_8', slow), registry.once('out_8', slow)]);

    assert.strictEqual(sends, 1);
    assert.strictEqual(a.body.n, b.body.n);
});

test('a failed send is tried again, and a restart remembers what was sent', async () => {
    const path1 = file();
    const registry = createSentRegistry({ file: path1 });
    let sends = 0;

    await registry.once('out_9', async () => ({ status: 200, body: { ok: false } }));
    await registry.once('out_9', async () => ({ status: 200, body: { ok: true, n: ++sends } }));
    assert.strictEqual(sends, 1);

    const restarted = createSentRegistry({ file: path1 });
    await restarted.once('out_9', async () => ({ status: 200, body: { ok: true, n: ++sends } }));
    assert.strictEqual(sends, 1);
});

test('no client_id = sent as before', async () => {
    const registry = createSentRegistry({ file: file() });
    let sends = 0;
    const send = async () => ({ status: 200, body: { ok: true, n: ++sends } });

    await registry.once(undefined, send);
    await registry.once(undefined, send);
    assert.strictEqual(sends, 2);
});
