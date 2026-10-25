// Collect sender identities only. Message content is never retained.
function createContacts(saved = []) {
    const records = new Map(saved.map(row => [row.id, row]));
    const aliases = new Map(saved.flatMap(row => (row.aliases || []).map(id => [id, row.id])));
    const direct = id => typeof id === 'string' && /@(s\.whatsapp\.net|c\.us|lid)$/.test(id);
    const phone = id => /@(s\.whatsapp\.net|c\.us)$/.test(id || '') ? id.split('@')[0].split(':')[0] : '';
    function merge(id, values = {}) {
        if (!direct(id)) return;
        id = aliases.get(id) || id;
        const old = records.get(id) || { id, phone: phone(id), name: '', username: '', received: false };
        records.set(id, { ...old, ...Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v != null)), id });
    }
    function link(lid, pn) {
        if (!direct(lid) || !phone(pn)) return;
        const old = records.get(lid);
        merge(pn, { ...(old || {}), phone: phone(pn),
            received: Boolean(old?.received || records.get(pn)?.received),
            aliases: [...new Set([...(old?.aliases || []), ...(records.get(pn)?.aliases || []), lid])],
        });
        aliases.set(lid, pn);
        records.delete(lid);
    }
    return {
        contact(c) {
            if (!direct(c.id)) return;
            merge(c.id, { name: c.name || c.notify || c.verifiedName || '', username: c.username || '' });
            if (c.lid && phone(c.id)) link(c.lid, c.id);
            if (c.phoneNumber && c.id.endsWith('@lid')) link(c.id, c.phoneNumber.includes('@') ? c.phoneNumber : `${c.phoneNumber}@s.whatsapp.net`);
        },
        message(m) {
            if (m.key?.fromMe || !m.message || !direct(m.key?.remoteJid)) return;
            const id = m.key.remoteJid;
            merge(id, { received: true, name: m.pushName || '' });
            const alt = m.key.remoteJidAlt;
            if (id.endsWith('@lid') && phone(alt)) link(id, alt);
            if (phone(id) && alt?.endsWith('@lid')) link(alt, id);
        },
        link,
        saved: () => [...records.values()],
        rows() {
            return [...records.values()].filter(r => r.received).map(r => ({
                phone: r.phone || '', name: r.name || '', username: r.username || '',
                identifier: r.phone || r.username || r.name || 'اسم ورقم غير متاحين',
            })).sort((a, b) => a.identifier.localeCompare(b.identifier));
        },
    };
}
module.exports = { createContacts };
