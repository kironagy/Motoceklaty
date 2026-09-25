const makeWASocket = require('@whiskeysockets/baileys').default;
const {
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
    downloadMediaMessage,
    generateMessageIDV2,
    Browsers,
} = require('@whiskeysockets/baileys');

const pino = require('pino');
const axios = require('axios');
const express = require('express');
const QRCode = require('qrcode');
const fs = require('fs');
const path = require('path');

require('dotenv').config();

const app = express();
app.use(express.json({ limit: '200mb' }));

const sessions = {};
const latestQr = {};
const statuses = {};
const starting = {};
const handledMessages = new Set();
const chatQueues = {};

/*
 * Laravel processes replies async (a queue worker, seconds later), so by
 * the time it tells us "quote this one" via /send-message it can't hand us
 * back the original Baileys message object - it only ever saw the id. Kept
 * here in memory (same process the message arrived on) so /send-message
 * can look it up and pass it straight to sock.sendMessage's `quoted` option.
 */
const recentRawMessages = new Map();

/*
 * Ids of messages this process sent itself. Baileys echoes every own send
 * back through messages.upsert as fromMe - the same shape as staff typing
 * on the phone (DEC-17) - and the echo can reach Laravel before the
 * /send-message caller has recorded the id, so Laravel stored bot photos
 * as "[staff] [media]" and then hit a duplicate-key error. The id is
 * generated before the send, so the echo is always recognisable.
 */
const botSentIds = new Set();

const PORT = process.env.PORT || 3080;
const LARAVEL_TIMEOUT = Number(process.env.LARAVEL_TIMEOUT || 30000);
const LOG_LEVEL = process.env.LOG_LEVEL || 'debug';

/*
 * Anti-ban pacing. WhatsApp restricted the number for bursts of replies:
 * twenty customers writing at once got twenty replies in the same second,
 * each after exactly one second of "typing". Every send on a number now
 * waits its turn behind a randomised gap and a per-minute cap, and the
 * typing time follows the length of the reply.
 */
const SEND_MAX_PER_MINUTE = Number(process.env.SEND_MAX_PER_MINUTE || 20);
const SEND_MIN_GAP_MS = Number(process.env.SEND_MIN_GAP_MS || 1500);
const SEND_MAX_GAP_MS = Number(process.env.SEND_MAX_GAP_MS || 4000);
const TYPING_MS_PER_CHAR = Number(process.env.TYPING_MS_PER_CHAR || 45);
const TYPING_MIN_MS = Number(process.env.TYPING_MIN_MS || 1500);
const TYPING_MAX_MS = Number(process.env.TYPING_MAX_MS || 7000);
const READ_DELAY_MIN_MS = Number(process.env.READ_DELAY_MIN_MS || 1000);
const READ_DELAY_MAX_MS = Number(process.env.READ_DELAY_MAX_MS || 4000);

const sendLimiters = {};

async function fetchLatestActiveBotId() {
    try {
        const webhookUrl = process.env.LARAVEL_WEBHOOK_URL || '';
        const baseUrl = webhookUrl.replace(/\/whatsapp\/incoming-message\/?$/, '');

        if (!baseUrl) return null;

        const response = await axios.get(`${baseUrl}/whatsapp/latest-active-bot`, {
            headers: { 'X-BOT-TOKEN': process.env.BOT_TOKEN },
            timeout: LARAVEL_TIMEOUT,
        });

        return {
            latest: response.data?.bot_id ? String(response.data.bot_id) : null,
            active: (response.data?.active_bot_ids || []).map(String),
        };
    } catch (error) {
        console.error('fetch latest active bot id error:', error?.message || error);
        return { latest: null, active: [] };
    }
}

/*
 * A restart used to start only the newest active bot - bot 86, never
 * linked, sat on a QR while the linked bot 85 stayed down and customers got
 * no replies. A bot counts as linked when its saved creds carry `me`.
 */
