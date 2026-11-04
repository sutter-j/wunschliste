<?php
declare(strict_types=1);

require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/guard.php';
require __DIR__ . '/lib/reservations.php';

wl_session_start();

$id      = (string)($_GET['id'] ?? $_POST['id'] ?? '');
$gift    = $id !== '' ? gift_find($id) : null;
$list    = $gift ? list_find((string)($gift['list'] ?? '')) : null;

/* Der Wunsch ist nur zugänglich, wenn seine Liste offen ist. */
if ($gift) {
    guest_gate($list);
}
$partial = (($_GET['partial'] ?? $_POST['partial'] ?? '') === '1');   // Inhalt für den Modal-Dialog
$selfUrl = 'gift.php?id=' . urlencode($id);

if (!$gift) {
    http_response_code(404);
    $notFound = '<div class="empty card-soft"><p class="empty-emoji">&#129300;</p>'
        . '<h2>Diesen Wunsch gibt es nicht (mehr)</h2><p><a href="index.php">Zurück zur Liste</a></p></div>';
    if ($partial) {
        header('Content-Type: text/html; charset=utf-8');
        echo $notFound;
        exit;
    }
    layout_header('Nicht gefunden', 'page-detail', list_default());
    echo '<main class="wrap">' . $notFound . '</main>';
    layout_footer();
    exit;
}

/**
 * Nach einer Aktion: im Modal direkt neu zeichnen, sonst wie gewohnt umleiten.
 */
function finish(string $id, bool $partial, string $anchor = ''): void
{
    if ($partial) {
        $gift = gift_find($id);
        if ($gift) {
            render_gift_detail($gift, true);
        }
        exit;
    }
    redirect('gift.php?id=' . urlencode($id) . $anchor);
}

/* ---------- Kurze Statusabfrage für das Modal ----------
   Das Modal fragt alle 10 Sekunden nach, ob der Wunsch noch frei ist. */
if (($_GET['check'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'available' => gift_is_available($gift),
        'taken'     => gift_reserved_count($gift),
        'quantity'  => gift_quantity($gift),
    ]);
    exit;
}

/* ---------- Aktionen ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!csrf_check()) {
        flash_set('error', 'Die Sitzung ist abgelaufen. Bitte nochmals versuchen.');
        finish($id, $partial);
    }

    if ($action === 'reserve') {
        $name    = trim((string)($_POST['name'] ?? ''));
        $email   = trim((string)($_POST['email'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $confirm = !empty($_POST['confirm']);

        if ($name === '') {
            flash_set('error', 'Bitte den eigenen Namen eintragen.');
            finish($id, $partial, '#schenken');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Bitte eine gültige E-Mail-Adresse eintragen, dorthin geht die Bestätigung.');
            finish($id, $partial, '#schenken');
        }
        if (!$confirm) {
            flash_set('error', 'Bitte die Reservation bestätigen.');
            finish($id, $partial, '#schenken');
        }

        $res = reservation_create($id, $name, $email, $message);

        if ($res === null) {
            flash_set('error', 'Dieser Wunsch wurde leider gerade von jemand anderem reserviert.');
            finish($id, $partial);
        }

        $mailOk = send_confirmation_mail($list ?? [], gift_find($id) ?? $gift, $res, $mailFehler);
        $_SESSION['last_reservation'] = [
            'gift'       => $id,
            'code'       => $res['code'],
            'name'       => $res['name'],
            'email'      => $res['email'],
            'mail_ok'    => $mailOk,
            'mail_error' => $mailFehler,
        ];
        flash_set('ok', 'Danke, ' . $res['name'] . '! Der Wunsch ist jetzt für dich reserviert.');
        finish($id, $partial);
    }

    finish($id, $partial);
}

/* ---------- Anzeige ---------- */

/**
 * Der Inhalt eines Wunsches, einmal als eigene Seite, einmal als Modal-Inhalt.
 */
