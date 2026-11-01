const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
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
    vm.runInNewContext(source.slice(start), {
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
 vm.runInNewContext(source.slice(start),{document:{querySelectorAll:()=>[form]},window:{confirm:()=>confirmResult}});
 const first=event();form.submit(first);assert.equal(first.defaultPrevented,!confirmResult);assert.equal(button.disabled,confirmResult);
 if(confirmResult){const second=event();form.submit(second);assert(second.defaultPrevented);}
}
console.log('PASS: batch return requires confirmation and blocks repeated submissions');

{
 let confirmations=0;const forms=['same','same','other'].map(action=>({action,button:{disabled:false,textContent:'Return'},querySelector(){return this.button;},addEventListener(name,fn){this[name]=fn;}}));
 const source=fs.readFileSync('public/js/app.js','utf8');vm.runInNewContext(source.slice(source.indexOf('// Returning a queue entry')),{document:{querySelectorAll:()=>forms},window:{confirm:()=>{confirmations++;return true;}}});
 forms[0].submit(event());assert(forms[0].button.disabled&&forms[1].button.disabled&&!forms[2].button.disabled);const repeat=event();forms[1].submit(repeat);assert(repeat.defaultPrevented);assert.equal(confirmations,1);
 console.log('PASS: queued cards for the same batch disable together while unrelated batch controls remain available');
}
