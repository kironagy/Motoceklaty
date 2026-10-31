const fs=require('fs'), vm=require('vm'), assert=require('node:assert/strict');
const path=require('path');
const source=fs.readFileSync(path.join(__dirname,'../../resources/views/forms/partials/script.blade.php'),'utf8').replace('<script>','').replace('</script>','');
const spoken=[];const speech={cancel(){},getVoices(){return[]},speak(x){spoken.push(x.text)}};
function Utterance(text){this.text=text}
const F={sections:[{key:'person',items:[{key:'home',type:'address',required:true,address_required:['governorate','area','street','landmark']},{key:'machine',type:'machine'},{key:'plan',type:'plan'}]}],state:{files:[],progress:{}},bindings:{machine:'request.machine',plan:'request.plan'},machines:[{id:1,name:'A One',brand_id:1,brand:'A'},{id:2,name:'B Two',brand_id:2,brand:'B'}],governorates:[],nidGovernorates:{}};
const sandbox={window:{__FORM__:F,speechSynthesis:speech,SpeechSynthesisUtterance:Utterance},SpeechSynthesisUtterance:Utterance,document:{querySelector:()=>({content:'test'})},clearTimeout,setTimeout,setInterval,console};
const make=vm.runInNewContext(source+'; applicationForm;',sandbox);const form=make();
const tick=()=>new Promise(r=>setImmediate(r));let n=0;const ok=(x,label)=>{assert(x,label);console.log('PASS '+label);n++};
(async()=>{
form.answers={home:{governorate:'القاهرة',area:'عين شمس',street:'الزهراء',landmark:'جنب مدرسة الخنساء'},machine:1,plan:''};
ok(form.machineOptions('machine').length===0,'brand is selected before showing models');
form.selectedBrand='2';ok(form.machineOptions('machine').length===1&&form.machineOptions('machine')[0].id===2,'brand filter shows only selected brand');
ok(form.brands().length===2,'brand slider derives all brands');
const calls=[];form.post=(route,payload)=>new Promise(resolve=>calls.push({payload,resolve}));
const first=form.save('home');await tick();form.answers.home={...form.answers.home,landmark:'أمام المسجد'};const second=form.save('home');await tick();
ok(calls.length===1,'address saves are serialized');calls[0].resolve({error:'old validation error'});await tick();ok(calls.length===2,'latest address saves after previous request');ok(!form.errors.home,'stale error is not applied to new address');calls[1].resolve({error:null});await Promise.all([first,second]);ok(calls[1].payload.value.landmark==='أمام المسجد'&&!form.errors.home,'latest landmark preserved and valid');
form.post=async()=>({error:null});await form.flush();ok(form.sectionDone(form.sections[0]),'complete address and landmark allow next step');
delete form.answers.home.street;ok(form.sectionProblems(form.sections[0]).home.includes('الشارع'),'missing address part named correctly');form.answers.home.street='الزهراء';
form.plans=[{id:10,capped:true,down_payment:20000,months:12,monthly:5000,upfront:22000}];form.pickPlan('plan',form.plans[0]);ok(form.capOffer&&form.answers.plan==='','capped plan waits for popup confirmation');form.acceptCap();ok(form.capConsent.amount===20000&&form.answers.plan===10,'popup acceptance records exact current amount');ok(!form.needsCap(form.plans[0]),'same confirmed offer can continue');ok(form.needsCap({...form.plans[0],down_payment:21000}),'changed amount needs new confirmation');
ok(form.voiceEnabled===true,'voice is enabled by default without an optional toggle');form.addressPrompt('branch_street');ok(spoken[0].includes('المتفرع')&&!spoken[0].includes('أمام المسجد'),'voice reads field guidance, not private answers');
form.speakProblems({home:'كمّل: المحافظة، المنطقة'});ok(spoken.at(-1).includes('المحافظة')&&spoken.at(-1).includes('المنطقة'),'blocked step speaks exact missing address parts');
form.post=async()=>null;ok(!await form.flush(),'failed save stops progression');
console.log(n+' checks passed');
})().catch(e=>{console.error(e);process.exitCode=1});
