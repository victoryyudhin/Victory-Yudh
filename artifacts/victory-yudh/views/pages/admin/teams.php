<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
  <div><p class="text-[10px] font-black uppercase tracking-[.2em] text-violet-300">Roster controls</p><h1 class="mt-2 text-3xl font-black">Team management</h1><p class="mt-2 text-sm text-slate-400">Review captains, rosters, pending invites, and active tournament entries. Roster edits are blocked while an entry is active.</p></div>
  <form method="get" class="glass-card mt-5 flex flex-wrap items-end gap-3 rounded-2xl p-4">
    <input type="hidden" name="page" value="admin-teams">
    <label class="min-w-48 flex-1 text-xs font-bold text-slate-400">Search team or captain<input class="form-control mt-2" name="q" maxlength="80" value="<?= h($search) ?>" placeholder="Team, player, or email"></label>
    <button class="btn-primary rounded-xl px-5 py-3 text-sm font-black">Search teams</button>
  </form>

  <section class="mt-5 space-y-4">
    <?php if (!$teams): ?><div class="glass-card rounded-2xl p-10 text-center text-sm text-slate-500">No teams match this search.</div><?php endif; ?>
    <?php foreach ($teams as $team): ?>
      <article class="glass-card rounded-2xl p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div><p class="text-[10px] font-black uppercase tracking-widest text-violet-300">Team #<?= h($team['id']) ?></p><h2 class="mt-1 text-xl font-black"><?= h($team['name']) ?></h2><p class="mt-1 text-xs text-slate-400">Captain: <?= h($team['captain_name'] ?: $team['captain_email']) ?> · <?= h($team['captain_email']) ?></p></div>
          <div class="flex flex-wrap gap-2 text-[10px]"><span class="badge badge-soon"><?= h($team['member_count']) ?>/4 members</span><span class="badge badge-soon"><?= h($team['pending_invites']) ?> pending invites</span><span class="badge <?= (int)$team['active_entries']>0?'badge-open':'badge-soon' ?>"><?= h($team['active_entries']) ?> active entries</span></div>
        </div>
        <div class="table-wrap mt-4">
          <table class="w-full text-left text-xs"><thead class="text-[10px] uppercase tracking-wider text-slate-500"><tr><th class="pb-3">Player</th><th class="pb-3">Role</th><th class="pb-3">Joined</th><th class="pb-3">Action</th></tr></thead>
            <tbody class="divide-y divide-white/[.07]">
              <?php foreach ($team['members'] as $member): ?>
                <tr><td class="py-3"><p class="font-bold"><?= h($member['display_name'] ?: $member['email']) ?></p><p class="mt-1 text-[10px] text-slate-500"><?= h($member['email']) ?></p></td><td class="py-3 uppercase text-violet-200"><?= h($member['member_role']) ?></td><td class="py-3 text-[10px] text-slate-500"><?= h(date('d M Y',strtotime((string)$member['joined_at'].' UTC'))) ?></td><td class="py-3"><?php if ($member['member_role'] === 'member' && (int)$team['active_entries'] === 0): ?><form method="post" action="<?= h(app_url('index.php?action=admin-team-remove-member')) ?>" data-confirm="Remove this player from the team?"><?= csrf_field() ?><input type="hidden" name="team_id" value="<?= h($team['id']) ?>"><input type="hidden" name="member_id" value="<?= h($member['user_id']) ?>"><button class="rounded-lg border border-rose-300/15 px-3 py-2 text-[10px] font-bold text-rose-200">Remove member</button></form><?php elseif ($member['member_role'] === 'captain'): ?><span class="text-[10px] text-slate-600">Captain protected</span><?php else: ?><span class="text-[10px] text-slate-600">Active entry protected</span><?php endif; ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="mt-3 text-[10px] text-slate-600">Created <?= h(date('d M Y',strtotime((string)$team['created_at'].' UTC'))) ?>. Team roster changes are recorded in the admin audit log.</p>
      </article>
    <?php endforeach; ?>
  </section>
  <?php if ($pageCount > 1): ?><nav class="mt-5 flex flex-wrap gap-2" aria-label="Team pages"><?php for($n=1;$n<=$pageCount;$n++): ?><a class="rounded-lg border px-3 py-2 text-xs <?= $n===$pageNumber?'border-violet-400/50 text-violet-200':'border-white/10 text-slate-400' ?>" href="<?= h(app_url('index.php?'.http_build_query(['page'=>'admin-teams','q'=>$search,'p'=>$n]))) ?>"><?= h($n) ?></a><?php endfor; ?></nav><?php endif; ?>
</main>
