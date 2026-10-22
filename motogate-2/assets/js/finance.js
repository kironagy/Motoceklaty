"use strict";
const financeBikes={keeway:{brand:'Keeway',name:'RKZ200',price:89000,image:'motorcycle-rkz200.webp'},sym:{brand:'SYM',name:'NH T200',image:'sym.webp'},haojue:{brand:'Haojue',name:'DR160',image:'haojue.webp'},benelli:{brand:'Benelli',name:'TNT',image:'benelli.webp'}};
const financeBrand=document.getElementById('financeBrand'),financeModel=document.getElementById('financeModel'),financeDeposit=document.getElementById('financeDeposit'),financeForm=document.getElementById('financeForm');
const money=value=>value.toLocaleString('ar-EG',{maximumFractionDigits:0})+' جنيه';
function calculateFinance(price,down,months,rate){return {total:(price-down)*(1+rate*months/12),monthly:(price-down)*(1+rate*months/12)/months}}
const number=value=>value.toLocaleString('ar-EG',{maximumFractionDigits:0});
function clearFinanceResult(message){for(const id of ['monthlyAmount','resultMonths','resultDeposit','resultTotal'])document.getElementById(id).textContent='—';document.getElementById('resultNote').textContent=message;document.getElementById('resultContact').hidden=true;}
function updateFinance(){
 const bike=financeBikes[financeModel.value],price=document.getElementById('financePrice');
 financeDeposit.setCustomValidity('');
 clearFinanceResult('اختر الموديل وأكمل البيانات لعرض حساب القسط هنا.');
 if(!bike){price.textContent='اختر الماركة والموديل للبدء';return null}
 const img=document.getElementById('financeBike');img.src='assets/images/'+bike.image;img.alt=bike.brand+' '+bike.name;document.getElementById('selectedBikeName').textContent=img.alt;
 price.textContent=bike.price?'السعر النقدي: '+money(bike.price):'السعر حسب التوفر';
 if(!bike.price){clearFinanceResult('سعر '+bike.brand+' '+bike.name+' حسب التوفر. تواصل معنا لتأكيد السعر وحساب القسط.');const link=document.getElementById('resultContact');link.hidden=false;link.href='https://wa.me/201001234567?text='+encodeURIComponent('أريد سعر وحساب قسط '+bike.brand+' '+bike.name);return null}
 const down=Number(financeDeposit.value);
 if(financeDeposit.value!==''&&down>=bike.price){financeDeposit.setCustomValidity('المقدم لازم يكون أقل من سعر الموتوسيكل');clearFinanceResult('المقدم لازم يكون أقل من سعر الموتوسيكل');return null}
 if(!financeForm.checkValidity())return null;
 const months=Number(document.getElementById('financeMonths').value),rate=Number(document.getElementById('financePlan').value),result=calculateFinance(bike.price,down,months,rate);
 document.getElementById('monthlyAmount').textContent=number(result.monthly);document.getElementById('resultMonths').textContent=number(months);document.getElementById('resultDeposit').textContent=number(down);document.getElementById('resultTotal').textContent=number(result.total);
 document.getElementById('resultNote').textContent='حساب تقديري بعائد ثابت حسب النظام المختار. لا يشمل الرسوم أو التأمين؛ العرض النهائي حسب شروط التعاقد.';
 return {bike,down,months,...result};
}
financeBrand.addEventListener('change',()=>{financeModel.replaceChildren(new Option('اختر النوع',''));Object.entries(financeBikes).filter(([,b])=>b.brand===financeBrand.value).forEach(([key,b])=>financeModel.add(new Option(b.name,key)));financeModel.disabled=!financeBrand.value;const first=[...financeModel.options][1];if(first)financeModel.value=first.value;updateFinance()});
financeModel.disabled=true;
financeForm.addEventListener('input',updateFinance);financeForm.addEventListener('change',updateFinance);
financeForm.addEventListener('submit',event=>{event.preventDefault();updateFinance();financeForm.reportValidity()});
document.querySelectorAll('#mobileMenu a').forEach(a=>a.addEventListener('click',()=>bootstrap.Offcanvas.getInstance(document.getElementById('mobileMenu'))?.hide()));
const initialModel=new URLSearchParams(location.search).get('model');if(financeBikes[initialModel]){financeBrand.value=financeBikes[initialModel].brand;financeBrand.dispatchEvent(new Event('change'));financeModel.value=initialModel;updateFinance()}
