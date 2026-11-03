const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
(async()=>{
 const label={textContent:''};const badge={dataset:{statusUrl:'/workspace/factory-status'},querySelector:()=>label};
 const intervals=[],timeouts=new Map();let timer=0,calls=0,fail=false;
 const context={document:{hidden:false,querySelector:s=>s==='[data-factory-connection]'?badge:null,addEventListener(){}},AbortController,Date,
  setTimeout(fn,delay){timeouts.set(++timer,{fn,delay});return timer;},clearTimeout(id){timeouts.delete(id);},setInterval(fn,delay){intervals.push({fn,delay});},
  async fetch(url,options){calls++;assert.equal(url,'/workspace/factory-status');assert.equal(options.redirect,'error');assert.equal(options.cache,'no-store');return {ok:!fail,headers:{get:()=> 'application/json'},json:async()=>({state:'online',online:1,expires_in_seconds:120})};}};
 vm.runInNewContext(fs.readFileSync('public/js/factory-monitor.js','utf8'),context);
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(badge.dataset.state,'online');assert.equal(label.textContent,'Factory: online');assert.equal(calls,1);
 const expiry=[...timeouts.values()].find(t=>t.delay>100000);assert(expiry);expiry.fn();assert.equal(badge.dataset.state,'offline');
 fail=true;await intervals.find(t=>t.delay===30000).fn();assert.equal(badge.dataset.state,'unavailable');assert.equal(label.textContent,'Factory: unavailable');
 assert(!label.textContent.includes('SHA')&&!label.textContent.includes('key'));
 console.log('PASS: shared Factory badge uses read-only fetch, expires healthy state and handles unavailable monitoring without private details');
})().catch(error=>{console.error(error);process.exitCode=1;});
