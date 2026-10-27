const {test} = require('node:test');
const assert = require('node:assert/strict');
const {createHistory} = require('../factory-history');
const {createContacts} = require('../factory-contacts');
test('raw chats with incoming timestamps count without content; outgoing receipts resolve LID',()=>{
 const c=createContacts();
 c.historyMappings({conversations:[{id:'123@lid',lastMessageRecvTimestamp:1700000000,messages:[{message:{key:{remoteJid:'123@lid',fromMe:true},userReceipt:[{userJid:'201222222222@s.whatsapp.net'}]}}]},{id:'201333333333@s.whatsapp.net',lastMessageRecvTimestamp:1700000000},{id:'201444444444@s.whatsapp.net'},{id:'g@g.us',lastMessageRecvTimestamp:1700000000}]});
 assert.deepEqual(c.rows().map(r=>r.phone).sort(),['201222222222','201333333333']);
 assert.equal(c.needsHistory('123@lid'),false);
 assert.equal(c.needsHistory('201444444444@s.whatsapp.net'),true);
 assert.ok(!JSON.stringify(c.saved()).includes('messageTimestamp'));
});
test('cursor paging persists keys only, requests once per oldest key, advances and excludes groups',()=>{
 let h=createHistory();
 const m=(id,time)=>({key:{remoteJid:'123@lid',id,fromMe:true},messageTimestamp:time,message:{conversation:'PRIVATE_TEXT'}});
 h.ingest({messages:[m('new',1700000000),m('old',1600000000),{key:{remoteJid:'g@g.us',id:'group'},messageTimestamp:1}]});
 let next=h.next(()=>true); assert.equal(next.key.id,'old'); assert.equal(next.timestamp,1600000000000);
 assert.equal(h.next(()=>true),null);
 h=createHistory(h.saved()); assert.equal(h.next(()=>true),null);
 h.ingest({conversations:[{messages:[{message:m('older',1500000000)}]}]});
 assert.equal(h.next(()=>false),null);
 assert.equal(h.next(()=>true).key.id,'older');
 assert.ok(!JSON.stringify(h.saved()).includes('PRIVATE_TEXT')); assert.equal(h.count(),1);
});
