(() => {
 const partner=document.querySelector('meta[name="production-partner"]')?.content;
 if(!partner)return;
 document.querySelectorAll('form:not([data-partner-filter])').forEach(form=>{
  if(!form.querySelector('[name="partner"]')){const input=document.createElement('input');input.type='hidden';input.name='partner';input.value=partner;form.append(input);}
 });
 document.querySelectorAll('a[href]').forEach(link=>{try{const url=new URL(link.href,location.href);if(url.origin===location.origin && !url.searchParams.has('partner') && ['http:','https:'].includes(url.protocol)){url.searchParams.set('partner',partner);link.href=url.href;}}catch(_){}});
 const original=window.fetch.bind(window);
 window.fetch=(input,options={})=>{try{const url=new URL(typeof input==='string'?input:input.url,location.href);if(url.origin===location.origin){const headers=new Headers(options.headers||(input instanceof Request?input.headers:undefined));headers.set('X-Production-Partner',partner);options={...options,headers};}}catch(_){}return original(input,options);};
})();
