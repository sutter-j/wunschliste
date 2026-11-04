<?php
declare(strict_types=1);

require_once __DIR__ . '/themes.php';

/**
 * @param array|null $list  Wunschliste, deren Design gelten soll.
 */
function layout_header(string $title, string $bodyClass = '', ?array $list = null): void
{
    $theme     = theme_of($list);
    $themeKey  = theme_key($list);
    $listName  = (string)($list['name'] ?? cfg('site_title'));
    $full      = $title !== '' ? $title . ' | ' . $listName : $listName;
    $favicon   = 'data:image/svg+xml,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
        . '<text y=".9em" font-size="90">' . $theme['emoji'] . '</text></svg>'
    );
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= h($theme['color'] ?? '#7d1620') ?>">
<title><?= h($full) ?></title>
<link rel="icon" href="<?= h($favicon) ?>">
<link rel="stylesheet" href="assets/style.css?v=19">
</head>
<body class="<?= h($bodyClass) ?>" data-theme="<?= h($themeKey) ?>" data-deco="<?= h($theme['deco']) ?>">
<div class="snow" aria-hidden="true"></div>
<?php
}

function layout_footer(): void
{
    ?>
<footer class="site-footer">
    <p>Viel Freude beim Schenken! &#127873;</p>
</footer>
<script src="assets/app.js?v=17" defer></script>
</body>
</html>
<?php
}

function render_flash(): void
{
    foreach (flash_take() as $flash) {
        $type = in_array($flash['type'], ['ok', 'error', 'info'], true) ? $flash['type'] : 'info';
        echo '<div class="flash flash-' . $type . '">' . h($flash['message']) . '</div>';
    }
}

function tag_pill(array $tag, bool $small = false): string
{
    $color = tag_color($tag);
    $cls = 'pill' . ($small ? ' pill-sm' : '');
    return '<span class="' . $cls . '" style="--pill:' . h($color) . '">' . h($tag['name']) . '</span>';
}

/**
 * Schmale Admin-Leiste über der Seite, nur für angemeldete Admins.
 * So kommt man von der Wunschliste aus direkt in die Verwaltung zurück.
 */
function admin_bar(?array $list = null): void
{
    if (!function_exists('is_admin') || !is_admin()) {
        return;
    }
    $l = isset($list['id']) ? '?l=' . urlencode((string)$list['id']) . '&amp;' : '?';
    ?>
    <div class="admin-bar">
        <div class="wrap admin-bar-inner">
            <span class="admin-bar-label">&#128274;&nbsp;Admin</span>
            <nav class="admin-bar-nav">
                <a href="admin.php<?= $l ?>view=gifts">Wünsche</a>
                <a href="admin.php<?= $l ?>view=tags">Tags</a>
                <a href="admin.php<?= $l ?>view=reservations">Reservationen</a>
                <a href="admin.php<?= $l ?>view=preise">Preise</a>
                <a href="admin.php<?= $l ?>view=lists">Wunschlisten</a>
                <a href="admin.php?action=logout">Abmelden</a>
            </nav>
        </div>
    </div>
    <?php
}

/** Link auf die Übersicht einer Liste. */
function list_url(?array $list, array $params = []): string
{
    if ($list) {
        $params = array_merge(['l' => $list['slug'] ?? ''], $params);
    }
    $qs = http_build_query($params);
    return 'index.php' . ($qs ? '?' . $qs : '');
}
