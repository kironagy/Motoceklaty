/*
 * Human pacing (owner 2026-11-12). Numbers were unlinked/banned within hours of every relink: the
 * bot answered ~150 different chats an hour, each one "seen" the instant the reply was ready, typed
 * for the same 1-3.5 s, in several chats at the very same moment, and reconnected every 3 s after
 * WhatsApp refused the number. None of that is what a person on WhatsApp Web looks like.
 *
 * This module only decides *how long* things take; index.js does the waiting. Everything is pure
 * (random and clock are injected) so the timings are tested without WhatsApp.
 *
 *  - replyDelayMs: how long after the customer's message the chat is opened ("seen"). A mixture, not
 *    a range: someone already in the chat answers in a second or two, someone busy with another
 *    customer takes a minute or two. The time Laravel already spent on the reply counts, so most
 *    replies are not slowed at all - only the too-fast ones are held back.
 *  - readingMs / typingPlan: reading the customer's message, then typing at a speed that differs
 *    from message to message, with a pause in the middle of a long one.
 *  - createAttention: one number is in ONE chat at a time (seen -> typing -> send), like one person.
 *  - reconnectPlan: back off, and stop knocking when WhatsApp said no.
 */

const DEFAULTS = {
    HUMAN_PACING: '1',
    // Never hold a ready reply longer than this - Laravel waits on the send (see DeliveryService).
    HUMAN_MAX_EXTRA_MS: 90000,
    // A chat we sent to this recently is a live back-and-forth.
    HUMAN_HOT_CHAT_MS: 120000,
    HUMAN_TYPING_MIN_MS: 1200,
    HUMAN_TYPING_MAX_MS: 12000,
    // Cairo hours when a real employee is slower (late night), [from, to).
    HUMAN_NIGHT_FROM: 1,
    HUMAN_NIGHT_TO: 8,
    HUMAN_NIGHT_FACTOR: 1.4,
};

/** [share, minMs, maxMs] - picked by share, then uniform inside the band. */
const HOT_BANDS = [
    [0.70, 1000, 6000],
    [0.25, 6000, 20000],
    [0.05, 20000, 45000],
];

const COLD_BANDS = [
    [0.15, 2000, 8000],
    [0.45, 8000, 25000],
    [0.25, 25000, 60000],
    [0.15, 60000, 120000],
];

