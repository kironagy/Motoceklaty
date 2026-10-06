const fs = require('fs');
const path = require('path');

/*
 * ERR-003: Laravel marks a reply item "sent" only when this worker answers.
 * A Laravel worker that died after posting (or a timeout while the send was
 * still waiting for its turn in the pacing) left the item "queued", and the
 * resend of the turn sent it to the customer a second time. Each item now
 * carries client_id: one already sent returns its first answer, one still
 * in flight is joined - it is never sent twice. Kept on disk (last 2000)
 * so a Node restart does not forget.
 */
function createSentRegistry({ file, max = 2000, log = console }) {
    let done = new Map();
    const inflight = new Map();

    try {
        done = new Map(JSON.parse(fs.readFileSync(file, 'utf8')));
    } catch {
        // first run, or an unreadable file: start empty
    }

    function save() {
        try {
            fs.mkdirSync(path.dirname(file), { recursive: true });
            fs.writeFileSync(`${file}.tmp`, JSON.stringify([...done]));
            fs.renameSync(`${file}.tmp`, file);
        } catch (error) {
            log.error('❌ Could not save the sent registry:', error?.message || error);
        }
    }

    /** send() resolves {status, body}; only a successful send is remembered. */
    function once(clientId, send) {
        if (!clientId) return send();

        const key = String(clientId);

        if (done.has(key)) {
            return Promise.resolve({ ...done.get(key), body: { ...done.get(key).body, duplicate: true } });
        }

        if (inflight.has(key)) return inflight.get(key);

        const promise = Promise.resolve()
            .then(send)
            .then(outcome => {
                if (outcome.status === 200 && outcome.body?.ok) {
                    done.set(key, outcome);

                    while (done.size > max) done.delete(done.keys().next().value);

                    save();
                }

                return outcome;
            })
            .finally(() => inflight.delete(key));

        inflight.set(key, promise);

        return promise;
    }

    return { once, has: key => done.has(String(key)) };
}

module.exports = { createSentRegistry };
