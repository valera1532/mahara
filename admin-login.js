(() => {
  'use strict';
  const form = document.querySelector('[data-admin-login]');
  if (!form) return;
  const submit = document.querySelector('[data-login-submit]');
  const errorNode = document.querySelector('[data-login-error]');
  let busy = false;

  fetch('/api/admin/session', { credentials: 'same-origin', cache: 'no-store' })
    .then(response => { if (response.ok) window.location.replace('/admin.html'); })
    .catch(() => {});

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !form.reportValidity()) return;
    busy = true;
    submit.disabled = true;
    submit.querySelector('span').textContent = 'Входим…';
    errorNode.textContent = '';
    const username = form.elements.username.value.trim();
    const password = form.elements.password.value;
    try {
      const response = await fetch('/api/admin/login', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password })
      });
      if (response.ok) {
        form.elements.password.value = '';
        window.location.replace('/admin.html');
        return;
      }
      if (response.status === 401) errorNode.textContent = 'Неверный логин или пароль.';
      else if (response.status === 429) errorNode.textContent = 'Слишком много попыток входа. Подождите немного и попробуйте снова.';
      else if (response.status === 503) errorNode.textContent = 'Доступ к управлению ещё не настроен на сервере. Обратитесь к владельцу сайта.';
      else errorNode.textContent = 'Не удалось войти. Попробуйте снова.';
      form.elements.password.value = '';
      form.elements.password.focus();
    } catch {
      errorNode.textContent = 'Нет связи с сервером. Проверьте подключение и попробуйте снова.';
    } finally {
      busy = false;
      submit.disabled = false;
      submit.querySelector('span').textContent = 'Войти';
    }
  });
})();
