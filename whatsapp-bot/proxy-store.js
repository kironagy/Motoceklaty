/*
 * Owner 2026-11-15: one proxy per number, so each number reaches WhatsApp from its own (Egyptian)
 * address instead of this server's. Set from the bots page (Laravel -> POST /sessions/:botId/proxy),
 * kept in proxies/<botId>.json (owner-only file, outside sessions/ so a relink keeps it).
 *
 *   http://user:pass@host:port    socket, uploads and downloads all go through it (recommended)
 *   socks5://user:pass@host:port  socket and uploads; Node's fetch has no SOCKS, so incoming media
 *                                 downloads go direct
 */
const fs = require('fs');
const path = require('path');
const { SocksProxyAgent } = require('socks-proxy-agent');
const { HttpsProxyAgent } = require('https-proxy-agent');
const { ProxyAgent } = require('undici');

const SCHEMES = ['http:', 'https:', 'socks5:', 'socks5h:', 'socks:'];

function parseProxy(raw) {
    const value = String(raw || '').trim();

    if (!value) return null;

    let url;
    try {
        url = new URL(value);
    } catch {
        throw new Error('proxy is not a valid URL (example: http://user:pass@host:port)');
    }

    if (!SCHEMES.includes(url.protocol)) throw new Error(`proxy scheme ${url.protocol} not supported`);
    if (!url.hostname || !url.port) throw new Error('proxy needs a host and a port');

    return url;
}

/** { agent, dispatcher, label } for one proxy URL, null without one. Never logs the password. */
function buildProxy(raw) {
    const url = parseProxy(raw);

    if (!url) return null;

    const socks = url.protocol.startsWith('socks');

    return {
        agent: socks ? new SocksProxyAgent(url.href) : new HttpsProxyAgent(url.href),
        dispatcher: socks ? undefined : new ProxyAgent(url.href),
        label: `${url.protocol}//${url.hostname}:${url.port}`,
    };
}

function createProxyStore({ dir }) {
    const file = botId => path.join(dir, `${botId}.json`);

    function get(botId) {
        try {
            return JSON.parse(fs.readFileSync(file(botId), 'utf8')).url || null;
        } catch {
            return null;
        }
    }

    /** Saves (or with an empty value removes) the bot's proxy; throws on an invalid URL. */
    function set(botId, raw) {
        const url = parseProxy(raw);

        if (!url) {
            fs.rmSync(file(botId), { force: true });

            return null;
        }

        fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
        const clean = url.href.replace(/\/$/, '');
        fs.writeFileSync(file(botId), JSON.stringify({ url: clean }), { mode: 0o600 });

        return clean;
    }

    return { get, set };
}

module.exports = { parseProxy, buildProxy, createProxyStore };
