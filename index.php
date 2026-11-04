<?php
declare(strict_types=1);

require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/guard.php';

wl_session_start();

/* --- Abmelden: alle geöffneten Listen wieder sperren --- */
if (($_GET['action'] ?? '') === 'logout') {
    unset($_SESSION['lists_ok']);
    session_regenerate_id(true);
    flash_set('ok', 'Du bist abgemeldet. Zum Weiterschauen brauchst du das Passwort wieder.');
    redirect('index.php');
}

/* --- Welche Wunschliste? --- */
$slug = trim((string)($_GET['l'] ?? ''));
$list = $slug !== '' ? list_by_slug($slug) : list_default();

/* Ist die Standardliste gerade zu, fragt die Seite nach einem Passwort,
   damit man trotzdem zu einer anderen Liste kommt. */
if ($slug === '' && $list && !list_is_open($list) && !is_admin()) {
    $list = null;
}
guest_gate($list);

$listId    = (string)$list['id'];
$theme     = theme_of($list);
$tagsMap   = tags_map($listId);
$allTags   = tags_all($listId);
$gifts     = gifts_all($listId);

/* --- Filter aus der URL --- */
$activeTags = isset($_GET['tag']) ? (array)$_GET['tag'] : [];
$activeTags = array_values(array_filter(array_map('strval', $activeTags), fn($t) => isset($tagsMap[$t])));
$activeTags = array_slice($activeTags, 0, 1);      // es ist immer nur ein Tag aktiv

$hideReserved = !list_setting($list, 'show_reserved_gifts', true);   // reservierte ganz ausblenden?

$status = $_GET['status'] ?? 'available';          // Standard: nur noch verfügbare
if (!in_array($status, ['available', 'reserved', 'all'], true)) {
    $status = 'available';
}
if ($hideReserved) {
    $status = 'available';                          // dann gibt es nichts anderes zu sehen
}
$query = trim((string)($_GET['q'] ?? ''));

$filtered = array_values(array_filter($gifts, function (array $gift) use ($activeTags, $status, $query) {
    if ($activeTags) {
        $giftTags = (array)($gift['tags'] ?? []);
        if (!array_intersect($activeTags, $giftTags)) {
            return false;
        }
    }
    if ($status === 'available' && !gift_is_available($gift)) {
        return false;
    }
    if ($status === 'reserved' && gift_is_available($gift)) {
        return false;
    }
    if ($query !== '') {
        $haystack = mb_strtolower(($gift['title'] ?? '') . ' ' . ($gift['description'] ?? ''));
        if (!str_contains($haystack, mb_strtolower($query))) {
            return false;
        }
    }
    return true;
}));

/** Baut eine URL mit geänderten Filtern. */
function filter_url(array $overrides, array $activeTags, string $status, string $query): string
{
    global $list;
    $params = array_merge([
        'l'      => $list['slug'] ?? '',
        'tag'    => $activeTags,
        'status' => $status,
        'q'      => $query,
    ], $overrides);
    if (($params['l'] ?? '') === '') {
        unset($params['l']);
    }
    if ($params['status'] === 'available') {
        unset($params['status']);
    }
    if (($params['q'] ?? '') === '') {
        unset($params['q']);
    }
    if (empty($params['tag'])) {
        unset($params['tag']);
    }
    $qs = http_build_query($params);
    return 'index.php' . ($qs ? '?' . $qs : '');
}

/** Tag anklicken: wählt ihn allein aus, nochmals klicken hebt ihn auf. */
function toggled_tags(array $activeTags, string $tagId): array
{
    return in_array($tagId, $activeTags, true) ? [] : [$tagId];
}

$countAvailable = count(array_filter($gifts, 'gift_is_available'));

/**
 * Trefferzahl und Karten. Wird auch allein ausgeliefert (partial=list),
 * damit die Suche ohne Seitenwechsel aktualisieren kann.
 */
