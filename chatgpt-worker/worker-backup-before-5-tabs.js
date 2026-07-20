const express = require('express');
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const app = express();
app.use(express.json({ limit: '200mb' }));

const PORT = process.env.PORT || 3005;
const PROFILE_DIR = process.env.CHATGPT_PROFILE_DIR || '/home/elhawy/chatgpt-profile';
const GPT_BASE_URL = process.env.CHATGPT_PROJECT_URL || 'https://chatgpt.com/g/g-p-6a08bca4a7b88191b7f5674d811dc402-motocyklaty-ai-sales-agent';
const STORE_FILE = process.env.CHATGPT_STORE_FILE || path.join(__dirname, 'chatgpt-conversations.json');

const ASSISTANT_SELECTOR = '[data-message-author-role="assistant"]';

let context;
let conversations = {};
let pages = {};

const pageLocks = {};
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}
function enqueuePage(key, job) {
    if (!pageLocks[key]) {
        pageLocks[key] = Promise.resolve();
    }

    const run = pageLocks[key].then(job, job);

    pageLocks[key] = run.catch(() => {});

    return run;
}

function loadStore() {
    try {
        if (fs.existsSync(STORE_FILE)) {
            conversations = JSON.parse(fs.readFileSync(STORE_FILE, 'utf8'));
        }
    } catch {
        conversations = {};
    }
}

function saveStore() {
    try {
        fs.writeFileSync(STORE_FILE, JSON.stringify(conversations, null, 2), 'utf8');
    } catch (e) {
        console.error('save store error:', e.message || e);
    }
}

