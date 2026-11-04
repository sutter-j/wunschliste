<?php
/**
 * Reservation aufheben, über den Link aus der Bestätigungsmail
 * oder von Hand mit dem Storno-Code. Dafür braucht es kein Listenpasswort,
 * der Code selbst ist der Ausweis.
 */
declare(strict_types=1);

require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/guard.php';
require __DIR__ . '/lib/reservations.php';

wl_session_start();

$code    = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
$fehler  = '';
$erledigt = false;
$treffer = $code !== '' ? reservation_find_by_code($code) : null;

/** Wurde dieser Code schon einmal storniert? */
function storno_bereits_erfolgt(string $code): ?array
{
    foreach (store_read('gifts') as $gift) {
        foreach (($gift['history'] ?? []) as $eintrag) {
            if (hash_equals((string)($eintrag['code'] ?? ''), $code)) {
                return ['gift' => $gift, 'entry' => $eintrag];
            }
        }
    }
    return null;
}

$schonStorniert = ($code !== '' && !$treffer) ? storno_bereits_erfolgt($code) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $fehler = 'Die Sitzung ist abgelaufen. Bitte nochmals versuchen.';
    } elseif (!$treffer) {
        $_SESSION['storno_versuche'] = (int)($_SESSION['storno_versuche'] ?? 0) + 1;
        usleep(400000);
        $fehler = $schonStorniert
            ? 'Diese Reservation wurde bereits aufgehoben.'
            : 'Dieser Storno-Code passt zu keiner Reservation.';
    } elseif (!empty($_POST['bestaetigt'])) {
        $erledigt = reservation_cancel((string)$treffer['gift']['id'],
                                       (string)$treffer['reservation']['id'], 'giver');
        if ($erledigt) {
            $treffer = null;
        } else {
            $fehler = 'Das hat nicht geklappt. Bitte nochmals versuchen.';
        }
    }
}

$gift = $treffer['gift'] ?? ($schonStorniert['gift'] ?? null);
$list = $gift ? list_find((string)($gift['list'] ?? '')) : list_default();

layout_header('Reservation aufheben', 'page-login', $list);
?>
<main class="login-wrap">
    <div class="login-card storno-card">
        <?php if ($erledigt): ?>
            <div class="login-emoji">&#128077;</div>
            <h1>Erledigt</h1>
            <p class="muted">Die Reservation ist aufgehoben, der Wunsch steht wieder für alle offen.
            Danke, dass du Bescheid gegeben hast!</p>
            <?php if ($list): ?>
                <p><a class="btn btn-primary" href="<?= h(list_url($list)) ?>">Zur Wunschliste</a></p>
            <?php endif; ?>

        <?php elseif ($treffer): ?>
            <?php $g = $treffer['gift']; $r = $treffer['reservation']; ?>
            <div class="login-emoji">&#127873;</div>
            <h1>Reservation aufheben?</h1>
            <p class="muted">Du hast das hier reserviert:</p>

            <div class="storno-gift">
                <?php if (gift_image_src($g) !== ''): ?>
                    <img src="<?= h(gift_image_src($g)) ?>" alt="">
                <?php endif; ?>
                <strong><?= h($g['title'] ?? '') ?></strong>
                <?php if (format_price($g['price'] ?? '') !== ''): ?>
                    <div class="price"><?= h(format_price($g['price'])) ?></div>
                <?php endif; ?>
                <div class="muted small">reserviert von <?= h($r['name'] ?? '') ?>
                am <?= h(format_date((string)($r['created_at'] ?? ''))) ?></div>
            </div>

            <?php if ($fehler !== ''): ?><div class="flash flash-error"><?= h($fehler) ?></div><?php endif; ?>

            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="code" value="<?= h($code) ?>">
                <input type="hidden" name="bestaetigt" value="1">
                <button class="btn btn-primary" type="submit">Ja, Reservation aufheben</button>
            </form>
            <?php if ($list): ?>
                <p class="muted small"><a href="<?= h(list_url($list)) ?>">Nein, doch nicht: zurück zur Liste</a></p>
            <?php endif; ?>

        <?php else: ?>
            <div class="login-emoji">&#128260;</div>
            <h1>Reservation aufheben</h1>
            <p class="muted">Gib den Storno-Code aus deiner Bestätigungsmail ein.</p>
            <?php if ($fehler !== ''): ?>
                <div class="flash flash-error"><?= h($fehler) ?></div>
            <?php elseif ($schonStorniert): ?>
                <div class="flash flash-info">Diese Reservation wurde bereits aufgehoben.</div>
            <?php elseif ($code !== ''): ?>
                <div class="flash flash-error">Dieser Storno-Code passt zu keiner Reservation.</div>
            <?php endif; ?>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <label for="code">Storno-Code</label>
                <input type="text" id="code" name="code" maxlength="12" required autofocus
                       style="text-transform:uppercase" placeholder="z.B. K7P2QMVX"
                       value="<?= h($code) ?>">
                <button class="btn btn-primary" type="submit">Weiter</button>
            </form>
            <?php if (trim((string)cfg('contact_hint', '')) !== ''): ?>
                <p class="muted small"><?= h(cfg('contact_hint')) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php layout_footer(); ?>
