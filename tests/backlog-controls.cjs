const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/js/app.js','utf8');
function setup(confirmed){
 const events={};let confirms=0;
 const button={textContent:'Refresh backlog',dataset:{},disabled:false};
 const form={button,attributes:{},addEventListener(name,handler){this[name]=handler;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},querySelectorAll(s){return s.includes('data-backlog-label')&&!button.dataset.backlogLabel?[]:[button];}};
 vm.runInNewContext(source.slice(source.indexOf('const backlogForms ='),source.indexOf('const rematchForms =')),{document:{querySelectorAll:()=>[form]},window:{confirm(){confirms++;return confirmed;},addEventListener(k,handler){events[k]=handler;}}});
 return {form,button,events,confirms:()=>confirms};
}
const event=()=>({defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}});
const canceled=setup(false),cancel=event();canceled.form.submit(cancel);assert(cancel.defaultPrevented&&!canceled.button.disabled);
const accepted=setup(true),first=event();accepted.form.submit(first);assert(!first.defaultPrevented&&accepted.button.disabled&&accepted.button.textContent==='Refreshing…'&&accepted.form.attributes['aria-busy']==='true');
const repeat=event();accepted.form.submit(repeat);assert(repeat.defaultPrevented&&accepted.confirms()===1);
accepted.events.pageshow();assert(!accepted.button.disabled&&accepted.button.textContent==='Refresh backlog'&&!accepted.form.attributes['aria-busy']);
const again=event();accepted.form.submit(again);assert(!again.defaultPrevented);
console.log('PASS: backlog confirmation, visible progress, duplicate prevention and browser-history retry');