function cleanBaseUrl(url) {
    return String(url || '').replace(/\/c\/[^/?#]+.*$/, '');
}

function normalizeKey(key) {
    return String(key || 'default')
        .replace(/[^a-zA-Z0-9_@.+-]/g, '_')
        .slice(0, 160);
}

function extractPhone(key) {
    const text = String(key || '');
    const match = text.match(/customer_([0-9]+)/);

    return match ? match[1] : text.replace(/\D/g, '') || text;
}

function cleanReply(reply) {
    return String(reply || '')
        .replace(/Pasted\stext(.txt)?/giu, '')
        .replace(/Attached\sfile\s*:/giu, '')
        .replace(/Analyzing image/giu, 'دقيقة يا فندم، جاري مراجعة البيانات')
        .replace(/Analyzing images/giu, 'دقيقة يا فندم، جاري مراجعة البيانات')
        .replace(/تمام،?\sاستلمت رسالتك.$/giu, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function buildMessageWithMemory(message, memoryPrompt, isFirstMessage, phone, mediaCount = 0) {
    const customerMessage = String(message || '').trim();
    const memory = String(memoryPrompt || '').trim();

    const mediaText = mediaCount > 0
        ? `

مع الرسالة دي فيه ${mediaCount} صورة/ملف مرفق من العميل.
اقرأ كل المرفقات بنفسك واستخرج البيانات المهمة منها.
لو بطاقة مصرية:
- استخرج الاسم.
- استخرج الرقم القومي.
- استخرج العنوان.
- استخرج تاريخ الميلاد من الرقم القومي.
- احسب السن من تاريخ الميلاد الموجود داخل الرقم القومي.
لو العميل قال سن مختلف، اعتمد السن المحسوب من الرقم القومي فقط.
لو الصورة غير واضحة قول للعميل يصورها بشكل أوضح.`
        : '';

    if (!isFirstMessage) {
        return `رسالة العميل:
${customerMessage || 'العميل أرسل صورة أو مستند.'}
${mediaText}`;
    }

    return `${memory}

رقم العميل: ${phone}
عنوان الدردشة: ${phone}

دي أول رسالة من العميل.
لازم ترد على رسالة العميل الموجودة تحت فقط.
ممنوع تقول للعميل إنك استلمت ملف أو ميموري أو تعليمات.
ممنوع تذكر Pasted text أو اسم أي ملف.
ممنوع تشرح إنك راجعت المحتوى.

${mediaText}

رسالة العميل:
${customerMessage || 'العميل أرسل صورة أو مستند.'}`;
}

async function isJustMoment(page) {
    const title = await page.title().catch(() => '');
    return title.includes('Just a moment');
}

async function findComposer(page) {
    const selectors = [
        'textarea[placeholder*="Message"]',
        'textarea[placeholder*="New chat"]',
        'textarea[placeholder*="دردشة"]',
        'textarea[placeholder*="رسالة"]',
        'textarea',
        '[contenteditable="true"]',
        '[role="textbox"]',
    ];

    for (const selector of selectors) {
        const count = await page.locator(selector).count().catch(() => 0);

        for (let i = count - 1; i >= 0; i--) {
            const locator = page.locator(selector).nth(i);

            const ok = await locator.evaluate(el => {
                const r = el.getBoundingClientRect();
                const s = window.getComputedStyle(el);

                return (
                    r.width > 100 &&
                    r.height > 20 &&
                    s.display !== 'none' &&
                    s.visibility !== 'hidden' &&
                    s.opacity !== '0'
                );
            }).catch(() => false);

            if (ok) return locator;
        }
    }

    return null;
}

async function waitUntilChatGPTReady(page, timeoutMs = 120000) {
    const startedAt = Date.now();

    while (Date.now() - startedAt < timeoutMs) {
        const title = await page.title().catch(() => '');

        if (title.includes('Just a moment')) {
            console.log('⏳ ChatGPT Just a moment...');
            await sleep(5000);
            continue;
        }

        const composer = await findComposer(page).catch(() => null);

        if (composer) return true;

        await sleep(3000);
    }

    console.log('CURRENT URL:', page.url());
    console.log('PAGE TITLE:', await page.title().catch(() => ''));

    await page.screenshot({
        path: `chatgpt-not-ready-${Date.now()}.png`,
        fullPage: true,
    }).catch(() => {});

    throw new Error('ChatGPT page not ready');
}

async function init() {
    loadStore();

    context = await chromium.launchPersistentContext(PROFILE_DIR, {
        headless: false,
        executablePath: '/usr/bin/google-chrome',
        viewport: { width: 1400, height: 900 },
        args: [
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-blink-features=AutomationControlled',
            '--disable-features=IsolateOrigins,site-per-process',
        ],
    });

    const page = context.pages()[0] || await context.newPage();

    await page.goto(cleanBaseUrl(GPT_BASE_URL), {
        waitUntil: 'domcontentloaded',
        timeout: 180000,
    });

    await waitUntilChatGPTReady(page, 180000);

    console.log('✅ ChatGPT worker ready');
}

async function getPageForCustomer(key) {
    key = normalizeKey(key);

    if (pages[key] && !pages[key].isClosed()) {
        if (!(await isJustMoment(pages[key]))) {
            await waitUntilChatGPTReady(pages[key], 90000);
            return pages[key];
        }

        await pages[key].close().catch(() => {});
        delete pages[key];
    }

    const page = await context.newPage();

    page.setDefaultTimeout(300000);
    page.setDefaultNavigationTimeout(300000);

    const savedUrl = conversations[key]?.url;
    const targetUrl = savedUrl || cleanBaseUrl(GPT_BASE_URL);

    await page.goto(targetUrl, {
        waitUntil: 'domcontentloaded',
        timeout: 180000,
    });

    await waitUntilChatGPTReady(page, 180000);

    pages[key] = page;

    return page;
}

async function getAssistantMessages(page) {
    const texts = await page.locator(ASSISTANT_SELECTOR).allTextContents().catch(() => []);

    return texts
        .map(t => String(t || '').trim())
        .filter(Boolean);
}

async function getComposer(page, timeoutMs = 90000) {
    await page.waitForLoadState('domcontentloaded').catch(() => {});

    const startedAt = Date.now();

    while (Date.now() - startedAt < timeoutMs) {
        if (await isJustMoment(page)) {
            console.log('⏳ Waiting Just a moment before composer...');
            await sleep(5000);
            continue;
        }

        const composer = await findComposer(page);

        if (composer) return composer;

        await sleep(1500);
    }

    await page.screenshot({
        path: `composer-not-found-${Date.now()}.png`,
        fullPage: true,
    }).catch(() => {});

    console.log('CURRENT URL:', page.url());
    console.log('PAGE TITLE:', await page.title().catch(() => ''));

    throw new Error('Visible composer not found');
}

async function fillComposer(page, message) {
    const composer = await getComposer(page);
    const text = String(message || '');

    await composer.click({ timeout: 30000 });
    await sleep(300);

    await page.keyboard.press('Control+A');
    await page.keyboard.press('Backspace');
    await sleep(200);

    await composer.evaluate((el, value) => {
        el.focus();

        if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
            el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }

        document.execCommand('selectAll', false, null);
        document.execCommand('delete', false, null);
        document.execCommand('insertText', false, value);

        el.dispatchEvent(new InputEvent('input', {
            bubbles: true,
            inputType: 'insertText',
            data: value,
        }));
    }, text);

    await sleep(1000);
}

function normalizeMediaPaths(mediaPaths, mediaItems, mediaPath) {
    const paths = [];

    if (Array.isArray(mediaPaths)) {
        for (const p of mediaPaths) {
            if (p) paths.push(String(p));
        }
    }

    if (Array.isArray(mediaItems)) {
        for (const item of mediaItems) {
            if (item?.media_path) paths.push(String(item.media_path));
            if (item?.path) paths.push(String(item.path));
        }
    }

    if (mediaPath) {
        paths.push(String(mediaPath));
    }

    return [...new Set(paths)].filter(Boolean);
}

async function uploadMediaFiles(page, mediaPaths = []) {
    const validPaths = mediaPaths.filter(p => fs.existsSync(p));

    if (!validPaths.length) return 0;

    console.log(`📎 Uploading ${validPaths.length} media file(s) to ChatGPT`);

    let fileInput = page.locator('input[type="file"]').last();

    if (!(await fileInput.count().catch(() => 0))) {
        const attachSelectors = [
            'button[aria-label*="Attach"]',
            'button[aria-label*="Upload"]',
            'button[aria-label*="إرفاق"]',
            'button[aria-label*="تحميل"]',
            'button:has-text("+")',
        ];

        for (const selector of attachSelectors) {
            const btn = page.locator(selector).first();

            if (await btn.count().catch(() => 0)) {
                await btn.click().catch(() => {});
                await sleep(1000);
                break;
            }
        }
    }

    fileInput = page.locator('input[type="file"]').last();

    if (!(await fileInput.count().catch(() => 0))) {
        await page.screenshot({
            path: `file-input-not-found-${Date.now()}.png`,
            fullPage: true,
        }).catch(() => {});

        throw new Error('file input not found');
    }

    await fileInput.setInputFiles(validPaths);
    await sleep(9000);

    console.log('✅ Media uploaded');

    return validPaths.length;
}

async function clickSend(page) {
    for (let i = 0; i < 30; i++) {
        const sendButton = page.locator('button[data-testid="send-button"]').last();

        if (await sendButton.isVisible().catch(() => false)) {
            await sendButton.click().catch(async () => {
                await page.keyboard.press('Enter');
            });

            await sleep(1000);
            return;
        }

        await sleep(500);
    }

    await page.keyboard.press('Enter');
    await sleep(1000);
}

async function sendMessage(page, message, mediaPaths = []) {
    await getComposer(page);

    if (mediaPaths.length) {
        await uploadMediaFiles(page, mediaPaths);
    }

    await fillComposer(page, message);
    await clickSend(page);
}
async function waitForAssistantReply(page, beforeCount, beforeLast, timeoutMs = 480000) {
    const startedAt = Date.now();

    let lastText = '';
    let stableTimes = 0;

    while (Date.now() - startedAt < timeoutMs) {
        await sleep(1500);

        const stopVisible = await page
            .locator('button[data-testid="stop-button"], button[aria-label*="Stop"], button[aria-label*="إيقاف"]')
            .last()
            .isVisible()
            .catch(() => false);

        const messages = await getAssistantMessages(page);
        const current = String(messages[messages.length - 1] || '').trim();

        if (!current) continue;

        if (messages.length <= beforeCount && current === beforeLast) {
            continue;
        }

        const cleaned = cleanReply(current);

        if (!cleaned) continue;
        if (cleaned === 'دقيقة يا فندم، جاري مراجعة البيانات') continue;

        if (cleaned === lastText) {
            stableTimes++;
        } else {
            stableTimes = 0;
            lastText = cleaned;
        }

        console.log('🤖 Reply:', cleaned);

        if (!stopVisible && stableTimes >= 2) {
            console.log('✅ Final Reply:', cleaned);
            return cleaned;
        }

        if (stableTimes >= 5) {
            console.log('✅ Stable Reply:', cleaned);
            return cleaned;
        }
    }

    if (lastText) {
        console.log('⚠️ Timeout but returning last reply:', lastText);
        return lastText;
    }

    await page.screenshot({
        path: `response-timeout-${Date.now()}.png`,
        fullPage: true,
    }).catch(() => {});

    throw new Error('response timeout');
}

async function askChatGPT(message, conversationKey, memoryPrompt = '', mediaPaths = []) {
    const key = normalizeKey(conversationKey);

    return enqueuePage(key, async () => {
        const phone = extractPhone(key);
        const customerMessage = String(message || '').trim();

        if (!customerMessage && !mediaPaths.length) {
            throw new Error('empty customer message');
        }

        const page = await getPageForCustomer(key);
        await getComposer(page);

        const isFirstMessage = !conversations[key]?.memory_sent;

        const finalMessage = buildMessageWithMemory(
            customerMessage,
            memoryPrompt,
            isFirstMessage,
            phone,
            mediaPaths.length
        );

        const before = await getAssistantMessages(page);
        const beforeCount = before.length;
        const beforeLast = before[before.length - 1] || '';

        console.log(`📨 Sending to ChatGPT key=${key} phone=${phone} first=${isFirstMessage} media_count=${mediaPaths.length}`);
        console.log(`📨 Customer message: ${customerMessage || '[MEDIA]'}`);

        await sendMessage(page, finalMessage, mediaPaths);

        // مهم: احفظ إن الميموري اتبعت بعد الإرسال مباشرة
        // عشان لو حصل timeout مايعيدش أول رسالة بالميموري تاني
        conversations[key] = {
            ...(conversations[key] || {}),
            url: page.url(),
            phone,
            memory_sent: true,
            memory_sent_at: conversations[key]?.memory_sent_at || new Date().toISOString(),
            updated_at: new Date().toISOString(),
        };

        saveStore();

        const replyTimeout = mediaPaths.length ? 600000 : (isFirstMessage ? 600000 : 420000);

        const reply = await waitForAssistantReply(page, beforeCount, beforeLast, replyTimeout);

        conversations[key] = {
            ...(conversations[key] || {}),
            url: page.url(),
            phone,
            memory_sent: true,
            memory_sent_at: conversations[key]?.memory_sent_at || new Date().toISOString(),
            updated_at: new Date().toISOString(),
        };

        saveStore();

        return reply;
    });
}


app.get('/health', (req, res) => {
    res.json({
        ok: true,
        project_url: cleanBaseUrl(GPT_BASE_URL),
        conversations_count: Object.keys(conversations).length,
        opened_pages: Object.keys(pages).length,
    });
});

app.post('/chat', async (req, res) => {
    const message = String(req.body.message || '').trim();
    const memoryPrompt = String(req.body.memory_prompt || '').trim();

    const mediaPaths = normalizeMediaPaths(
        req.body.media_paths,
        req.body.media_items,
        req.body.media_path
    );

    const conversationKey = normalizeKey(
        req.body.conversation_key ||
        req.body.customer_key ||
        req.body.phone ||
        'default'
    );

    if (!message && !mediaPaths.length) {
        return res.status(422).json({
            ok: false,
            error: 'message or media required',
        });
    }

    try {
const reply = await askChatGPT(message, conversationKey, memoryPrompt, mediaPaths);

        return res.json({
            ok: true,
            conversation_key: conversationKey,
            media_count: mediaPaths.length,
            reply,
        });
    } catch (e) {
        console.error('chat error:', e);

        return res.status(500).json({
            ok: false,
            error: e.message || 'failed',
        });
    }
});

app.post('/chat/:key/reset', async (req, res) => {
    const key = normalizeKey(req.params.key);

    delete conversations[key];

    if (pages[key] && !pages[key].isClosed()) {
        await pages[key].close().catch(() => {});
    }

    delete pages[key];


    saveStore();

    res.json({
        ok: true,
        reset: key,
    });
});

app.post('/reset-all', async (req, res) => {
    conversations = {};


    for (const key of Object.keys(pages)) {
        if (pages[key] && !pages[key].isClosed()) {
            await pages[key].close().catch(() => {});
        }
    }

    pages = {};

    saveStore();

    res.json({
        ok: true,
        reset: 'all',
    });
});

process.on('uncaughtException', error => {
    console.error('uncaughtException:', error);
});

process.on('unhandledRejection', error => {
    console.error('unhandledRejection:', error);
});

init()
    .then(() => {
        const server = app.listen(PORT, () => {
            console.log(`🚀 ChatGPT worker running on ${PORT}`);
        });

server.timeout = 700000;
server.requestTimeout = 700000;
server.headersTimeout = 710000;
server.keepAliveTimeout = 710000;
    })
    .catch(error => {
        console.error('init error:', error);
        process.exit(1);
    });
