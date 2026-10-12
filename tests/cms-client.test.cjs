'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '..', 'cms-data.js'), 'utf8');
const defaults = require('../site-defaults.json');
function client(fetch) {
  const sandbox = {window:{MaharaCatalog:{...require('../catalog-data.js'),products:structuredClone(defaults.products)},dispatchEvent(){}}, fetch,
    document:{getElementById(){return null;}}, CustomEvent:class {}, Event:class {}};
  vm.runInNewContext(source,sandbox);
  return sandbox.window.MaharaCMS;
}
test('new products and published revision survive normalization',async()=>{
  const content=structuredClone(defaults); content.revision=42;
  content.products.push({...content.products[0],id:'new-product',url:'product-new-product.html',title:'Новая вещь',image:'assets/uploads/photo.jpg'});
  const value=await client(async()=>({ok:true,json:async()=>content})).load();
  assert.equal(value.revision,42); assert.equal(value.products.length,9);
  assert.equal(value.products[8].url,'product-new-product.html');
  assert.equal(value.products[8].image,'assets/uploads/photo.jpg');
});
test('optional product facts preserve literal values, trim and cap independently',async()=>{
  const content=structuredClone(defaults);
  Object.assign(content.products[0], {composition:'  100% хлопок  ',care:'<img src=x onerror=alert(1)>\nБережная стирка',manufacturer:'ИП Тест, Россия',measurements:'М'.repeat(2100)});
  const value=await client(async()=>({ok:true,json:async()=>content})).load();
  assert.equal(value.products[0].composition,'100% хлопок');
  assert.equal(value.products[0].care,'<img src=x onerror=alert(1)>\nБережная стирка');
  assert.equal(value.products[0].manufacturer,'ИП Тест, Россия');
  assert.equal(value.products[0].measurements.length,2000);
  for (const key of ['composition','care','manufacturer','measurements']) assert.equal(value.products[1][key],'');
});
test('legacy and invalid fact fields become empty without borrowing another product facts',async()=>{
  const content=structuredClone(defaults);
  Object.assign(content.products[0], {composition:42,care:null,manufacturer:[],measurements:{}});
  content.products.push({...content.products[0],id:'legacy-new',composition:undefined});
  const api=client(async()=>({ok:true,json:async()=>content}));
  const value=await api.load();
  for (const product of [value.products[0],value.products.at(-1),...api.getDefaults().products]) {
    for (const key of ['composition','care','manufacturer','measurements']) assert.equal(product[key],'');
  }
});
test('save sends original revision and server csrf; returns published revision',async()=>{
  const calls=[]; const content={...structuredClone(defaults),revision:4};
  const api=client(async(url,options)=>{
    calls.push({url,options});
    return {ok:true,json:async()=>url.endsWith('/session')?{csrfToken:'csrf'}:{...content,revision:5}};
  });
  assert.equal((await api.save(content)).revision,5);
  assert.equal(JSON.parse(calls[1].options.body).revision,4);
  assert.equal(calls[1].options.headers['X-CSRF-Token'],'csrf');
});
test('conflicting revisions surface server message/status',async()=>{
  const api=client(async url=>url.endsWith('/session')?{ok:true,json:async()=>({csrfToken:'csrf'})}:{ok:false,status:409,json:async()=>({error:'Версия изменилась'})});
  await assert.rejects(api.save({...defaults,revision:1}),error=>error.status===409&&error.message==='Версия изменилась');
});