function isLinkedSession(botId) {
    try {
        const creds = JSON.parse(fs.readFileSync(path.join(__dirname, 'sessions', botId, 'creds.json'), 'utf8'));
        return Boolean(creds?.me?.id);
    } catch {
        return false;
    }
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function randomBetween(min, max) {
    return min + Math.random() * Math.max(0, max - min);
}

async function waitForSendSlot(limiter) {
    const gap = randomBetween(SEND_MIN_GAP_MS, SEND_MAX_GAP_MS);
    const sinceLast = Date.now() - limiter.lastAt;

    if (sinceLast < gap) {
        await sleep(gap - sinceLast);
    }

    for (;;) {
        const windowStart = Date.now() - 60000;
        limiter.sentAt = limiter.sentAt.filter(t => t > windowStart);

        if (limiter.sentAt.length < SEND_MAX_PER_MINUTE) return;

        await sleep(limiter.sentAt[0] - windowStart + 50);
    }
}

/**
 * Runs `send` once this number's pacing allows it. Sends on one number
 * go out one at a time, whichever chat they are for.
 */
function throttleSend(botId, send) {
    const limiter = sendLimiters[botId] ||= { chain: Promise.resolve(), sentAt: [], lastAt: 0 };

    const run = limiter.chain.then(async () => {
        await waitForSendSlot(limiter);

        try {
            return await send();
        } finally {
            limiter.lastAt = Date.now();
            limiter.sentAt.push(limiter.lastAt);
        }
    });

    limiter.chain = run.catch(() => {});

    return run;
}

/**
 * True once the HTTP caller has hung up. Laravel retries a send it gave
 * up on, so a queued send whose caller left must not go out as well.
 */
function callerGone(res) {
    let gone = false;

    res.on('close', () => {
        if (!res.writableFinished) gone = true;
    });

    return () => gone;
}

const LOCK_FILE = path.join(__dirname, 'whatsapp-bot.lock');

function isPidAlive(pid) {
    try {
        process.kill(pid, 0);
        return true;
    } catch {
        return false;
    }
}

function acquireSingleInstanceLock() {
    if (fs.existsSync(LOCK_FILE)) {
        const existingPid = parseInt(fs.readFileSync(LOCK_FILE, 'utf8').trim(), 10);

        if (existingPid && isPidAlive(existingPid)) {
            console.error(`Another whatsapp-bot instance is already running (pid ${existingPid}). Exiting.`);
            process.exit(1);
        }
    }

    fs.writeFileSync(LOCK_FILE, String(process.pid));

    const releaseLock = () => {
        try {
            if (fs.existsSync(LOCK_FILE) && fs.readFileSync(LOCK_FILE, 'utf8').trim() === String(process.pid)) {
                fs.unlinkSync(LOCK_FILE);
            }
        } catch {}
    };

    process.on('exit', releaseLock);
    process.on('SIGINT', () => { releaseLock(); process.exit(0); });
    process.on('SIGTERM', () => { releaseLock(); process.exit(0); });
}

acquireSingleInstanceLock();

function checkToken(req, res, next) {
    const token = req.headers['x-bot-token'];

    if (token !== process.env.BOT_TOKEN) {
        return res.status(401).json({ error: 'Unauthorized' });
    }

    next();
}

function normalizeBotId(botId) {
    return String(botId || '').replace(/[^a-zA-Z0-9_-]/g, '');
}

/**
 * الشات بتاع بعض العملاء بيستخدم @lid (معرّف داخلي من واتساب للخصوصية)
 * بدل رقم الموبايل الحقيقي في remoteJid. بنحاول نلاقي الرقم الحقيقي
 * (@s.whatsapp.net) من msg.key.remoteJidAlt أو من جدول lid-mapping بتاع
 * Baileys نفسه، عشان يظهر صح في الداشبورد - من غير ما نغيّر الـ JID اللي
 * بنرد بيه على العميل (ده لازم يفضل زي ما هو).
 */
async function resolveCustomerJid(sock, originalFrom, msg) {
    try {
        const alt = msg?.key?.remoteJidAlt;

        if (alt && (alt.endsWith('@s.whatsapp.net') || alt.endsWith('@c.us'))) {
            return alt;
        }

        if (originalFrom && originalFrom.endsWith('@lid') && sock?.signalRepository?.lidMapping) {
            const pn = await sock.signalRepository.lidMapping.getPNForLID(originalFrom);

            if (pn) return pn;
        }
    } catch (error) {
        console.error('resolveCustomerJid error:', error?.message || error);
    }

    return null;
}

function getMessageText(msg) {
    return (
        msg.message?.conversation ||
        msg.message?.extendedTextMessage?.text ||
        msg.message?.imageMessage?.caption ||
        msg.message?.videoMessage?.caption ||
        msg.message?.documentMessage?.caption ||
        ''
    ).trim();
}

function getMediaInfo(msg) {
    if (msg.message?.imageMessage) {
        return {
            type: 'image',
            mime: msg.message.imageMessage.mimetype || 'image/jpeg',
            fileName: msg.message.imageMessage.fileName || null,
        };
    }

    if (msg.message?.videoMessage) {
        return {
            type: 'video',
            mime: msg.message.videoMessage.mimetype || 'video/mp4',
            fileName: msg.message.videoMessage.fileName || null,
        };
    }

    if (msg.message?.documentMessage) {
        return {
            type: 'document',
            mime: msg.message.documentMessage.mimetype || 'application/octet-stream',
            fileName: msg.message.documentMessage.fileName || null,
        };
    }

    if (msg.message?.audioMessage) {
        return {
            type: 'audio',
            mime: msg.message.audioMessage.mimetype || 'audio/ogg; codecs=opus',
            fileName: null,
            ptt: Boolean(msg.message.audioMessage.ptt),
        };
    }

    if (msg.message?.stickerMessage) {
        return {
            type: 'sticker',
            mime: msg.message.stickerMessage.mimetype || 'image/webp',
            fileName: null,
        };
    }

    return null;
}

/**
 * Message "type" for the v2 ingestion payload (plan T04 §1). Distinct from
 * getMediaInfo()'s media_type: a location message has no media at all.
 */
function getMessageType(msg) {
    if (msg.message?.conversation || msg.message?.extendedTextMessage) return 'text';
    if (msg.message?.imageMessage) return 'image';
    if (msg.message?.videoMessage) return 'video';
    if (msg.message?.documentMessage) return 'document';
    if (msg.message?.audioMessage) return 'audio';
    if (msg.message?.stickerMessage) return 'sticker';
    if (msg.message?.locationMessage) return 'location';

    return 'unknown';
}

function getLocationInfo(msg) {
    const loc = msg.message?.locationMessage;

    if (!loc) return null;

    return {
        latitude: loc.degreesLatitude ?? null,
        longitude: loc.degreesLongitude ?? null,
        name: loc.name || null,
        address: loc.address || null,
    };
}

/**
 * Quoted message reference for the v2 payload: the wa_message_id convention
 * is "<botId>_<stanzaId>", matching how we build our own ids on ingestion.
 */
function getQuotedInfo(msg, botId) {
    const contextInfo = msg.message?.extendedTextMessage?.contextInfo
        || msg.message?.imageMessage?.contextInfo
        || msg.message?.videoMessage?.contextInfo
        || msg.message?.documentMessage?.contextInfo
        || msg.message?.audioMessage?.contextInfo;

    const stanzaId = contextInfo?.stanzaId;

    if (!stanzaId) return null;

    const quotedMessage = contextInfo.quotedMessage || {};
    const type = quotedMessage.conversation || quotedMessage.extendedTextMessage
        ? 'text'
        : quotedMessage.imageMessage ? 'image'
        : quotedMessage.videoMessage ? 'video'
        : quotedMessage.documentMessage ? 'document'
        : quotedMessage.audioMessage ? 'audio'
        : 'unknown';

    return {
        wa_message_id: `${botId}_${stanzaId}`,
        type,
        text: (quotedMessage.conversation || quotedMessage.extendedTextMessage?.text || '').trim() || null,
    };
}

async function extractMediaBase64(msg) {
    const mediaInfo = getMediaInfo(msg);

    if (!mediaInfo) return null;

    try {
        const buffer = await downloadMediaMessage(
            msg,
            'buffer',
            {},
            { logger: pino({ level: 'silent' }) }
        );

        if (!buffer?.length) return null;

        return {
            media_type: mediaInfo.type,
            mime: mediaInfo.mime,
            filename: mediaInfo.fileName,
            base64: buffer.toString('base64'),
            size: buffer.length,
        };
    } catch (error) {
        console.error('download media error:', error?.message || error);
        return null;
    }
}

function typingMs(text) {
    const base = Math.min(TYPING_MAX_MS, Math.max(TYPING_MIN_MS, text.length * TYPING_MS_PER_CHAR));

    return base * randomBetween(0.8, 1.2);
}

// No 'paused' afterwards: the message itself ends the typing indicator,
// and the send may still wait in the pacing queue.
async function showTyping(sock, jid, ms) {
    try {
        await sock.sendPresenceUpdate('composing', jid);
        await sleep(ms);
    } catch {}
}

function cleanReplyText(text) {
    return String(text || '')
        .replace(/Analyzing image/giu, 'دقيقة يا فندم، جاري مراجعة البيانات')
        .trim();
}

/**
 * Returns the sent Baileys message (so callers can read its WhatsApp id
 * and cache it for later quoting), or null on failure.
 */
async function sendTextSafely(sock, jid, text, quotedMsg = null, isCancelled = () => false) {
    try {
        const cleanText = cleanReplyText(text);

        if (!cleanText) return null;

        console.log(`📨 Sending WhatsApp reply to ${jid}: ${cleanText}`);

        await showTyping(sock, jid, typingMs(cleanText));

        try {
            const sent = await sock.sendMessage(
                jid,
                { text: cleanText },
                quotedMsg ? { quoted: quotedMsg, isCancelled } : { isCancelled }
            );

            console.log(`✅ WhatsApp reply sent with quote to ${jid}`);
            return sent;
        } catch (quoteError) {
            console.error('⚠️ send with quote failed, retry without quote:', quoteError?.message || quoteError);

            if (isCancelled()) throw quoteError;

            const sent = await sock.sendMessage(jid, { text: cleanText }, { isCancelled });

            console.log(`✅ WhatsApp reply sent without quote to ${jid}`);
            return sent;
        }
    } catch (error) {
        console.error('❌ sendTextSafely failed:', error?.message || error);
        return null;
    }
}

async function sendImageSafely(sock, jid, image, caption = '', quotedMsg = null) {
    try {
        const sent = await sock.sendMessage(
            jid,
            {
                image: { url: image },
                caption: caption || '',
            },
            quotedMsg ? { quoted: quotedMsg } : {}
        );

        return sent;
    } catch (error) {
        console.error('sendImageSafely:', error?.message || error);
        return null;
    }
}

function enqueueChat(chatKey, job) {
    if (!chatQueues[chatKey]) {
        chatQueues[chatKey] = Promise.resolve();
    }

    chatQueues[chatKey] = chatQueues[chatKey]
        .then(job)
        .catch(error => {
            console.error(`queue error for ${chatKey}:`, error?.message || error);
        });

    return chatQueues[chatKey];
}

const LARAVEL_RETRY_ATTEMPTS = Number(process.env.LARAVEL_RETRY_ATTEMPTS || 3);
const LARAVEL_RETRY_BASE_MS = Number(process.env.LARAVEL_RETRY_BASE_MS || 1000);

async function postToLaravel(payload) {
    return axios.post(
        process.env.LARAVEL_WEBHOOK_URL,
        payload,
        {
            headers: {
                'X-BOT-TOKEN': process.env.BOT_TOKEN,
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            timeout: LARAVEL_TIMEOUT,
            maxBodyLength: Infinity,
            maxContentLength: Infinity,
        }
    );
}

/**
 * Laravel's response is only ever {ok, duplicate} now - it never carries a
 * reply (T04: replies come only through /send-message and
 * /send-media-items). A network error or 5xx here is retried with
 * exponential backoff so a message isn't silently lost.
 */
async function postToLaravelWithRetry(payload) {
    let lastError = null;

    for (let attempt = 1; attempt <= LARAVEL_RETRY_ATTEMPTS; attempt++) {
        try {
            return await postToLaravel(payload);
        } catch (error) {
            lastError = error;
            const status = error?.response?.status;
            const isRetryable = !status || status >= 500;

            if (!isRetryable || attempt === LARAVEL_RETRY_ATTEMPTS) {
                break;
            }

            await sleep(LARAVEL_RETRY_BASE_MS * (2 ** (attempt - 1)));
        }
    }

    throw lastError;
}

async function handleIncomingMessage(sock, botId, msg) {
    const originalFrom = msg.key.remoteJid;

    if (!originalFrom || originalFrom === 'status@broadcast' || originalFrom.endsWith('@g.us')) {
        return;
    }

    const isFromMe = Boolean(msg.key.fromMe);

    if (isFromMe && botSentIds.has(msg.key.id)) return;
    const cleanText = getMessageText(msg);
    const media = await extractMediaBase64(msg);
    const type = getMessageType(msg);
    const location = getLocationInfo(msg);

    if (!cleanText && !media && !location) return;

    const messageId = `${botId}_${msg.key.id}`;

    if (handledMessages.has(messageId)) return;

    handledMessages.add(messageId);

    if (handledMessages.size > 5000) {
        handledMessages.clear();
    }

    console.log(`📩 ${isFromMe ? '[fromMe] ' : ''}${originalFrom}: ${cleanText || `[${type}]`}`);

    // A read receipt in the same instant the message lands is a bot tell.
    if (!isFromMe) {
        setTimeout(() => {
            sock.readMessages([msg.key]).catch(() => {});
        }, randomBetween(READ_DELAY_MIN_MS, READ_DELAY_MAX_MS));
    }

    recentRawMessages.set(messageId, msg);

    if (recentRawMessages.size > 2000) {
        recentRawMessages.clear();
    }

    try {
        const customerJid = isFromMe ? null : await resolveCustomerJid(sock, originalFrom, msg);

        const payload = {
            bot_id: botId,
            wa_message_id: messageId,
            chat_jid: originalFrom,
            customer_jid: customerJid,
            push_name: msg.pushName || null,
            timestamp: Number(msg.messageTimestamp) || Math.floor(Date.now() / 1000),
            type,
            text: cleanText || null,
            media: media ? [media] : [],
            location,
            quoted: getQuotedInfo(msg, botId),
            // DEC-17: staff replying from the phone itself, not the bot.
            direction: isFromMe ? 'outgoing' : 'incoming',
        };

        console.log(`📤 Sending to Laravel (${type}):`, cleanText || `[${type}]`);

        const response = await postToLaravelWithRetry(payload);

        console.log('📥 Laravel Response:', JSON.stringify(response.data));
    } catch (error) {
        console.error('Laravel Error:', error?.response?.data || error?.message || error);
    }
}

function archiveLoggedOutSession(botId) {
    const dir = path.join(__dirname, 'sessions', String(botId));

    if (!fs.existsSync(dir)) return;

    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const target = path.join(__dirname, 'sessions', `${botId}.loggedout-${stamp}`);

    try {
        fs.renameSync(dir, target);
        console.warn(`Session ${botId} was logged out - credentials moved to ${target}. Start the session again to get a new QR.`);
    } catch (error) {
        console.error(`Could not archive logged-out session ${botId}:`, error?.message || error);
    }
}

async function startSession(botId) {
    botId = normalizeBotId(botId);

    if (!botId) {
        throw new Error('bot_id required');
    }

    if (starting[botId] || sessions[botId]) {
        return;
    }

    starting[botId] = true;
    statuses[botId] = 'starting';

    const { state, saveCreds } = await useMultiFileAuthState(`./sessions/${botId}`);
    const { version } = await fetchLatestBaileysVersion();

    const sock = makeWASocket({
        version,
        auth: state,
        logger: pino({ level: LOG_LEVEL }),
        printQRInTerminal: false,
        // An ordinary desktop Chrome fingerprint; a custom OS name marks the
        // device as unofficial. Only read when a QR is scanned, so already
        // linked sessions are unaffected until they re-link.
        browser: Browsers.macOS('Chrome'),
        syncFullHistory: false,
        markOnlineOnConnect: false,
    });

    const rawSendMessage = sock.sendMessage.bind(sock);
    sock.sendMessage = (jid, content, options = {}) => {
        const { isCancelled, ...sendOptions } = options;

        return throttleSend(botId, () => {
            if (isCancelled?.()) {
                throw new Error('send cancelled: caller stopped waiting');
            }

            const messageId = sendOptions.messageId || generateMessageIDV2(sock.user?.id);

            botSentIds.add(messageId);

            if (botSentIds.size > 5000) {
                botSentIds.delete(botSentIds.values().next().value);
            }

            return rawSendMessage(jid, content, { ...sendOptions, messageId });
        });
    };

    sessions[botId] = sock;

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async update => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            latestQr[botId] = await QRCode.toDataURL(qr);
            statuses[botId] = 'qr';
        }

        if (connection === 'open') {
            latestQr[botId] = null;
            statuses[botId] = 'connected';
            starting[botId] = false;

            console.log(`✅ Bot ${botId} connected`);
        }

        if (connection === 'close') {
            starting[botId] = false;

            const statusCode = lastDisconnect?.error?.output?.statusCode;
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

            console.error('WhatsApp connection closed', {
                botId,
                statusCode,
                shouldReconnect,
                error: lastDisconnect?.error?.message || lastDisconnect?.error || null,
            });

            delete sessions[botId];

            statuses[botId] = shouldReconnect ? 'disconnected' : 'logged_out';

            // Logged out = these credentials are dead. Kept as they were,
            // every restart reused them, was logged out again and never
            // showed a QR - the only visible way out was deleting the bot,
            // which cascades to every customer and application. Move them
            // aside (never deleted) so the next start asks for a new QR.
            if (!shouldReconnect) {
                archiveLoggedOutSession(botId);
            }

            if (shouldReconnect) {
                setTimeout(() => {
                    startSession(botId).catch(error => {
                        console.error(`restart session error ${botId}:`, error?.message || error);
                    });
                }, 3000);
            }
        }
    });

    sock.ev.on('messages.upsert', async ({ messages, type }) => {
        try {
            if (!['notify', 'append'].includes(type)) return;

            for (const msg of messages) {
                if (!msg?.message) continue;
const chatKey = `${botId}_${msg.key.remoteJid || 'unknown'}`;

enqueueChat(
    chatKey,
    () => handleIncomingMessage(sock, botId, msg)
);
            }
        } catch (error) {
            console.error('messages.upsert error:', error?.message || error);
        }
    });
}

