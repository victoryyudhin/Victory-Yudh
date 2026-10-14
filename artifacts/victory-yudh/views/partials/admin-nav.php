<aside class="border-b border-white/10 bg-[#0c0d20] lg:sticky lg:top-0 lg:h-screen lg:border-b-0 lg:border-r">
  <div class="flex items-center justify-between px-5 py-5 lg:block">
    <a class="flex items-center gap-3" href="<?= h(app_url('index.php?page=admin')) ?>">
      <span class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-violet-500 to-indigo-700 font-black">V</span>
      <span><span class="block text-sm font-black tracking-widest">VICTORY YUDH</span><span class="text-[10px] font-bold uppercase tracking-[.18em] text-violet-300">Admin control</span></span>
    </a>
    <button type="button" data-menu-toggle class="focus-ring rounded-lg border border-white/10 px-3 py-2 text-slate-300 lg:hidden" aria-label="Open admin navigation"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
  </div>
  <nav data-admin-menu class="hidden px-3 pb-4 lg:block lg:px-4" aria-label="Admin navigation">
    <p class="px-3 pb-2 pt-1 text-[10px] font-bold uppercase tracking-[.18em] text-slate-500">Workspace</p>
    <?php
    $adminLinks = [
      ['admin','Overview','fa-chart-pie'],
      ['admin-tournaments','Tournaments','fa-trophy'],
      ['admin-results','Results & scoring','fa-ranking-star'],
      ['admin-users','Users & teams','fa-users'],
      ['admin-wallet','Payments & wallet','fa-wallet'],
      ['admin-withdrawals','Withdrawals','fa-money-bill-transfer'],
      ['admin-announcements','Announcements','fa-bullhorn'],
      ['admin-support','Support inbox','fa-headset'],
      ['admin-audit','Audit log','fa-shield-halved'],
      ['admin-settings','Settings','fa-sliders'],
    ];
    foreach ($adminLinks as [$target,$label,$icon]):
      $active = ($page === $target) || ($page === 'admin' && $target === 'admin');
    ?>
      <a class="focus-ring mb-1 flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-semibold transition <?= $active ? 'bg-violet-500/15 text-violet-200' : 'text-slate-400 hover:bg-white/[.05] hover:text-white' ?>" href="<?= h(app_url('index.php?page=' . $target)) ?>">
        <i class="fa-solid <?= h($icon) ?> w-5 text-center" aria-hidden="true"></i><?= h($label) ?>
      </a>
    <?php endforeach; ?>
    <div class="my-4 border-t border-white/10"></div>
    <a class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-semibold text-slate-400 hover:bg-white/[.05] hover:text-white" href="<?= h(app_url('index.php')) ?>"><i class="fa-solid fa-arrow-left w-5 text-center" aria-hidden="true"></i>Public site</a>
  </nav>
</aside>
