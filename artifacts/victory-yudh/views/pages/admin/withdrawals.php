<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
  <header>
    <p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300">Payout review</p>
    <h1 class="mt-2 text-3xl font-black">Withdrawal requests</h1>
    <p class="mt-2 text-sm text-slate-400">UPI destinations are decrypted only for admins. Transfer the net amount, then record a confirmed payout reference.</p>
  </header>

  <form method="get" class="glass-card mt-5 flex flex-wrap items-end gap-3 rounded-2xl p-4">
    <input type="hidden" name="page" value="admin-withdrawals">
    <label class="min-w-48 flex-1 text-xs font-bold text-slate-400">Search player or request
      <input class="form-control mt-2" name="q" maxlength="80" value="<?= h($search) ?>" placeholder="Email or player name">
    </label>
    <label class="min-w-36 text-xs font-bold text-slate-400">Status
      <select class="form-control mt-2" name="status">
        <option value="all">All statuses</option>
        <?php foreach (['pending','approved','processing','paid','rejected'] as $state): ?>
          <option value="<?= h($state) ?>" <?= $statusFilter === $state ? 'selected' : '' ?>><?= h(ucfirst($state)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn-primary rounded-xl px-5 py-3 text-sm font-black">Filter</button>
  </form>

  <div class="mt-5 space-y-3">
    <?php if (!$withdrawals): ?>
      <div class="glass-card rounded-2xl p-10 text-center text-sm text-slate-500">No withdrawal requests match this filter.</div>
    <?php endif; ?>
    <?php foreach ($withdrawals as $item): ?>
      <article class="glass-card rounded-2xl p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-64 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="font-black"><?= h($item['display_name'] ?: $item['email']) ?></h2>
              <span class="badge <?= $item['status'] === 'paid' ? 'badge-open' : ($item['status'] === 'rejected' ? 'badge-closed' : 'badge-soon') ?>"><?= h(ucfirst($item['status'])) ?></span>
            </div>
            <p class="mt-1 text-xs text-slate-500"><?= h($item['email']) ?> · Request #<?= h($item['id']) ?> · <?= h(date('d M Y · g:i A', strtotime((string) $item['created_at'] . ' UTC'))) ?> IST</p>
            <dl class="mt-4 grid max-w-xl grid-cols-3 gap-3 rounded-xl border border-white/[.08] bg-black/10 p-3">
              <div><dt class="text-[10px] text-slate-500">Requested</dt><dd class="mt-1 text-sm font-black text-slate-100"><?= h(format_money((int) $item['amount_minor'])) ?></dd></div>
              <div><dt class="text-[10px] text-slate-500">18% GST</dt><dd class="mt-1 text-sm font-bold text-amber-200">−<?= h(format_money((int) $item['gst_minor'])) ?></dd></div>
              <div><dt class="text-[10px] text-slate-500">Transfer amount</dt><dd class="mt-1 text-sm font-black text-neon-400"><?= h(format_money((int) $item['net_amount_minor'])) ?></dd></div>
            </dl>
            <p class="mt-3 text-xs text-slate-400">UPI: <span class="font-mono font-bold text-slate-200"><?= h($item['upi_id']) ?></span></p>
            <?php if ($item['payout_reference']): ?>
              <p class="mt-2 text-[10px] text-slate-500">Payout reference <?= h($item['payout_reference']) ?></p>
            <?php endif; ?>
          </div>

          <div class="min-w-60 space-y-2">
            <?php if ($item['status'] === 'pending'): ?>
              <form method="post" action="<?= h(app_url('index.php?action=admin-withdraw-review')) ?>" class="flex gap-2">
                <?= csrf_field() ?><input type="hidden" name="withdrawal_id" value="<?= h($item['id']) ?>"><input type="hidden" name="decision" value="approved">
                <button class="flex-1 rounded-xl bg-violet-500/15 px-3 py-3 text-xs font-black text-violet-200">Approve</button>
              </form>
              <form method="post" action="<?= h(app_url('index.php?action=admin-withdraw-review')) ?>" class="space-y-2" data-confirm="Reject and release the reserved funds to the player's available wallet?">
                <?= csrf_field() ?><input type="hidden" name="withdrawal_id" value="<?= h($item['id']) ?>"><input type="hidden" name="decision" value="rejected">
                <input class="form-control !py-2 text-xs" name="note" maxlength="255" required placeholder="Reason for rejection">
                <button class="w-full rounded-xl border border-rose-300/20 px-3 py-2 text-xs font-black text-rose-200">Reject & return funds</button>
              </form>
            <?php elseif ($item['status'] === 'approved'): ?>
              <form method="post" action="<?= h(app_url('index.php?action=admin-withdraw-review')) ?>">
                <?= csrf_field() ?><input type="hidden" name="withdrawal_id" value="<?= h($item['id']) ?>"><input type="hidden" name="decision" value="processing">
                <button class="w-full rounded-xl border border-violet-300/20 bg-violet-500/10 px-3 py-3 text-xs font-black text-violet-200">Mark processing</button>
              </form>
              <form method="post" action="<?= h(app_url('index.php?action=admin-withdraw-review')) ?>" data-confirm="Reject and return reserved funds?">
                <?= csrf_field() ?><input type="hidden" name="withdrawal_id" value="<?= h($item['id']) ?>"><input type="hidden" name="decision" value="rejected">
                <input class="form-control !py-2 text-xs" name="note" maxlength="255" required placeholder="Reason for rejection">
                <button class="mt-2 w-full rounded-xl border border-rose-300/20 px-3 py-2 text-xs font-black text-rose-200">Reject & return funds</button>
              </form>
            <?php elseif ($item['status'] === 'processing'): ?>
              <form method="post" action="<?= h(app_url('index.php?action=admin-withdraw-review')) ?>" class="space-y-2" data-confirm="Only mark paid after the actual payout is confirmed.">
                <?= csrf_field() ?><input type="hidden" name="withdrawal_id" value="<?= h($item['id']) ?>"><input type="hidden" name="decision" value="paid">
                <input class="form-control !py-2 text-xs" name="note" maxlength="140" required placeholder="Confirmed payout reference">
                <button class="w-full rounded-xl bg-neon-400/10 px-3 py-3 text-xs font-black text-neon-400">Record confirmed as paid</button>
              </form>
            <?php else: ?>
              <p class="rounded-xl border border-white/[.07] p-3 text-[10px] text-slate-500">No further actions. Status: <?= h($item['status']) ?>.</p>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($pageCount > 1): ?>
    <nav class="mt-5 flex gap-2" aria-label="Withdrawal request pages">
      <?php for ($n = 1; $n <= $pageCount; $n++): ?>
        <a class="rounded-lg border px-3 py-2 text-xs <?= $n === $pageNumber ? 'border-violet-400/50 text-violet-200' : 'border-white/10 text-slate-400' ?>" href="<?= h(app_url('index.php?' . http_build_query(['page'=>'admin-withdrawals','q'=>$search,'status'=>$statusFilter,'p'=>$n]))) ?>"><?= h($n) ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</main>