app.post('/sessions/start', checkToken, async (req, res) => {
    try {
        const botId = normalizeBotId(req.body.bot_id);

        await startSession(botId);

        res.json({
            success: true,
            bot_id: botId,
            status: statuses[botId] || 'starting',
            qr: latestQr[botId] || null,
        });
    } catch (error) {
        res.status(500).json({
            error: error.message || 'start failed',
        });
    }
});

app.get('/status', checkToken, (req, res) => {
    res.json({
        ok: true,
        sessions: Object.keys(statuses).map(botId => ({
            bot_id: botId,
            status: statuses[botId],
            has_qr: !!latestQr[botId],
            connected: statuses[botId] === 'connected',
        })),
    });
});

app.get('/sessions/:botId/qr', checkToken, (req, res) => {
    const botId = normalizeBotId(req.params.botId);

    res.json({
        bot_id: botId,
        status: statuses[botId] || 'not_started',
        qr: latestQr[botId] || null,
    });
});

app.get('/sessions/:botId/status', checkToken, (req, res) => {
    const botId = normalizeBotId(req.params.botId);

    res.json({
        bot_id: botId,
        status: statuses[botId] || 'not_started',
        has_qr: !!latestQr[botId],
        connected: statuses[botId] === 'connected',
    });
});



app.post('/chats/archive', checkToken, async (req, res) => {
    try {
        const botId = normalizeBotId(req.body.bot_id);
        const jid = req.body.jid;
        const archive = req.body.archive !== false;

        if (!botId || !jid) {
            return res.status(422).json({ ok: false, error: 'bot_id, jid required' });
        }

        const sock = sessions[botId];

        if (!sock) {
            return res.status(404).json({ ok: false, error: 'session not found', bot_id: botId });
        }

        await sock.chatModify({ archive, lastMessages: [] }, jid);

        return res.json({ ok: true, bot_id: botId, jid, archived: archive });
    } catch (error) {
        console.error('ARCHIVE CHAT ENDPOINT ERROR:', error?.message || error);

        return res.status(500).json({ ok: false, error: error?.message || 'archive failed' });
    }
});

