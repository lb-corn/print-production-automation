const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
{
 const source=fs.readFileSync('public/js/app.js','utf8'),events={};
 const input={value:'',addEventListener(k,fn){this[k]=fn;},focus(){this.focused=true;}},clear={hidden:true,addEventListener(k,fn){this[k]=fn;}},count={},empty={};
 const rows=[{dataset:{filenameSearchText:'CLV-RETRO 7 GRUNGE.jpg LB-CLV-RET7-SINGLE.jpg Lucky Bags Retro 7 Clovers 2027-RET7-CLV-0001'}},{dataset:{filenameSearchText:'old-duck.jpg LB-PSE-DUC-RED-SINGLE.jpg Duck Camo Red 2027-DUC-PSE-0001'}}];
 const nodes={'[data-filename-search]':input,'[data-filename-clear]':clear,'[data-filename-count]':count,'[data-filename-empty]':empty};
 vm.runInNewContext(source.slice(source.indexOf('const filenameSearch ='),source.indexOf('const filenameProcessForms =')),{document:{querySelector:s=>nodes[s],querySelectorAll:()=>rows},window:{addEventListener:(k,fn)=>events[k]=fn}});
 input.value='RET7 SINGLE';input.input();assert(!rows[0].hidden&&rows[1].hidden&&count.textContent==='1 of 2 files');
 input.value='2027-DUC-PSE';input.input();assert(rows[0].hidden&&!rows[1].hidden);
 input.value='no such filename';input.input();assert(rows.every(r=>r.hidden)&&!empty.hidden);
 clear.click();assert(rows.every(r=>!r.hidden)&&empty.hidden&&clear.hidden&&input.focused);
 console.log('PASS: filename search filters current/suggested names and SKU, handles no results and clears without submitting rename actions');
}
{
 const source=fs.readFileSync('public/js/app.js','utf8'),events={};
 const forms=[0,1].map(()=>({attributes:{},button:{disabled:false,textContent:'Process suggested name'},querySelector(){return this.button;},addEventListener(k,fn){this[k]=fn;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];}}));
 vm.runInNewContext(source.slice(source.indexOf('const filenameProcessForms ='),source.indexOf('const catalogSyncForms =')),{document:{querySelectorAll:()=>forms},window:{addEventListener:(k,fn)=>events[k]=fn}});
 const first={preventDefault(){this.prevented=true;}};forms[0].submit(first);assert(!first.prevented&&forms.every(f=>f.button.disabled)&&forms[0].button.textContent==='Processing…');
 const duplicate={preventDefault(){this.prevented=true;}};forms[1].submit(duplicate);assert(duplicate.prevented);
 events.pageshow();assert(forms.every(f=>!f.button.disabled&&f.button.textContent==='Process suggested name'&&!f.attributes['aria-busy']));
 console.log('PASS: simple filename processing blocks duplicate actions and restores controls after browser navigation');
}
{
 const source=fs.readFileSync('public/js/app.js','utf8');const events={};
 const forms=[0,1].map(()=>({attributes:{},addEventListener(name,handler){this[name]=handler;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];}}));
 const buttons=[{textContent:'Sync catalog',dataset:{},disabled:false},{textContent:'Refresh files',dataset:{},disabled:false}];
 vm.runInNewContext(source.slice(source.indexOf('const catalogSyncForms ='),source.indexOf('const backlogForms =')),{document:{querySelectorAll:selector=>selector==='[data-catalog-sync]'?forms:buttons},window:{addEventListener:(name,fn)=>events[name]=fn}});
 const first={preventDefault(){this.prevented=true;}};forms[0].submit(first);
 assert(!first.prevented&&buttons.every(b=>b.disabled)&&forms.every(f=>f.attributes['aria-busy']==='true'));
 const duplicate={preventDefault(){this.prevented=true;}};forms[1].submit(duplicate);assert(duplicate.prevented);
 events.pageshow();assert(buttons.every(b=>!b.disabled)&&buttons[0].textContent==='Sync catalog'&&buttons[1].textContent==='Refresh files'&&forms.every(f=>!f.attributes['aria-busy']));
 const retry={preventDefault(){this.prevented=true;}};forms[1].submit(retry);assert(!retry.prevented);
 console.log('PASS: catalog sync blocks duplicate submissions from both entry points and restores controls after browser navigation');
}
function setup(confirmResult) {
    const events = {};
    let confirmations = 0;
    const forms = [0, 1].map(() => ({
        attributes: {}, button: {dataset: {}, textContent: 'Rematch Artwork', disabled: false},
        addEventListener(name, handler) { this[name] = handler; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        querySelectorAll(selector) { return selector.includes('data-rematch-label') && !this.button.dataset.rematchLabel ? [] : [this.button]; },
    }));
    const source = fs.readFileSync('public/js/app.js', 'utf8');
    const start = source.indexOf('const rematchForms =');
    assert(start >= 0);
    vm.runInNewContext(source.slice(start, source.indexOf('// Overview tabs use server-authorized panels')), {
        document: {querySelectorAll: selector => selector === '[data-rematch-form]' ? forms : []},
        window: {confirm: () => { confirmations++; return confirmResult; }, addEventListener: (name, handler) => { events[name] = handler; }},
    });
    return {forms, events, confirmations: () => confirmations};
}
const event = () => ({defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }});
const canceled = setup(false);const cancelEvent = event();canceled.forms[0].submit(cancelEvent);
assert(cancelEvent.defaultPrevented);assert(canceled.forms.every(form => !form.button.disabled));
console.log('PASS: canceled confirmation leaves all rematch buttons available');
const accepted = setup(true);const first = event();accepted.forms[0].submit(first);
assert(!first.defaultPrevented);assert(accepted.forms.every(form => form.button.disabled && form.button.textContent === 'Rematching…' && form.attributes['aria-busy'] === 'true'));
const duplicate = event();accepted.forms[1].submit(duplicate);
assert(duplicate.defaultPrevented);assert.equal(accepted.confirmations(), 1);
console.log('PASS: confirmed rematch disables every rematch control and blocks duplicate submissions');
accepted.events.pageshow();assert(accepted.forms.every(form => !form.button.disabled && form.button.textContent === 'Rematch Artwork' && !form.attributes['aria-busy']));
const retry = event();accepted.forms[1].submit(retry);assert(!retry.defaultPrevented);
console.log('PASS: browser history restore resets controls and permits a new deliberate submission');

