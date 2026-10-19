<section class="glass-card mt-5 rounded-2xl p-5">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <p class="text-[10px] font-black uppercase tracking-widest text-violet-300">Full match entry</p>
      <h2 class="mt-1 text-lg font-black">Bulk results · Match <?= h($activeMatchNumber) ?></h2>
      <p class="mt-2 max-w-2xl text-xs leading-5 text-slate-500">Enter one row for every confirmed player or team. Equal placement ranks are allowed for shared winners. Existing results for this match are prefilled.</p>
    </div>
    <span class="badge badge-soon"><?= h(count($registrations)) ?> entries</span>
  </div>
  <?php if (!$registrations): ?>
    <p class="mt-5 rounded-xl border border-dashed border-white/10 p-6 text-center text-xs text-slate-500">No confirmed players or teams are registered yet.</p>
  <?php else: ?>
    <form method="post" action="<?= h(app_url('index.php?action=admin-results-bulk-save')) ?>" class="mt-5" data-bulk-results>
      <?= csrf_field() ?>
      <input type="hidden" name="tournament_id" value="<?= h($selectedTournament['id']) ?>">
      <input type="hidden" name="match_number" value="<?= h($activeMatchNumber) ?>">
      <input type="hidden" name="results_json" value="">
      <label class="mb-4 block text-xs font-bold text-slate-400">Match Note · optional
        <textarea class="form-control mt-2 min-h-20" name="match_note" maxlength="500" placeholder="Optional message shown above published results"><?= h($currentMatchNote) ?></textarea>
      </label>
      <div class="table-wrap">
        <table class="w-full min-w-[720px] text-left text-xs">
          <thead class="text-[10px] uppercase tracking-wider text-slate-500">
            <tr><th class="pb-3 pr-3">Player / team · username / IGN</th><th class="pb-3 pr-3">Placement</th><th class="pb-3 pr-3">Kills</th><th class="pb-3 pr-3">Bonus input</th><th class="pb-3">Penalty input</th></tr>
          </thead>
          <tbody class="divide-y divide-white/[.07]">
            <?php foreach ($registrations as $entry):
              $saved = $existingMatchResults[(int) $entry['id']] ?? [];
            ?>
              <tr data-bulk-result-row data-registration-id="<?= h($entry['id']) ?>">
                <td class="py-3 pr-3"><p class="font-bold text-slate-200"><?= h($entry['entry_name']) ?></p><p class="mt-1 text-[10px] text-slate-600">Entry #<?= h($entry['id']) ?> · <?= h($entry['member_count']) ?> player(s)</p><ul class="mt-2 space-y-1"><?php foreach ($entry['players'] as $player): ?><li class="text-[10px] leading-4 text-slate-400"><span class="text-slate-500">Username:</span> <?= h($player['username']) ?> <span class="text-slate-600">·</span> <span class="text-slate-500">IGN:</span> <?= h($player['ign'] ?: '—') ?></li><?php endforeach; ?></ul></td>
                <td class="py-3 pr-3"><input class="form-control w-24 !px-2 !py-2" type="number" min="1" max="1000" required data-result-field="placement_rank" value="<?= h($saved['placement_rank'] ?? '') ?>" aria-label="Placement for <?= h($entry['entry_name']) ?>"></td>
                <td class="py-3 pr-3"><input class="form-control w-24 !px-2 !py-2" type="number" min="0" max="1000" required data-result-field="kills" value="<?= h($saved['kills'] ?? 0) ?>" aria-label="Kills for <?= h($entry['entry_name']) ?>"></td>
                <td class="py-3 pr-3"><input class="form-control w-24 !px-2 !py-2" type="number" min="0" max="10000" required data-result-field="bonus_points" value="<?= h($saved['bonus_input'] ?? 0) ?>" aria-label="Bonus points for <?= h($entry['entry_name']) ?>"></td>
                <td class="py-3"><input class="form-control w-24 !px-2 !py-2" type="number" min="0" max="10000" required data-result-field="penalty_points" value="<?= h($saved['penalty_input'] ?? 0) ?>" aria-label="Penalty points for <?= h($entry['entry_name']) ?>"></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-[10px] leading-5 text-slate-600">The server verifies the roster and saves every row together. If any row is invalid, no results are saved.</p>
        <button class="btn-primary rounded-xl px-5 py-3 text-sm font-black">Save all <?= h(count($registrations)) ?> results</button>
      </div>
    </form>
  <?php endif; ?>
</section>
