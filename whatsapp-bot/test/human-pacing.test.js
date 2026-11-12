// Run with `node --test whatsapp-bot/test/human-pacing.test.js` (no Baileys, no WhatsApp).
const { test } = require('node:test');
const assert = require('node:assert');
const { createHumanPacing, createAttention, cairoHour } = require('../human-pacing');

/** Deterministic random: mulberry32. */
function seeded(seed) {
    return () => {
        seed |= 0; seed = seed + 0x6D2B79F5 | 0;
        let t = Math.imul(seed ^ seed >>> 15, 1 | seed);
        t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
        return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
}

const sample = (n, fn) => Array.from({ length: n }, fn);
const share = (list, pred) => list.filter(pred).length / list.length;

test('a cold chat is answered anywhere from a couple of seconds to two minutes', () => {
    const pacing = createHumanPacing({ random: seeded(1), env: {} });
    const delays = sample(4000, () => pacing.replyDelayMs({ waitedMs: 0, hot: false, cairoHour: 14 }));

    assert.ok(Math.min(...delays) >= 2000);
    assert.ok(Math.max(...delays) <= 90000, 'never held past HUMAN_MAX_EXTRA_MS');
    assert.ok(share(delays, d => d < 8000) > 0.10, 'some replies are quick');
    assert.ok(share(delays, d => d >= 40000) > 0.15, 'some take most of a minute or more');
    assert.ok(new Set(delays).size > 3000, 'no fixed values');
});

test('a live back-and-forth is mostly answered within seconds', () => {
    const pacing = createHumanPacing({ random: seeded(2), env: {} });
    const delays = sample(4000, () => pacing.replyDelayMs({ waitedMs: 0, hot: true }));

    assert.ok(share(delays, d => d <= 6000) > 0.6);
    assert.ok(Math.max(...delays) <= 45000);
});

test('time the reply already took is not added again', () => {
    const pacing = createHumanPacing({ random: seeded(3), env: {} });
    const delays = sample(2000, () => pacing.replyDelayMs({ waitedMs: 25000, hot: false, cairoHour: 14 }));

    assert.ok(share(delays, d => d === 0) > 0.5, 'a reply that took 25 s mostly goes out at once');
    assert.strictEqual(pacing.replyDelayMs({ waitedMs: 500000, hot: false }), 0);
});

test('late night is slower than daytime', () => {
    const avg = hour => {
        const pacing = createHumanPacing({ random: seeded(4), env: {} });
        const delays = sample(4000, () => pacing.replyDelayMs({ hot: false, cairoHour: hour }));

        return delays.reduce((a, b) => a + b, 0) / delays.length;
    };

    assert.ok(avg(3) > avg(15) * 1.1);
});

test('HUMAN_PACING=0 turns the extra wait off', () => {
    const pacing = createHumanPacing({ random: seeded(5), env: { HUMAN_PACING: '0' } });

    assert.strictEqual(pacing.replyDelayMs({ hot: false }), 0);
    assert.strictEqual(pacing.switchChatMs(), 0);
});

test('typing follows the length of the text, within limits, never the same twice', () => {
    const pacing = createHumanPacing({ random: seeded(6), env: {} });
    const total = plan => plan.reduce((sum, b) => sum + b.composeMs + b.pauseMs, 0);
    const short = sample(500, () => total(pacing.typingPlan('تمام يا فندم')));
    const long = sample(500, () => total(pacing.typingPlan('ا'.repeat(400))));

    assert.ok(Math.min(...short) >= 1200);
    assert.ok(Math.max(...long) <= 12000 + 2 * 1800);
    assert.ok(Math.min(...long) > Math.max(...short));
    assert.strictEqual(pacing.typingPlan('ا'.repeat(400)).length, 3, 'a long text is typed in bursts');
    assert.strictEqual(pacing.typingPlan('أهلا').length, 1);
    assert.strictEqual(pacing.typingPlan('ا'.repeat(400)).at(-1).pauseMs, 0);
});

test('a number with chats waiting types less', () => {
    const total = pressure => {
        const pacing = createHumanPacing({ random: seeded(7), env: {} });

        return pacing.typingPlan('ا'.repeat(200), { pressure }).reduce((sum, b) => sum + b.composeMs + b.pauseMs, 0);
    };

    assert.ok(total(4) < total(0) / 2);
});

test('reading time grows with the customer message and is capped', () => {
    const pacing = createHumanPacing({ random: seeded(8), env: {} });

    assert.ok(pacing.readingMs(0) >= 500);
    assert.ok(pacing.readingMs(5000) <= 4000);
});

test('reconnect: a logout stops and archives', () => {
    const { reconnectPlan } = createHumanPacing({ env: {} });

    assert.deepStrictEqual(reconnectPlan({ statusCode: 401 }), { reconnect: false, delayMs: 0, status: 'logged_out', archive: true });
});

test('reconnect: a refused number (403) is tried three times slowly, then given up', () => {
    const { reconnectPlan } = createHumanPacing({ random: seeded(9), env: {} });
    const first = reconnectPlan({ statusCode: 403, attempt: 0 });
    const third = reconnectPlan({ statusCode: 403, attempt: 2 });

    assert.ok(first.reconnect && first.delayMs >= 54000);
    assert.ok(third.reconnect && third.delayMs >= 14 * 60000);
    assert.deepStrictEqual(reconnectPlan({ statusCode: 403, attempt: 3 }), { reconnect: false, delayMs: 0, status: 'logged_out', archive: true });
});

test('reconnect: an unlinked bot stops asking for QR codes after three rounds', () => {
    const { reconnectPlan } = createHumanPacing({ random: seeded(10), env: {} });
    const qr = attempt => reconnectPlan({ statusCode: 408, message: 'QR refs attempts ended', attempt, linked: false });

    assert.ok(qr(0).reconnect && qr(1).reconnect);
    assert.deepStrictEqual(qr(2), { reconnect: false, delayMs: 0, status: 'not_running', archive: false });
});

test('reconnect: a linked bot that lost the connection backs off and keeps trying', () => {
    const { reconnectPlan } = createHumanPacing({ random: seeded(11), env: {} });
    const lost = attempt => reconnectPlan({ statusCode: 408, message: 'Connection was lost', attempt, linked: true });

    assert.ok(lost(0).delayMs >= 2400 && lost(0).delayMs <= 3900);
    assert.ok(lost(4).delayMs > lost(0).delayMs * 5);
    assert.ok(lost(50).reconnect && lost(50).delayMs <= 390000);
    // a linked bot never hits the QR rule, whatever the message says
    assert.ok(reconnectPlan({ statusCode: 408, message: 'QR refs attempts ended', attempt: 9, linked: true }).reconnect);
});

test('attention: one number works one chat at a time, numbers do not wait on each other', async () => {
    const attention = createAttention();
    const log = [];
    const job = (name, ms) => async () => {
        log.push(`start ${name}`);
        await new Promise(resolve => setTimeout(resolve, ms));
        log.push(`end ${name}`);

        return name;
    };

    const a = attention.run('1', job('a', 30));
    const b = attention.run('1', job('b', 5));
    const other = attention.run('2', job('other', 1));

    assert.strictEqual(attention.pressure('1'), 2);
    assert.deepStrictEqual(await Promise.all([a, b, other]), ['a', 'b', 'other']);
    assert.ok(log.indexOf('end a') < log.indexOf('start b'));
    assert.ok(log.indexOf('end other') < log.indexOf('end a'));
    assert.strictEqual(attention.pressure('1'), 0);
});

test('attention: a failed job does not block the next one', async () => {
    const attention = createAttention();
    const failed = attention.run('1', async () => { throw new Error('boom'); });

    await assert.rejects(failed, /boom/);
    assert.strictEqual(await attention.run('1', async () => 'ok'), 'ok');
});

test('cairoHour reads the hour in Cairo', () => {
    assert.strictEqual(cairoHour(new Date('2026-11-12T23:30:00Z')), 1);
});
