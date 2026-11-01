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

// Shopify is primary: file labels must not hide catalog products.
{
 const element = (dataset={}) => ({dataset,value:'',hidden:false,handlers:{},addEventListener(n,fn){(this.handlers[n]??=[]).push(fn);},change(){for(const fn of this.handlers.change||[])fn();},input(e){for(const fn of this.handlers.input||[])fn(e);},removeAttribute(n){delete this[n];}});
 const source=element(),catalog=element(),side=element(),color=element(),drive=element(),product=element(),label=element(),needed=element(),link=element(),sourceSearch=element(),productSearch=element();
 const file={value:'file',textContent:'RED-AND-BLACK-GRUNGE',dataset:{series:'SNP',color:'BLUE',side:'back',thumbnail:'https://drive.google.com/thumbnail?id=file',url:'https://drive.google.com/file/d/file/view'}};
 const wrongColor={value:'white',textContent:'Grunge White',dataset:{series:'SNP',color:'WHITE'}};
 const wrongSeries={value:'pse',textContent:'Grunge Blue',dataset:{series:'PSE',color:'BLUE'}};
 const blue={value:'blue',textContent:'Redline Grunge Blue',dataset:{series:'SNP',color:'BLUE',type:'double',image:'https://cdn.shopify.com/blue.jpg'}};
 source.options=[file,wrongColor,wrongSeries];source.selectedOptions=[file];catalog.options=[blue];catalog.selectedOptions=[blue];catalog.value='blue';
 const nodes={'[data-naming-source]':source,'[data-naming-catalog]':catalog,'[data-naming-side]':side,'[data-naming-color]':color,'[data-naming-source-image]':drive,'[data-naming-product-image]':product,'[data-naming-detected]':label,'[data-naming-side-needed]':needed,'[data-naming-original]':link,'[data-naming-source-search]':sourceSearch,'[data-naming-product-search]':productSearch};
 vm.runInNewContext(fs.readFileSync('public/js/artwork-names.js','utf8'),{document:{querySelector:s=>nodes[s]||null,querySelectorAll:()=>[]},window:{addEventListener(){}}});
 catalog.change();assert(!file.disabled&&wrongColor.disabled&&wrongSeries.disabled);
 source.value='file';source.change();assert.equal(catalog.value,'blue');assert.equal(side.value,'back');assert.equal(color.value,'BLUE');assert(needed.hidden);assert.equal(product.src,blue.dataset.image);
 file.dataset.side='';source.change();assert(!needed.hidden);assert.equal(side.value,'');
 productSearch.input({target:{value:'grunge BLUE'}});assert(!blue.hidden);
 productSearch.input({target:{value:'absent'}});assert(blue.hidden);productSearch.input({target:{value:''}});assert(!blue.hidden);
 // A Shopify variant without a color option still permits BLACK-labeled Drive artwork.
 blue.value='gta';blue.textContent='Lucky Bags 2027 GTA';blue.dataset={series:'LUC',color:'',type:'single'};catalog.value='gta';file.textContent='GTA BLACK';file.dataset={series:'LUC',color:'BLACK',side:'single'};
 catalog.change();assert(!file.disabled);source.value='file';source.change();assert.equal(catalog.value,'gta');assert.equal(color.value,'BLACK');assert.equal(side.value,'single');assert(needed.hidden);
 productSearch.input({target:{value:'Lucky Bags 2027 GTA'}});assert(!blue.hidden);
 sourceSearch.input({target:{value:'gta'}});assert(!file.hidden&&wrongColor.hidden);
 console.log('PASS: Shopify-first picker keeps GTA available, filters Drive series/colors and asks only for missing FRONT/BACK');
}

