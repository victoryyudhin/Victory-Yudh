<main>
  <section class="mx-auto max-w-7xl px-4 pb-14 pt-12 sm:px-6 sm:pt-20 lg:px-8">
    <div class="grid items-center gap-10 lg:grid-cols-[1.05fr_.95fr]">
      <div>
        <div class="inline-flex items-center gap-2 rounded-full border border-violet-300/20 bg-violet-500/10 px-3 py-1.5 text-[10px] font-black uppercase tracking-[.19em] text-violet-200"><span class="h-1.5 w-1.5 rounded-full bg-neon-400"></span> Free Fire tournament arena</div>
        <h1 class="mt-6 max-w-3xl text-5xl font-black leading-[.99] tracking-[-.045em] sm:text-6xl lg:text-7xl">Your next match.<br><span class="bg-gradient-to-r from-violet-300 via-violet-400 to-neon-400 bg-clip-text text-transparent">Your victory.</span></h1>
        <p class="mt-6 max-w-xl text-base leading-7 text-slate-400 sm:text-lg">Find a squad, enter the arena, and climb the leaderboard. Solo, Duo and Squad tournaments with transparent scoring and verified wallet records.</p>
        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
          <a class="btn-primary focus-ring inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3.5 text-sm font-black" href="<?= h(app_url('index.php?page=tournaments')) ?>">Explore tournaments <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
          <?php if (!$current): ?><a class="focus-ring inline-flex items-center justify-center rounded-xl border border-white/10 px-6 py-3.5 text-sm font-bold text-slate-200 hover:bg-white/[.05]" href="<?= h(app_url('index.php?page=register')) ?>">Create your player account</a><?php endif; ?>
        </div>
        <div class="mt-9 flex flex-wrap gap-x-8 gap-y-3 text-xs font-semibold text-slate-400">
          <span><i class="fa-solid fa-shield-halved mr-2 text-neon-400" aria-hidden="true"></i>Verified wallet ledger</span>
          <span><i class="fa-solid fa-ranking-star mr-2 text-violet-300" aria-hidden="true"></i>Clear match scoring</span>
        </div>
      </div>
      <div class="relative mx-auto w-full max-w-xl">
        <div class="absolute -inset-4 rounded-[2rem] bg-violet-600/10 blur-3xl"></div>
        <div class="glass-card relative overflow-hidden rounded-[1.7rem] p-6 sm:p-8">
          <div class="absolute -right-20 -top-24 h-64 w-64 rounded-full border border-violet-300/10"></div>
          <div class="absolute -right-10 -top-14 h-44 w-44 rounded-full border border-violet-300/10"></div>
          <div class="relative flex items-center justify-between">
            <span class="badge badge-open"><span class="mr-2 h-1.5 w-1.5 rounded-full bg-neon-400"></span>Live arena</span>
            <i class="fa-solid fa-crosshairs text-2xl text-violet-300/80" aria-hidden="true"></i>
          </div>
          <div class="relative mt-10">
            <p class="text-[10px] font-black uppercase tracking-[.2em] text-slate-500">Upcoming battles</p>
            <p class="mt-2 text-5xl font-black tracking-tight"><?= h(number_format($stats['upcoming'])) ?><span class="ml-2 text-lg font-bold text-slate-400">tournaments</span></p>
            <p class="mt-2 text-sm text-slate-400">Pick a mode. Bring your best game.</p>
          </div>
          <div class="relative mt-8 grid grid-cols-3 gap-3">
            <?php foreach ([['SOLO','01','fa-user'],['DUO','02','fa-user-group'],['SQUAD','04','fa-users']] as [$mode,$size,$icon]): ?>
              <a href="<?= h(app_url('index.php?page=tournaments&mode=' . strtolower($mode))) ?>" class="focus-ring rounded-xl border border-white/10 bg-black/20 p-3 text-center transition hover:border-violet-300/30 hover:bg-violet-500/10">
                <i class="fa-solid <?= h($icon) ?> text-violet-300" aria-hidden="true"></i><p class="mt-2 text-[10px] font-black tracking-widest"><?= h($mode) ?></p><p class="mt-1 text-[10px] text-slate-500"><?= h($size) ?> player<?= $size === '01' ? '' : 's' ?></p>
              </a>
            <?php endforeach; ?>
          </div>
          <div class="relative mt-6 flex items-center justify-between border-t border-white/[.08] pt-5 text-xs">
            <span class="text-slate-500">Players competing</span><span class="font-black text-white"><?= h(number_format($stats['players'])) ?></span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div><p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300">Your next drop</p><h2 class="mt-2 text-2xl font-black sm:text-3xl">Featured tournaments</h2></div>
      <a class="text-xs font-bold text-violet-300 hover:text-white" href="<?= h(app_url('index.php?page=tournaments')) ?>">Browse all <i class="fa-solid fa-arrow-right ml-1" aria-hidden="true"></i></a>
    </div>
    <div class="mb-5 flex flex-wrap gap-2">
      <?php foreach (['all'=>'All modes','solo'=>'Solo','duo'=>'Duo','squad'=>'Squad'] as $value=>$label): ?>
        <a class="rounded-full border px-4 py-2 text-xs font-bold <?= $modeFilter === $value ? 'border-violet-400/50 bg-violet-500/15 text-violet-200' : 'border-white/10 text-slate-400 hover:text-white' ?>" href="<?= h(app_url('index.php?mode=' . $value)) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (!$tournaments): ?>
      <div class="glass-card rounded-2xl px-6 py-12 text-center"><i class="fa-solid fa-trophy text-2xl text-violet-300" aria-hidden="true"></i><h3 class="mt-4 text-lg font-black">No tournaments yet</h3><p class="mt-2 text-sm text-slate-400">Check back soon for the next Victory Yudh match.</p></div>
    <?php else: ?>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"><?php foreach ($tournaments as $tournament): require __DIR__ . '/../partials/tournament-card.php'; endforeach; ?></div>
    <?php endif; ?>
  </section>

  <?php if ($announcements): ?>
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <div class="glass-card rounded-2xl p-5 sm:p-6"><p class="text-[10px] font-black uppercase tracking-[.2em] text-neon-400">From the arena</p>
        <div class="mt-4 grid gap-4 md:grid-cols-2"><?php foreach ($announcements as $announcement): ?>
          <article class="rounded-xl border border-white/[.08] bg-black/15 p-4"><h3 class="font-bold"><?= h($announcement['title']) ?></h3><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-400"><?= h($announcement['body']) ?></p></article>
        <?php endforeach; ?></div>
      </div>
    </section>
  <?php endif; ?>

  <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
    <div class="grid gap-4 md:grid-cols-3">
      <?php foreach ([['01','Choose your match','Filter by Solo, Duo, or Squad and check the rules, entry fee, and available slots.','fa-list-check'],['02','Join your lineup','Use your wallet to enter. Duo and Squad entries require a captain-led team with the exact player count.','fa-people-group'],['03','Play and climb','Admins enter or import match results. Placement points and kills are calculated and ranked transparently.','fa-chart-line']] as [$number,$title,$copy,$icon]): ?>
        <article class="glass-card rounded-2xl p-5"><div class="flex items-center justify-between"><span class="text-xs font-black tracking-widest text-violet-300"><?= h($number) ?></span><i class="fa-solid <?= h($icon) ?> text-violet-300" aria-hidden="true"></i></div><h3 class="mt-5 text-lg font-black"><?= h($title) ?></h3><p class="mt-2 text-sm leading-6 text-slate-400"><?= h($copy) ?></p></article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="glass-card rounded-2xl p-6 sm:p-8">
      <div class="grid gap-7 md:grid-cols-[.7fr_1.3fr]">
        <div><p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300">Quick answers</p><h2 class="mt-2 text-2xl font-black">Before you drop in</h2><a class="mt-5 inline-flex text-xs font-bold text-violet-300 hover:text-white" href="<?= h(app_url('index.php?page=faq')) ?>">Read all FAQs <i class="fa-solid fa-arrow-right ml-2" aria-hidden="true"></i></a></div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><h3 class="text-sm font-bold">How are scores calculated?</h3><p class="mt-1 text-xs leading-5 text-slate-400">Placement points + kills × points per kill + bonuses − penalties. Each tournament publishes its scoring rules.</p></div>
          <div><h3 class="text-sm font-bold">Does Victory Yudh fetch live game results?</h3><p class="mt-1 text-xs leading-5 text-slate-400">No. Results are entered or imported by authorized admins; the platform calculates totals from that data.</p></div>
          <div><h3 class="text-sm font-bold">When can I see a room password?</h3><p class="mt-1 text-xs leading-5 text-slate-400">Only confirmed participants can access room credentials after the admin-configured release time.</p></div>
          <div><h3 class="text-sm font-bold">How do deposits work?</h3><p class="mt-1 text-xs leading-5 text-slate-400">Pay to the UPI ID shown in your wallet, then submit the amount and transaction ID or UTR. An admin verifies each request before crediting it.</p></div>
        </div>
      </div>
    </div>
  </section>
</main>
