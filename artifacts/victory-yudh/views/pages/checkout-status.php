<main class="mx-auto max-w-2xl px-4 py-16 pb-28 sm:px-6 md:pb-12">
  <section class="glass-card rounded-2xl p-7 text-center">
    <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl <?= $paymentStatus==='captured'?'bg-neon-400/10 text-neon-400':'bg-amber-300/10 text-amber-200' ?>"><i class="fa-solid <?= $paymentStatus==='captured'?'fa-check':'fa-hourglass-half' ?> text-xl" aria-hidden="true"></i></span>
    <p class="mt-5 text-[10px] font-black uppercase tracking-[.2em] text-violet-300">Payment status</p>
    <h1 class="mt-2 text-2xl font-black"><?= $paymentStatus==='captured'?'Deposit verified':'Payment is not yet captured' ?></h1>
    <p class="mt-3 text-sm leading-6 text-slate-400"><?= $paymentStatus==='captured'?'The verified deposit is available in your wallet.':'Your payment is pending capture or provider verification. No wallet funds were added yet. Check back after the provider confirms the payment.' ?></p>
    <a class="btn-primary mt-6 inline-flex rounded-xl px-5 py-3 text-sm font-black" href="<?= h(app_url('index.php?page=wallet')) ?>">Open wallet</a>
  </section>
</main>