// Exercise the enhanced dropdown itself, including keyboard selection and dismissal.
{
 class Node {
  constructor(){this.children=[];this.attributes={};this.handlers={};this.hidden=false;this.value='';this.classList={toggle(){}};}
  setAttribute(k,v){this.attributes[k]=v;} getAttribute(k){return this.attributes[k];} removeAttribute(k){delete this.attributes[k];}
  addEventListener(k,fn){(this.handlers[k]??=[]).push(fn);} dispatchEvent(e){for(const fn of this.handlers[e.type]||[])fn(e);}
  append(...nodes){this.children.push(...nodes);for(const node of nodes)node.parent=this;}
  replaceChildren(){this.children=[];} before(node){this.wrapper=node;} focus(){this.focused=true;} scrollIntoView(){}
  contains(node){return node===this||this.children.some(child=>child.contains(node));}
 }
 const select=new Node();select.options=[{value:'',textContent:'Choose design'},{value:'miami',textContent:'Miami 27',hidden:false,disabled:false},{value:'grunge',textContent:'Redline Grunge',hidden:false,disabled:false}];
 Object.defineProperty(select,'selectedOptions',{get(){return [select.options.find(option=>option.value===select.value)];}});
 const doc=new Node();doc.createElement=()=>new Node();
 const code=fs.readFileSync('public/js/artwork-names.js','utf8');const begin=code.indexOf('function namingSearchableSelect(');const end=code.indexOf("if (!document.querySelector('[data-artwork-match-dialog]'))",begin);
 const context={document:doc,Event:class{constructor(type){this.type=type;}},namingChoiceFilter:(node,query)=>{for(const option of node.options)option.hidden=!!query&&!option.textContent.toLowerCase().includes(query.toLowerCase());node.namingRefresh();},select};
 vm.runInNewContext(code.slice(begin,end)+'namingSearchableSelect(select);',context);
 const [trigger,panel]=select.wrapper.children;const [search,list]=panel.children;
 assert(select.hidden&&panel.hidden);trigger.dispatchEvent({type:'click'});assert(!panel.hidden&&search.focused);assert.equal(trigger.getAttribute('aria-expanded'),'true');
 search.value='grunge';search.dispatchEvent({type:'input'});assert.equal(list.children.length,1);assert.equal(list.children[0].textContent,'Redline Grunge');
 search.dispatchEvent({type:'keydown',key:'ArrowDown',preventDefault(){}});assert(search.getAttribute('aria-activedescendant'));
 let prevented=false;list.children[0].dispatchEvent({type:'pointerdown',preventDefault(){prevented=true;}});assert(prevented);list.children[0].dispatchEvent({type:'click',preventDefault(){}});assert.equal(select.value,'grunge');assert(panel.hidden);assert.equal(trigger.textContent,'Redline Grunge');
 trigger.dispatchEvent({type:'click'});search.dispatchEvent({type:'keydown',key:'ArrowDown',preventDefault(){}});search.dispatchEvent({type:'keydown',key:'Enter',preventDefault(){}});assert.equal(select.value,'miami');assert(panel.hidden);
 trigger.dispatchEvent({type:'click'});assert.equal(search.value,'');search.dispatchEvent({type:'keydown',key:'Escape',preventDefault(){}});assert(panel.hidden);
 trigger.dispatchEvent({type:'click'});doc.dispatchEvent({type:'click',target:new Node()});assert(panel.hidden);
 trigger.dispatchEvent({type:'click'});search.value='absent';search.dispatchEvent({type:'input'});assert.equal(list.children[0].textContent,'No matches found');
 console.log('PASS: integrated dropdown search opens inside menu, filters, selects with keyboard, resets and closes safely');
}

