<main class="mx-auto max-w-7xl px-4 py-8 pb-28 sm:px-6 lg:px-8 md:pb-12">
  <a class="text-xs font-bold text-slate-400 hover:text-white" href="<?= h(app_url('index.php?page=tournaments')) ?>"><i class="fa-solid fa-arrow-left mr-2" aria-hidden="true"></i>All tournaments</a>
  <div class="mt-5 grid items-start gap-5 lg:grid-cols-[1fr_340px]">
    <section class="min-w-0">
      <div class="glass-card overflow-hidden rounded-2xl">
        <div class="relative min-h-56 overflow-hidden bg-[radial-gradient(ellipse_at_75%_10%,rgba(144,97,255,.45),transparent_40%),linear-gradient(120deg,#171832,#0d0e22)] p-6 sm:p-9">
          <div class="absolute -right-8 -top-10 h-64 w-64 rounded-full border border-violet-200/10"></div>
          <span class="badge badge-soon"><?= h(strtoupper($tournament['mode'])) ?> · <?= h($tournament['team_size']) ?> PLAYER<?= (int) $tournament['team_size'] === 1 ? '' : 'S' ?></span>
          <h1 class="relative mt-7 max-w-2xl text-3xl font-black leading-tight sm:text-4xl"><?= h($tournament['title']) ?></h1>
          <p class="relative mt-3 text-sm text-slate-300"><?= h($tournament['map_name']) ?> <span class="mx-2 text-slate-600">/</span> <?= h(date('D, d M Y · g:i A', strtotime((string) $tournament['match_time'] . ' UTC'))) ?> IST</p>
        </div>
        <div class="grid grid-cols-2 gap-px bg-white/[.07] sm:grid-cols-4">
          <?php foreach ([['Prize pool',format_money((int)$tournament['prize_pool_minor']),'fa-trophy'],['Entry fee',format_money((int)$tournament['entry_fee_minor']),'fa-ticket'],['Map',$tournament['map_name'],'fa-map'],['Players',$occupied.' / '.$tournament['participant_limit'],'fa-users']] as [$label,$value,$icon]): ?>
            <div class="bg-[#121328] p-4 sm:p-5"><p class="text-[10px] font-bold uppercase tracking-[.15em] text-slate-500"><?= h($label) ?></p><p class="mt-2 flex items-center gap-2 font-black"><i class="fa-solid <?= h($icon) ?> text-xs text-violet-300" aria-hidden="true"></i><?= h($value) ?></p></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="mt-5 grid gap-5 md:grid-cols-2">
        <section class="glass-card rounded-2xl p-5"><h2 class="text-lg font-black">Match overview</h2><p class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-400"><?= h($tournament['description']) ?></p><dl class="mt-5 space-y-3 border-t border-white/[.08] pt-4 text-xs"><div class="flex justify-between gap-3"><dt class="text-slate-500">Registration closes</dt><dd class="text-right font-bold"><?= h(date('d M · g:i A', strtotime((string) $tournament['registration_deadline'] . ' UTC'))) ?> IST</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-500">Room details release</dt><dd class="text-right font-bold"><?= h(date('d M · g:i A', strtotime((string) $tournament['room_release_at'] . ' UTC'))) ?> IST</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-500">Status</dt><dd><span class="badge <?= $tournament['status']==='open'?'badge-open':'badge-soon' ?>"><?= h(ucfirst($tournament['status'])) ?></span></dd></div></dl></section>
        <section class="glass-card rounded-2xl p-5"><h2 class="text-lg font-black">Rules & scoring</h2><p class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-400"><?= h($tournament['rules']) ?></p><div class="mt-4 rounded-xl border border-violet-300/10 bg-violet-500/[.06] p-3 text-xs leading-5 text-slate-300"><strong class="text-violet-200">Points:</strong> placement points + (kills × <?= h($killPoints) ?>) + bonuses − penalties. Scores are calculated from admin-entered or imported match results.</div></section>
      </div>
    </section>
    <aside class="space-y-4">
      <section class="glass-card rounded-2xl p-5">
        <p class="text-[10px] font-black uppercase tracking-[.18em] text-violet-300">Your entry</p>
        <?php if ($registration): ?>
          <div class="mt-4 flex items-start gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-neon-400/10 text-neon-400"><i class="fa-solid fa-check" aria-hidden="true"></i></span><div><h2 class="font-black">You’re in</h2><p class="mt-1 text-xs leading-5 text-slate-400">Your registration is confirmed. Room access is restricted to active participants.</p></div></div>
        <?php elseif (!$current): ?>
          <p class="mt-3 text-sm leading-6 text-slate-400">Sign in or create an account to join this tournament.</p><a class="btn-primary mt-4 flex justify-center rounded-xl px-4 py-3 text-sm font-black" href="<?= h(app_url('index.php?page=login')) ?>">Sign in to join</a>
        <?php elseif ($tournament['status'] !== 'open' || strtotime((string) $tournament['registration_deadline'] . ' UTC') <= time()): ?>
          <p class="mt-3 text-sm text-slate-400">Registration is not currently open.</p>
        <?php elseif ($tournament['mode'] === 'solo'): ?>
          <p class="mt-3 text-sm leading-6 text-slate-400">Enter solo and compete for <?= h(format_money((int) $tournament['prize_pool_minor'])) ?> in prizes.</p>
          <form method="post" action="<?= h(app_url('index.php?action=join-tournament')) ?>" class="mt-4"><?= csrf_field() ?><input type="hidden" name="tournament_id" value="<?= h($tournament['id']) ?>"><input type="hidden" name="return_to" value="index.php?page=tournament&amp;id=<?= h($tournament['id']) ?>"><button class="btn-primary w-full rounded-xl px-4 py-3 text-sm font-black">Join solo · <?= h(format_money((int) $tournament['entry_fee_minor'])) ?></button></form>
        <?php else: ?>
          <p class="mt-3 text-sm leading-6 text-slate-400">A captain can register a team with exactly <?= h($tournament['team_size']) ?> active players.</p>
          <?php if ($myTeams): ?><form method="post" action="<?= h(app_url('index.php?action=join-tournament')) ?>" class="mt-4 space-y-3"><?= csrf_field() ?><input type="hidden" name="tournament_id" value="<?= h($tournament['id']) ?>"><input type="hidden" name="return_to" value="index.php?page=tournament&amp;id=<?= h($tournament['id']) ?>"><label class="block text-xs font-bold text-slate-400">Select your team<select class="form-control mt-2" name="team_id" required><?php foreach ($myTeams as $team): ?><option value="<?= h($team['id']) ?>"><?= h($team['name']) ?> · <?= h($team['member_count']) ?>/<?= h($tournament['team_size']) ?> players</option><?php endforeach; ?></select></label><button class="btn-primary w-full rounded-xl px-4 py-3 text-sm font-black">Register team · <?= h(format_money((int) $tournament['entry_fee_minor'])) ?></button></form>
          <?php else: ?><a class="mt-4 flex justify-center rounded-xl border border-white/10 px-4 py-3 text-sm font-bold hover:bg-white/[.05]" href="<?= h(app_url('index.php?page=team')) ?>">Create a team first</a><?php endif; ?>
        <?php endif; ?>
        <?php if ($registration && $roomAvailable): ?><div class="mt-5 rounded-xl border border-neon-400/20 bg-neon-400/[.06] p-4"><p class="text-[10px] font-black uppercase tracking-widest text-neon-400">Room details unlocked</p><dl class="mt-3 space-y-2 text-sm"><div class="flex justify-between gap-3"><dt class="text-slate-400">Room ID</dt><dd class="font-black"><?= h($roomId) ?></dd></div><div class="flex justify-between gap-3"><dt class="text-slate-400">Password</dt><dd class="font-black"><?= h($roomPassword) ?></dd></div></dl><p class="mt-3 text-[10px] leading-4 text-slate-500">Do not share these credentials outside your registered team.</p></div>
        <?php elseif ($registration): ?><p class="mt-4 rounded-xl border border-white/[.08] bg-black/15 p-3 text-xs leading-5 text-slate-400"><i class="fa-solid fa-lock mr-2 text-violet-300" aria-hidden="true"></i>Room ID and password unlock at the published release time.</p><?php endif; ?>
      </section>
      <a class="glass-card flex items-center justify-between rounded-2xl p-4 hover:border-violet-300/30" href="<?= h(app_url('index.php?page=leaderboard&id=' . (int) $tournament['id'])) ?>"><span><span class="block text-[10px] font-black uppercase tracking-widest text-slate-500">Tournament standings</span><span class="mt-1 block text-sm font-bold">Open leaderboard</span></span><i class="fa-solid fa-arrow-right text-violet-300" aria-hidden="true"></i></a>
    </aside>
  </div>
</main>
