const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createContacts } = require('../factory-contacts');
const message = (id, fromMe = false, extra = {}) => ({ key: { remoteJid: id, fromMe, ...extra }, pushName: 'عميل', message: { conversation: 'not stored' } });
test('only incoming direct-chat senders are exported and deduplicated', () => {
    const c = createContacts();
    c.contact({ id: '999@s.whatsapp.net', name: 'Contact without incoming chat' });
    c.message(message('111@s.whatsapp.net', true));
    c.message(message('group@g.us'));
    c.message(message('status@broadcast'));
    c.message(message('20122222222@s.whatsapp.net')); c.message(message('20122222222@s.whatsapp.net'));
    assert.equal(c.rows().length, 1); assert.equal(c.rows()[0].phone, '20122222222');
    assert.ok(!JSON.stringify(c.saved()).includes('not stored'));
});
test('hidden LID is never a phone; resolve aliases without duplicates across restart', () => {
    let c = createContacts();
    c.message(message('123456789@lid'));
    assert.equal(c.rows()[0].phone, ''); assert.equal(c.rows()[0].identifier, 'عميل');
    c.link('123456789@lid', '201234567890@s.whatsapp.net');
    c.message(message('123456789@lid'));
    assert.equal(c.rows().length, 1); assert.equal(c.rows()[0].phone, '201234567890');
    c = createContacts(c.saved()); c.message(message('123456789@lid'));
    assert.equal(c.rows().length, 1);
});
test('preserve username when phone unavailable and handle alternate message address', () => {
    const c = createContacts();
    c.contact({ id: '12@lid', username: 'customer_alias' }); c.message(message('12@lid'));
    assert.equal(c.rows()[0].username, 'customer_alias');
    c.message(message('12@lid', false, { remoteJidAlt: '201111111111@s.whatsapp.net' }));
    assert.equal(c.rows().length, 1); assert.equal(c.rows()[0].phone, '201111111111');
});
test('recover raw history phoneNumberToLidMappings dropped by rc.9', () => {
    const c = createContacts(); c.message(message('123@lid'));
    c.historyMappings({ phoneNumberToLidMappings: [{ lidJid: '123@lid', pnJid: '201234567890:0@s.whatsapp.net' }] });
    assert.equal(c.rows()[0].phone, '201234567890');
    c.message(message('201234567890@s.whatsapp.net'));
    assert.equal(c.rows().length, 1);
});
test('resolve stored LID mappings and reject placeholder zero', async () => {
    const c = createContacts(); c.message(message('555@lid')); c.message(message('0@s.whatsapp.net'));
    await c.resolve(async () => '201234567890:0@s.whatsapp.net');
    assert.equal(c.rows().filter(r => r.phone).length, 1);
    assert.equal(c.rows().find(r => r.phone).phone, '201234567890');
});
