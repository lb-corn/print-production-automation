const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/js/app.js','utf8').split('// Shared sync feedback stays above either overview panel.')[1].split('// Size the preview label against the artwork')[0];
function setup(state='success') {
 const events={};const button={disabled:false,textContent:''};
 const status={dataset:{orderSyncState:state,statusUrl:'/orders/sync-status'}};
 const form={attributes:{},querySelector:()=>button,setAttribute(k,v){this.attributes[k]=v},addEventListener(k,v){events[k]=v}};
 vm.runInNewContext(source,{document:{querySelector:s=>s==='[data-order-sync-state]'?status:form},window:{setInterval(){},addEventListener(k,v){events[k]=v}}});
 return {events,button,form,status};
}
const active=setup();assert(!active.button.disabled);
const event=()=>({defaultPrevented:false,preventDefault(){this.defaultPrevented=true}});
let first=event();active.events.submit(first);assert(!first.defaultPrevented && active.button.disabled && active.button.textContent==='Syncing…');
let repeat=event();active.events.submit(repeat);assert(repeat.defaultPrevented);
active.events.pageshow();assert(!active.button.disabled && active.button.textContent==='Sync orders');
const busy=setup('running');assert(busy.button.disabled && busy.form.attributes['aria-busy']==='true');
const blocked=event();busy.events.submit(blocked);assert(blocked.defaultPrevented);
console.log('PASS common sync loading state, duplicate prevention and browser-history reset');
