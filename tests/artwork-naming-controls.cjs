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

// Compact mapping preserves detected attributes and never selects a product by itself.
{
 const element = (dataset={}) => ({dataset, value:'', hidden:false, addEventListener(n,fn){this[n]=fn;}, removeAttribute(n){delete this[n];}});
 const source=element(), catalog=element(), side=element(), color=element(), drive=element(), product=element(), label=element(), advanced=element(), link=element();
 source.selectedOptions=[{value:'file',dataset:{series:'SNP',color:'BLUE',side:'back',thumbnail:'https://drive.google.com/thumbnail?id=file',url:'https://drive.google.com/file/d/file/view'}}];
 const blue={value:'blue',dataset:{series:'SNP',color:'BLUE',type:'double',image:'https://cdn.shopify.com/blue.jpg'}};
 const white={value:'white',dataset:{series:'SNP',color:'WHITE'}};
 const wrongSeries={value:'pse',dataset:{series:'PSE',color:'BLUE'}};
 catalog.options=[blue,white,wrongSeries];catalog.selectedOptions=[blue];
 const sourceSearch=element(), productSearch=element();blue.textContent='Redline Grunge Blue';white.textContent='Redline Grunge White';wrongSeries.textContent='Redline Grunge Blue';source.options=[{value:'miami',textContent:'MIAMI-27 Luciano',dataset:{}},{value:'grunge',textContent:'RED-AND-BLACK-GRUNGE Snipers',dataset:{}}];
 const nodes={'[data-naming-source-search]':sourceSearch,'[data-naming-product-search]':productSearch,'[data-naming-source]':source,'[data-naming-catalog]':catalog,'[data-naming-side]':side,'[data-naming-color]':color,'[data-naming-source-image]':drive,'[data-naming-product-image]':product,'[data-naming-detected]':label,'.naming-review-advanced':advanced,'[data-naming-original]':link};
 vm.runInNewContext(fs.readFileSync('public/js/artwork-names.js','utf8'),{document:{querySelector:s=>nodes[s]||null,querySelectorAll:()=>[]},window:{addEventListener(){}}});
 source.change();assert.equal(side.value,'back');assert.equal(color.value,'BLUE');assert.equal(catalog.value,'');assert(!blue.disabled&&white.disabled&&wrongSeries.disabled);assert.equal(drive.src,source.selectedOptions[0].dataset.thumbnail);
 catalog.change();assert.equal(product.src,blue.dataset.image);assert.equal(side.value,'back');assert(label.textContent.includes('BLUE · back'));
 source.selectedOptions[0].dataset.side='';source.change();assert.equal(advanced.open,true);
 blue.dataset.image='javascript:alert(1)';catalog.change();assert.equal(product.src,undefined);
 productSearch.input({target:{value:'GRUNGE blue'}});assert(!blue.hidden && white.hidden && wrongSeries.hidden);
 productSearch.input({target:{value:'not present'}});assert(blue.hidden);
 productSearch.input({target:{value:''}});assert(!blue.hidden && white.hidden && wrongSeries.hidden);
 sourceSearch.input({target:{value:'miami 27'}});assert(!source.options[0].hidden && source.options[1].hidden);
 sourceSearch.input({target:{value:''}});assert(source.options.every(option=>!option.hidden));
 assert.equal(catalog.value,'');
 console.log('PASS: case-insensitive dropdown search handles spaces/hyphens, clears safely and preserves series/color restrictions');
 console.log('PASS: compact thumbnails filter variants, preserve BACK, expose missing sides and reject unsafe image URLs');
}
