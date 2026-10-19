<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
  <div>
    <p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300">Manual wallet operations</p>
    <h1 class="mt-2 text-3xl font-black">Wallet & deposits</h1>
    <p class="mt-2 text-sm text-slate-400">Verify UPI deposit requests, adjust player balances, and review every wallet movement in the ledger.</p>
  </div>

  <div class="mt-5 grid gap-3 sm:grid-cols-2">
    <div class="glass-card rounded-2xl p-4"><p class="text-[10px] uppercase tracking-widest text-slate-500">Approved manual deposits</p><p class="mt-2 text-xl font-black text-neon-400"><?= h(format_money($manualDepositTotals['approved_minor'])) ?></p></div>
    <div class="glass-card rounded-2xl p-4"><p class="text-[10px] uppercase tracking-widest text-slate-500">Pending verification</p><p class="mt-2 text-xl font-black text-amber-200"><?= h(number_format($manualDepositTotals['pending'])) ?></p></div>
  </div>

  <section class="glass-card mt-5 rounded-2xl p-5">
    <div>
      <p class="text-[10px] font-black uppercase tracking-widest text-violet-300">Balance control</p>
      <h2 class="mt-1 text-lg font-black">Add or deduct funds</h2>
      <p class="mt-1 text-xs leading-5 text-slate-500">Search by player email, username, or account ID. Each adjustment is recorded in the immutable wallet ledger.</p>
    </div>
    <form method="get" class="mt-4 flex flex-wrap items-end gap-3">
      <input type="hidden" name="page" value="admin-wallet">
      <label class="min-w-52 flex-1 text-xs font-bold text-slate-400">Find a player
        <input class="form-control mt-2" name="wallet_user_q" maxlength="100" value="<?= h($walletUserSearch) ?>" placeholder="Email, username, or account ID">
      </label>
      <button class="btn-primary rounded-xl px-5 py-3 text-sm font-black">Search accounts</button>
    </form>
    <?php if ($walletUserSearch === ''): ?>
      <p class="mt-4 rounded-xl border border-dashed border-white/10 p-5 text-center text-xs text-slate-500">Search for a player to open their wallet controls.</p>
    <?php elseif (!$walletUsers): ?>
      <p class="mt-4 rounded-xl border border-dashed border-white/10 p-5 text-center text-xs text-slate-500">No player accounts match that search.</p>
    <?php else: ?>
      <div class="mt-4 grid gap-3 lg:grid-cols-2">
        <?php foreach ($walletUsers as $walletUser): ?>
          <article class="rounded-2xl border border-white/[.08] bg-black/10 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="font-black"><?= h($walletUser['display_name'] ?: $walletUser['email']) ?></p>
                <p class="mt-1 text-[10px] text-slate-500"><?= h($walletUser['email']) ?> · Account #<?= h($walletUser['id']) ?> · <?= h(ucfirst($walletUser['status'])) ?></p>
              </div>
              <div class="text-right"><p class="text-[10px] text-slate-500">Available balance</p><p class="font-black text-neon-400"><?= h(format_money((int) $walletUser['balance_minor'])) ?></p><p class="mt-1 text-[10px] text-slate-500">Reserved <?= h(format_money((int) $walletUser['reserved_minor'])) ?></p></div>
            </div>
            <form method="post" action="<?= h(app_url('index.php?action=admin-wallet-adjust')) ?>" class="mt-4 grid gap-3 sm:grid-cols-2">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= h($walletUser['id']) ?>">
              <input type="hidden" name="adjustment_nonce" value="<?= h($walletAdjustNonce) ?>">
              <label class="text-xs font-bold text-slate-400">Action
                <select class="form-control mt-2" name="direction" required><option value="credit">Credit wallet</option><option value="debit">Debit wallet</option></select>
              </label>
              <label class="text-xs font-bold text-slate-400">Amount · INR
                <input class="form-control mt-2" type="number" name="amount_rupees" min="0.01" max="500000" step="0.01" required placeholder="e.g. 250.00">
              </label>
              <label class="text-xs font-bold text-slate-400 sm:col-span-2">Reason
                <input class="form-control mt-2" name="reason" maxlength="255" required placeholder="Required audit note">
              </label>
              <div class="sm:col-span-2"><button class="btn-primary rounded-xl px-4 py-3 text-xs font-black">Post wallet adjustment</button></div>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="glass-card mt-5 rounded-2xl p-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="text-[10px] font-black uppercase tracking-widest text-violet-300">UPI verification</p>
        <h2 class="mt-1 text-lg font-black">Deposit requests</h2>
        <p class="mt-1 text-xs text-slate-500"><?= h(number_format($depositTotal)) ?> requests</p>
      </div>
    </div>
    <form method="get" class="mt-4 flex flex-wrap items-end gap-3">
      <input type="hidden" name="page" value="admin-wallet">
      <label class="min-w-48 flex-1 text-xs font-bold text-slate-400">Search player or UTR
        <input class="form-control mt-2" name="deposit_q" maxlength="100" value="<?= h($depositSearch) ?>" placeholder="Email, username, or transaction ID">
      </label>
      <label class="min-w-36 text-xs font-bold text-slate-400">Status
        <select class="form-control mt-2" name="deposit_status"><option value="pending" <?= $depositStatus==='pending'?'selected':'' ?>>Pending</option><option value="approved" <?= $depositStatus==='approved'?'selected':'' ?>>Approved</option><option value="rejected" <?= $depositStatus==='rejected'?'selected':'' ?>>Rejected</option><option value="all" <?= $depositStatus==='all'?'selected':'' ?>>All statuses</option></select>
      </label>
      <button class="btn-primary rounded-xl px-5 py-3 text-sm font-black">Filter requests</button>
    </form>
    <?php if (!$depositRequests): ?>
      <p class="mt-4 rounded-xl border border-dashed border-white/10 p-8 text-center text-xs text-slate-500">No deposit requests match this filter.</p>
    <?php else: ?>
      <div class="table-wrap mt-4">
        <table class="w-full min-w-[780px] text-left text-xs">
          <thead class="text-[10px] uppercase tracking-wider text-slate-500"><tr><th class="pb-3 pr-4">Player</th><th class="pb-3 pr-4">Amount</th><th class="pb-3 pr-4">Transaction ID / UTR</th><th class="pb-3 pr-4">Status</th><th class="pb-3 pr-4">Review</th></tr></thead>
          <tbody class="divide-y divide-white/[.07]">
            <?php foreach ($depositRequests as $request): ?>
              <tr>
                <td class="py-4 pr-4"><p class="font-bold"><?= h($request['display_name'] ?: $request['email']) ?></p><p class="mt-1 text-[10px] text-slate-500"><?= h($request['email']) ?> · #<?= h($request['id']) ?></p><p class="mt-1 text-[10px] text-slate-500"><?= h(date('d M Y · g:i A', strtotime((string) $request['created_at'] . ' UTC'))) ?></p></td>
                <td class="py-4 pr-4 font-black"><?= h(format_money((int) $request['amount_minor'])) ?></td>
                <td class="py-4 pr-4 font-mono text-[10px]"><?= h($request['utr_number']) ?></td>
                <td class="py-4 pr-4"><span class="badge <?= $request['status']==='approved'?'badge-open':($request['status']==='rejected'?'badge-closed':'badge-soon') ?>"><?= h(ucfirst($request['status'])) ?></span><?php if ($request['review_note']): ?><p class="mt-2 max-w-48 text-[10px] leading-4 text-slate-500"><?= h($request['review_note']) ?></p><?php endif; ?></td>
                <td class="py-4 pr-4">
                  <?php if ($request['status'] === 'pending'): ?>
                    <form method="post" action="<?= h(app_url('index.php?action=admin-deposit-review')) ?>" class="min-w-48 space-y-2">
                      <?= csrf_field() ?><input type="hidden" name="deposit_request_id" value="<?= h($request['id']) ?>">
                      <input class="form-control !px-2 !py-2 text-[10px]" name="note" maxlength="255" placeholder="Review note (required to reject)">
                      <div class="flex gap-2"><button class="rounded-lg bg-neon-400/10 px-3 py-2 text-[10px] font-bold text-neon-300" name="decision" value="approved">Approve & credit</button><button class="rounded-lg border border-rose-300/20 px-3 py-2 text-[10px] font-bold text-rose-200" name="decision" value="rejected" data-confirm="Reject this request? No wallet credit will be issued.">Reject</button></div>
                    </form>
                  <?php else: ?>
                    <span class="text-[10px] text-slate-600"><?= $request['reviewed_at'] ? h(date('d M Y · g:i A', strtotime((string) $request['reviewed_at'] . ' UTC'))) : 'Reviewed' ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php if ($depositPageCount > 1): ?><nav class="mt-4 flex flex-wrap gap-2" aria-label="Deposit request pages"><?php for ($n=1; $n<=$depositPageCount; $n++): ?><a class="rounded-lg border px-3 py-2 text-xs <?= $n===$depositPage?'border-violet-400/50 text-violet-200':'border-white/10 text-slate-400' ?>" href="<?= h(app_url('index.php?' . http_build_query(['page'=>'admin-wallet','deposit_q'=>$depositSearch,'deposit_status'=>$depositStatus,'deposit_p'=>$n,'wallet_user_q'=>$walletUserSearch,'ledger_q'=>$ledgerSearch,'ledger_type'=>$ledgerType,'ledger_p'=>$ledgerPage]))) ?>"><?= h($n) ?></a><?php endfor; ?></nav><?php endif; ?>
  </section>

  <section class="glass-card mt-5 rounded-2xl p-5">
    <div><p class="text-[10px] font-black uppercase tracking-widest text-violet-300">Immutable ledger</p><h2 class="mt-1 text-lg font-black">Wallet transactions</h2><p class="mt-1 text-xs text-slate-500"><?= h(number_format($ledgerTotal)) ?> entries · balance changes are never edited in place.</p></div>
    <form method="get" class="mt-4 flex flex-wrap items-end gap-3">
      <input type="hidden" name="page" value="admin-wallet"><input type="hidden" name="deposit_q" value="<?= h($depositSearch) ?>"><input type="hidden" name="deposit_status" value="<?= h($depositStatus) ?>"><input type="hidden" name="wallet_user_q" value="<?= h($walletUserSearch) ?>">
      <label class="min-w-48 flex-1 text-xs font-bold text-slate-400">Search player, reference, or transaction key<input class="form-control mt-2" name="ledger_q" maxlength="100" value="<?= h($ledgerSearch) ?>"></label>
      <label class="min-w-40 text-xs font-bold text-slate-400">Transaction type<select class="form-control mt-2" name="ledger_type"><option value="all">All types</option><?php foreach(['deposit','entry_fee','entry_refund','prize','withdrawal_reserve','withdrawal_release','withdrawal_paid','admin_adjustment'] as $type): ?><option value="<?= h($type) ?>" <?= $ledgerType===$type?'selected':'' ?>><?= h(str_replace('_',' ',$type)) ?></option><?php endforeach; ?></select></label>
      <button class="btn-primary rounded-xl px-5 py-3 text-sm font-black">Filter ledger</button>
    </form>
    <?php if (!$ledger): ?><p class="mt-4 text-xs text-slate-500">No ledger entries match this filter.</p><?php else: ?>
      <div class="table-wrap mt-4"><table class="w-full min-w-[850px] text-left text-xs"><thead class="text-[10px] uppercase tracking-wider text-slate-500"><tr><th class="pb-3">Player / transaction</th><th class="pb-3">Type</th><th class="pb-3">Amount</th><th class="pb-3">Available Δ</th><th class="pb-3">Reserved Δ</th><th class="pb-3">After · available / reserved</th><th class="pb-3">Reference / time</th></tr></thead><tbody class="divide-y divide-white/[.07]"><?php foreach($ledger as $entry): ?><tr><td class="py-3"><p class="font-bold"><?= h($entry['display_name'] ?: $entry['email']) ?></p><p class="mt-1 max-w-52 truncate font-mono text-[9px] text-slate-600"><?= h($entry['idempotency_key']) ?></p></td><td class="py-3 uppercase text-violet-200"><?= h(str_replace('_',' ',$entry['transaction_type'])) ?></td><td class="py-3 font-black"><?= h(format_money((int)$entry['amount_minor'])) ?></td><td class="py-3 <?= (int)$entry['available_delta_minor']<0?'text-rose-200':'text-neon-400' ?>"><?= h(format_money((int)$entry['available_delta_minor'])) ?></td><td class="py-3 text-slate-300"><?= h(format_money((int)$entry['reserved_delta_minor'])) ?></td><td class="py-3 text-slate-300"><?= h(format_money((int)$entry['available_after_minor'])) ?> / <?= h(format_money((int)$entry['reserved_after_minor'])) ?></td><td class="py-3 text-[10px] text-slate-500"><p><?= h($entry['reference_type'] ?: '—') ?> · <?= h($entry['reference_id'] ?: '—') ?></p><p class="mt-1"><?= h(date('d M Y · g:i A',strtotime((string)$entry['created_at'].' UTC'))) ?></p></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php if ($ledgerPages>1): ?><nav class="mt-4 flex flex-wrap gap-2" aria-label="Ledger pages"><?php for($n=1;$n<=$ledgerPages;$n++): ?><a class="rounded-lg border px-3 py-2 text-xs <?= $n===$ledgerPage?'border-violet-400/50 text-violet-200':'border-white/10 text-slate-400' ?>" href="<?= h(app_url('index.php?'.http_build_query(['page'=>'admin-wallet','deposit_q'=>$depositSearch,'deposit_status'=>$depositStatus,'wallet_user_q'=>$walletUserSearch,'deposit_p'=>$depositPage,'ledger_q'=>$ledgerSearch,'ledger_type'=>$ledgerType,'ledger_p'=>$n]))) ?>"><?= h($n) ?></a><?php endfor; ?></nav><?php endif; ?>
  </section>
</main>
