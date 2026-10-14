document.addEventListener('click', (event) => {
  const toggle = event.target.closest('[data-menu-toggle]');
  if (toggle) {
    const menu = document.querySelector('[data-admin-menu]');
    if (menu) menu.classList.toggle('hidden');
  }
  const confirmTarget = event.target.closest('[data-confirm]');
  if (confirmTarget && !window.confirm(confirmTarget.dataset.confirm || 'Are you sure?')) {
    event.preventDefault();
  }
});
