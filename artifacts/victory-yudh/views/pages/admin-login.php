<main class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
  <section class="mx-auto max-w-xl rounded-3xl border border-violet-300/15 bg-[#101126] p-6 shadow-2xl shadow-black/30 sm:p-9">
    <p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300">Secure administrator access</p>
    <h1 class="mt-3 text-3xl font-black">Sign in to the admin panel</h1>
    <p class="mt-3 text-sm leading-6 text-slate-400">Use an active administrator account. Player accounts cannot access admin tools.</p>
    <?php if ($current): ?>
      <p class="mt-4 rounded-xl border border-white/10 bg-white/[.04] px-4 py-3 text-xs leading-5 text-slate-300">A player session is currently active in this browser. It will switch to the administrator account only after successful verification.</p>
    <?php endif; ?>
    <form class="mt-7 space-y-4" method="post" action="<?= h(app_url('index.php?action=admin-login')) ?>">
      <?= csrf_field() ?>
      <label class="block text-xs font-bold text-slate-400">Administrator email
        <input class="form-control mt-2" type="email" name="admin_email" maxlength="254" autocomplete="username" required placeholder="admin@example.com">
      </label>
      <label class="block text-xs font-bold text-slate-400">Password
        <input class="form-control mt-2" type="password" name="admin_password" autocomplete="current-password" required placeholder="Enter your administrator password">
      </label>
      <button class="btn-primary w-full rounded-xl px-5 py-3.5 text-sm font-black">Sign in as administrator</button>
    </form>
    <p class="mt-6 text-center text-xs text-slate-500"><a class="font-bold text-violet-300 hover:text-white" href="<?= h(app_url('index.php')) ?>">Return to Victory Yudh</a></p>
  </section>
</main>