function createHumanPacing({ random = Math.random, env = process.env } = {}) {
    const cfg = Object.fromEntries(Object.entries(DEFAULTS).map(([key, fallback]) => {
        const raw = env[key];

        return [key, raw === undefined || raw === '' ? fallback : (typeof fallback === 'number' ? Number(raw) : raw)];
    }));

    const enabled = !['0', 'false', 'off'].includes(String(cfg.HUMAN_PACING).toLowerCase());
    const between = (min, max) => min + random() * Math.max(0, max - min);

    function pickBand(bands) {
        let roll = random();

        for (const [share, min, max] of bands) {
            if (roll < share) return between(min, max);
            roll -= share;
        }

        const [, min, max] = bands[bands.length - 1];

        return between(min, max);
    }

    function isNight(cairoHour) {
        return cairoHour >= cfg.HUMAN_NIGHT_FROM && cairoHour < cfg.HUMAN_NIGHT_TO;
    }

    /**
     * Extra wait before the chat is marked seen.
     * waitedMs: how long the customer's message has already been waiting (the reply took that long).
     * hot: we sent to this chat a moment ago (live conversation).
     */
    function replyDelayMs({ waitedMs = 0, hot = false, cairoHour = 12 } = {}) {
        if (!enabled) return 0;

        let target = pickBand(hot ? HOT_BANDS : COLD_BANDS);

        if (!hot && isNight(cairoHour)) target *= cfg.HUMAN_NIGHT_FACTOR;

        return Math.round(Math.min(cfg.HUMAN_MAX_EXTRA_MS, Math.max(0, target - waitedMs)));
    }

    /** Between "seen" and the first keystroke: reading what the customer wrote. */
    function readingMs(incomingChars = 0) {
        if (!enabled) return between(500, 1200);

        return Math.round(Math.min(4000, between(500, 1300) + incomingChars * between(15, 35)));
    }

    /**
     * Typing bursts for one outgoing text: [{ composeMs, pauseMs }]. The speed is drawn per message;
     * a long message is typed in two or three bursts with a short stop between them.
     * pressure: chats waiting behind this one on the same number - a busy person types less.
     */
    function typingPlan(text, { pressure = 0 } = {}) {
        const chars = String(text || '').length;
        const perChar = between(35, 80);
        const squeeze = 1 / (1 + Math.max(0, pressure) * 0.5);
        const total = Math.min(cfg.HUMAN_TYPING_MAX_MS, Math.max(cfg.HUMAN_TYPING_MIN_MS, chars * perChar)) * squeeze;

        if (!enabled) return [{ composeMs: Math.round(Math.min(3500, Math.max(1000, chars * 25))), pauseMs: 0 }];

        const bursts = chars > 260 ? 3 : chars > 110 ? 2 : 1;
        const plan = [];
        let left = total;

        for (let i = 0; i < bursts; i++) {
            const last = i === bursts - 1;
            const share = last ? left : left * between(0.35, 0.65);

            plan.push({ composeMs: Math.round(share), pauseMs: last ? 0 : Math.round(between(500, 1800) * squeeze) });
            left -= share;
        }

        return plan;
    }

    /** Leaving one chat and opening the next. */
    function switchChatMs() {
        return enabled ? Math.round(between(600, 2500)) : 0;
    }

    /**
     * What to do after the connection closed.
     * -> { reconnect, delayMs, status, archive }
     *   401 logged out            : stop, archive the dead credentials (a new QR is needed)
     *   403 refused by WhatsApp   : three slow tries (1, 4, 16 min), then stop like a logout.
     *                               It used to retry every 3 s - 29,891 times on one number.
     *   408 QR never scanned      : an unlinked bot shows three rounds of QR codes, then stops
     *                               (it used to register a new device with WhatsApp for days).
     *   anything else             : 3 s, 6 s, 12 s ... up to 5 min, with jitter.
     */
    function reconnectPlan({ statusCode, message = '', attempt = 0, linked = true } = {}) {
        if (statusCode === 401) return { reconnect: false, delayMs: 0, status: 'logged_out', archive: true };

        if (statusCode === 403) {
            if (attempt >= 3) return { reconnect: false, delayMs: 0, status: 'logged_out', archive: true };

            return { reconnect: true, delayMs: Math.round(60000 * (4 ** attempt) * between(0.9, 1.2)), status: 'disconnected', archive: false };
        }

        if (!linked && /QR refs attempts ended/i.test(String(message))) {
            if (attempt >= 2) return { reconnect: false, delayMs: 0, status: 'not_running', archive: false };

            return { reconnect: true, delayMs: Math.round(between(2000, 5000)), status: 'disconnected', archive: false };
        }

        const base = Math.min(300000, 3000 * (2 ** Math.min(attempt, 10)));

        return { reconnect: true, delayMs: Math.round(base * between(0.8, 1.3)), status: 'disconnected', archive: false };
    }

    return { enabled, replyDelayMs, readingMs, typingPlan, switchChatMs, reconnectPlan, isNight };
}

/**
 * One number, one chat at a time. run(botId, jid, job) runs jobs of the same number one after
 * another; pressure(botId) is how many are waiting. A failed job never blocks the next one.
 */
function createAttention() {
    const lanes = {};

    function run(botId, job) {
        const lane = lanes[botId] ||= { chain: Promise.resolve(), waiting: 0 };

        lane.waiting++;

        const result = lane.chain.then(() => {
            lane.waiting--;

            return job();
        });

        lane.chain = result.catch(() => {});

        return result;
    }

    return { run, pressure: botId => lanes[botId]?.waiting || 0 };
}

function cairoHour(date = new Date()) {
    return Number(new Intl.DateTimeFormat('en-GB', { hour: '2-digit', hour12: false, timeZone: 'Africa/Cairo' }).format(date)) % 24;
}

module.exports = { createHumanPacing, createAttention, cairoHour };