// The visual worklist exposes all eligible thumbnails and requires an explicit selection.
{
 class Element {
  constructor(dataset={}){this.dataset=dataset;this.value='';this.hidden=false;this.children=[];this.handlers={};this.attributes={};}
  addEventListener(type,fn){(this.handlers[type]??=[]).push(fn);} dispatchEvent(e){for(const fn of this.handlers[e.type]||[])fn(e);}
  setAttribute(k,v){this.attributes[k]=v;}removeAttribute(k){delete this[k];}append(...nodes){this.children.push(...nodes);}replaceChildren(){this.children=[];}
  showModal(){this.open=true;}close(){this.open=false;}focus(){}contains(node){return this===node;}
 }
 const source=new Element(),catalog=new Element(),dialog=new Element(),grid=new Element(),search=new Element(),confirm=new Element(),side=new Element(),color=new Element();
 const option=(value,name,dataset)=>Object.assign(new Element(dataset),{value,textContent:name});
 const sku=option('sku','Shopify Elite S / Navy',{series:'ELT-S',color:'NAVY',type:'single',image:'https://cdn.shopify.com/navy.jpg',folder:'Group B / Elite S',folderUrl:'https://drive.google.com/drive/folders/elite',folderId:'elite',scopeReady:'1'});
 catalog.options=[option('','Choose',{}),sku];
 const standard=option('standard','ELT-S-NAVY-ART.jpg',{series:'ELT-S',color:'NAVY',side:'single',parentId:'elite',thumbnail:'https://drive.google.com/thumbnail?id=standard',linked:'Existing catalog design'});
 const unknown=option('unknown','ELT-S-OLD-NAME.jpg',{series:'ELT-S',color:'',side:'single',parentId:'elite'});
 const wrong=option('wrong','ELT-S-RED.jpg',{series:'ELT-S',color:'RED',side:'single',parentId:'elite'});
 const elsewhere=option('elsewhere','ELT-S-NAVY.jpg',{series:'ELT-S',color:'NAVY',side:'single',parentId:'other-folder'});
 const protectedFile=option('protected','SUP-BLUE.jpg',{series:'ELT-S',color:'NAVY',side:'single',parentId:'elite',protected:'1',url:'https://drive.google.com/file/d/protected/view'});
 source.options=[option('','Choose',{}),standard,unknown,wrong,elsewhere,protectedFile];
 for(const select of [source,catalog])Object.defineProperty(select,'selectedOptions',{get(){return [select.options.find(item=>item.value===select.value)];}});
 const button=new Element({matchProduct:'sku'}),row=new Element({artworkStatus:'missing'});row.textContent='Shopify Navy sku';
 const nodes={'[data-artwork-match-dialog]':dialog,'[data-naming-source]':source,'[data-naming-catalog]':catalog,'[data-artwork-candidates]':grid,'[data-naming-source-search]':search,'[data-match-confirm]':confirm,'[data-naming-side]':side,'[data-naming-color]':color};
 for(const key of ['data-match-title','data-match-folder','data-match-folder-link','data-match-selection','data-match-selection-name','data-naming-source-image','data-naming-product-image','data-naming-original','data-artwork-candidate-empty','data-match-candidate-count','data-naming-side-needed','data-match-close','data-artwork-match-form','data-artwork-excluded','data-match-excluded-count','data-match-sku-warning','data-match-pair','data-match-pair-mode','data-match-front-input','data-match-back-input','data-match-front-name','data-match-back-name','data-match-color-confirm','data-match-color-checkbox'])nodes['['+key+']']=new Element();
 nodes['.artwork-find-help']=new Element();
 const productSearch=new Element(),productStatus=new Element(),emptyNotice=new Element(),emptyText=new Element(),clearSearch=new Element();productStatus.value='attention';nodes['[data-catalog-search]']=productSearch;nodes['[data-catalog-status]']=productStatus;nodes['[data-catalog-empty]']=emptyNotice;nodes['[data-catalog-empty-message]']=emptyText;nodes['[data-catalog-clear]']=clearSearch;
 const frontSlot=new Element({matchSideTarget:'front'}),backSlot=new Element({matchSideTarget:'back'});
 const document={createElement:()=>new Element(),querySelector:s=>nodes[s]||null,querySelectorAll:s=>s==='[data-match-side-target]'?[frontSlot,backSlot]:s==='[data-match-product]'?[button]:s==='[data-catalog-row]'?[row]:s==='[data-artwork-file]'?grid.children:[],addEventListener(){}};
 vm.runInNewContext(fs.readFileSync('public/js/artwork-names.js','utf8'),{document,window:{addEventListener(){}},Event:class{constructor(type){this.type=type;}}});
 productSearch.value='LB-SUP-BAC-WHITE-SINGLE';productSearch.dispatchEvent({type:'input'});assert(!emptyNotice.hidden);assert(emptyText.textContent.includes('product name or SKU'));clearSearch.dispatchEvent({type:'click'});assert.equal(productSearch.value,'');assert(emptyNotice.hidden);
 row.dataset.artworkStatus='matched';productStatus.dispatchEvent({type:'change'});assert(!emptyNotice.hidden);assert.equal(clearSearch.textContent,'Show all products');clearSearch.dispatchEvent({type:'click'});assert.equal(productStatus.value,'all');assert(emptyNotice.hidden);row.dataset.artworkStatus='missing';
 button.dispatchEvent({type:'click'});assert(dialog.open);assert.equal(catalog.value,'sku');assert.equal(source.value,'');assert(confirm.disabled);assert.equal(grid.children.length,2);assert.equal(nodes['[data-artwork-excluded]'].children.length,2);assert(nodes['[data-artwork-excluded]'].children[0].children[1].children[0].textContent.includes('RED'));assert(nodes['[data-artwork-excluded]'].children[1].children[1].children[0].textContent.includes('Protected'));
 assert(grid.children[0].children[1].children[1].textContent.includes('Linked: Existing catalog design'));
 grid.children[0].dispatchEvent({type:'click'});assert.equal(source.value,'standard');assert(!confirm.disabled);assert.equal(side.value,'single');assert.equal(color.value,'NAVY');
 search.value='absent';search.dispatchEvent({type:'input',target:search});assert.equal(source.value,'standard');assert.equal(standard.disabled,false);
 search.value='red';search.dispatchEvent({type:'input',target:search});assert.equal(nodes['[data-artwork-excluded]'].children.length,1);assert(nodes['.artwork-find-help'].open);
 const correctionAction=nodes['[data-artwork-excluded]'].children[0].children[2].children[1];assert.equal(correctionAction.textContent,'Use & correct color');correctionAction.dispatchEvent({type:'click'});
 assert.equal(source.value,'wrong');assert(confirm.disabled);assert(!nodes['[data-match-color-confirm]'].hidden);const correctionCheck=nodes['[data-match-color-checkbox]'];assert(!correctionCheck.disabled);correctionCheck.checked=true;correctionCheck.dispatchEvent({type:'change'});assert(!confirm.disabled);
 button.dispatchEvent({type:'click'});assert.equal(source.value,'');assert(correctionCheck.disabled&&!correctionCheck.checked);assert(wrong.disabled);assert(confirm.disabled);
 search.value='old name';search.dispatchEvent({type:'input',target:search});assert.equal(grid.children.length,1);assert.equal(grid.children[0].dataset.artworkFile,'unknown');
 button.dispatchEvent({type:'click'});assert.equal(source.value,'');assert(confirm.disabled);assert.equal(search.value,'');assert.equal(grid.children.length,2);
 sku.dataset.scopeReady='0';button.dispatchEvent({type:'click'});assert.equal(grid.children.length,0);assert(nodes['[data-artwork-candidate-empty]'].textContent.includes('missing or ambiguous'));
 sku.dataset.scopeReady='1';sku.dataset.type='double';standard.dataset.side='front';unknown.dataset.side='back';
 button.dispatchEvent({type:'click'});assert(!nodes['[data-match-pair]'].hidden);assert(source.disabled&&side.disabled);assert(confirm.disabled);
 grid.children[0].dispatchEvent({type:'click'});assert.equal(nodes['[data-match-front-input]'].value,'standard');assert(confirm.disabled);
 grid.children[1].dispatchEvent({type:'click'});assert.equal(nodes['[data-match-back-input]'].value,'unknown');assert(!confirm.disabled);assert.equal(confirm.textContent,'Confirm front + back');
 assert(grid.children.every(item=>item.attributes['aria-pressed']==='true'));assert(!nodes['[data-match-pair-mode]'].disabled);
 button.dispatchEvent({type:'click'});assert(confirm.disabled);assert.equal(nodes['[data-match-front-input]'].value,'');assert.equal(nodes['[data-match-back-input]'].value,'');
 standard.dataset.side='';unknown.dataset.side='';button.dispatchEvent({type:'click'});frontSlot.dispatchEvent({type:'click'});grid.children[0].dispatchEvent({type:'click'});backSlot.dispatchEvent({type:'click'});grid.children[1].dispatchEvent({type:'click'});assert(!confirm.disabled);
 console.log('PASS: double-sided chooser retains FRONT and BACK together, requires both, supports manual slots and resets stale pairs');
 console.log('PASS: one worklist opens thumbnail choices, includes linked files, excludes wrong color/parent, clears stale choices and requires confirmation');
}
