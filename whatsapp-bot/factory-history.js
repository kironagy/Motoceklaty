// Only message keys and timestamps: no message text, media, or customer names.
function createHistory(saved = []) {
    const chats = new Map(saved.map(row => [row.key.remoteJid, row]));
    function message(m) {
        const key = m?.key, timestamp = Number(m?.messageTimestamp);
        if (!key?.id || !/@(s\.whatsapp\.net|lid|c\.us)$/.test(key.remoteJid || '') || !Number.isFinite(timestamp) || timestamp <= 0) return;
        const old = chats.get(key.remoteJid);
        if (!old || timestamp < old.timestamp) chats.set(key.remoteJid, {
            key: {remoteJid:key.remoteJid, id:key.id, fromMe:!!key.fromMe}, timestamp,
            requestedKey: old?.requestedKey || null, attempts: old?.attempts || 0,
        });
    }
    return {
        message,
        ingest(data) {
            for (const m of data.messages || []) message(m);
            for (const chat of data.conversations || data.chats || []) {
                for (const item of chat.messages || []) message(item.message?.key ? item.message : item);
            }
        },
        next(needed) {
            const row = [...chats.values()].find(r => needed(r.key.remoteJid) && r.requestedKey !== r.key.id && r.attempts < 10);
            if (!row) return null;
            row.requestedKey = row.key.id; row.attempts++;
            // WhatsApp's on-demand protocol expects milliseconds.
            return {key:{...row.key}, timestamp:row.timestamp < 1e12 ? row.timestamp * 1000 : row.timestamp};
        },
        saved: () => [...chats.values()],
        count: () => chats.size,
    };
}
module.exports = {createHistory};
