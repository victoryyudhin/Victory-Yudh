<section class="mt-6 border-t border-white/[.08] pt-5">
  <div>
    <h3 class="text-sm font-black">Editable formula settings</h3>
    <p class="mt-1 text-[10px] leading-5 text-slate-500">Changing a value recalculates saved results. Multiplier values range from 0 to 100.</p>
  </div>
  <form method="post" action="<?= h(app_url('index.php?action=admin-scoring-save')) ?>" class="mt-4 space-y-4">
    <?= csrf_field() ?>
    <input type="hidden" name="tournament_id" value="<?= h($selectedTournament['id']) ?>">
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="text-xs font-bold text-slate-400">Kills multiplier<input class="form-control mt-2" type="number" name="points_per_kill" min="0" max="100" required value="<?= h($formulaSettings['points_per_kill']) ?>"></label>
      <label class="text-xs font-bold text-slate-400">Placement multiplier<input class="form-control mt-2" type="number" name="placement_multiplier" min="0" max="100" required value="<?= h($formulaSettings['placement_multiplier']) ?>"></label>
      <label class="text-xs font-bold text-slate-400">Bonus multiplier<input class="form-control mt-2" type="number" name="bonus_multiplier" min="0" max="100" required value="<?= h($formulaSettings['bonus_multiplier']) ?>"></label>
      <label class="text-xs font-bold text-slate-400">Penalty multiplier<input class="form-control mt-2" type="number" name="penalty_multiplier" min="0" max="100" required value="<?= h($formulaSettings['penalty_multiplier']) ?>"></label>
      <label class="text-xs font-bold text-slate-400 sm:col-span-2">Placement points · one rank=points per line<textarea class="form-control mt-2 min-h-28 font-mono text-xs" name="placement_points" required><?= h($formulaSettings['placement_text']) ?></textarea></label>
    </div>
    <fieldset>
      <legend class="text-xs font-bold text-slate-400">Tie-breaker order</legend>
      <div class="mt-2 grid gap-2 sm:grid-cols-2">
        <?php foreach (['total_points'=>'Total points','total_kills'=>'Total kills','placement_points'=>'Placement points','best_rank'=>'Best placement','registration_time'=>'Earlier registration'] as $key=>$label): ?>
          <label class="flex items-center gap-2 rounded-lg border border-white/[.06] px-3 py-2 text-xs text-slate-300"><input type="checkbox" name="tie_breakers[]" value="<?= h($key) ?>" <?= in_array($key, $formulaSettings['tie_breakers'], true) ? 'checked' : '' ?>><?= h($label) ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <button class="rounded-xl border border-violet-300/20 bg-violet-500/10 px-4 py-3 text-xs font-black text-violet-200">Save formula and recalculate</button>
  </form>
</section>