function render_results(array $filtered, array $gifts, array $tagsMap, string $status, int $countAvailable, string $listId = ''): void
{
?>
<p class="result-count">
    <?= count($filtered) ?> <?= count($filtered) === 1 ? 'Wunsch' : 'Wünsche' ?>
    <?php if ($status === 'available'): ?> noch verfügbar<?php endif; ?>
    <?php if ($countAvailable === 0 && $gifts): ?> &middot; alles reserviert, super! &#127881;<?php endif; ?>
</p>

<?php if (!$gifts): ?>
    <div class="empty card-soft">
        <p class="empty-emoji">&#127876;</p>
        <h2>Noch keine Wünsche erfasst</h2>
        <p class="muted">Im <a href="admin.php">Admin-Bereich</a> können die ersten Wünsche angelegt werden.</p>
    </div>
<?php elseif (!$filtered): ?>
    <div class="empty card-soft">
        <p class="empty-emoji">&#128269;</p>
        <h2>Keine Treffer</h2>
        <p class="muted">Mit diesen Filtern gibt es nichts. <a href="index.php">Filter zurücksetzen</a></p>
    </div>
<?php else: ?>
    <ul class="grid">
    <?php foreach ($filtered as $gift):
        $available = gift_is_available($gift);
        $image     = gift_image_src($gift);
        $qty       = gift_quantity($gift);
        $taken     = gift_reserved_count($gift);
        $link      = 'gift.php?id=' . urlencode($gift['id']);
    ?>
        <li class="card <?= $available ? '' : 'card-reserved' ?>">
            <a class="card-link" href="<?= h($link) ?>" data-gift-link
               data-title="<?= h($gift['title'] ?? '') ?>">
                <div class="card-media">
                    <?php if ($image !== ''): ?>
                        <img src="<?= h($image) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="card-media-placeholder">&#127873;</div>
                    <?php endif; ?>
                    <?php if (!$available): ?>
                        <span class="badge badge-reserved">Reserviert</span>
                    <?php elseif ($qty > 1): ?>
                        <span class="badge badge-qty"><?= $qty - $taken ?>&times; frei</span>
                    <?php endif; ?>
                    <?php if ((int)($gift['priority'] ?? 2) === 3): ?>
                        <span class="badge badge-star">&#11088;&nbsp;Grosser Wunsch</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <h2 class="card-title"><?= h($gift['title'] ?? 'Ohne Titel') ?></h2>
                    <?php if (!empty($gift['tags'])): ?>
                        <div class="pill-row">
                            <?php foreach ((array)$gift['tags'] as $tagId):
                                if (isset($tagsMap[$tagId])) echo tag_pill($tagsMap[$tagId], true);
                            endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($gift['description'])): ?>
                        <p class="card-text"><?= h(mb_strimwidth((string)$gift['description'], 0, 120, '…')) ?></p>
                    <?php endif; ?>
                    <div class="card-foot">
                        <?php if (format_price($gift['price'] ?? '') !== ''): ?>
                            <span class="price"><?= h(format_price($gift['price'])) ?></span>
                        <?php endif; ?>
                        <span class="card-cta"><?= $available ? 'Ansehen &amp; schenken' : 'Details' ?> &rarr;</span>
                    </div>
                </div>
            </a>
            <?php if (is_admin()): ?>
                <a class="card-admin" title="Im Admin bearbeiten"
                   aria-label="«<?= h($gift['title'] ?? '') ?>» im Admin bearbeiten"
                   href="admin.php?l=<?= h($listId) ?>&amp;edit=<?= h($gift['id']) ?>#form">&#9998;</a>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif;
}


/* Nur die Trefferliste (für die Suche ohne Seitenwechsel) */
if (($_GET['partial'] ?? '') === 'list') {
    header('Content-Type: text/html; charset=utf-8');
    render_results($filtered, $gifts, $tagsMap, $status, $countAvailable, $listId);
    exit;
}

layout_header('', 'page-list', $list);
?>
<?php admin_bar($list); ?>
<header class="site-header<?= !empty($_SESSION['lists_ok']) ? ' has-logout' : '' ?>">
    <div class="wrap header-inner">
        <?php if (!empty($_SESSION['lists_ok'])): ?>
            <a class="header-logout" href="index.php?action=logout">Abmelden</a>
        <?php endif; ?>
        <div class="header-text">
            <p class="eyebrow"><?= $theme['emoji'] ?> Wunschliste</p>
            <h1><?= h($list['name']) ?></h1>
            <?php if (!empty($list['subtitle'])): ?><p class="subtitle"><?= h($list['subtitle']) ?></p><?php endif; ?>
        </div>
    </div>
    <div class="garland" aria-hidden="true"></div>
</header>

