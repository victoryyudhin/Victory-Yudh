<footer class="mt-16 border-t border-white/[.08] pb-28 pt-10 md:pb-10">
  <div class="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 md:grid-cols-[1fr_auto] lg:px-8">
    <div>
      <a href="<?= h(app_url('index.php')) ?>" class="text-sm font-black tracking-[.14em]">VICTORY <span class="text-violet-300">YUDH</span></a>
      <p class="mt-2 max-w-md text-xs leading-6 text-slate-500">A competitive Free Fire tournament platform. Scores are calculated from match results entered or imported by authorized tournament admins.</p>
    </div>
    <nav class="flex flex-wrap gap-x-5 gap-y-3 text-xs font-semibold text-slate-400" aria-label="Footer links">
      <a class="hover:text-white" href="<?= h(app_url('index.php?page=faq')) ?>">FAQ</a>
      <a class="hover:text-white" href="<?= h(app_url('index.php?page=support')) ?>">Support</a>
      <a class="hover:text-white" href="<?= h(app_url('index.php?page=terms')) ?>">Terms</a>
      <a class="hover:text-white" href="<?= h(app_url('index.php?page=privacy')) ?>">Privacy</a>
      <a class="hover:text-white" href="<?= h(app_url('index.php?page=refund-policy')) ?>">Refund policy</a>
    </nav>
    <p class="text-[11px] text-slate-600 md:col-span-2">© <?= h(date('Y')) ?> Victory Yudh. Free Fire is a trademark of its respective owner; Victory Yudh is an independent community tournament platform.</p>
  </div>
</footer>
