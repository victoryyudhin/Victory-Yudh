<main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
  <div>
    <p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300">Player communications</p>
    <h1 class="mt-2 text-3xl font-black">Send notifications</h1>
    <p class="mt-2 text-sm text-slate-400">Send an in-app message to all active players or to selected accounts.</p>
  </div>

  <section class="glass-card mt-5 rounded-2xl p-5">
    <div class="flex items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-violet-500/10 text-violet-300"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i></span>
      <div><h2 class="font-black">Choose recipients</h2><p class="mt-1 text-xs leading-5 text-slate-500">There are <?= h(number_format($activeNotificationUsers)) ?> active player accounts.</p></div>
    </div>
    <form method="get" class="mt-4 flex flex-wrap items-end gap-3">
      <input type="hidden" name="page" value="admin-notifications">
      <label class="min-w-52 flex-1 text-xs font-bold text-slate-400">Search players by username, IGN, or account ID
        <input class="form-control mt-2" name="q" maxlength="100" value="<?= h($notificationSearch) ?>" placeholder="Type a name or email">
      </label>
      <button class="btn-primary rounded-xl px-5 py-3 text-sm font-black">Search players</button>
    </form>
    <?php if ($notificationSearch === ''): ?>
      <p class="mt-4 rounded-xl border border-dashed border-white/10 p-5 text-center text-xs leading-5 text-slate-500">Use the search above to load specific recipients. You can still send to all active players without searching.</p>
    <?php elseif (!$notificationUsers): ?>
      <p class="mt-4 rounded-xl border border-dashed border-white/10 p-5 text-center text-xs text-slate-500">No active players match that search.</p>
    <?php else: ?>
      <div class="mt-4 rounded-xl border border-white/[.08] bg-black/10 p-4">
        <p class="text-xs font-bold text-slate-300">Search results · <?= h(count($notificationUsers)) ?> shown, up to 200</p>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
          <?php foreach ($notificationUsers as $recipient): ?>
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/[.06] p-3 hover:border-violet-300/20">
              <input class="mt-1 accent-violet-400" type="checkbox" name="user_ids[]" value="<?= h($recipient['id']) ?>" form="send-notification">
              <span class="min-w-0"><span class="block truncate text-xs font-bold"><?= h($recipient['display_name'] ?: $recipient['email']) ?></span><span class="mt-1 block truncate text-[10px] text-slate-500"><?= h($recipient['email']) ?> · Account #<?= h($recipient['id']) ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <form id="send-notification" method="post" action="<?= h(app_url('index.php?action=admin-notification-send')) ?>" class="glass-card mt-5 space-y-4 rounded-2xl p-5">
    <?= csrf_field() ?>
    <fieldset>
      <legend class="text-xs font-black uppercase tracking-widest text-violet-300">Audience</legend>
      <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/[.08] bg-black/10 p-4">
          <input class="mt-1 accent-violet-400" type="radio" name="target_mode" value="all" checked>
          <span><span class="block text-sm font-bold">Send to All Users</span><span class="mt-1 block text-[10px] leading-4 text-slate-500">All <?= h(number_format($activeNotificationUsers)) ?> active player accounts.</span></span>
        </label>
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/[.08] bg-black/10 p-4">
          <input class="mt-1 accent-violet-400" type="radio" name="target_mode" value="selected">
          <span><span class="block text-sm font-bold">Search & select specific users</span><span class="mt-1 block text-[10px] leading-4 text-slate-500">Select one or more accounts from the search results above.</span></span>
        </label>
      </div>
    </fieldset>
    <label class="block text-xs font-bold text-slate-400">Notification title
      <input class="form-control mt-2" name="title" maxlength="120" required placeholder="Short, clear title">
    </label>
    <label class="block text-xs font-bold text-slate-400">Message
      <textarea class="form-control mt-2 min-h-32" name="body" maxlength="500" required placeholder="Write the message players will see in their notification inbox."></textarea>
    </label>
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-white/[.08] pt-4">
      <p class="max-w-xl text-[10px] leading-5 text-slate-500">Notifications appear in the selected players’ in-app inboxes. Selected delivery is limited to 200 active accounts per send.</p>
      <button class="btn-primary rounded-xl px-5 py-3 text-sm font-black" data-confirm="Send this in-app notification to the chosen audience?">Send notification</button>
    </div>
  </form>
</main>
