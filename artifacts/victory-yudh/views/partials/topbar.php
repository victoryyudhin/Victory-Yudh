<header class="sticky top-0 z-40 border-b border-white/[.08] bg-[#080916]/90 backdrop-blur-xl">
  <div class="mx-auto flex h-[68px] max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
    <a class="focus-ring flex items-center gap-3 rounded-lg" href="<?= h(app_url('index.php')) ?>" aria-label="Victory Yudh home">
      <span class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-violet-500 to-indigo-700 text-lg font-black shadow-lg shadow-violet-950/50">V</span>
      <span class="leading-tight"><span class="block text-sm font-black tracking-[.12em]">VICTORY <span class="text-violet-300">YUDH</span></span><span class="block text-[9px] font-semibold tracking-[.25em] text-slate-500">FREE FIRE ARENA</span></span>
    </a>
    <nav class="hidden items-center gap-7 text-sm font-semibold text-slate-300 md:flex" aria-label="Main navigation">
      <a class="transition hover:text-white" href="<?= h(app_url('index.php?page=tournaments')) ?>">Tournaments</a>
      <a class="transition hover:text-white" href="<?= h(app_url('index.php?page=leaderboard')) ?>">Leaderboard</a>
      <a class="transition hover:text-white" href="<?= h(app_url('index.php?page=support')) ?>">Support</a>
      <?php if ($current): ?>
        <a class="transition hover:text-white" href="<?= h(app_url('index.php?page=dashboard')) ?>">Dashboard</a>
        <?php if ($current['role'] === 'admin'): ?><a class="text-violet-300 hover:text-violet-200" href="<?= h(app_url('index.php?page=admin')) ?>">Admin</a><?php endif; ?>
      <?php endif; ?>
    </nav>
    <div class="flex items-center gap-2">
      <?php if ($current): ?>
        <a class="focus-ring hidden rounded-full border border-white/10 px-3 py-2 text-xs font-bold text-slate-200 sm:inline-flex" href="<?= h(app_url('index.php?page=notifications')) ?>" aria-label="Notifications"><i class="fa-regular fa-bell mr-2 text-violet-300" aria-hidden="true"></i>Updates</a>
        <a class="focus-ring hidden max-w-36 truncate rounded-full bg-white/[.06] px-3 py-2 text-xs font-bold text-white sm:inline-flex" href="<?= h(app_url('index.php?page=profile')) ?>"><?= h($current['display_name'] ?: $current['email']) ?></a>
        <form method="post" action="<?= h(app_url('index.php?action=logout')) ?>" class="hidden sm:block">
          <?= csrf_field() ?>
          <button class="focus-ring rounded-xl px-3 py-2 text-xs font-bold text-slate-400 hover:text-white">Log out</button>
        </form>
      <?php else: ?>
        <a class="focus-ring rounded-xl px-3 py-2 text-xs font-bold text-slate-200 hover:text-white" href="<?= h(app_url('index.php?page=login')) ?>">Log in</a>
        <a class="btn-primary focus-ring rounded-xl px-3 py-2 text-xs font-black" href="<?= h(app_url('index.php?page=register')) ?>">Join free</a>
      <?php endif; ?>
    </div>
  </div>
</header>
