<?php
/**
 * Zugang zu einer Wunschliste. Jede Liste kann ein eigenes Gäste-Passwort haben;
 * wer es eingibt, landet automatisch auf der passenden Liste.
 */
declare(strict_types=1);

/**
 * Ist die Liste für Besucher gerade offen?
 * Eine Liste kann abgeschaltet sein oder nur in einem Zeitraum gelten.
 *
 * @return array{open:bool, reason:string, text:string}
 */
function list_availability(?array $list): array
{
    if ($list === null) {
        return ['open' => false, 'reason' => 'fehlt', 'text' => 'Diese Wunschliste gibt es nicht.'];
    }
    if (isset($list['active']) && !$list['active']) {
        return ['open' => false, 'reason' => 'inaktiv',
                'text' => 'Diese Wunschliste ist zurzeit abgeschaltet.'];
    }
    $heute = date('Y-m-d');
    $von   = trim((string)($list['from'] ?? ''));
    $bis   = trim((string)($list['until'] ?? ''));

    if ($von !== '' && $heute < $von) {
        return ['open' => false, 'reason' => 'noch-nicht',
                'text' => 'Diese Wunschliste ist erst ab dem ' . date('d.m.Y', strtotime($von)) . ' offen.'];
    }
    if ($bis !== '' && $heute > $bis) {
        return ['open' => false, 'reason' => 'vorbei',
                'text' => 'Diese Wunschliste war bis zum ' . date('d.m.Y', strtotime($bis)) . ' offen.'];
    }
    return ['open' => true, 'reason' => '', 'text' => ''];
}

function list_is_open(?array $list): bool
{
    return list_availability($list)['open'];
}

function is_admin(): bool
{
    wl_session_start();
    return !empty($_SESSION['admin_ok']);
}

function list_needs_password(?array $list): bool
{
    return $list !== null && trim((string)($list['password'] ?? '')) !== '';
}

function list_is_unlocked(?array $list): bool
{
    if ($list === null) {
        return false;
    }
    if (!list_needs_password($list)) {
        return true;
    }
    wl_session_start();
    if (!empty($_SESSION['admin_ok'])) {
        return true;
    }
    $seit = $_SESSION['lists_ok'][$list['id']] ?? null;
    if ($seit === null) {
        return false;
    }
    if (is_int($seit) && time() - $seit > session_ttl()) {
        unset($_SESSION['lists_ok'][$list['id']]);   // abgelaufen
        return false;
    }
    return true;
}

function list_unlock(array $list): void
{
    wl_session_start();
    $_SESSION['lists_ok'][$list['id']] = time();
}

/** Sucht die Liste, zu der ein eingegebenes Passwort gehört. */
function list_by_password(string $password): ?array
{
    if (trim($password) === '') {
        return null;
    }
    foreach (lists_all() as $list) {
        if (list_needs_password($list) && password_matches($password, (string)$list['password'])) {
            return $list;
        }
    }
    return null;
}

/**
 * Lässt durch, wenn die Liste offen ist, sonst Passwortseite und Ende.
 */
function guest_gate(?array $list): void
{
    wl_session_start();

    /* Abgeschaltet oder ausserhalb des Zeitraums: für Besucher dicht. */
    if ($list !== null && !list_is_open($list) && !is_admin()) {
        list_closed_page($list);
    }

    if ($list !== null && list_is_unlocked($list)) {
        return;
    }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guest_password'])) {
        if (!csrf_check()) {
            $error = 'Die Sitzung ist abgelaufen. Bitte nochmals versuchen.';
        } else {
            $match = list_by_password((string)$_POST['guest_password']);
            if ($match && !list_is_open($match) && !is_admin()) {
                list_closed_page($match);
            }
            if ($match) {
                session_regenerate_id(true);
                list_unlock($match);
                redirect(list_url($match));
            }
            usleep(400000);
            $error = 'Dieses Passwort passt zu keiner Wunschliste.';
        }
    }

    $lists  = lists_all();
    $open   = array_values(array_filter($lists, fn($l) =>
        list_is_open($l) && (!list_needs_password($l) || list_is_unlocked($l))));
    $theme  = theme_of($list);

    layout_header('Passwort', 'page-login', $list);
    ?>
    <main class="login-wrap">
        <div class="login-card">
            <div class="login-emoji"><?= $theme['emoji'] ?></div>
            <?php if ($list): ?>
                <h1><?= h($list['name']) ?></h1>
                <?php if (!empty($list['subtitle'])): ?><p class="muted"><?= h($list['subtitle']) ?></p><?php endif; ?>
            <?php elseif (!$lists): ?>
                <h1>Noch keine Wunschliste</h1>
                <p class="muted">Im <a href="admin.php">Admin-Bereich</a> kann die erste Liste angelegt werden.</p>
            <?php else: ?>
                <h1>Wunschliste öffnen</h1>
                <p class="muted">Gib das Passwort ein, das du bekommen hast, du landest automatisch
                auf der richtigen Liste.</p>
            <?php endif; ?>

            <?php render_flash(); ?>
            <?php if ($error !== ''): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>

            <?php if ($lists): ?>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <label for="guest_password">Passwort</label>
                <input type="password" id="guest_password" name="guest_password"
                       autocomplete="current-password" autofocus required>
                <button class="btn btn-primary" type="submit">Wunschliste öffnen</button>
            </form>

                <?php if (count($lists) > 1): ?>
                    <p class="muted small gate-hint">Du möchtest eine andere Wunschliste anschauen?
                    Gib hier einfach deren Passwort ein, du wirst automatisch auf die richtige Liste geleitet.</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($open): ?>
                <p class="muted small open-lists">Diese Listen stehen dir offen:<br>
                <?php foreach ($open as $o): ?>
                    <a href="<?= h(list_url($o)) ?>"><?= h($o['name']) ?></a><br>
                <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
    </main>
    <?php
    layout_footer();
    exit;
}

/**
 * Seite für eine Liste, die gerade nicht zugänglich ist.
 */
function list_closed_page(array $list): void
{
    $info  = list_availability($list);
    $theme = theme_of($list);

    http_response_code(403);
    layout_header('Geschlossen', 'page-login', $list);
    ?>
    <main class="login-wrap">
        <div class="login-card">
            <div class="login-emoji">&#128274;</div>
            <h1><?= h($list['name']) ?></h1>
            <p class="muted"><?= h($info['text']) ?></p>
            <?php if ($info['reason'] === 'noch-nicht' || $info['reason'] === 'vorbei'): ?>
                <p class="muted small">
                    <?php
                    $von = trim((string)($list['from'] ?? ''));
                    $bis = trim((string)($list['until'] ?? ''));
                    if ($von !== '' && $bis !== '') {
                        echo 'Offen vom ' . h(date('d.m.Y', strtotime($von))) . ' bis ' . h(date('d.m.Y', strtotime($bis))) . '.';
                    }
                    ?>
                </p>
            <?php endif; ?>
            <p class="muted small">Wenn das nicht stimmt, melde dich bei uns.</p>
            <p class="muted small"><a href="index.php">Zu den anderen Wunschlisten</a></p>
        </div>
    </main>
    <?php
    layout_footer();
    exit;
}
