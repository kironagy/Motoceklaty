const fs = require('fs');
const path = require('path');

/*
 * WA-002: after the three tries in postToLaravelWithRetry the message was
 * only logged - a Laravel restart or a deploy lost every message that
 * arrived meanwhile. It is written to a small file spool instead and sent
 * again on boot, after the next successful post, and every minute while
 * files wait. Laravel ignores a wa_message_id it already has, so a replay
 * is safe.
 */
function createSpool({ dir, post, maxFiles = 500, log = console }) {
    let replaying = false;

    const retryable = error => {
        const status = error?.response?.status;

        return !status || status >= 500;
    };

    function files() {
        try {
            return fs.readdirSync(dir).filter(name => name.endsWith('.json')).sort();
        } catch {
            return [];
        }
    }

    function moveAside(name) {
        fs.mkdirSync(path.join(dir, 'failed'), { recursive: true });
        fs.renameSync(path.join(dir, name), path.join(dir, 'failed', name));
    }

    function add(payload) {
        try {
            fs.mkdirSync(dir, { recursive: true });

            if (files().length >= maxFiles) {
                log.error(`❌ Spool full (${maxFiles}) - message lost:`, payload.wa_message_id);
                return false;
            }

            const safeId = String(payload.wa_message_id).replace(/[^A-Za-z0-9_-]/g, '_');
            const file = path.join(dir, `${Date.now()}_${safeId}.json`);

            fs.writeFileSync(`${file}.tmp`, JSON.stringify(payload));
            fs.renameSync(`${file}.tmp`, file);
            log.error('💾 Laravel unreachable - message spooled:', payload.wa_message_id);

            return true;
        } catch (error) {
            log.error('❌ Could not spool message:', payload.wa_message_id, error?.message || error);

            return false;
        }
    }

    async function replay() {
        if (replaying) return;

        replaying = true;

        try {
            for (const name of files()) {
                let payload;

                try {
                    payload = JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8'));
                } catch (error) {
                    log.error('❌ Unreadable spool file, moved aside:', name, error?.message || error);
                    moveAside(name);
                    continue;
                }

                try {
                    await post(payload);
                    fs.unlinkSync(path.join(dir, name));
                    log.log('📤 Spooled message delivered to Laravel:', payload.wa_message_id);
                } catch (error) {
                    // still down: keep it (and the rest, in order) for the next try
                    if (retryable(error)) break;

                    // Laravel refused it (4xx): it never will - kept aside for a look
                    log.error('❌ Laravel refused a spooled message, moved aside:', name, error?.response?.data || error?.response?.status);
                    moveAside(name);
                }
            }
        } finally {
            replaying = false;
        }
    }

    return { add, replay, files, retryable };
}

module.exports = { createSpool };
