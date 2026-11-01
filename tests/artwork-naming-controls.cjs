const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function setup(confirmResult, failStep = false) {
    const calls = []; const redirects = []; const dialog = {open: confirmResult, showModal(){this.open=true;}, close(){this.open=false;}, addEventListener(){}}; const progress = {textContent: ''}; const password = {value: 'transient password'};
    const controls = [{disabled: false}];
    const input = {checked: true, disabled: false, addEventListener(){}, closest: () => ({hidden: false})};
    const form = {reportValidity:()=>true, action: '/begin', dataset: {stepUrl: '/step'},
        querySelectorAll: selector => selector.includes('ids[]') ? [input] : controls,
        querySelector: () => password, addEventListener(name, handler) { this[name] = handler; }};
    class FormData {
        constructor(initial) { this.values = new Map(initial ? [['_token','csrf'],['plan','reviewed-plan'],['password','transient password']] : []); }
        get(key) { return this.values.get(key); } set(key,value) { this.values.set(key,value); }
    }
    const context = {FormData, document: {
        querySelector(selector) { return selector === '#artwork-naming-apply' ? form : selector === '[data-naming-progress]' ? progress : selector === '[data-naming-dialog]' ? dialog : null; },
        querySelectorAll: () => [],
    }, window: {addEventListener(){}, location: {assign: url => redirects.push(url)}},
        fetch: async (url, options) => {
            calls.push({url,body: options.body});
            if (failStep && url === '/step') throw new Error('Network interrupted');
            return {ok: true, json: async () => ({status: calls.length < 3 ? 'applying' : 'complete', renamed: calls.length-1, remaining: Math.max(0,3-calls.length), url: '/audit'})};
        }};
    vm.runInNewContext(fs.readFileSync('public/js/artwork-names.js','utf8'),context);
    return {form,calls,redirects,controls,password,progress};
}
(async () => {
    const canceled=setup(false);await canceled.form.submit({preventDefault(){}});assert.equal(canceled.calls.length,0);
    console.log('PASS: opening the confirmation dialog sends no rename requests');
    const run=setup(true);const active=run.form.submit({preventDefault(){}});await run.form.submit({preventDefault(){}});await active;
    assert.deepEqual(run.calls.map(call=>call.url),['/begin','/step','/step']);assert.deepEqual(run.redirects,['/audit']);
    assert(run.controls.every(control=>control.disabled));assert.equal(run.password.value,'');
    assert.equal(run.calls[1].body.get('password'),undefined);assert.equal(run.calls[1].body.get('_token'),'csrf');
    console.log('PASS: one confirmation processes bounded chunks, blocks duplicate submissions and clears the password');
    const failed=setup(true,true);await failed.form.submit({preventDefault(){}});
    assert.equal(failed.calls.length,2);assert.equal(failed.redirects.length,0);assert.match(failed.progress.textContent,/Some files may already be renamed/);assert.equal(failed.password.value,'');
    await failed.form.submit({preventDefault(){}});assert.equal(failed.calls.length,2);
    console.log('PASS: uncertain network outcomes stop and do not retry automatically');
})().catch(error=>{console.error(error);process.exitCode=1;});