function render_gift_detail(array $gift, bool $partial): void
{
    $id          = (string)$gift['id'];
    $tagsMap     = tags_map((string)($gift['list'] ?? ''));
    $available   = gift_is_available($gift);
    $qty         = gift_quantity($gift);
    $taken       = gift_reserved_count($gift);
    $image       = gift_image_src($gift);
    $shopUrl     = safe_url((string)($gift['url'] ?? ''));
    $liste       = list_find((string)($gift['list'] ?? ''));
    $showNames   = (bool)list_setting($liste, 'show_reserver_names', false);
    $contactHint = trim((string)cfg('contact_hint', ''));

    $lastRes = $_SESSION['last_reservation'] ?? null;
    if ($lastRes && ($lastRes['gift'] ?? '') !== $id) {
        $lastRes = null;
    }
    unset($_SESSION['last_reservation']);
    ?>
    <div class="detail-inner" data-gift="<?= h($id) ?>" data-available="<?= $available ? '1' : '0' ?>">
        <?php render_flash(); ?>

        <?php if ($lastRes): ?>
            <?php if (!empty($lastRes['mail_ok'])): ?>
                <div class="code-box">
                    <p class="mail-ok">&#9993;&nbsp;Bestätigung unterwegs</p>
                    <p class="muted">Wir haben dir eine Mail an <strong><?= h($lastRes['email']) ?></strong>
                    geschickt, mit dem Link zum Shop und einem Link, falls du die Reservation
                    doch wieder aufheben willst.</p>
                </div>
            <?php else: ?>
                <div class="code-box">
                    <p><strong>Dein Storno-Code:</strong></p>
                    <p class="code"><?= h($lastRes['code']) ?></p>
                    <p class="muted">Bitte notieren: Die Bestätigungsmail konnte nicht verschickt werden.
                    Mit diesem Code kannst du die Reservation unter
                    <a href="storno.php">storno.php</a> wieder aufheben.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <article class="detail-card">
            <div class="detail-media">
                <?php if ($image !== ''): ?>
                    <img src="<?= h($image) ?>" alt="<?= h($gift['title'] ?? '') ?>">
                <?php else: ?>
                    <div class="card-media-placeholder big">&#127873;</div>
                <?php endif; ?>
            </div>

            <div class="detail-body">
                <h1><?= h($gift['title'] ?? 'Ohne Titel') ?></h1>

                <?php if (!empty($gift['tags'])): ?>
                    <div class="pill-row">
                        <?php foreach ((array)$gift['tags'] as $tagId):
                            if (isset($tagsMap[$tagId])) echo tag_pill($tagsMap[$tagId]);
                        endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="meta-row">
                    <?php if (format_price($gift['price'] ?? '') !== ''): ?>
                        <span class="price big"><?= h(format_price($gift['price'])) ?></span>
                    <?php endif; ?>
                    <?php if ((int)($gift['priority'] ?? 2) === 3): ?>
                        <span class="meta-tag">&#11088;&nbsp;Grosser Wunsch</span>
                    <?php endif; ?>
                    <?php if ($qty > 1): ?>
                        <span class="meta-tag"><?= $taken ?> von <?= $qty ?> reserviert</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($gift['description'])): ?>
                    <div class="prose"><?= nl2br(h($gift['description'])) ?></div>
                <?php endif; ?>

                <?php if ($shopUrl !== ''): ?>
                    <p><a class="btn btn-ghost" href="<?= h($shopUrl) ?>" target="_blank" rel="noopener noreferrer">
                        &#128722;&nbsp; Im Shop ansehen<?php if (url_host($shopUrl)) echo ' (' . h(url_host($shopUrl)) . ')'; ?>
                    </a></p>
                <?php endif; ?>

                <?php if ($available): ?>
                    <div class="status status-free">&#10004;&nbsp;Noch verfügbar</div>
                <?php else: ?>
                    <div class="status status-taken">
                        &#127873;&nbsp;Schon reserviert
                        <?php if ($showNames && !empty($gift['reservations'])): ?>
                            von <?= h(implode(', ', array_column($gift['reservations'], 'name'))) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <?php if ($available): ?>
        <section class="give-box" id="schenken">
            <h2>&#127877;&nbsp;Dieses Geschenk übernehmen</h2>
            <p class="muted">Damit ist der Wunsch für alle anderen als reserviert markiert.</p>
            <form method="post" action="<?= h('gift.php?id=' . urlencode($id)) ?>" class="stack" data-confirm-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reserve">
                <input type="hidden" name="id" value="<?= h($id) ?>">

                <label for="name">Dein Name *</label>
                <input type="text" id="name" name="name" maxlength="80" required
                       placeholder="z.B. Tante Anna" autocomplete="name">

                <label for="email">Deine E-Mail-Adresse *</label>
                <input type="email" id="email" name="email" maxlength="120" required
                       placeholder="z.B. anna@example.ch" autocomplete="email">
                <p class="field-hint muted small">Kommt die Bestätigung nicht an, bitte auch im
                Spam-Ordner nachschauen.</p>

                <label for="message">Notiz (optional, nur für die Eltern sichtbar)</label>
                <textarea id="message" name="message" rows="2" maxlength="500"
                          placeholder="z.B. bringe ich am 24. mit"></textarea>

                <label class="check" for="confirm">
                    <input type="checkbox" id="confirm" name="confirm" value="1" required>
                    <span>Ja, das schenke ich!</span>
                </label>

                <button class="btn btn-primary btn-big" type="submit">Schenken</button>
            </form>
            <p class="muted small reserve-note">Überlegst du es dir später anders, hebst du die
            Reservation mit dem Link aus der Bestätigungsmail selbst wieder auf.</p>
        </section>
        <?php endif; ?>

        <?php if (!$available): ?>
        <section class="cancel-box">
            <p class="muted"><strong>Du hast das reserviert und willst es doch nicht?</strong>
            In deiner Bestätigungsmail steht ein Link, mit dem du es wieder freigeben kannst.
            Den Storno-Code kannst du auch auf der <a href="storno.php">Storno-Seite</a> eingeben.
            <?= $contactHint !== '' ? h($contactHint) : '' ?></p>
        </section>
        <?php endif; ?>
    </div>
    <?php
}

if ($partial) {
    header('Content-Type: text/html; charset=utf-8');
    render_gift_detail($gift, true);
    exit;
}

layout_header((string)($gift['title'] ?? 'Wunsch'), 'page-detail', $list);
?>
<?php admin_bar($list); ?>
<header class="detail-top">
    <div class="wrap">
        <a class="back-link" href="<?= h(list_url($list)) ?>">&larr; Zurück zur Liste</a>
    </div>
</header>

<main class="wrap detail">
    <?php render_gift_detail($gift, false); ?>
</main>
<?php layout_footer(); ?>
