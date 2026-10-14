<main class="mx-auto max-w-2xl px-4 py-12 pb-28 sm:px-6 md:pb-12">
  <div class="glass-card rounded-2xl p-5 sm:p-8">
    <div class="flex items-center gap-3"><span class="grid h-11 w-11 place-items-center rounded-xl bg-violet-500/10 text-violet-300"><i class="fa-solid fa-lock" aria-hidden="true"></i></span><div><p class="text-[10px] font-black uppercase tracking-widest text-violet-300">Razorpay checkout</p><h1 class="mt-1 text-xl font-black">Complete your deposit</h1></div></div>
    <div class="mt-6 rounded-xl border border-white/[.08] bg-black/15 p-4"><p class="text-xs text-slate-500">Order amount</p><p class="mt-1 text-3xl font-black text-neon-400"><?= h(format_money((int)$order['amount_minor'])) ?></p><p class="mt-2 font-mono text-[10px] text-slate-600"><?= h($order['provider_order_id']) ?></p></div>
    <form id="razorpay-verify-form" method="post" action="<?= h(app_url('index.php?action=payment-verify')) ?>" class="mt-5">
      <?= csrf_field() ?>
      <input type="hidden" name="local_order_id" value="<?= h($order['id']) ?>">
      <input type="hidden" name="razorpay_order_id" value="<?= h($order['provider_order_id']) ?>">
      <input type="hidden" name="razorpay_payment_id" value="">
      <input type="hidden" name="razorpay_signature" value="">
      <button id="razorpay-pay-button" class="btn-primary w-full rounded-xl px-4 py-3.5 text-sm font-black"><i class="fa-solid fa-arrow-up-right-from-square mr-2" aria-hidden="true"></i>Open secure checkout</button>
    </form>
    <p class="mt-4 text-[10px] leading-5 text-slate-500">Payment success is verified by the server and payment provider. Wallet funds are credited only after a verified captured payment. If a payment is authorized but not captured, it is not spendable.</p>
    <a class="mt-4 inline-flex text-xs font-bold text-slate-400 hover:text-white" href="<?= h(app_url('index.php?page=wallet')) ?>"><i class="fa-solid fa-arrow-left mr-2" aria-hidden="true"></i>Return to wallet</a>
  </div>
</main>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script nonce="<?= h($cspNonce) ?>">
(() => {
  const button = document.getElementById('razorpay-pay-button');
  if (!button || typeof window.Razorpay === 'undefined') return;
  const form = document.getElementById('razorpay-verify-form');
  const options = {
    key: <?= json_encode($order['key_id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    amount: <?= (int) $order['amount_minor'] ?>,
    currency: 'INR',
    name: 'Victory Yudh',
    description: 'Wallet deposit',
    order_id: <?= json_encode($order['provider_order_id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    theme: { color: '#9061ff' },
    handler: (response) => {
      form.elements.razorpay_payment_id.value = response.razorpay_payment_id || '';
      form.elements.razorpay_signature.value = response.razorpay_signature || '';
      HTMLFormElement.prototype.submit.call(form);
    }
  };
  button.addEventListener('click', (event) => {
    event.preventDefault();
    new window.Razorpay(options).open();
  });
})();
</script>
