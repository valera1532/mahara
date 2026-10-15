(() => {
  'use strict';
  const $ = selector => document.querySelector(selector);
  const types = {receipt:'Приход',order:'Заказ',writeoff:'Списание',set:'Корректировка'};
  const statuses = {payment_pending:'Ожидает оплаты',paid:'Оплачен',canceled:'Отменён',expired:'Время оплаты истекло',failed:'Ошибка оплаты',refunded:'Оплата возвращена',partial_refunded:'Частичный возврат',refund_pending:'Возврат обрабатывается'};
  const money = amount => new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB'}).format(amount/100);
  let session, generation=0, timer, pagination={page:1,pageSize:50,pages:1,total:0};
  const el = (tag,text,className) => { const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(className)node.className=className;return node; };
  function error(text) { $('[data-error]').textContent=text;$('[data-error]').hidden=!text; }
  async function request(url,options={}) {
    const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),20000);
    try {
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',redirect:'error',...options,signal:controller.signal});
      const data=await response.json().catch(()=>({}));
      if(response.status===401){location.assign('/admin/login');throw Error('Войдите в панель управления.');}
      if(!response.ok)throw Error(data.error || 'Не удалось загрузить журнал.');
      return data;
    } catch(e){if(e.name==='AbortError')throw Error('Ответ задерживается. Нажмите «Обновить».');throw e;}
    finally{clearTimeout(timeout);}
  }
  function render(data) {
    const body=$('[data-entries]');body.replaceChildren();
    for(const entry of data.entries || []) {
      const row=el('tr');row.dataset.operationId=entry.id;
      const when=el('td');const date=new Date(entry.createdAt);when.append(el('div',Number.isNaN(date.getTime())?'—':date.toLocaleString('ru-RU',{dateStyle:'short',timeStyle:'short'})),el('small',entry.actor));
      const operation=el('td');operation.append(el('span',types[entry.kind] || entry.kind,`operations-badge operations-badge--${entry.kind}`));
      if(entry.order)operation.append(el('small',statuses[entry.order.status] || entry.order.status));
      const items=el('td');
      for(const item of entry.items || []){const line=el('div',undefined,'operations-item');line.append(el('div',item.title),el('small',`${item.productId} · ${item.size} · ${item.quantity} шт.`));items.append(line);}
      const balances=el('td');
      if(entry.stockChanges?.length)for(const change of entry.stockChanges){const line=el('div',`${change.before===null?'Не задано':change.before} → ${change.after}`,'operations-balance');if(entry.stockChanges.length>1)line.append(el('small',`${change.productId} · ${change.size}`));balances.append(line);}
      else balances.append(el('div','Без списания'));
      const note=el('td');
      if(entry.order){const link=el('a',entry.order.number+' ↗','operations-order-link');link.href='/orders.html?'+new URLSearchParams({order:entry.order.id,q:entry.order.number});note.append(link,el('small',money(entry.order.totalAmount)),el('small',entry.order.stockApplied?'Товар списан после оплаты':'Товар со склада не списан'));}
      else {note.append(el('div',entry.comment || '—'));const quantity=entry.items?.[0]?.quantity;if(quantity!==undefined)operation.append(el('small',entry.kind==='receipt'?`+${quantity} шт.`:entry.kind==='writeoff'?`−${quantity} шт.`:`Факт: ${entry.stockChanges?.[0]?.after ?? quantity} шт.`));}
      row.append(when,operation,items,balances,note);body.append(row);
    }
    pagination=data.pagination;$('[data-page-prev]').disabled=pagination.page<=1;$('[data-page-next]').disabled=pagination.page>=pagination.pages;
    $('[data-page-info]').textContent=`Страница ${pagination.page} из ${pagination.pages}`;
    $('[data-empty]').hidden=!!data.entries?.length;
    $('[data-status]').textContent=`Найдено операций: ${pagination.total}. Заказы показаны с текущим статусом; списание после оплаты учтено внутри заказа.`;
  }
  async function load(page=pagination.page) {
    const own=++generation;error('');$('[data-refresh]').disabled=true;
    const params=new URLSearchParams({page:String(page),pageSize:String(pagination.pageSize),q:$('[data-search]').value.trim(),kind:$('[data-kind]').value});
    try{const data=await request('/api/admin/operations?'+params);if(own===generation)render(data);}
    catch(e){if(own===generation){error(e.message);$('[data-status]').textContent='Не удалось загрузить журнал.';}}
    finally{if(own===generation)$('[data-refresh]').disabled=false;}
  }
  $('[data-refresh]').addEventListener('click',()=>load());
  $('[data-search]').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>load(1),250);});
  $('[data-kind]').addEventListener('change',()=>{clearTimeout(timer);load(1);});
  $('[data-page-prev]').addEventListener('click',()=>load(pagination.page-1));$('[data-page-next]').addEventListener('click',()=>load(pagination.page+1));
  $('[data-logout]').addEventListener('click',async event=>{const button=event.currentTarget;button.disabled=true;try{await request('/api/admin/logout',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrfToken},body:'{}'});location.assign('/admin/login');}catch(e){error(e.message);button.disabled=false;}});
  request('/api/admin/session').then(value=>{session=value;$('[data-logout]').disabled=false;return load();}).catch(e=>error(e.message));
})();
