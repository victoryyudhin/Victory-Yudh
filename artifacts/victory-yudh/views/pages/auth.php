<main class="mx-auto max-w-6xl px-4 py-10 pb-28 sm:px-6 lg:px-8 md:pb-14">
  <div class="mx-auto grid max-w-4xl overflow-hidden rounded-[1.6rem] border border-white/10 bg-[#101126] shadow-2xl shadow-black/20 md:grid-cols-[.85fr_1.15fr]">
    <section class="relative hidden overflow-hidden bg-[radial-gradient(ellipse_at_90%_10%,rgba(144,97,255,.5),transparent_40%),linear-gradient(145deg,#1b1740,#101126)] p-8 md:block">
      <div class="absolute -right-12 top-24 h-52 w-52 rounded-full border border-violet-300/10"></div><div class="absolute -right-2 top-36 h-36 w-36 rounded-full border border-violet-300/10"></div>
      <p class="relative text-[10px] font-black uppercase tracking-[.22em] text-violet-200">VICTORY YUDH</p>
      <h2 class="relative mt-16 max-w-xs text-4xl font-black leading-tight">Play the match.<br><span class="text-violet-300">Own the result.</span></h2>
      <p class="relative mt-4 max-w-xs text-sm leading-6 text-slate-300/70">A fair arena for Free Fire players who want to compete, team up, and see how every point was earned.</p>
      <div class="relative mt-10 space-y-4 text-xs font-semibold text-slate-300"><p><i class="fa-solid fa-shield-halved mr-3 text-neon-400" aria-hidden="true"></i>Secure player accounts</p><p><i class="fa-solid fa-ranking-star mr-3 text-violet-300" aria-hidden="true"></i>Transparent leaderboards</p></div>
    </section>
    <section class="p-5 sm:p-8">
      <p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300"><?= $authMode === 'register' ? 'Create player profile' : 'Welcome back' ?></p>
      <h1 class="mt-2 text-2xl font-black"><?= $authMode === 'register' ? 'Join the arena' : 'Sign in to Victory Yudh' ?></h1>
      <p class="mt-2 text-sm text-slate-400"><?= $authMode === 'register' ? 'Set up your account to join upcoming tournaments.' : 'Pick up where your next match begins.' ?></p>
      <form class="mt-7 space-y-4" method="post" action="<?= h(app_url('index.php?action=' . ($authMode === 'register' ? 'register' : 'login'))) ?>">
        <?= csrf_field() ?>
        <?php if ($authMode === 'register'): ?>
          <label class="block text-xs font-bold text-slate-400">Player name<input class="form-control mt-2" name="display_name" maxlength="80" autocomplete="nickname" required placeholder="Your in-game name"></label>
        <?php endif; ?>
        <label class="block text-xs font-bold text-slate-400">Email address<input class="form-control mt-2" type="email" name="email" maxlength="254" autocomplete="email" required placeholder="you@example.com"></label>
        <?php if ($authMode === 'register'): ?>
          <label class="block text-xs font-bold text-slate-400">Free Fire UID <span class="font-normal text-slate-600">· optional</span><input class="form-control mt-2" name="free_fire_uid" inputmode="numeric" maxlength="32" placeholder="Your player UID"></label>
        <?php endif; ?>
        <label class="block text-xs font-bold text-slate-400">Password<input class="form-control mt-2" type="password" name="password" autocomplete="<?= $authMode === 'register' ? 'new-password' : 'current-password' ?>" minlength="<?= $authMode === 'register' ? '12' : '1' ?>" required placeholder="<?= $authMode === 'register' ? 'At least 12 characters' : 'Enter your password' ?>"></label>
        <?php if ($authMode === 'register'): ?><label class="block text-xs font-bold text-slate-400">Confirm password<input class="form-control mt-2" type="password" name="password_confirm" autocomplete="new-password" minlength="12" required placeholder="Repeat your password"></label><?php endif; ?>
        <button class="btn-primary focus-ring mt-2 w-full rounded-xl px-5 py-3.5 text-sm font-black"><?= $authMode === 'register' ? 'Create account' : 'Log in' ?></button>
      </form>
      <p class="mt-6 text-center text-xs text-slate-500"><?= $authMode === 'register' ? 'Already have an account?' : 'New to Victory Yudh?' ?> <a class="font-bold text-violet-300 hover:text-white" href="<?= h(app_url('index.php?page=' . ($authMode === 'register' ? 'login' : 'register'))) ?>"><?= $authMode === 'register' ? 'Log in' : 'Create an account' ?></a></p>
      <?php if ($authMode === 'register'): ?><p class="mt-5 text-[10px] leading-5 text-slate-600">By joining, you agree to the <a class="underline hover:text-slate-300" href="<?= h(app_url('index.php?page=terms')) ?>">Terms</a> and <a class="underline hover:text-slate-300" href="<?= h(app_url('index.php?page=privacy')) ?>">Privacy Policy</a>. Use only accounts and payment methods you are authorized to use.</p><?php endif; ?>
    </section>
  </div>
</main>
