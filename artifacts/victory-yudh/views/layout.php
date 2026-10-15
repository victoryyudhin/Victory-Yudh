<?php
$pageTitle = $pageTitle ?? 'Victory Yudh';
$current = $current ?? current_user();
$flashes = take_flashes();
$page = $_GET['page'] ?? 'home';
$admin = $current && $current['role'] === 'admin';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#080916">
  <meta name="description" content="Join Free Fire Solo, Duo and Squad tournaments, compete for prizes, and track every match on Victory Yudh.">
  <link rel="manifest" href="<?= h(app_url('manifest.webmanifest')) ?>">
  <link rel="icon" href="<?= h(app_url('assets/mark.svg')) ?>" type="image/svg+xml">
  <link rel="apple-touch-icon" href="<?= h(app_url('assets/icon-192.png')) ?>">
  <link rel="stylesheet" href="<?= h(app_url('assets/app.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" referrerpolicy="no-referrer">
  <title><?= h($pageTitle) ?> · Victory Yudh</title>
</head>
<body class="page-glow min-h-screen text-white antialiased">
  <?php if ($admin && str_starts_with((string) $page, 'admin')): ?>
    <div class="min-h-screen lg:grid lg:grid-cols-[248px_1fr]">
      <?php require __DIR__ . '/partials/admin-nav.php'; ?>
      <div class="min-w-0">
        <?php require __DIR__ . '/partials/topbar.php'; ?>
        <?php foreach ($flashes as $item): ?>
          <div class="mx-4 mt-4 rounded-xl border px-4 py-3 text-sm <?= $item['type'] === 'success' ? 'border-emerald-400/20 bg-emerald-400/10 text-emerald-200' : 'border-rose-400/20 bg-rose-400/10 text-rose-200' ?>"><?= h($item['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?? '' ?>
      </div>
    </div>
  <?php else: ?>
    <?php require __DIR__ . '/partials/topbar.php'; ?>
    <?php foreach ($flashes as $item): ?>
      <div class="mx-auto mt-4 max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="rounded-xl border px-4 py-3 text-sm <?= $item['type'] === 'success' ? 'border-emerald-400/20 bg-emerald-400/10 text-emerald-200' : 'border-rose-400/20 bg-rose-400/10 text-rose-200' ?>"><?= h($item['message']) ?></div>
      </div>
    <?php endforeach; ?>
    <?= $content ?? '' ?>
    <?php require __DIR__ . '/partials/footer.php'; ?>
    <?php if ($current): ?>
      <nav aria-label="Mobile navigation" class="bottom-safe fixed inset-x-0 bottom-0 z-40 border-t border-white/10 bg-[#0b0c1bee] px-3 pt-2 backdrop-blur-xl md:hidden">
        <div class="mx-auto grid max-w-lg grid-cols-4 gap-1">
          <?php
            $bottomLinks = [
              ['home','Home','fa-house'],
              ['tournaments','Matches','fa-trophy'],
              ['wallet','Wallet','fa-wallet'],
              ['profile','Profile','fa-user'],
            ];
            foreach ($bottomLinks as [$target,$label,$icon]):
              $active = $page === $target || ($target === 'tournaments' && $page === 'tournament');
          ?>
            <a class="focus-ring flex min-h-12 flex-col items-center justify-center gap-1 rounded-xl text-[10px] font-semibold <?= $active ? 'text-violet-300' : 'text-slate-400' ?>" href="<?= h(app_url('index.php?page=' . $target)) ?>">
              <i class="fa-solid <?= h($icon) ?> text-base" aria-hidden="true"></i><span><?= h($label) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
  <script src="<?= h(app_url('assets/app.js')) ?>" defer></script>
  <script nonce="<?= h($cspNonce) ?>">
    if ('serviceWorker' in navigator && location.protocol === 'https:') {
      window.addEventListener('load', () => navigator.serviceWorker.register('<?= h(app_url('sw.js')) ?>').catch(() => {}));
    }
  </script>
</body>
</html>
