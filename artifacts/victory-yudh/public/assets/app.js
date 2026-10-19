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

document.addEventListener('submit', (event) => {
  const form = event.target.closest('[data-bulk-results]');
  if (!form) return;

  const rows = [...form.querySelectorAll('[data-bulk-result-row]')].map((row) => {
    const value = (field) => Number(row.querySelector(`[data-result-field="${field}"]`).value);
    return {
      registration_id: Number(row.dataset.registrationId),
      placement_rank: value('placement_rank'),
      kills: value('kills'),
      bonus_points: value('bonus_points'),
      penalty_points: value('penalty_points'),
    };
  });
  form.querySelector('[name="results_json"]').value = JSON.stringify(rows);
});
