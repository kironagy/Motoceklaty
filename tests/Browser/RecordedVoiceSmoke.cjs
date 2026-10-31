const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict'),path=require('path');
const source=fs.readFileSync(path.join(__dirname,'../../resources/views/forms/partials/script.blade.php'),'utf8').replace('<script>','').replace('</script>','');
let denied=true,instances=0;const played=[];
class AudioMock {
 constructor(){instances++;}pause(){this.paused=true;}
 play(){played.push(this.src);if(denied){const e=new Error('gesture required');e.name='NotAllowedError';return Promise.reject(e);}queueMicrotask(()=>this.onended?.());return Promise.resolve();}
}
const sandbox={window:{__FORM__:{sections:[],state:{files:[],progress:{}},machines:[]},__FORM_VOICE__:{base:'/audio/form-eg-v1',clips:['welcome','missing','address_governorate','address_area']}},Audio:AudioMock,document:{querySelector:()=>({content:'test'})},setTimeout,clearTimeout,setInterval};
const form=vm.runInNewContext(source+'; applicationForm();',sandbox);
(async()=>{
 form.voiceStarted=true;await form.playVoice(['welcome']);assert.equal(form.voiceStarted,false);assert(form.voiceStatus.includes('المس'));
 denied=false;await form.playVoice(['missing','address_governorate','address_area','address_area','unknown']);
 assert.equal(instances,1,'reuse unlocked audio element for mobile browsers');assert.deepEqual(played.slice(1),['/audio/form-eg-v1/missing.wav','/audio/form-eg-v1/address_governorate.wav','/audio/form-eg-v1/address_area.wav']);
 assert(form.voiceStatus.includes('بالمصري'));
 console.log('PASS recorded audio playback, gesture retry, ordered missing fields, deduplication and mobile element reuse');
})().catch(e=>{console.error(e);process.exitCode=1;});
