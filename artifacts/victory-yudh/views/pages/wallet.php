<main class="mx-auto max-w-7xl px-4 py-9 pb-28 sm:px-6 lg:px-8 md:pb-12">
  <div><p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300">Wallet ledger</p><h1 class="mt-2 text-3xl font-black">Your wallet</h1><p class="mt-2 text-sm text-slate-400">Every change is recorded. Funds pending payout are reserved and not marked paid until confirmed.</p></div>
  <div class="mt-6 grid gap-4 sm:grid-cols-2">
    <section class="glass-card rounded-2xl p-5"><p class="text-xs font-semibold text-slate-400">Available balance</p><p class="mt-2 text-3xl font-black text-neon-400"><?= h(format_money((int)$wallet['balance_minor'])) ?></p><p class="mt-2 text-[10px] text-slate-500">Ready for tournament entry or withdrawal requests.</p></section>
    <section class="glass-card rounded-2xl p-5"><p class="text-xs font-semibold text-slate-400">Reserved for withdrawal</p><p class="mt-2 text-3xl font-black text-violet-300"><?= h(format_money((int)$wallet['reserved_minor'])) ?></p><p class="mt-2 text-[10px] text-slate-500">Reserved funds remain in your wallet until an admin confirms a payout.</p></section>
  </div>
  <div class="mt-5 grid items-start gap-5 lg:grid-cols-2">
    <section class="glass-card rounded-2xl p-5">
      <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-violet-500/10 text-violet-300"><i class="fa-solid fa-circle-plus" aria-hidden="true"></i></span><div><h2 class="font-black">Manual deposit</h2><p class="text-xs text-slate-500">Pay by UPI, then send the transaction details for review.</p></div></div>
      <div class="mt-5 grid gap-4 sm:grid-cols-[190px_1fr]">
        <div class="rounded-2xl bg-[#f7d78a] p-3 text-center text-slate-950">
          <img class="mx-auto aspect-square w-full max-w-[170px] rounded-xl bg-white p-2" src="<?= h(DepositService::QR_IMAGE_URL) ?>" width="300" height="300" alt="UPI payment QR code">
          <p class="mt-2 text-[10px] font-bold uppercase tracking-widest text-slate-700">Scan to pay</p>
        </div>
        <div class="flex flex-col justify-center rounded-2xl border border-amber-200/15 bg-amber-200/[.05] p-4">
          <p class="text-[10px] font-black uppercase tracking-widest text-amber-200">UPI ID</p>
          <p class="mt-2 select-all break-all font-mono text-lg font-black text-white"><?= h(DepositService::UPI_ID) ?></p>
          <p class="mt-2 text-xs leading-5 text-slate-400">After payment, submit the exact amount and transaction ID / UTR below. Your balance changes only after an admin verifies the transfer.</p>
        </div>
      </div>
      <form method="post" action="<?= h(app_url('index.php?action=deposit-request')) ?>" class="mt-5 space-y-3">
        <?= csrf_field() ?><input type="hidden" name="deposit_nonce" value="<?= h($depositNonce) ?>">
        <label class="block text-xs font-bold text-slate-400">Amount paid (INR)<input class="form-control mt-2" type="number" name="amount_rupees" min="1" max="500000" step="0.01" required placeholder="Minimum ₹10"></label>
        <label class="block text-xs font-bold text-slate-400">Transaction ID / UTR number<input class="form-control mt-2" type="text" name="utr_number" minlength="6" maxlength="120" autocomplete="off" required placeholder="Enter the UPI transaction reference"></label>
        <button class="btn-primary w-full rounded-xl px-4 py-3 text-sm font-black">Send deposit request</button>
      </form>
      <p class="mt-3 text-[10px] leading-5 text-slate-500">Do not submit the request more than once. A pending request is not a wallet credit.</p>
    </section>
    <section class="glass-card rounded-2xl p-5">
      <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-neon-400/10 text-neon-400"><i class="fa-solid fa-money-bill-transfer" aria-hidden="true"></i></span><div><h2 class="font-black">Request a withdrawal</h2><p class="text-xs text-slate-500">Payout destination is encrypted at rest.</p></div></div>
      <form method="post" action="<?= h(app_url('index.php?action=withdraw-request')) ?>" class="mt-5 space-y-3">
        <?= csrf_field() ?><input type="hidden" name="withdraw_nonce" value="<?= h($withdrawNonce) ?>">
        <label class="block text-xs font-bold text-slate-400">Amount in INR<input class="form-control mt-2" type="number" name="amount_rupees" min="100" max="50000" step="0.01" required placeholder="Minimum ₹100"></label>
        <label class="block text-xs font-bold text-slate-400">UPI ID<input class="form-control mt-2" name="upi_id" maxlength="100" autocomplete="off" required placeholder="name@bank"></label>
        <p class="rounded-xl border border-amber-300/20 bg-amber-300/[.08] px-3 py-2.5 text-xs font-semibold leading-5 text-amber-100">Note: 18% GST will be deducted from each withdrawal amount.</p>
        <button class="w-full rounded-xl border border-white/10 bg-white/[.05] px-4 py-3 text-sm font-black hover:border-violet-300/30 hover:bg-violet-500/10">Send Withdrawal Request</button>
      </form>
      <p class="mt-3 text-[10px] leading-5 text-slate-500">A request reserves the amount. An admin arranges the transfer manually; “Paid” is recorded only after payout confirmation.</p>
    </section>
  </div>
  <div class="mt-5 grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(0,.9fr)_minmax(0,.9fr)]">
    <section class="glass-card min-w-0 rounded-2xl p-5">
      <div class="flex min-w-0 items-center justify-between gap-3">
        <div class="min-w-0"><p class="text-[10px] font-black uppercase tracking-widest text-violet-300">Immutable records</p><h2 class="mt-1 text-lg font-black">Transactions</h2></div>
        <span class="shrink-0 text-[10px] text-slate-500"><?= h(number_format($transactionCount)) ?> entries</span>
      </div>
      <?php if (!$transactions): ?>
        <p class="mt-5 rounded-xl border border-dashed border-white/10 p-7 text-center text-xs text-slate-500">Your wallet transactions will appear here.</p>
      <?php else: ?>
        <div role="list" aria-label="Wallet transactions" class="mt-4 space-y-2 md:hidden">
          <?php foreach ($transactions as $tx): $positive=(int)$tx['available_delta_minor']>0; ?>
            <article role="listitem" class="min-w-0 rounded-xl border border-white/[.08] bg-black/10 p-3">
              <div class="flex min-w-0 items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                  <p class="break-words text-xs font-bold"><?= h(ucwords(str_replace('_',' ',$tx['transaction_type']))) ?></p>
                  <p class="mt-1 break-words text-[10px] leading-4 text-slate-500"><?= h($tx['note'] ?: ucfirst($tx['status'])) ?></p>
                </div>
                <p class="max-w-[45%] shrink-0 break-words text-right text-xs font-black <?= $positive ? 'text-neon-400' : 'text-slate-200' ?>"><?= $positive ? '+' : '' ?><?= h(format_money((int)$tx['amount_minor'])) ?></p>
              </div>
              <dl class="mt-3 grid min-w-0 grid-cols-2 gap-x-3 gap-y-1 border-t border-white/[.07] pt-3 text-[10px]">
                <dt class="text-slate-500">Available after</dt><dd class="min-w-0 break-words text-right"><?= h(format_money((int)$tx['available_after_minor'])) ?></dd>
                <dt class="text-slate-500">Time</dt><dd class="min-w-0 break-words text-right"><?= h(date('d M Y · g:i A',strtotime((string)$tx['created_at'].' UTC'))) ?></dd>
                <dt class="text-slate-500">ID / reference</dt>
                <dd class="min-w-0 break-all text-right font-mono text-slate-400">#<?= h($tx['id']) ?><?php if ($tx['reference_id']): ?><span class="block">ref <?= h($tx['reference_type']) ?>:<?= h($tx['reference_id']) ?></span><?php endif; ?></dd>
              </dl>
            </article>
          <?php endforeach; ?>
        </div>
        <div class="table-wrap mt-4 hidden min-w-0 md:block">
          <table class="w-full text-left text-xs">
            <thead class="text-[10px] uppercase tracking-wider text-slate-500"><tr><th class="pb-3">Transaction</th><th class="pb-3">ID / reference</th><th class="pb-3">Amount</th><th class="pb-3">Available after</th><th class="pb-3">Time</th></tr></thead>
            <tbody class="divide-y divide-white/[.07]">
              <?php foreach ($transactions as $tx): $positive=(int)$tx['available_delta_minor']>0; ?>
                <tr>
                  <td class="py-3 pr-4"><p class="break-words font-bold"><?= h(ucwords(str_replace('_',' ',$tx['transaction_type']))) ?></p><p class="mt-1 break-words text-[10px] text-slate-500"><?= h($tx['note'] ?: ucfirst($tx['status'])) ?></p></td>
                  <td class="break-all py-3 pr-4 font-mono text-[10px] text-slate-500">#<?= h($tx['id']) ?><?php if ($tx['reference_id']): ?><span class="block">ref <?= h($tx['reference_type']) ?>:<?= h($tx['reference_id']) ?></span><?php endif; ?></td>
                  <td class="whitespace-nowrap py-3 pr-4 font-black <?= $positive ? 'text-neon-400' : 'text-slate-200' ?>"><?= $positive ? '+' : '' ?><?= h(format_money((int)$tx['amount_minor'])) ?></td>
                  <td class="whitespace-nowrap py-3 pr-4"><?= h(format_money((int)$tx['available_after_minor'])) ?></td>
                  <td class="whitespace-nowrap py-3 text-[10px] text-slate-500"><?= h(date('d M Y · g:i A',strtotime((string)$tx['created_at'].' UTC'))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($transactionPages>1): ?><nav class="mt-4 flex flex-wrap gap-2" aria-label="Transaction pages"><?php for($n=1;$n<=$transactionPages;$n++): ?><a class="rounded-lg border px-3 py-2 text-xs font-bold <?= $n===$transactionPage?'border-violet-400/50 text-violet-200':'border-white/10 text-slate-400' ?>" href="<?= h(app_url('index.php?page=wallet&p='.$n)) ?>"><?= h($n) ?></a><?php endfor; ?></nav><?php endif; ?>
      <?php endif; ?>
    </section>
    <section class="glass-card min-w-0 rounded-2xl p-5">
      <div><p class="text-[10px] font-black uppercase tracking-widest text-violet-300">Manual deposits</p><h2 class="mt-1 text-lg font-black">Deposit request status</h2></div>
      <?php if (!$depositRequests): ?>
        <p class="mt-5 rounded-xl border border-dashed border-white/10 p-7 text-center text-xs text-slate-500">No deposit requests submitted.</p>
      <?php else: ?>
        <div class="mt-4 min-w-0 space-y-3">
          <?php foreach ($depositRequests as $request): ?>
            <article class="min-w-0 rounded-xl border border-white/[.08] bg-black/10 p-4">
              <div class="flex min-w-0 items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-black"><?= h(format_money((int)$request['amount_minor'])) ?></p>
                  <p class="mt-1 break-all text-[10px] leading-4 text-slate-500">UTR <?= h($request['utr_number']) ?></p>
                  <p class="mt-1 text-[10px] leading-4 text-slate-500"><?= h(date('d M Y · g:i A',strtotime((string)$request['created_at'].' UTC'))) ?></p>
                </div>
                <span class="badge shrink-0 whitespace-nowrap <?= $request['status']==='approved'?'badge-open':($request['status']==='rejected'?'badge-closed':'badge-soon') ?>"><?= h(ucfirst($request['status'])) ?></span>
              </div>
              <?php if ($request['review_note']): ?><p class="mt-3 break-words text-[10px] leading-4 text-slate-400"><?= h($request['review_note']) ?></p><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <section class="glass-card min-w-0 rounded-2xl p-5">
      <div><p class="text-[10px] font-black uppercase tracking-widest text-violet-300">Withdrawal requests</p><h2 class="mt-1 text-lg font-black">Payout status</h2></div>
      <?php if (!$withdrawals): ?>
        <p class="mt-5 rounded-xl border border-dashed border-white/10 p-7 text-center text-xs text-slate-500">No withdrawal requests submitted.</p>
      <?php else: ?>
        <div class="mt-4 min-w-0 space-y-3">
          <?php foreach ($withdrawals as $request): ?>
            <article class="min-w-0 rounded-xl border border-white/[.08] bg-black/10 p-4">
              <div class="flex min-w-0 items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-black"><?= h(format_money((int)$request['amount_minor'])) ?></p>
                  <p class="mt-1 break-words text-[10px] leading-4 text-slate-500">Request #<?= h($request['id']) ?> · <?= h(date('d M Y · g:i A',strtotime((string)$request['created_at'].' UTC'))) ?></p>
                </div>
                <span class="badge shrink-0 whitespace-nowrap <?= $request['status']==='paid'?'badge-open':($request['status']==='rejected'?'badge-closed':'badge-soon') ?>"><?= h(ucfirst($request['status'])) ?></span>
              </div>
              <?php if ($request['status']==='paid' && $request['payout_reference']): ?>
                <p class="mt-3 break-all text-[10px] text-slate-400">Payout ref: <span class="font-mono"><?= h($request['payout_reference']) ?></span></p>
              <?php elseif ($request['status']==='rejected'): ?>
                <p class="mt-3 break-words text-[10px] text-slate-400">Reserved funds were returned to your available balance.</p>
              <?php else: ?>
                <p class="mt-3 break-words text-[10px] leading-4 text-slate-500">Funds are reserved. This status is not confirmation of payment.</p>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>
