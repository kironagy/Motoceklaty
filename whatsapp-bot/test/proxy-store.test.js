// Run with `node --test whatsapp-bot/test/proxy-store.test.js` (no network).
const { test } = require('node:test');
const assert = require('node:assert');
const os = require('os');
const path = require('path');
const fs = require('fs');
const { parseProxy, buildProxy, createProxyStore } = require('../proxy-store');

test('an http proxy covers the socket, uploads and downloads', () => {
    const p = buildProxy('http://u:p@1.2.3.4:8080');

    assert.ok(p.agent && p.dispatcher);
    assert.strictEqual(p.label, 'http://1.2.3.4:8080', 'the password is never in the label');
});

test('a socks5 proxy covers the socket and uploads', () => {
    const p = buildProxy('socks5://u:p@1.2.3.4:1080');

    assert.ok(p.agent);
    assert.strictEqual(p.dispatcher, undefined);
});

test('no proxy is null, a broken one is refused', () => {
    assert.strictEqual(buildProxy(''), null);
    assert.throws(() => parseProxy('1.2.3.4:8080'));
    assert.throws(() => parseProxy('ftp://1.2.3.4:21'));
    assert.throws(() => parseProxy('http://1.2.3.4'));
});

test('the store keeps one proxy per bot and removes it on an empty value', () => {
    const store = createProxyStore({ dir: fs.mkdtempSync(path.join(os.tmpdir(), 'px-')) });

    store.set(5, 'http://u:p@1.2.3.4:8080');
    assert.strictEqual(store.get(5), 'http://u:p@1.2.3.4:8080');
    assert.strictEqual(store.get(6), null);
    store.set(5, '');
    assert.strictEqual(store.get(5), null);
});
