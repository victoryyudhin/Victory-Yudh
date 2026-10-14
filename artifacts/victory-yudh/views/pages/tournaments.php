<main class="mx-auto max-w-7xl px-4 py-10 pb-28 sm:px-6 lg:px-8 md:pb-12">
  <div class="flex flex-wrap items-end justify-between gap-4">
    <div><p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300">Arena schedule</p><h1 class="mt-2 text-3xl font-black">Tournaments</h1><p class="mt-2 text-sm text-slate-400">Browse upcoming matches and enter while registration is open.</p></div>
    <?php if ($current): ?><a class="focus-ring rounded-xl border border-white/10 px-4 py-3 text-xs font-bold text-slate-200 hover:bg-white/[.05]" href="<?= h(app_url('index.php?page=dashboard')) ?>">My dashboard <i class="fa-solid fa-arrow-right ml-1 text-violet-300" aria-hidden="true"></i></a><?php endif; ?>
  </div>
  <form method="get" class="glass-card mt-7 flex flex-wrap items-end gap-3 rounded-2xl p-4">
    <input type="hidden" name="page" value="tournaments">
    <label class="min-w-44 flex-1 text-xs font-bold text-slate-400">Find a tournament<input class="form-control mt-2" name="q" maxlength="80" value="<?= h($search) ?>" placeholder="Search title or map"></label>
    <label class="min-w-36 text-xs font-bold text-slate-400">Mode<select class="form-control mt-2" name="mode"><option value="all">All modes</option><?php foreach (['solo'=>'Solo','duo'=>'Duo','squad'=>'Squad'] as $value=>$label): ?><option value="<?= h($value) ?>" <?= $modeFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
    <label class="min-w-36 text-xs font-bold text-slate-400">Status<select class="form-control mt-2" name="status"><option value="all">All</option><?php foreach (['open'=>'Open','locked'=>'Locked','live'=>'Live','completed'=>'Completed'] as $value=>$label): ?><option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
    <button class="btn-primary focus-ring rounded-xl px-5 py-3 text-sm font-black">Apply filters</button>
  </form>
  <?php if (!$tournaments): ?>
    <div class="glass-card mt-6 rounded-2xl px-6 py-14 text-center"><i class="fa-solid fa-magnifying-glass text-2xl text-violet-300" aria-hidden="true"></i><h2 class="mt-4 text-lg font-black">No matching tournaments</h2><p class="mt-2 text-sm text-slate-400">Try another mode or search term.</p></div>
  <?php else: ?>
    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3"><?php foreach ($tournaments as $tournament): require __DIR__ . '/../partials/tournament-card.php'; endforeach; ?></div>
    <?php if ($pageCount > 1): ?><nav class="mt-7 flex justify-center gap-2" aria-label="Pagination"><?php for ($n=1;$n<=$pageCount;$n++): ?><a class="rounded-lg border px-3 py-2 text-xs font-bold <?= $n === $pageNumber ? 'border-violet-400/50 bg-violet-500/15 text-violet-200' : 'border-white/10 text-slate-400' ?>" href="<?= h(app_url('index.php?' . http_build_query(['page'=>'tournaments','q'=>$search,'mode'=>$modeFilter,'status'=>$statusFilter,'p'=>$n]))) ?>"><?= h($n) ?></a><?php endfor; ?></nav><?php endif; ?>
  <?php endif; ?>
</main>
