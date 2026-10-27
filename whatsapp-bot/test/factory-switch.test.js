const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {createContacts} = require('../factory-contacts');

test('switch archives old account, starts empty QR, ignores late events and isolates staff', async () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'factory-switch-'));
    const routes = new Map(), sockets = [];
    const app = {use(){}, param(){}, get(p,h){routes.set('GET '+p,h)}, post(p,h){routes.set('POST '+p,h)}, listen(){}};
    const baileys = {
        default: () => { const handlers = {}; const sock = {user:{id:'201111111111:2@s.whatsapp.net'}, ev:{on:(e,h)=>handlers[e]=h}, end(){this.closed=true}, handlers}; sockets.push(sock); return sock; },
        useMultiFileAuthState: async dir => {fs.mkdirSync(dir,{recursive:true}); return {state:{creds:{}},saveCreds:async()=>{}}},
        fetchLatestBaileysVersion: async()=>({version:[1,2,3]}), Browsers:{macOS:()=>[]}, DisconnectReason:{loggedOut:401}, getHistoryMsg:()=>null,
    };
    const context = {require: name => ({dotenv:{config(){}}, './factory-runtime/node_modules/@whiskeysockets/baileys':baileys, './factory-history':require('../factory-history'), express:()=>app, pino:()=>({}), qrcode:{toDataURL:async()=> 'data:image/png;base64,test'}, './factory-contacts':{createContacts}}[name] || require(name)), process:{umask(){},env:{FACTORY_DATA_DIR:root,BOT_TOKEN:'test'}}, console, Buffer, setTimeout, clearTimeout};
    const source = fs.readFileSync(path.join(__dirname,'../factory-worker.js'),'utf8');
    vm.runInNewContext(source, context);
    const call = async(method,suffix,id='staff_27') => {let body,status=200; const res={json(v){body=v;return this},status(v){status=v;return this}}; await routes.get(method+' /sessions/:id'+suffix)({params:{id}},res);return {body,status};};
    try {
        assert.equal((await call('POST','/start')).status,200);
        await sockets[0].handlers['messages.upsert']({messages:[{key:{remoteJid:'201234567890@s.whatsapp.net',fromMe:false},message:{conversation:'not saved'}}]});
        assert.equal((await call('GET','')).body.count,1);
        assert.equal((await call('GET','')).body.account,'201111111111');
        await call('POST','/start'); assert.equal(sockets.length,1);
        const result=await call('POST','/switch'); assert.equal(result.status,200);
        assert.equal(sockets[0].closed,true); assert.equal(sockets.length,2);
        assert.equal((await call('GET','')).body.count,0);
        assert.equal(fs.readdirSync(path.join(root,'archives')).length,1);
        const archive=path.join(root,'archives',fs.readdirSync(path.join(root,'archives'))[0]);
        assert.ok(fs.readFileSync(path.join(archive,'senders.json'),'utf8').includes('201234567890'));
        sockets[0].handlers['contacts.upsert']([{id:'209999999999@s.whatsapp.net'}]);
        assert.equal((await call('GET','')).body.count,0);
        await sockets[1].handlers['connection.update']({qr:'new-qr'});
        assert.equal((await call('GET','')).body.status,'qr');
        assert.equal((await call('GET','', 'staff_25')).body.count,0);
        await sockets[1].handlers['messages.upsert']({messages:[{key:{remoteJid:'201555555555@s.whatsapp.net',fromMe:false},message:{conversation:'not saved'}}]});
        assert.equal((await call('GET','')).body.count,1);
        assert.equal((await call('POST','/resync')).status,200);
        assert.equal((await call('GET','')).body.count,1);
        sockets[2].handlers['contacts.upsert']([{id:'209999999999@s.whatsapp.net'}]);
        sockets[2].user.id='201999999999@s.whatsapp.net';
        await sockets[2].handlers['connection.update']({connection:'open'});
        assert.equal((await call('GET','')).body.status,'wrong_account');
        await sockets[2].handlers['messages.upsert']({messages:[{key:{remoteJid:'201666666666@s.whatsapp.net',fromMe:false},message:{conversation:'not saved'}}]});
        assert.equal((await call('GET','')).body.count,1);
    } finally {fs.rmSync(root,{recursive:true,force:true});}
});




