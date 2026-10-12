(() => {
  'use strict';
  const $ = selector => document.querySelector(selector);
  const names = {pending:'Ожидает оплаты',payment_pending:'Ожидает оплаты',paid:'Оплачен',canceled:'Отменён',expired:'Время оплаты истекло',failed:'Ошибка оплаты',refunded:'Оплата возвращена',partial_refunded:'Частичный возврат',refund_pending:'Возврат обрабатывается'};
  const money = value => new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB'}).format(Number(value)/100);
  let session, orders = [], refundOrder, refundRequestId, listGeneration = 0, searchTimer;
  let pagination = {page:1,pageSize:50,total:0,pages:1};
  function el(tag, className, text) { const node = document.createElement(tag); if(className) node.className=className; if(text!==undefined) node.textContent=text; return node; }
  async function request(url, options = {}) {
    const controller = new AbortController(); const timer = setTimeout(()=>controller.abort(),25000);
    try {
      const response = await fetch(url,{credentials:'same-origin',cache:'no-store',redirect:'error',...options,signal:controller.signal});
      const data = await response.json().catch(()=>({}));
      if(response.status===401) { location.assign('/admin/login'); throw new Error('Войдите в панель управления.'); }
      if(!response.ok) throw new Error(data.error || 'Не удалось выполнить действие.');
      return data;
    } catch(error) { if(error.name==='AbortError') throw new Error('Ответ задерживается. Обновите список перед повторной попыткой.'); throw error; }
    finally { clearTimeout(timer); }
  }
  function errorMessage(message) { $('[data-error]').textContent=message; $('[data-error]').hidden=!message; }
  function write(url, body) { return request(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrfToken},body:JSON.stringify(body)}); }
  function orderId(order) { return encodeURIComponent(order.id); }
  function render() {
    const container=$('[data-orders]'); const openIds=new Set([...container.querySelectorAll('details[open]')].map(node=>node.dataset.orderId));
    container.replaceChildren();
    $('[data-page-info]').textContent=`Страница ${pagination.page} из ${pagination.pages} · всего ${pagination.total}`;
    $('[data-page-prev]').disabled=pagination.page<=1;
    $('[data-page-next]').disabled=pagination.page>=pagination.pages;
    if(!orders.length) { container.append(el('p','orders-empty',$('[data-search]').value.trim() || $('[data-filter]').value!=='all'?'Заказы по выбранным условиям не найдены.':'Заказов пока нет. Здесь появятся покупки с сайта.')); return; }
    for(const order of orders) {
      const card=el('details','orders-card'); card.dataset.orderId=order.id; card.open=openIds.has(order.id);
      const summary=el('summary'); const heading=el('div'); heading.append(el('strong','',`Заказ ${order.number || order.id}`));
      const date=new Date(typeof order.createdAt==='number'?order.createdAt*1000:order.createdAt); heading.append(el('small','',Number.isNaN(date.getTime())?'':date.toLocaleString('ru-RU')));
      summary.append(heading,el('span',`orders-badge orders-badge--${Object.hasOwn(names,order.status)?order.status:'pending'}`,names[order.status]||order.status),el('b','',money(order.totalAmount)));
      const body=el('div','orders-card__body'); const details=el('div','orders-card__details');
      const contact=el('section'); contact.append(el('h2','','Покупатель')); const customer=order.customer||{};
      for(const text of [customer.name,customer.phone,customer.email].filter(Boolean)) contact.append(el('p','',text));
      const shipping=el('section'); shipping.append(el('h2','','Доставка СДЭК')); const delivery=order.delivery||{};
      for(const text of [delivery.city,delivery.point?`ПВЗ: ${delivery.point}`:'',delivery.address,delivery.label].filter(Boolean)) shipping.append(el('p','',text));
      if(Number.isSafeInteger(delivery.shippingAmount)) shipping.append(el('p','',`Расчёт доставки: ${money(delivery.shippingAmount)} — покупатель оплачивает СДЭК отдельно.`));
      details.append(contact,shipping); body.append(details);
      const items=el('ul','orders-items');
      for(const item of order.items||[]) { const line=el('li'); const name=el('div','',item.title||item.id); name.append(el('small','',`Размер ${item.size} · ${item.qty} шт.`)); line.append(name,el('span','',money(item.amount ?? item.unitAmount*item.qty))); items.append(line); }
      body.append(items);
      if(order.deliveryAmount) body.append(el('p','',`Доставка: ${money(order.deliveryAmount)}`));
      if(order.operatorMessage) body.append(el('p','orders-review',order.operatorMessage));
      if(order.providerErrorCode) body.append(el('p','orders-card__hint',`Код ответа банка: ${order.providerErrorCode}. Сообщите его поддержке Т-Кассы.`));
      if(order.acceptance?.acceptedAt) {
        const accepted=new Date(order.acceptance.acceptedAt);
        body.append(el('p','orders-card__hint',`Условия приняты: ${Number.isNaN(accepted.getTime())?order.acceptance.acceptedAt:accepted.toLocaleString('ru-RU')} · версия ${order.acceptance.legalVersion || 'не указана'}.`));
      }
      if(order.status==='paid') {
        const form=el('form','orders-fulfillment'); const statusLabel=el('label','','Обработка заказа'); const select=el('select'); select.setAttribute('aria-label',`Статус заказа ${order.number}`);
        for(const [value,label] of [['processing','Собирается'],['shipped','Отправлен'],['completed','Получен']]) { const option=el('option','',label);option.value=value;select.append(option); }
        select.value=order.fulfillmentStatus||order.fulfillment?.status||'processing'; statusLabel.append(select);
        const trackingLabel=el('label','','Номер отправления СДЭК'); const tracking=el('input');tracking.maxLength=80;tracking.value=order.trackingNumber||order.fulfillment?.trackingNumber||'';trackingLabel.append(tracking);
        const save=el('button','orders-button','Сохранить');save.type='submit';form.append(statusLabel,trackingLabel,save);
        form.addEventListener('submit',async event=>{event.preventDefault();save.disabled=true;errorMessage('');try{await write(`/api/admin/orders/${orderId(order)}/fulfillment`,{status:select.value,trackingNumber:tracking.value.trim()});await load();$('[data-status]').textContent='Статус заказа сохранён.';}catch(error){errorMessage(error.message);}finally{save.disabled=false;}});
        body.append(form);
      }
      const actions=el('div','orders-card__actions'); actions.append(el('p','orders-card__hint','Статус оплаты подтверждает банк.'));
      const reconcile=el('button','orders-button','Проверить статус в банке');reconcile.type='button';reconcile.dataset.reconcile=order.id;
      reconcile.addEventListener('click',async()=>{
        reconcile.disabled=true;errorMessage('');
        try{const result=await write(`/api/admin/orders/${orderId(order)}/reconcile`,{});await load();$('[data-status]').textContent=result.order?.operatorMessage || `Статус заказа: ${names[result.order?.status] || result.order?.status || 'ожидает проверки'}. При задержке сверяйте операцию в кабинете Т-Кассы.`;}
        catch(error){errorMessage(error.message);}
        finally{reconcile.disabled=false;}
      });
      actions.append(reconcile);
      if(order.status==='paid') { const refund=el('button','orders-button orders-button--danger','Вернуть всю оплату');refund.type='button';refund.addEventListener('click',()=>openRefund(order));actions.append(refund); }
      body.append(actions);card.append(summary,body);container.append(card);
    }
  }
  async function load(page=pagination.page) {
    const generation=++listGeneration;$('[data-refresh]').disabled=true;errorMessage('');
    const params=new URLSearchParams({page:String(page),pageSize:String(pagination.pageSize),q:$('[data-search]').value.trim(),status:$('[data-filter]').value});
    try {
      const data=await request(`/api/admin/orders?${params}`);if(generation!==listGeneration)return;
      orders=Array.isArray(data.orders)?data.orders:[];
      pagination=data.pagination || {page:1,pageSize:50,total:orders.length,pages:1};
      render();$('[data-status]').textContent=`Найдено заказов: ${pagination.total}. Страница ${pagination.page} из ${pagination.pages}.`;
    }
    catch(error){if(generation===listGeneration){errorMessage(error.message);$('[data-status]').textContent='Не удалось загрузить заказы.';}}
    finally{if(generation===listGeneration)$('[data-refresh]').disabled=false;}
  }
  function openRefund(order) {
    if(refundOrder?.id!==order.id) refundRequestId=crypto.randomUUID();refundOrder=order;
    $('[data-refund-description]').textContent=`Заказ ${order.number}: возврат ${money(order.totalAmount)} покупателю.`;
    $('[data-refund-error]').hidden=true;$('[data-refund-dialog]').showModal();$('[data-refund-cancel]').focus();
  }
  $('[data-refund-confirm]').addEventListener('click',async()=>{
    if(!refundOrder)return;const button=$('[data-refund-confirm]');button.disabled=true;$('[data-refund-cancel]').disabled=true;
    try{const result=await write(`/api/admin/orders/${orderId(refundOrder)}/refund`,{requestId:refundRequestId});$('[data-refund-dialog]').close();refundOrder=null;refundRequestId=null;await load();$('[data-status]').textContent=result.order?.operatorMessage || 'Запрос возврата обработан. Проверьте статус заказа.';}
    catch(error){$('[data-refund-error]').textContent=error.message;$('[data-refund-error]').hidden=false;}
    finally{button.disabled=false;$('[data-refund-cancel]').disabled=false;}
  });
  $('[data-refund-dialog]').addEventListener('cancel',event=>{if($('[data-refund-confirm]').disabled)event.preventDefault();});
  $('[data-refresh]').addEventListener('click',()=>load());
  $('[data-search]').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>load(1),250);});
  $('[data-filter]').addEventListener('change',()=>{clearTimeout(searchTimer);load(1);});
  $('[data-page-prev]').addEventListener('click',()=>load(pagination.page-1));
  $('[data-page-next]').addEventListener('click',()=>load(pagination.page+1));
  $('[data-logout]').addEventListener('click',async()=>{try{await write('/api/admin/logout',{});location.assign('/admin/login');}catch(error){errorMessage(error.message);}});
  request('/api/admin/session').then(data=>{session=data;return load();}).catch(error=>errorMessage(error.message));
})();
