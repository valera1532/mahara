'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '..', 'cms-data.js'), 'utf8');
const defaults = require('../site-defaults.json');
function client(fetch, document = {getElementById(){return null;}}) {
  const sandbox = {window:{MaharaCatalog:{...require('../catalog-data.js'),products:structuredClone(defaults.products)},dispatchEvent(){}}, fetch,
    document, CustomEvent:class {}, Event:class {}};
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

test('ten ordered product photos survive load and save with canonical legacy aliases',async()=>{
  const content={...structuredClone(defaults),revision:8};
  const photos=Array.from({length:10},(_,index)=>`assets/uploads/photo-${index}.webp`);
  Object.assign(content.products[0],{images:photos,image:content.products[0].image,altImage:content.products[0].altImage});
  const calls=[];
  const api=client(async(url,options)=>{calls.push({url,options});return {ok:true,json:async()=>url.endsWith('/session')?{csrfToken:'csrf'}:content};});
  const loaded=await api.load();
  assert.deepEqual(JSON.parse(JSON.stringify(loaded.products[0].images)),photos);
  assert.equal(loaded.products[0].image,photos[0]);assert.equal(loaded.products[0].altImage,photos[1]);
  const saved=await api.save(loaded);
  const payload=JSON.parse(calls.find(call=>call.options?.method==='PUT').options.body);
  assert.deepEqual(payload.products[0].images,photos);
  assert.deepEqual(JSON.parse(JSON.stringify(saved.products[0].images)),photos);
});

test('legacy photo pairs and a single image normalize without duplicates',async()=>{
  const content=structuredClone(defaults);
  content.products[1].altImage=content.products[1].image;
  const api=client(async()=>({ok:true,json:async()=>content}));const value=await api.load();
  assert.deepEqual(JSON.parse(JSON.stringify(value.products[0].images)),[content.products[0].image,content.products[0].altImage]);
  assert.deepEqual(JSON.parse(JSON.stringify(value.products[1].images)),[content.products[1].image]);
  assert.equal(value.products[1].altImage,value.products[1].image);
  assert.deepEqual(JSON.parse(JSON.stringify(api.productImages({image:'',altImage:''}))),[]);
});

test('photo helper preserves unique order and rejects external or unsafe paths',()=>{
  const api=client(async()=>({ok:true,json:async()=>defaults}));
  const value=api.productImages({images:['assets/uploads/two.png','https://other.example/a.jpg','assets/uploads/../secret.jpg','assets/uploads/one.webp','assets/uploads/two.png']});
  assert.deepEqual(JSON.parse(JSON.stringify(value)),['assets/uploads/two.png','assets/uploads/one.webp']);
});

test('legacy and partial category photos fall back independently without accepting unsafe paths',async()=>{
  const content=structuredClone(defaults);
  delete content.sections.categories;
  const api=client(async()=>({ok:true,json:async()=>content}));
  const expected=api.getDefaults().sections.categories;
  assert.deepEqual(JSON.parse(JSON.stringify((await api.load()).sections.categories)),JSON.parse(JSON.stringify(expected)));
  content.sections.categories={hoodieImage:'assets/uploads/hoodie.webp',hoodieSecondaryImage:'https://other.example/hoodie.jpg',teeImage:'assets/uploads/../secret.png'};
  const loaded=await api.load();
  assert.equal(loaded.sections.categories.hoodieImage,'assets/uploads/hoodie.webp');
  for (const key of ['hoodieSecondaryImage','teeImage','teeSecondaryImage']) assert.equal(loaded.sections.categories[key],expected[key]);
});

test('all four category photos survive load and save without changing hero or product photos',async()=>{
  const content={...structuredClone(defaults),revision:12};
  const categories={hoodieImage:'assets/uploads/hoodie-main.jpg',hoodieSecondaryImage:'assets/uploads/hoodie-back.png',teeImage:'assets/uploads/tee-main.webp',teeSecondaryImage:'assets/uploads/tee-back.jpg'};
  content.sections.categories=categories;
  const calls=[];
  const api=client(async(url,options)=>{
    calls.push({url,options});
    return {ok:true,json:async()=>url.endsWith('/session')?{csrfToken:'csrf'}:content};
  });
  const loaded=await api.load();
  const saved=await api.save(loaded);
  const payload=JSON.parse(calls.find(call=>call.options?.method==='PUT').options.body);
  assert.deepEqual(payload.sections.categories,categories);
  assert.deepEqual(JSON.parse(JSON.stringify(saved.sections.categories)),categories);
  assert.deepEqual(payload.sections.hero,content.sections.hero);
  assert.equal(saved.products[0].image,content.products[0].image);
  assert.equal(saved.products[0].altImage,content.products[0].altImage);
});

test('category hydration updates all four images and removes stale srcset on every apply',()=>{
  const mapping={'#hoodies .category-card__front':'hoodieImage','#hoodies .category-card__back':'hoodieSecondaryImage','#tshirts .category-card__front':'teeImage','#tshirts .category-card__back':'teeSecondaryImage'};
  const nodes=Object.fromEntries(Object.keys(mapping).map(selector=>[selector,{src:'old.jpg',attributes:new Map([['srcset','old-large.jpg 2x']]),removeAttribute(name){this.attributes.delete(name);}}]));
  const document={getElementById(){return null;},querySelector(selector){return nodes[selector]||null;},querySelectorAll(){return [];}};
  const api=client(async()=>{throw new Error('No network expected');},document);
  const content=api.getDefaults();
  for (const key of Object.values(mapping)) content.sections.categories[key]=`assets/uploads/${key}.webp`;
  api.apply(content);
  for (const [selector,key] of Object.entries(mapping)) {
    assert.equal(nodes[selector].src,content.sections.categories[key]);
    assert.equal(nodes[selector].attributes.has('srcset'),false);
  }
  content.sections.categories.teeSecondaryImage='assets/uploads/updated-tee.webp';
  api.apply(content);
  assert.equal(nodes['#tshirts .category-card__back'].src,'assets/uploads/updated-tee.webp');
  assert.equal(nodes['#hoodies .category-card__front'].src,content.sections.categories.hoodieImage);
});
