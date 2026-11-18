// Run with `node --test whatsapp-bot/test/contact-book.test.js` (no WhatsApp).
const { test } = require('node:test');
const assert = require('node:assert');
const os = require('os');
const path = require('path');
const fs = require('fs');
const { createContactBook } = require('../contact-book');

const quiet = { log() {}, error() {} };
const tmpFile = () => path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'cb-')), 'saved.json');

test('a customer is saved once, with his WhatsApp name, before the first reply', async () => {
    const file = tmpFile();
    const book = createContactBook({ file, log: quiet, enabled: true });
    const calls = [];
    const sock = { addOrEditContact: async (jid, contact) => calls.push([jid, contact]) };

    book.noteName(5, ['201012345678@s.whatsapp.net'], 'أحمد علي');
    assert.strictEqual(await book.ensureSaved(sock, 5, '201012345678@s.whatsapp.net'), true);
    assert.strictEqual(await book.ensureSaved(sock, 5, '201012345678@s.whatsapp.net'), false);

    assert.deepStrictEqual(calls, [['201012345678@s.whatsapp.net', { fullName: 'أحمد علي', firstName: 'أحمد', saveOnPrimaryAddressbook: true, pnJid: '201012345678@s.whatsapp.net' }]]);
    // a restart does not save him again
    assert.strictEqual(createContactBook({ file, log: quiet, enabled: true }).has(5, '201012345678@s.whatsapp.net'), true);
});

test('an @lid customer without a name gets one from his number and keeps his lid', async () => {
    const book = createContactBook({ file: tmpFile(), log: quiet, enabled: true });
    const calls = [];
    const sock = {
        addOrEditContact: async (jid, contact) => calls.push(contact),
        signalRepository: { lidMapping: { getPNForLID: async () => '201099990000@s.whatsapp.net' } },
    };

    await book.ensureSaved(sock, 5, '1234@lid');

    assert.deepStrictEqual(calls[0], { fullName: 'عميل 0000', firstName: 'عميل', saveOnPrimaryAddressbook: true, lidJid: '1234@lid', pnJid: '201099990000@s.whatsapp.net' });
});

test('a failed save never stops the reply and is tried again next time', async () => {
    const book = createContactBook({ file: tmpFile(), log: quiet, enabled: true });
    let fail = true;
    const sock = { addOrEditContact: async () => { if (fail) throw new Error('nope'); } };

    assert.strictEqual(await book.ensureSaved(sock, 5, '201012345678@s.whatsapp.net'), false);
    fail = false;
    assert.strictEqual(await book.ensureSaved(sock, 5, '201012345678@s.whatsapp.net'), true);
});

test('groups are never saved', async () => {
    const book = createContactBook({ file: tmpFile(), log: quiet, enabled: true });
    assert.strictEqual(await book.ensureSaved({ addOrEditContact: async () => assert.fail() }, 5, '123@g.us'), false);
});

test('an @lid customer is saved under his phone number from the message, with his name (owner 2026-11-18)', async () => {
    const book = createContactBook({ file: tmpFile(), log: quiet, enabled: true });
    const calls = [];
    const sock = { addOrEditContact: async (jid, contact) => calls.push([jid, contact]) };

    book.noteName(5, ['999@lid', '201055550000@s.whatsapp.net'], 'محمود');
    assert.strictEqual(await book.ensureSaved(sock, 5, '999@lid', '201055550000@s.whatsapp.net'), true);
    assert.strictEqual(calls[0][0], '201055550000@s.whatsapp.net');
    assert.strictEqual(calls[0][1].pnJid, '201055550000@s.whatsapp.net');
    assert.strictEqual(calls[0][1].lidJid, '999@lid');
    assert.strictEqual(calls[0][1].fullName, 'محمود');
    // the reply's own save (by lid alone, mapping unknown) does not save him twice
    assert.strictEqual(await book.ensureSaved(sock, 5, '201055550000@s.whatsapp.net'), false);
});

test('saving contacts is off unless SAVE_CONTACTS=1 (owner 2026-11-18)', async () => {
    const book = createContactBook({ file: tmpFile(), log: quiet, enabled: false });
    assert.strictEqual(await book.ensureSaved({ addOrEditContact: async () => assert.fail() }, 5, '201012345678@s.whatsapp.net'), false);
});
