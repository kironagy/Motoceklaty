const makeWASocket = require('@whiskeysockets/baileys').default;
const {
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
    downloadMediaMessage,
} = require('@whiskeysockets/baileys');

const pino = require('pino');
const axios = require('axios');
const express = require('express');
const QRCode = require('qrcode');

require('dotenv').config();

const app = express();
app.use(express.json({ limit: '200mb' }));

const sessions = {};
const latestQr = {};
const statuses = {};
const starting = {};
const handledMessages = new Set();
const mediaCollectors = {};
const chatQueues = {};

const PORT = process.env.PORT || 3010;
const LARAVEL_TIMEOUT = Number(process.env.LARAVEL_TIMEOUT || 180000);
const LOG_LEVEL = process.env.LOG_LEVEL || 'debug';
const AUTO_START_BOT_ID = process.env.AUTO_START_BOT_ID || '';

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

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

function normalizeJid(jid) {
    return jid || null;
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

    return null;
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
            media_mime: mediaInfo.mime,
            media_filename: mediaInfo.fileName,
            media_base64: buffer.toString('base64'),
            media_size: buffer.length,
        };
    } catch (error) {
        console.error('download media error:', error?.message || error);
        return null;
    }
}

async function showTyping(sock, jid, seconds = 1) {
    try {
        await sock.sendPresenceUpdate('composing', jid);
        await sleep(seconds * 1000);
        await sock.sendPresenceUpdate('paused', jid);
    } catch {}
}

function cleanReplyText(text) {
    return String(text || '')
        .replace(/Analyzing image/giu, 'دقيقة يا فندم، جاري مراجعة البيانات')
        .trim();
}

async function sendTextSafely(sock, jid, text, quotedMsg = null) {
    try {
        const cleanText = cleanReplyText(text);

        if (!cleanText) return false;

        console.log(`📨 Sending WhatsApp reply to ${jid}: ${cleanText}`);

        await showTyping(sock, jid, 1);

        try {
            await sock.sendMessage(
                jid,
                { text: cleanText },
                quotedMsg ? { quoted: quotedMsg } : {}
            );

            console.log(`✅ WhatsApp reply sent with quote to ${jid}`);
            return true;
        } catch (quoteError) {
            console.error('⚠️ send with quote failed, retry without quote:', quoteError?.message || quoteError);

            await sock.sendMessage(jid, { text: cleanText });

            console.log(`✅ WhatsApp reply sent without quote to ${jid}`);
            return true;
        }
    } catch (error) {
        console.error('❌ sendTextSafely failed:', error?.message || error);
        return false;
    }
}

async function sendImageSafely(sock, jid, image, caption = '', quotedMsg = null) {
    try {
        await sock.sendMessage(
            jid,
            {
                image: { url: image },
                caption: caption || '',
            },
            quotedMsg ? { quoted: quotedMsg } : {}
        );

        return true;
    } catch (error) {
        console.error('sendImageSafely:', error?.message || error);
        return false;
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

async function handleLaravelResponse(sock, replyJid, data, quotedMsg = null) {
console.log('📨 handleLaravelResponse', data);
    const reply = data?.reply || null;

    const imageItems = Array.isArray(data?.image_items) ? data.image_items : [];
    const images = Array.isArray(data?.images) ? data.images : [];

    if (reply) {
        await sendTextSafely(sock, replyJid, reply, quotedMsg);
    }

    if (imageItems.length) {
        for (const item of imageItems) {
            const caption = item.caption || item.machine_name || '';

            if (caption) {
                await sendTextSafely(sock, replyJid, `📌 ${caption}`, quotedMsg);
            }

            await sendImageSafely(sock, replyJid, item.url, '', quotedMsg);
            await sleep(500);
        }

        return;
    }

    if (images.length) {
        for (const image of images) {
            await sendImageSafely(sock, replyJid, image, '', quotedMsg);
            await sleep(500);
        }
    }
}

async function flushMediaCollector(sock, botId, originalFrom, replyJid, collector, quotedMsg) {
    try {
        const payload = {
            bot_id: botId,
            from: originalFrom,
            reply_jid: replyJid,
            message: collector.message || '',
            direction: 'incoming',
            media_items: collector.mediaItems,
        };

        console.log(`📤 Sending ${collector.mediaItems.length} media items to Laravel`);

        const response = await postToLaravel(payload);

        await handleLaravelResponse(sock, replyJid, response.data, quotedMsg);
    } catch (error) {
        console.error('flushMediaCollector error:', error?.response?.data || error?.message || error);
    }
}

async function handleIncomingMessage(sock, botId, msg) {
    const originalFrom = msg.key.remoteJid;
    const replyJid = normalizeJid(originalFrom);

    if (
        !originalFrom ||
        originalFrom === 'status@broadcast' ||
        originalFrom.endsWith('@g.us') ||
        msg.key.fromMe
    ) {
        return;
    }

    const cleanText = getMessageText(msg);
    const media = await extractMediaBase64(msg);

    if (!cleanText && !media) return;

    const messageId = `${botId}_${msg.key.id}`;

    if (handledMessages.has(messageId)) return;

    handledMessages.add(messageId);

    if (handledMessages.size > 5000) {
        handledMessages.clear();
    }

    console.log(`📩 ${originalFrom}: ${cleanText || '[MEDIA]'}`);

    try {
        await sock.readMessages([msg.key]);
    } catch {}

    const collectorKey = `${botId}_${originalFrom}`;

    if (media) {
        if (!mediaCollectors[collectorKey]) {
            mediaCollectors[collectorKey] = {
                mediaItems: [],
                message: cleanText || '',
                timer: null,
            };
        }

        mediaCollectors[collectorKey].mediaItems.push(media);

        if (cleanText) {
            mediaCollectors[collectorKey].message = cleanText;
        }

        clearTimeout(mediaCollectors[collectorKey].timer);

        mediaCollectors[collectorKey].timer = setTimeout(async () => {
            const collector = mediaCollectors[collectorKey];

            delete mediaCollectors[collectorKey];

            await flushMediaCollector(
                sock,
                botId,
                originalFrom,
                replyJid,
                collector,
                msg
            );
        }, 3000);

        return;
    }

    try {
        const payload = {
            bot_id: botId,
            from: originalFrom,
            reply_jid: replyJid,
            message: cleanText,
            direction: 'incoming',
        };
console.log('📤 Sending to Laravel:', cleanText);
        const response = await postToLaravel(payload);
console.log('📥 Laravel Response:', JSON.stringify(response.data));
        await handleLaravelResponse(sock, replyJid, response.data, msg);
    } catch (error) {
        console.error('Laravel Error:', error?.response?.data || error?.message || error);
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
        browser: [`Motocyklaty ${botId}`, 'Chrome', '1.0.0'],
        syncFullHistory: false,
        markOnlineOnConnect: false,
    });

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

        const sent = await sendTextSafely(sock, jid, text);

        return res.json({
            ok: sent,
            bot_id: botId,
            jid,
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

            if (type === 'video' || mime.startsWith('video/')) {
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
                await sock.sendMessage(jid, payload);
                results.push({ ok: true, url, type, filename });
            } catch (e) {
                results.push({ ok: false, url, error: e?.message || String(e) });
            }

            await sleep(700);
        }

        return res.json({
            ok: results.some(r => r.ok),
            sent_count: results.filter(r => r.ok).length,
            total: results.length,
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

    if (AUTO_START_BOT_ID) {
        startSession(AUTO_START_BOT_ID).catch(error => {
            console.error('AUTO START WHATSAPP SESSION ERROR:', error?.stack || error);
        });
    }
});