app.post('/send-message', checkToken, async (req, res) => {
    try {
        const botId = normalizeBotId(req.body.bot_id);
        const jid = req.body.jid;
        const text = req.body.message || req.body.text;

        if (!botId || !jid || !text) {
            return res.status(422).json({
                ok: false,
                error: 'bot_id, jid, message required',
            });
        }

        const sock = sessions[botId];

        if (!sock) {
            return res.status(404).json({
                ok: false,
                error: 'session not found',
                bot_id: botId,
            });
        }

        const quotedMsg = req.body.quoted_message
            ? recentRawMessages.get(req.body.quoted_message) || null
            : null;

        const sent = await sendTextSafely(sock, jid, text, quotedMsg, callerGone(res));
        const waMessageId = sent?.key?.id ? `${botId}_${sent.key.id}` : null;

        if (sent && waMessageId) {
            recentRawMessages.set(waMessageId, sent);
        }

        return res.json({
            ok: Boolean(sent),
            bot_id: botId,
            jid,
            wa_message_id: waMessageId,
        });
    } catch (error) {
        console.error('SEND MESSAGE ENDPOINT ERROR:', error?.message || error);

        return res.status(500).json({
            ok: false,
            error: error?.message || 'send failed',
        });
    }
});
app.post('/send-media-items', checkToken, async (req, res) => {
    try {
        const botId = normalizeBotId(req.body.bot_id);
        const jid = req.body.jid;
        const mediaItems = Array.isArray(req.body.media_items) ? req.body.media_items : [];

        if (!botId || !jid || !mediaItems.length) {
            return res.status(422).json({ ok: false, error: 'bot_id, jid, media_items required' });
        }

        const sock = sessions[botId];

        if (!sock) {
            return res.status(404).json({ ok: false, error: 'session not found', bot_id: botId });
        }

        const results = [];
        const isCancelled = callerGone(res);

        for (const item of mediaItems) {
            const url = item.url || item.media_url || item.image || item.path;
            const type = String(item.type || item.media_type || 'image').toLowerCase();
            const mime = String(item.mime || item.media_mime || '').toLowerCase();
            const caption = item.caption || '';
            const filename = item.filename || item.media_filename || 'file';

            if (!url) {
                results.push({ ok: false, error: 'missing url', item });
                continue;
            }

            let payload;

            if (type === 'audio' || mime.startsWith('audio/')) {
                payload = { audio: { url }, mimetype: mime || 'audio/ogg; codecs=opus', ptt: true };
            } else if (type === 'video' || mime.startsWith('video/')) {
                payload = { video: { url }, caption };
            } else if (type === 'document' || type === 'file' || mime.includes('pdf') || mime.startsWith('application/')) {
                payload = {
                    document: { url },
                    mimetype: mime || 'application/octet-stream',
                    fileName: filename,
                    caption,
                };
            } else {
                payload = { image: { url }, caption };
            }

            try {
                const sent = await sock.sendMessage(jid, payload, { isCancelled });
                const waMessageId = sent?.key?.id ? `${botId}_${sent.key.id}` : null;

                if (sent && waMessageId) {
                    recentRawMessages.set(waMessageId, sent);
                }

                results.push({ ok: true, url, type, filename, wa_message_id: waMessageId });
            } catch (e) {
                results.push({ ok: false, url, error: e?.message || String(e) });
            }

            // The gap between photos comes from the per-number pacing.
        }

        return res.json({
            ok: results.some(r => r.ok),
            sent_count: results.filter(r => r.ok).length,
            total: results.length,
            wa_message_ids: results.filter(r => r.ok).map(r => r.wa_message_id),
            results,
        });
    } catch (error) {
        return res.status(500).json({
            ok: false,
            error: error?.message || 'send media items failed',
        });
    }
});
app.listen(PORT, () => {
    console.log(`🚀 WhatsApp Worker Running ${PORT}`);

    fetchLatestActiveBotId().then(({ latest, active }) => {
        const linked = active.filter(isLinkedSession);
        const toStart = linked.length ? linked : (latest ? [latest] : []);

        if (!toStart.length) {
            console.log('⚠️ No active WhatsApp bot found to auto-start.');
            return;
        }

        for (const botId of toStart) {
            console.log(`🔄 Auto-starting ${linked.length ? 'linked' : 'latest active'} bot: ${botId}`);

            startSession(botId).catch(error => {
                console.error('AUTO START WHATSAPP SESSION ERROR:', error?.stack || error);
            });
        }
    });
});