<main class="wrap">
    <?php render_flash(); ?>

    <?php if (!list_is_open($list)): ?>
        <div class="flash flash-info">
            <strong>Nur für dich sichtbar:</strong> <?= h(list_availability($list)['text']) ?>
            Besucher sehen diese Liste gerade nicht.
        </div>
    <?php endif; ?>

    <section class="intro card-soft">
        <p><strong>So funktioniert's:</strong> Wunsch aussuchen, eigenen Namen eintragen und auf
        <em>Schenken</em> klicken. Der Wunsch wird dann für alle anderen als reserviert markiert.</p>
    </section>

    <form class="filters" method="get" action="index.php" id="filter-form">
        <div class="search-row">
            <input type="hidden" name="l" value="<?= h($list['slug'] ?? '') ?>">
            <div class="search-field">
                <input type="search" id="q" name="q" value="<?= h($query) ?>" autocomplete="off"
                       placeholder="Wunsch suchen …" aria-label="Wunsch suchen"
                       aria-controls="results">
                <button type="button" class="search-clear" data-search-clear
                        aria-label="Suche zurücksetzen" <?= $query === '' ? 'hidden' : '' ?>>&times;</button>
            </div>
            <?php foreach ($activeTags as $t): ?><input type="hidden" name="tag[]" value="<?= h($t) ?>"><?php endforeach; ?>
            <input type="hidden" name="status" value="<?= h($status) ?>">
            <button class="btn btn-ghost" type="submit" data-search-submit>Suchen</button>
        </div>

        <?php if ($allTags): ?>
        <div class="chip-row" role="group" aria-label="Nach Tags filtern">
            <a class="chip <?= $activeTags ? '' : 'chip-on' ?>" href="<?= h(filter_url(['tag' => []], $activeTags, $status, $query)) ?>">Alle</a>
            <?php foreach ($allTags as $tag):
                $on = in_array($tag['id'], $activeTags, true); ?>
                <a class="chip <?= $on ? 'chip-on' : '' ?>"
                   style="--pill:<?= h(tag_color($tag)) ?>"
                   href="<?= h(filter_url(['tag' => toggled_tags($activeTags, $tag['id'])], $activeTags, $status, $query)) ?>">
                    <?= h($tag['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$hideReserved): ?>
        <?php $filterAktiv = ($query !== '' || $activeTags || $status !== 'available'); ?>
        <div class="chip-row chip-row-status" role="group" aria-label="Nach Status filtern">
            <?php
            $statusLabels = [
                'available' => 'Noch verfügbar',
                'reserved'  => 'Schon reserviert',
                'all'       => 'Alle anzeigen',
            ];
            foreach ($statusLabels as $key => $label): ?>
                <a class="chip chip-status <?= $status === $key ? 'chip-on' : '' ?>"
                   href="<?= h(filter_url(['status' => $key], $activeTags, $status, $query)) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
            <a class="chip chip-reset" id="filter-reset" href="<?= h(list_url($list)) ?>"
               <?= $filterAktiv ? '' : 'hidden' ?>>&#8635; Zurücksetzen</a>
        </div>
        <?php else: ?>
        <div class="chip-row chip-row-status">
            <a class="chip chip-reset" id="filter-reset" href="<?= h(list_url($list)) ?>"
               <?= $filterAktiv ? '' : 'hidden' ?>>&#8635; Zurücksetzen</a>
        </div>
        <?php endif; ?>
    </form>

    <div id="results" aria-live="polite">
        <?php render_results($filtered, $gifts, $tagsMap, $status, $countAvailable, $listId); ?>
    </div>

    <?php
    $andere = array_values(array_filter(lists_all(),
        fn($l) => $l['id'] !== $listId && list_is_open($l) && list_is_unlocked($l)));
    if ($andere): ?>
        <nav class="other-lists">
            <span class="muted small">Weitere Listen:</span>
            <?php foreach ($andere as $o): ?>
                <a class="chip" href="<?= h(list_url($o)) ?>"><?= h($o['name']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <p class="admin-link"><a href="admin.php">Admin-Bereich</a></p>
</main>

<dialog id="gift-modal" class="modal" aria-label="Wunsch">
    <button type="button" class="modal-close" data-modal-close aria-label="Schliessen">&times;</button>
    <div class="modal-scroll" id="gift-modal-body"></div>
</dialog>
<?php layout_footer(); ?>
