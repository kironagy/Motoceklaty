/*
 * Owner 2026-11-15 (anti-ban): a customer is saved in the number's contacts before the first reply,
 * so the number talks to its contacts, not to random numbers. Baileys 7.0.0-rc14 syncs this as a
 * contactAction (addOrEditContact) to the phone's address book.
 *
 * Saved once per number + chat; the list lives in a file so a restart does not save everyone again.
 * A failure is logged and never holds the reply back.
 */
const fs = require('fs');
const path = require('path');

function createContactBook({ file, log = console } = {}) {
    let saved = new Set();
    const names = new Map();

    try {
        saved = new Set(JSON.parse(fs.readFileSync(file, 'utf8')));
    } catch {}

    function persist() {
        try {
            fs.mkdirSync(path.dirname(file), { recursive: true });
            fs.writeFileSync(`${file}.tmp`, JSON.stringify([...saved]));
            fs.renameSync(`${file}.tmp`, file);
        } catch (error) {
            log.error('contact book save failed:', error?.message || error);
        }
    }

    /** The customer's WhatsApp name, remembered from his messages. */
    function noteName(botId, jids, pushName) {
        const name = String(pushName || '').trim();

        if (!name) return;

        for (const jid of jids.filter(Boolean)) {
            names.set(`${botId}|${jid}`, name);
        }

        if (names.size > 20000) names.delete(names.keys().next().value);
    }

    /** What the contact is called: his WhatsApp name, else "عميل" + the last digits of his number. */
    function contactFor(botId, jid, pn = null) {
        const digits = String(pn || jid).split('@')[0].replace(/\D/g, '');
        const fullName = names.get(`${botId}|${jid}`) || (pn && names.get(`${botId}|${pn}`)) || `عميل ${digits.slice(-4)}`;
        const contact = { fullName, firstName: fullName.split(/\s+/)[0], saveOnPrimaryAddressbook: true };

        if (jid.endsWith('@lid')) {
            contact.lidJid = jid;
            if (pn) contact.pnJid = pn;
        } else {
            contact.pnJid = jid;
        }

        return contact;
    }

    async function ensureSaved(sock, botId, jid) {
        const key = `${botId}|${jid}`;

        if (!jid || saved.has(key) || jid.endsWith('@g.us') || jid.includes('broadcast')) return false;

        try {
            let pn = null;

            if (jid.endsWith('@lid') && sock?.signalRepository?.lidMapping?.getPNForLID) {
                pn = await sock.signalRepository.lidMapping.getPNForLID(jid).catch(() => null);
            }

            await sock.addOrEditContact(jid, contactFor(botId, jid, pn));
            saved.add(key);
            persist();
            log.log(`📇 bot ${botId} saved ${jid} as a contact`);

            return true;
        } catch (error) {
            log.error(`📇 bot ${botId} could not save ${jid} as a contact:`, error?.message || error);

            return false;
        }
    }

    return { noteName, contactFor, ensureSaved, has: (botId, jid) => saved.has(`${botId}|${jid}`) };
}

module.exports = { createContactBook };