// Return-to-review confirmation is isolated from rematching and blocks repeated submits.
for (const confirmResult of [false,true]) {
 const button={disabled:false,textContent:'Return to Artwork Review'};
 const form={querySelector:()=>button,addEventListener(name,fn){this[name]=fn;}};
 const source=fs.readFileSync('public/js/app.js','utf8');const start=source.indexOf('// Returning a queue entry');
 vm.runInNewContext(source.slice(start, source.indexOf('// Overview tabs use server-authorized panels')),{document:{querySelectorAll:()=>[form]},window:{confirm:()=>confirmResult}});
 const first=event();form.submit(first);assert.equal(first.defaultPrevented,!confirmResult);assert.equal(button.disabled,confirmResult);
 if(confirmResult){const second=event();form.submit(second);assert(second.defaultPrevented);}
}
console.log('PASS: batch return requires confirmation and blocks repeated submissions');

{
 let confirmations=0;const forms=['same','same','other'].map(action=>({action,button:{disabled:false,textContent:'Return'},querySelector(){return this.button;},addEventListener(name,fn){this[name]=fn;}}));
 const source=fs.readFileSync('public/js/app.js','utf8');vm.runInNewContext(source.slice(source.indexOf('// Returning a queue entry'), source.indexOf('// Overview tabs use server-authorized panels')),{document:{querySelectorAll:()=>forms},window:{confirm:()=>{confirmations++;return true;}}});
 forms[0].submit(event());assert(forms[0].button.disabled&&forms[1].button.disabled&&!forms[2].button.disabled);const repeat=event();forms[1].submit(repeat);assert(repeat.defaultPrevented);assert.equal(confirmations,1);
 console.log('PASS: queued cards for the same batch disable together while unrelated batch controls remain available');
}

{
 let focused=false,scrolled=false;const notice={setAttribute(name,value){this[name]=value;},scrollIntoView(){scrolled=true;},focus(){focused=true;}};
 const source=fs.readFileSync('public/js/app.js','utf8');const start=source.indexOf('// Bring action errors/setup notices');
 vm.runInNewContext(source.slice(start, source.indexOf('// Overview tabs use server-authorized panels')),{document:{querySelector:()=>notice},requestAnimationFrame:fn=>fn()});assert(focused&&scrolled);assert.equal(notice.tabindex,'-1');
 console.log('PASS: action errors and server-setup notices scroll into view and receive accessible focus');
}
