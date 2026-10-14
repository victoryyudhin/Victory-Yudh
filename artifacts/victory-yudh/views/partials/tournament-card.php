<?php
$filled = (int) ($tournament['occupied_slots'] ?? 0);
$limit = (int) ($tournament['participant_limit'] ?? 0);
$percent = $limit > 0 ? min(100, (int) floor($filled * 100 / $limit)) : 0;
$modeLabels = ['solo' => 'SOLO', 'duo' => 'DUO', 'squad' => 'SQUAD'];
?>
<article class="glass-card card-hover overflow-hidden rounded-2xl">
  <a class="block" href="<?= h(app_url('index.php?page=tournament&id=' . (int) $tournament['id'])) ?>">
    <div class="relative min-h-36 overflow-hidden bg-[radial-gradient(ellipse_at_80%_15%,rgba(144,97,255,.5),transparent_40%),linear-gradient(125deg,#171832,#101126_55%,#171329)] p-5">
      <div class="absolute -right-4 -top-7 h-36 w-36 rounded-full border border-violet-300/10"></div>
      <div class="absolute -right-1 top-1 h-28 w-28 rounded-full border border-violet-300/10"></div>
      <div class="relative flex items-start justify-between gap-4">
        <span class="badge badge-soon"><?= h($modeLabels[$tournament['mode']] ?? strtoupper($tournament['mode'])) ?></span>
        <span class="badge <?= $tournament['status'] === 'open' ? 'badge-open' : ($tournament['status'] === 'cancelled' ? 'badge-closed' : 'badge-soon') ?>"><?= h(strtoupper($tournament['status'])) ?></span>
      </div>
      <h3 class="relative mt-7 max-w-sm text-xl font-black leading-tight"><?= h($tournament['title']) ?></h3>
      <p class="relative mt-1 text-xs font-semibold text-violet-100/75"><?= h($tournament['map_name']) ?> <span class="mx-1 text-white/30">/</span> <?= h(date('D, d M · g:i A', strtotime((string) $tournament['match_time'] . ' UTC'))) ?></p>
    </div>
  </a>
  <div class="p-5">
    <div class="grid grid-cols-2 gap-3">
      <div><p class="text-[10px] font-bold uppercase tracking-[.15em] text-slate-500">Prize pool</p><p class="mt-1 font-black text-neon-400"><?= h(format_money((int) $tournament['prize_pool_minor'])) ?></p></div>
      <div><p class="text-[10px] font-bold uppercase tracking-[.15em] text-slate-500">Entry</p><p class="mt-1 font-black"><?= h(format_money((int) $tournament['entry_fee_minor'])) ?></p></div>
    </div>
    <div class="mt-4">
      <div class="mb-2 flex items-center justify-between text-[11px] font-semibold"><span class="text-slate-400">Players joined</span><span><?= h($filled) ?> / <?= h($limit) ?></span></div>
      <div class="h-1.5 overflow-hidden rounded-full bg-white/[.08]"><div class="h-full rounded-full bg-gradient-to-r from-violet-500 to-neon-400" style="width:<?= h($percent) ?>%"></div></div>
    </div>
    <a class="focus-ring mt-5 flex w-full items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/[.04] px-4 py-3 text-sm font-bold transition hover:border-violet-300/30 hover:bg-violet-500/10" href="<?= h(app_url('index.php?page=tournament&id=' . (int) $tournament['id'])) ?>">Tournament details <i class="fa-solid fa-arrow-right text-xs text-violet-300" aria-hidden="true"></i></a>
  </div>
</article>
