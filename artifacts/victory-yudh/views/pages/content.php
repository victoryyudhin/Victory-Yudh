<main class="mx-auto max-w-5xl px-4 py-10 pb-28 sm:px-6 lg:px-8 md:pb-12">
  <div class="glass-card rounded-2xl p-5 sm:p-8">
    <p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300"><?= h($eyebrow ?? 'Victory Yudh') ?></p>
    <h1 class="mt-2 text-3xl font-black"><?= h($heading ?? 'Information') ?></h1>
    <div class="prose-copy mt-5"><?= $contentHtml ?? '' ?></div>
    <?php if (($contentPage ?? '') === 'support'): ?>
      <div class="mt-8 border-t border-white/[.08] pt-6">
        <h2 class="text-lg font-black">Contact tournament support</h2>
        <p class="mt-2 text-xs leading-5 text-slate-400">Send a request to the Victory Yudh team. Do not include passwords, full card numbers, or payment secrets.</p>
        <form method="post" action="<?= h(app_url('index.php?action=support-submit')) ?>" class="mt-5 grid gap-4 sm:grid-cols-2">
          <?= csrf_field() ?>
          <label class="text-xs font-bold text-slate-400">Email<input class="form-control mt-2" type="email" name="email" maxlength="254" required value="<?= h($current['email'] ?? '') ?>" placeholder="you@example.com"></label>
          <label class="text-xs font-bold text-slate-400">Subject<input class="form-control mt-2" name="subject" maxlength="140" required placeholder="How can we help?"></label>
          <label class="text-xs font-bold text-slate-400 sm:col-span-2">Message<textarea class="form-control mt-2 min-h-32" name="message" maxlength="4000" required placeholder="Include the tournament or transaction reference if relevant."></textarea></label>
          <div><button class="btn-primary rounded-xl px-5 py-3 text-sm font-black">Send support request</button></div>
        </form>
        <?php if($supportEmail): ?><p class="mt-4 text-[10px] text-slate-500">You can also email <a class="text-violet-300 underline" href="mailto:<?= h($supportEmail) ?>"><?= h($supportEmail) ?></a>.</p><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</main>
