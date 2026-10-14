<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/actions.php';
require dirname(__DIR__) . '/app/pages.php';

if (isset($_GET['action'])) {
    $action = is_string($_GET['action']) ? $_GET['action'] : '';
    try {
        handle_post_action();
    } catch (InvalidArgumentException|DomainException $e) {
        flash('error', $e->getMessage());
        redirect(fallback_for_action($action));
    } catch (Throwable $e) {
        error_log('Victory Yudh action failure: ' . get_class($e));
        flash('error', 'The request could not be completed. No success was recorded; check the relevant status page before retrying.');
        redirect(fallback_for_action($action));
    }
}

try {
    $pageResult = render_page();
    if ($pageResult['not_found'] ?? false) {
        http_response_code(404);
        $pageTitle = 'Page not found';
        $content = '<main class="mx-auto max-w-xl px-5 py-24 text-center"><p class="text-violet-300 text-sm font-bold">VICTORY YUDH · 404</p><h1 class="mt-4 text-3xl font-black">This page is not in the arena.</h1><a class="btn-primary mt-8 inline-flex rounded-xl px-5 py-3 font-bold" href="' . h(app_url('index.php')) . '">Back to home</a></main>';
        $current = current_user();
    } else {
        extract($pageResult, EXTR_OVERWRITE);
    }
    require dirname(__DIR__) . '/views/layout.php';
} catch (Throwable $e) {
    error_log('Victory Yudh page failure: ' . get_class($e));
    http_response_code(500);
    $pageTitle = 'Something went wrong';
    $content = '<main class="mx-auto max-w-xl px-5 py-24 text-center"><p class="text-violet-300 text-sm font-bold">VICTORY YUDH · 500</p><h1 class="mt-4 text-3xl font-black">The page could not load.</h1><p class="mt-3 text-sm text-slate-400">No secrets or database details are shown here. Please try again or contact support.</p><a class="btn-primary mt-8 inline-flex rounded-xl px-5 py-3 font-bold" href="' . h(app_url('index.php?page=support')) . '">Contact support</a></main>';
    $current = current_user();
    require dirname(__DIR__) . '/views/layout.php';
}
