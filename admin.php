<?php
declare(strict_types=1);

require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/metafetch.php';
require __DIR__ . '/lib/guard.php';
require __DIR__ . '/lib/reservations.php';

wl_session_start();

/* ---------------- Login ---------------- */

function admin_is_logged_in(): bool
{
    if (empty($_SESSION['admin_ok'])) {
        return false;
    }
    $seit = (int)($_SESSION['admin_since'] ?? 0);
    if ($seit > 0 && time() - $seit > session_ttl()) {
        unset($_SESSION['admin_ok'], $_SESSION['admin_since']);   // abgelaufen
        return false;
    }
    return true;
}

function admin_login_page(string $error = ''): void
{
    layout_header('Admin', 'page-login', list_default());
    ?>
    <main class="login-wrap">
        <div class="login-card">
            <div class="login-emoji">&#128274;</div>
            <h1>Admin-Bereich</h1>
            <p class="muted">Wünsche und Reservationen verwalten</p>
            <?php if ($error !== ''): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
                <button class="btn btn-primary" type="submit">Anmelden</button>
            </form>
            <p class="muted small"><a href="index.php">&larr; Zurück zur Wunschliste</a></p>
        </div>
    </main>
    <?php
    layout_footer();
    exit;
}

if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect('index.php');
}

if (!admin_is_logged_in()) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        $_SESSION['login_tries'] = (int)($_SESSION['login_tries'] ?? 0) + 1;
        if ($_SESSION['login_tries'] > 8) {
            $error = 'Zu viele Versuche. Bitte ein paar Minuten warten.';
            sleep(3);
        } elseif (!csrf_check()) {
            $error = 'Die Sitzung ist abgelaufen. Bitte nochmals versuchen.';
        } elseif (password_matches((string)($_POST['password'] ?? ''), (string)cfg('admin_password'))) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            $_SESSION['admin_since'] = time();
            $_SESSION['login_tries'] = 0;
            redirect('admin.php');
        } else {
            usleep(600000);
            $error = 'Falsches Passwort.';
        }
    }
    admin_login_page($error);
}

/* ---------------- Aktuelle Wunschliste ---------------- */

$allLists  = lists_all();
$currentId = (string)($_GET['l'] ?? $_POST['l'] ?? $_SESSION['admin_list'] ?? '');
$current   = $currentId !== '' ? list_find($currentId) : null;
if (!$current) {
    $current = list_default();
}
if ($current) {
    $_SESSION['admin_list'] = $current['id'];
}
$currentListId = (string)($current['id'] ?? '');

/** Adresse im Admin-Bereich, behält die gewählte Liste bei. */
function admin_url(array $params = [], string $anchor = ''): string
{
    global $currentListId;
    if ($currentListId !== '') {
        $params = array_merge(['l' => $currentListId], $params);
    }
    $qs = http_build_query($params);
    return 'admin.php' . ($qs ? '?' . $qs : '') . $anchor;
}

/* ---------------- Upload ---------------- */

function handle_upload(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Das Bild konnte nicht hochgeladen werden (Fehler ' . $file['error'] . ').');
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('Das Bild ist zu gross (max. 8 MB).');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        throw new RuntimeException('Die Datei ist kein gültiges Bild.');
    }
    $ext = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ][$info[2]] ?? null;
    if ($ext === null) {
        throw new RuntimeException('Nur JPG, PNG, GIF oder WebP sind möglich.');
    }
    if (!is_dir(WL_UPLOADS)) {
        @mkdir(WL_UPLOADS, 0775, true);
    }
    $name = 'img_' . date('Ymd') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $target = WL_UPLOADS . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Das Bild konnte nicht gespeichert werden.');
    }
    @chmod($target, 0644);
    return 'uploads/' . $name;
}

function delete_upload(string $path): void
{
    if ($path !== '' && str_starts_with($path, 'uploads/') && !str_contains($path, '..')) {
        @unlink(WL_ROOT . '/' . $path);
    }
}

/**
 * Prüft, ob die Datendatei über das Internet herunterladbar ist.
 * Die Adresse wird aus dem eigenen Server gebildet, nicht aus Benutzereingaben.
 */
function check_data_protection(): array
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir    = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $url    = $scheme . '://' . $host . $dir . '/data/gifts.json';

    if (!function_exists('curl_init')) {
        return ['state' => 'unbekannt', 'url' => $url, 'info' => 'Auf diesem Server fehlt cURL, die Prüfung ist nicht möglich.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'Wunschliste-Selbsttest',
    ]);
    $body   = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err !== '' && $status === 0) {
        return ['state' => 'unbekannt', 'url' => $url, 'info' => 'Der Server konnte sich nicht selbst aufrufen (' . $err . ').'];
    }
    if ($status === 200 && str_contains($body, '"title"')) {
        return ['state' => 'offen', 'url' => $url,
                'info' => 'Die Datei wurde mit HTTP 200 ausgeliefert, der Schutz greift hier nicht.'];
    }
    return ['state' => 'geschützt', 'url' => $url, 'info' => 'Der Server antwortet mit HTTP ' . $status . '.'];
}

/* ---------------- Aktionen ---------------- */

$view = $_GET['view'] ?? 'gifts';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('error', 'Die Sitzung ist abgelaufen. Bitte nochmals versuchen.');
        redirect('admin.php');
    }
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {

            case 'save_gift': {
                $id       = trim((string)($_POST['id'] ?? ''));
                $title    = trim((string)($_POST['title'] ?? ''));
                if ($title === '') {
                    throw new RuntimeException('Der Titel darf nicht leer sein.');
                }
                $existing = $id !== '' ? gift_find($id) : null;
                $image    = $existing['image'] ?? '';

                // In welche Wunschliste kommt der Wunsch?
                $zielId = (string)($_POST['gift_list'] ?? '');
                if ($zielId === '' || !list_find($zielId)) {
                    $zielId = (string)($existing['list'] ?? $currentListId);
                }
                $verschoben = $existing !== null && ($existing['list'] ?? '') !== $zielId;

                $uploaded = handle_upload($_FILES['image_file'] ?? null);
                if ($uploaded !== null) {
                    if ($image !== '') {
                        delete_upload($image);
                    }
                    $image = $uploaded;
                } elseif (!empty($_POST['remove_image'])) {
                    delete_upload($image);
                    $image = '';
                } else {
                    $imageUrl = trim((string)($_POST['image_url'] ?? ''));
                    if ($imageUrl !== '' && $imageUrl !== $image) {
                        $image = safe_url($imageUrl);
                        // Bild auf den eigenen Server kopieren, damit es nicht vom Shop abhängt
                        if ($image !== '' && !empty($_POST['copy_image'])) {
                            $copied = download_remote_image($image);
                            if ($copied !== null) {
                                $image = $copied;
                            }
                        }
                    } elseif ($imageUrl === '' && !str_starts_with((string)$image, 'uploads/')) {
                        $image = '';
                    }
                }

                $data = [
                    'title'       => mb_substr($title, 0, 150),
                    'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000),
                    'url'         => safe_url((string)($_POST['url'] ?? '')),
                    'image'       => $image,
                    'price'       => mb_substr(trim((string)($_POST['price'] ?? '')), 0, 30),
                    'priority'    => max(1, min(3, (int)($_POST['priority'] ?? 2))),
                    'quantity'    => max(1, min(20, (int)($_POST['quantity'] ?? 1))),
                    // Tags gehören zur Liste, beim Wechsel fallen die alten weg
                    'tags'        => array_values(array_filter((array)($_POST['tags'] ?? []),
                                        fn($t) => isset(tags_map($zielId)[$t]))),
                    'list'        => $zielId,
                ];

                store_mutate('gifts', function (array &$gifts) use ($id, $data) {
                    if ($id !== '') {
                        foreach ($gifts as &$g) {
                            if (($g['id'] ?? '') === $id) {
                                $g = array_merge($g, $data);
                                return;
                            }
                        }
                    }
                    $gifts[] = array_merge([
                        'id'           => new_id('g_'),
                        'created_at'   => date('c'),
                        'reservations' => [],
                    ], $data);
                });

                $ziel = list_find($zielId);
                $wohin = $ziel && $zielId !== $currentListId ? ' in «' . $ziel['name'] . '»' : '';
                flash_set('ok', ($id !== '' ? 'Wunsch gespeichert' : 'Wunsch hinzugefügt') . $wohin . '.'
                    . ($verschoben ? ' Die Tags der alten Liste wurden entfernt, bitte hier neu zuweisen.' : ''));
                $_SESSION['admin_list'] = $zielId;
                redirect('admin.php?l=' . urlencode($zielId));
            }

            case 'import_url': {
                $url = trim((string)($_POST['import_url'] ?? ''));
                if ($url === '') {
                    throw new RuntimeException('Bitte zuerst einen Produkt-Link einfügen.');
                }
                $meta = fetch_product_meta($url);
                $_SESSION['prefill'] = [
                    'title'       => $meta['title'],
                    'description' => $meta['description'],
                    'url'         => $meta['url'],
                    'image_url'   => $meta['image'],
                    'price'       => $meta['price'],
                ];
                flash_set($meta['ok'] ? 'ok' : 'info', $meta['ok']
                    ? 'Daten von ' . ($meta['source'] ?: 'der Seite') . ' übernommen, bitte prüfen und dann speichern.'
                    : $meta['notice']);
                redirect(admin_url([], '#form'));
            }

            case 'delete_gift': {
                $id = (string)($_POST['id'] ?? '');
                $gift = gift_find($id);
                if ($gift && !empty($gift['image'])) {
                    delete_upload((string)$gift['image']);
                }
                store_mutate('gifts', function (array &$gifts) use ($id) {
                    $gifts = array_values(array_filter($gifts, fn($g) => ($g['id'] ?? '') !== $id));
                });
                flash_set('ok', 'Wunsch gelöscht.');
                redirect(admin_url());
            }

            case 'reset_gift': {
                $id  = (string)($_POST['id'] ?? '');
                $res = (string)($_POST['reservation'] ?? '');
                $gift = gift_find($id);
                $wieviele = 0;
                foreach (($gift['reservations'] ?? []) as $r) {
                    if ($res === '' || ($r['id'] ?? '') === $res) {
                        // einzeln aufheben, damit jede Reservation in den Verlauf kommt
                        if (reservation_cancel($id, (string)$r['id'], 'admin')) {
                            $wieviele++;
                        }
                    }
                }
                flash_set('ok', $wieviele === 1
                    ? 'Die Reservation wurde aufgehoben und steht im Verlauf.'
                    : $wieviele . ' Reservationen aufgehoben, sie stehen im Verlauf.');
                redirect(admin_url(['view' => (string)($_POST['back'] ?? 'gifts')]));
            }

            case 'save_tag': {
                $id    = trim((string)($_POST['id'] ?? ''));
                $name  = trim((string)($_POST['name'] ?? ''));
                $color = (string)($_POST['color'] ?? '#1f6f4a');
                $sort  = (int)($_POST['sort'] ?? 0);
                if ($name === '') {
                    throw new RuntimeException('Der Tag braucht einen Namen.');
                }
                if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) {
                    $color = '#1f6f4a';
                }
                $listId = $currentListId;
                store_mutate('tags', function (array &$tags) use ($id, $name, $color, $sort, $listId) {
                    if ($id !== '') {
                        foreach ($tags as &$t) {
                            if (($t['id'] ?? '') === $id) {
                                $t['name']  = $name;
                                $t['color'] = $color;
                                $t['sort']  = $sort;
                                return;
                            }
                        }
                    }
                    $tags[] = ['id' => new_id('t_'), 'name' => $name, 'color' => $color,
                               'sort' => $sort, 'list' => $listId];
                });
                flash_set('ok', 'Tag gespeichert.');
                redirect(admin_url(['view' => 'tags']));
            }

            case 'delete_tag': {
                $id = (string)($_POST['id'] ?? '');
                store_mutate('tags', function (array &$tags) use ($id) {
                    $tags = array_values(array_filter($tags, fn($t) => ($t['id'] ?? '') !== $id));
                });
                store_mutate('gifts', function (array &$gifts) use ($id) {
                    foreach ($gifts as &$g) {
                        $g['tags'] = array_values(array_filter((array)($g['tags'] ?? []), fn($t) => $t !== $id));
                    }
                });
                flash_set('ok', 'Tag gelöscht.');
                redirect(admin_url(['view' => 'tags']));
            }

            case 'save_prices': {
                $preise = (array)($_POST['price'] ?? []);
                $geaendert = 0;
                store_mutate('gifts', function (array &$gifts) use ($preise, &$geaendert) {
                    foreach ($gifts as &$g) {
                        $id = (string)($g['id'] ?? '');
                        if (!array_key_exists($id, $preise)) {
                            continue;
                        }
                        $neu = mb_substr(trim((string)$preise[$id]), 0, 30);
                        if ($neu !== (string)($g['price'] ?? '')) {
                            $g['price'] = $neu;
                            $geaendert++;
                        }
                    }
                });
                flash_set('ok', $geaendert === 0 ? 'Nichts verändert.'
                    : $geaendert . ($geaendert === 1 ? ' Preis' : ' Preise') . ' gespeichert.');
                redirect(admin_url(['view' => 'preise']));
            }

            case 'resend_mail': {
                $giftId = (string)($_POST['id'] ?? '');
                $resId  = (string)($_POST['reservation'] ?? '');
                $gift   = gift_find($giftId);
                $res    = null;
                foreach (($gift['reservations'] ?? []) as $r) {
                    if (($r['id'] ?? '') === $resId) {
                        $res = $r;
                    }
                }
                if (!$gift || !$res) {
                    throw new RuntimeException('Diese Reservation gibt es nicht mehr.');
                }
                $ok = send_confirmation_mail(list_find((string)($gift['list'] ?? '')) ?? [], $gift, $res, $mailFehler);
                flash_set($ok ? 'ok' : 'error', $ok
                    ? 'Bestätigung nochmals an ' . ($res['email'] ?? '') . ' verschickt.'
                    : 'Versand fehlgeschlagen: ' . $mailFehler);
                redirect(admin_url(['view' => 'reservations']));
            }

            case 'test_mail': {
                $an = trim((string)($_POST['test_mail'] ?? ''));
                if (!filter_var($an, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Bitte eine gültige Mailadresse eintragen.');
                }
                $ok = send_mail($an, 'Testmail der Wunschliste',
                    '<p>Das hat geklappt, der Mailversand ist richtig eingerichtet.</p>',
                    'Das hat geklappt, der Mailversand ist richtig eingerichtet.', $mailFehler);
                flash_set($ok ? 'ok' : 'error', $ok
                    ? 'Testmail an ' . $an . ' verschickt.'
                    : 'Versand fehlgeschlagen: ' . $mailFehler);
                redirect(admin_url(['view' => 'lists']));
            }

            case 'check_protection': {
                $check = check_data_protection();
                $type  = ['geschützt' => 'ok', 'offen' => 'error'][$check['state']] ?? 'info';
                flash_set($type, 'Datendateien: ' . $check['state'] . '. ' . $check['info']);
                redirect(admin_url(['view' => 'lists']));
            }

            case 'save_list': {
                $id       = trim((string)($_POST['list_id'] ?? ''));
                $name     = trim((string)($_POST['name'] ?? ''));
                $subtitle = trim((string)($_POST['subtitle'] ?? ''));
                $password = trim((string)($_POST['password'] ?? ''));
                $theme    = (string)($_POST['theme'] ?? 'weihnachten');
                $wish     = trim((string)($_POST['slug'] ?? ''));
                $makeDefault = !empty($_POST['is_default']);
                $active   = !empty($_POST['active']);
                $schalter = [
                    'show_reserved_gifts' => !empty($_POST['show_reserved_gifts']),
                    'show_reserver_names' => !empty($_POST['show_reserver_names']),
                    'send_mail'           => !empty($_POST['send_mail']),
                ];
                $von      = trim((string)($_POST['from'] ?? ''));
                $bis      = trim((string)($_POST['until'] ?? ''));
                foreach ([$von, $bis] as $datum) {
                    if ($datum !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
                        throw new RuntimeException('Das Datum muss im Format JJJJ-MM-TT sein.');
                    }
                }
                if ($von !== '' && $bis !== '' && $von > $bis) {
                    throw new RuntimeException('«Offen ab» liegt nach «Offen bis».');
                }

                if ($name === '') {
                    throw new RuntimeException('Die Wunschliste braucht einen Namen.');
                }
                if (!isset(themes_all()[$theme])) {
                    $theme = 'weihnachten';
                }
                $slug = list_free_slug($wish !== '' ? $wish : $name, $id);
                $newId = $id !== '' ? $id : new_id('l_');

                store_mutate('lists', function (array &$lists) use ($id, $newId, $name, $subtitle, $password, $theme, $slug, $makeDefault, $active, $von, $bis, $schalter) {
                    $data = array_merge([
                        'name' => mb_substr($name, 0, 80), 'subtitle' => mb_substr($subtitle, 0, 120),
                        'slug' => $slug, 'theme' => $theme, 'password' => $password,
                        'active' => $active, 'from' => $von, 'until' => $bis,
                    ], $schalter);
                    $found = false;
                    foreach ($lists as &$l) {
                        if (($l['id'] ?? '') === $id && $id !== '') {
                            $l = array_merge($l, $data);
                            $found = true;
                        }
                    }
                    unset($l);
                    if (!$found) {
                        $lists[] = array_merge($data, [
                            'id' => $newId, 'is_default' => count($lists) === 0, 'created_at' => date('c'),
                        ]);
                    }
                    if ($makeDefault) {
                        foreach ($lists as &$l) {
                            $l['is_default'] = (($l['id'] ?? '') === $newId);
                        }
                        unset($l);
                    }
                });

                $_SESSION['admin_list'] = $newId;
                flash_set('ok', $id !== '' ? 'Wunschliste gespeichert.' : 'Wunschliste «' . $name . '» angelegt.');
                redirect('admin.php?l=' . urlencode($newId) . '&view=lists');
            }

            case 'delete_list': {
                $id = (string)($_POST['list_id'] ?? '');
                if (count(lists_all()) < 2) {
                    throw new RuntimeException('Die letzte Wunschliste kann nicht gelöscht werden.');
                }
                foreach (gifts_all($id) as $g) {
                    if (!empty($g['image'])) {
                        delete_upload((string)$g['image']);
                    }
                }
                store_mutate('gifts', function (array &$gifts) use ($id) {
                    $gifts = array_values(array_filter($gifts, fn($g) => ($g['list'] ?? '') !== $id));
                });
                store_mutate('tags', function (array &$tags) use ($id) {
                    $tags = array_values(array_filter($tags, fn($t) => ($t['list'] ?? '') !== $id));
                });
                store_mutate('lists', function (array &$lists) use ($id) {
                    $lists = array_values(array_filter($lists, fn($l) => ($l['id'] ?? '') !== $id));
                    $hasDefault = false;
                    foreach ($lists as $l) {
                        $hasDefault = $hasDefault || !empty($l['is_default']);
                    }
                    if (!$hasDefault && $lists) {
                        $lists[0]['is_default'] = true;
                    }
                });
                unset($_SESSION['admin_list']);
                flash_set('ok', 'Wunschliste mit allen Wünschen gelöscht.');
                redirect('admin.php?view=lists');
            }

            default:
                redirect(admin_url());
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect(admin_url(['view' => $view]));
    }
}

/* ---------------- CSV-Export ---------------- */

if (($_GET['action'] ?? '') === 'export') {
    $tagsMap = tags_map($currentListId);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'
        . slugify((string)($current['name'] ?? 'wunschliste')) . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel Umlaute versteht
    fputcsv($out, ['Wunsch', 'Tags', 'Preis', 'Anzahl', 'Reserviert von', 'Notiz', 'Reserviert am', 'Link'], ';');
    foreach (gifts_all($currentListId) as $g) {
        $tagNames = implode(', ', array_map(
            fn($t) => $tagsMap[$t]['name'] ?? $t,
            (array)($g['tags'] ?? [])
        ));
        if (empty($g['reservations'])) {
            fputcsv($out, [$g['title'] ?? '', $tagNames, $g['price'] ?? '', gift_quantity($g), '', '', '', $g['url'] ?? ''], ';');
        } else {
            foreach ($g['reservations'] as $r) {
                fputcsv($out, [
                    $g['title'] ?? '', $tagNames, $g['price'] ?? '', gift_quantity($g),
                    $r['name'] ?? '', $r['message'] ?? '', format_date((string)($r['created_at'] ?? '')), $g['url'] ?? '',
                ], ';');
            }
        }
    }
    fclose($out);
    exit;
}

/* ---------------- Anzeige ---------------- */

$gifts   = gifts_all($currentListId);
$allTags = tags_all($currentListId);
$tagsMap = tags_map($currentListId);
$editGift = isset($_GET['edit']) ? gift_find((string)$_GET['edit']) : null;
$editList = isset($_GET['edit_list']) ? list_find((string)$_GET['edit_list']) : null;
$prefill  = $editGift ? [] : (array)($_SESSION['prefill'] ?? []);
unset($_SESSION['prefill']);
function pre(array $prefill, string $key): string { return (string)($prefill[$key] ?? ''); }
$editTag  = null;
if (isset($_GET['edit_tag'])) {
    foreach ($allTags as $t) {
        if ($t['id'] === $_GET['edit_tag']) {
            $editTag = $t;
        }
    }
}

$reservedCount = 0;
foreach ($gifts as $g) {
    $reservedCount += gift_reserved_count($g);
}

layout_header('Admin', 'page-admin', $current);
?>
<header class="admin-header">
    <div class="wrap admin-header-inner">
        <div class="admin-top">
            <h1>&#127873; Admin</h1>
            <?php if (count($allLists) > 1): ?>
            <form method="get" class="list-switch">
                <input type="hidden" name="view" value="<?= h($view) ?>">
                <label class="visually-hidden" for="switch-list">Wunschliste wählen</label>
                <select id="switch-list" name="l" onchange="this.form.submit()">
                    <?php foreach ($allLists as $l): ?>
                        <option value="<?= h($l['id']) ?>" <?= $l['id'] === $currentListId ? 'selected' : '' ?>>
                            <?= h(theme_of($l)['emoji']) ?> <?= h($l['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-mini" type="submit">Wechseln</button></noscript>
            </form>
            <?php endif; ?>
        </div>
        <nav class="admin-nav">
            <a class="<?= $view === 'gifts' ? 'on' : '' ?>" href="<?= h(admin_url(['view' => 'gifts'])) ?>">Wünsche</a>
            <a class="<?= $view === 'tags' ? 'on' : '' ?>" href="<?= h(admin_url(['view' => 'tags'])) ?>">Tags</a>
            <a class="<?= $view === 'reservations' ? 'on' : '' ?>" href="<?= h(admin_url(['view' => 'reservations'])) ?>">Reservationen<?= $reservedCount ? ' (' . $reservedCount . ')' : '' ?></a>
            <a class="<?= $view === 'preise' ? 'on' : '' ?>" href="<?= h(admin_url(['view' => 'preise'])) ?>">Preise</a>
            <a class="<?= $view === 'lists' ? 'on' : '' ?>" href="<?= h(admin_url(['view' => 'lists'])) ?>">Wunschlisten</a>
            <a href="<?= h(list_url($current)) ?>">Ansehen</a>
            <a href="admin.php?action=logout">Abmelden</a>
        </nav>
    </div>
</header>

<main class="wrap admin">
    <?php render_flash(); ?>

    <?php if ($current && !list_is_open($current)): ?>
        <div class="flash flash-info">
            <strong><?= h($current['name']) ?></strong> ist für Besucher gerade nicht erreichbar:
            <?= h(list_availability($current)['text']) ?>
            <a href="<?= h(admin_url(['view' => 'lists', 'edit_list' => $current['id']])) ?>">ändern</a>
        </div>
    <?php endif; ?>

    <?php if ($view === 'preise'): ?>
        <?php
        $ohnePreis = array_values(array_filter($gifts, fn($g) => trim((string)($g['price'] ?? '')) === ''));
        $mitPreis  = array_values(array_filter($gifts, fn($g) => trim((string)($g['price'] ?? '')) !== ''));
        $sortiert  = array_merge($ohnePreis, $mitPreis);
        ?>
        <section class="panel">
            <h2>&#128181;&nbsp;Preise nachtragen
                <span class="panel-sub">in <?= h(theme_of($current)['emoji']) ?> <?= h($current['name'] ?? '') ?></span>
            </h2>
            <p class="muted small">Manche grossen Shops lassen keine automatische Abfrage zu, dort fehlt
            der Preis. Hier stehen alle Wünsche untereinander: Link antippen, Preis ablesen,
            eintragen. Am Schluss einmal speichern. <?= count($ohnePreis) ?> von <?= count($gifts) ?>
            <?= count($gifts) === 1 ? 'Wunsch hat' : 'Wünschen haben' ?> noch keinen Preis.</p>

            <?php if (!$gifts): ?>
                <p class="muted">In dieser Liste gibt es noch keine Wünsche.</p>
            <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_prices">
                <ul class="admin-list price-list">
                    <?php foreach ($sortiert as $g):
                        $shop = safe_url((string)($g['url'] ?? '')); ?>
                        <li>
                            <div class="ag-main">
                                <?php if (gift_image_src($g) !== ''): ?>
                                    <img class="ag-thumb" src="<?= h(gift_image_src($g)) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="ag-thumb ag-thumb-empty">&#127873;</span>
                                <?php endif; ?>
                                <div>
                                    <strong><?= h($g['title'] ?? '') ?></strong>
                                    <?php if ($shop !== ''): ?>
                                        <div class="muted small">
                                            <a href="<?= h($shop) ?>" target="_blank" rel="noopener noreferrer">
                                                <?= h(url_host($shop)) ?> öffnen &#8599;</a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="price-input">
                                <span class="muted small"><?= h(cfg('currency', 'CHF')) ?></span>
                                <input type="text" name="price[<?= h($g['id']) ?>]" inputmode="decimal"
                                       value="<?= h($g['price'] ?? '') ?>" placeholder="z.B. 49.90"
                                       aria-label="Preis für <?= h($g['title'] ?? '') ?>">
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p><button class="btn btn-primary" type="submit">Preise speichern</button></p>
            </form>
            <?php endif; ?>
        </section>

    <?php elseif ($view === 'lists'): ?>
        <section class="panel">
            <h2><?= $editList ? 'Wunschliste bearbeiten' : 'Neue Wunschliste' ?></h2>
            <p class="muted small">Jede Liste hat eigene Wünsche, eigene Tags, ein eigenes Design und ein
            eigenes Gäste-Passwort. Wer das Passwort eingibt, landet automatisch auf der passenden Liste.</p>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_list">
                <input type="hidden" name="list_id" value="<?= h($editList['id'] ?? '') ?>">

                <div class="field">
                    <label for="list_name">Name *</label>
                    <input type="text" id="list_name" name="name" required maxlength="80"
                           value="<?= h($editList['name'] ?? '') ?>" placeholder="z.B. Geburtstag Mia 2027">
                </div>
                <div class="field">
                    <label for="list_subtitle">Untertitel</label>
                    <input type="text" id="list_subtitle" name="subtitle" maxlength="120"
                           value="<?= h($editList['subtitle'] ?? '') ?>" placeholder="z.B. Familie Muster">
                </div>

                <div class="field">
                    <label for="list_password">Gäste-Passwort</label>
                    <input type="text" id="list_password" name="password" maxlength="60"
                           value="<?= h($editList['password'] ?? '') ?>" placeholder="leer = ohne Passwort offen">
                    <p class="muted small">Dieses Passwort gibst du der Verwandtschaft weiter.</p>
                </div>
                <div class="field">
                    <label for="list_slug">Adresse (optional)</label>
                    <input type="text" id="list_slug" name="slug" maxlength="60"
                           value="<?= h($editList['slug'] ?? '') ?>" placeholder="wird aus dem Namen gebildet">
                    <p class="muted small">index.php?l=<strong><?= h($editList['slug'] ?? 'name-der-liste') ?></strong></p>
                </div>

                <div class="field field-full">
                    <label>Design</label>
                    <div class="theme-picker">
                        <?php $sel = $editList['theme'] ?? 'weihnachten';
                        foreach (themes_all() as $key => $t): ?>
                            <label class="theme-card">
                                <input type="radio" name="theme" value="<?= h($key) ?>" <?= $sel === $key ? 'checked' : '' ?>>
                                <span class="theme-body">
                                    <span class="theme-swatch">
                                        <?php foreach ($t['swatch'] as $c): ?>
                                            <i style="background:<?= h($c) ?>"></i>
                                        <?php endforeach; ?>
                                    </span>
                                    <strong><?= $t['emoji'] ?> <?= h($t['name']) ?></strong>
                                    <span class="muted small"><?= h($t['hint']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="field">
                    <label for="list_from">Offen ab (optional)</label>
                    <input type="date" id="list_from" name="from" value="<?= h($editList['from'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="list_until">Offen bis (optional)</label>
                    <input type="date" id="list_until" name="until" value="<?= h($editList['until'] ?? '') ?>">
                    <p class="muted small">Leer = keine Begrenzung. Ausserhalb des Zeitraums ist die Liste
                    auch mit Passwort zu. Für dich als Admin bleibt sie sichtbar.</p>
                </div>

                <div class="field field-full">
                    <label class="check check-inline">
                        <input type="checkbox" name="active" value="1" <?= ($editList === null || !isset($editList['active']) || $editList['active']) ? 'checked' : '' ?>>
                        <span class="small"><strong>Aktiv</strong>: Besucher dürfen die Liste öffnen</span>
                    </label>
                    <label class="check check-inline">
                        <input type="checkbox" name="show_reserved_gifts" value="1" <?= list_setting($editList, 'show_reserved_gifts', true) ? 'checked' : '' ?>>
                        <span class="small">Reservierte Wünsche bleiben für Besucher sichtbar (markiert als «reserviert»)</span>
                    </label>
                    <label class="check check-inline">
                        <input type="checkbox" name="show_reserver_names" value="1" <?= list_setting($editList, 'show_reserver_names', false) ? 'checked' : '' ?>>
                        <span class="small">Besucher sehen auch, <em>wer</em> reserviert hat</span>
                    </label>
                    <label class="check check-inline">
                        <input type="checkbox" name="send_mail" value="1" <?= list_setting($editList, 'send_mail', true) ? 'checked' : '' ?>>
                        <span class="small">Bestätigungsmail nach dem Schenken verschicken</span>
                    </label>
                    <label class="check check-inline">
                        <input type="checkbox" name="is_default" value="1" <?= !empty($editList['is_default']) ? 'checked' : '' ?>>
                        <span class="small">Diese Liste zeigen, wenn jemand die Seite ohne Link aufruft</span>
                    </label>
                </div>

                <div class="field field-actions">
                    <button class="btn btn-primary" type="submit"><?= $editList ? 'Änderungen speichern' : 'Wunschliste anlegen' ?></button>
                    <?php if ($editList): ?><a class="btn btn-ghost" href="<?= h(admin_url(['view' => 'lists'])) ?>">Abbrechen</a><?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>&#9993;&nbsp;Mailversand</h2>
            <?php $smtp = smtp_settings(); ?>
            <?php if (!mail_is_configured()): ?>
                <p class="muted small">In der <code>config.php</code> fehlen noch die SMTP-Angaben
                (<code>smtp_host</code> und <code>mail_from</code>). Solange sie fehlen, wird beim
                Schenken keine Bestätigung verschickt. Die schenkende Person bekommt stattdessen
                den Storno-Code direkt angezeigt.</p>
            <?php else: ?>
                <p class="muted small">Verschickt über <strong><?= h($smtp['host']) ?>:<?= h((string)$smtp['port']) ?></strong>
                (<?= h($smtp['security']) ?>) als <strong><?= h($smtp['from']) ?></strong>.
                Ob eine Liste Mails verschickt, steht bei der jeweiligen Liste.</p>
                <form method="post" class="search-row">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test_mail">
                    <input type="email" name="test_mail" placeholder="deine@adresse.ch für eine Testmail" required>
                    <button class="btn btn-ghost" type="submit">Testmail senden</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>&#128274;&nbsp;Schutz der Datendateien</h2>
            <p class="muted small">Die Wünsche liegen als Dateien im Ordner <code>data/</code>. Eine
            <code>.htaccess</code> sperrt den Zugriff. Hier lässt sich prüfen, ob der Hoster das auch umsetzt.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="check_protection">
                <button class="btn btn-ghost btn-mini" type="submit">Jetzt prüfen</button>
            </form>
        </section>

        <section class="panel">
            <h2>Alle Wunschlisten (<?= count($allLists) ?>)</h2>
            <ul class="admin-list">
                <?php foreach ($allLists as $l):
                    $anzahl = count(gifts_all($l['id']));
                    $t = theme_of($l); ?>
                    <li>
                        <div class="ag-main">
                            <span class="ag-thumb ag-thumb-empty" style="font-size:1.5rem"><?= $t['emoji'] ?></span>
                            <div>
                                <strong><?= h($l['name']) ?></strong>
                                <?php $zustand = list_availability($l); ?>
                                <?php if ($zustand['open']): ?>
                                    <span class="pill pill-sm" style="--pill:var(--green-600)">offen</span>
                                <?php else: ?>
                                    <span class="pill pill-sm" style="--pill:var(--ink-soft)"><?= h([
                                        'inaktiv'    => 'inaktiv',
                                        'noch-nicht' => 'noch nicht offen',
                                        'vorbei'     => 'abgelaufen',
                                    ][$zustand['reason']] ?? 'zu') ?></span>
                                <?php endif; ?>
                                <?php if (!empty($l['is_default'])): ?><span class="pill pill-sm">Standard</span><?php endif; ?>
                                <?php if ($l['id'] === $currentListId): ?><span class="pill pill-sm" style="--pill:var(--ginger-500)">in Bearbeitung</span><?php endif; ?>
                                <div class="muted small">
                                    <?= h($t['name']) ?> &middot; <?= $anzahl ?> <?= $anzahl === 1 ? 'Wunsch' : 'Wünsche' ?> &middot;
                                    <?= trim((string)($l['password'] ?? '')) !== '' ? 'Passwort: ' . h($l['password']) : 'ohne Passwort' ?>
                                </div>
                                <div class="muted small">index.php?l=<?= h($l['slug']) ?>
                                <?php
                                $von = trim((string)($l['from'] ?? ''));
                                $bis = trim((string)($l['until'] ?? ''));
                                if ($von !== '' || $bis !== ''):
                                    echo ' &middot; ';
                                    echo $von !== '' ? 'ab ' . h(date('d.m.Y', strtotime($von))) : '';
                                    echo ($von !== '' && $bis !== '') ? ' ' : '';
                                    echo $bis !== '' ? 'bis ' . h(date('d.m.Y', strtotime($bis))) : '';
                                endif; ?>
                                </div>
                            </div>
                        </div>
                        <span class="row-actions">
                            <a class="btn btn-mini" href="<?= h(list_url($l)) ?>">Ansehen</a>
                            <a class="btn btn-mini" href="admin.php?l=<?= h($l['id']) ?>&amp;view=gifts">Wünsche</a>
                            <a class="btn btn-mini" href="admin.php?l=<?= h($l['id']) ?>&amp;view=lists&amp;edit_list=<?= h($l['id']) ?>">Bearbeiten</a>
                            <?php if (count($allLists) > 1): ?>
                            <form method="post" onsubmit="return confirm('«<?= h($l['name']) ?>» mit allen <?= $anzahl ?> Wünschen und Tags löschen? Das lässt sich nicht rückgängig machen.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_list">
                                <input type="hidden" name="list_id" value="<?= h($l['id']) ?>">
                                <button class="btn btn-mini btn-danger" type="submit">Löschen</button>
                            </form>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

    <?php elseif ($view === 'tags'): ?>
        <section class="panel">
            <h2><?= $editTag ? 'Tag bearbeiten' : 'Neuer Tag' ?></h2>
            <p class="muted small">Tipp: pro Kind ein Tag, dazu z.B. ein Tag «Gemeinsamer Wunsch».</p>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_tag">
                <input type="hidden" name="id" value="<?= h($editTag['id'] ?? '') ?>">
                <div class="field">
                    <label for="tag_name">Name *</label>
                    <input type="text" id="tag_name" name="name" value="<?= h($editTag['name'] ?? '') ?>" required maxlength="40">
                </div>
                <div class="field field-sm">
                    <label for="tag_color">Farbe</label>
                    <input type="color" id="tag_color" name="color" value="<?= h($editTag['color'] ?? '#1f6f4a') ?>">
                </div>
                <div class="field field-sm">
                    <label for="tag_sort">Reihenfolge</label>
                    <input type="number" id="tag_sort" name="sort" value="<?= h((string)($editTag['sort'] ?? 0)) ?>">
                </div>
                <div class="field field-actions">
                    <button class="btn btn-primary" type="submit"><?= $editTag ? 'Speichern' : 'Tag anlegen' ?></button>
                    <?php if ($editTag): ?><a class="btn btn-ghost" href="<?= h(admin_url(['view' => 'tags'])) ?>">Abbrechen</a><?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Tags (<?= count($allTags) ?>)
                <span class="panel-sub">in <?= h(theme_of($current)['emoji']) ?> <?= h($current['name'] ?? '') ?></span>
            </h2>
            <?php if (!$allTags): ?>
                <p class="muted">Noch keine Tags.</p>
            <?php else: ?>
                <ul class="admin-list">
                    <?php foreach ($allTags as $tag):
                        $used = count(array_filter($gifts, fn($g) => in_array($tag['id'], (array)($g['tags'] ?? []), true))); ?>
                        <li>
                            <span><?= tag_pill($tag) ?> <span class="muted small"><?= $used ?> Wünsche</span></span>
                            <span class="row-actions">
                                <a class="btn btn-mini" href="<?= h(admin_url(['view' => 'tags', 'edit_tag' => $tag['id']])) ?>">Bearbeiten</a>
                                <form method="post" onsubmit="return confirm('Tag «<?= h($tag['name']) ?>» wirklich löschen?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_tag">
                                    <input type="hidden" name="id" value="<?= h($tag['id']) ?>">
                                    <button class="btn btn-mini btn-danger" type="submit">Löschen</button>
                                </form>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

    <?php elseif ($view === 'reservations'): ?>
        <section class="panel">
            <h2>Wer schenkt was?
                <span class="panel-sub">in <?= h(theme_of($current)['emoji']) ?> <?= h($current['name'] ?? '') ?></span>
            </h2>
            <p><a class="btn btn-ghost btn-mini" href="<?= h(admin_url(['action' => 'export'])) ?>">&#11015; Als CSV exportieren</a></p>
            <?php
            $rows = [];
            foreach ($gifts as $g) {
                foreach (($g['reservations'] ?? []) as $r) {
                    $rows[] = [$g, $r];
                }
            }
            usort($rows, fn($a, $b) => strcmp((string)($b[1]['created_at'] ?? ''), (string)($a[1]['created_at'] ?? '')));
            ?>
            <?php if (!$rows): ?>
                <p class="muted">Noch nichts reserviert.</p>
            <?php else: ?>
                <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Wunsch</th><th>Schenkt</th><th>E-Mail</th><th>Notiz</th><th>Wann</th><th>Code</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as [$g, $r]): ?>
                        <tr>
                            <td><a href="gift.php?id=<?= h($g['id']) ?>"><?= h($g['title'] ?? '') ?></a></td>
                            <td><strong><?= h($r['name'] ?? '') ?></strong></td>
                            <td class="muted small">
                                <?php if (!empty($r['email'])): ?>
                                    <a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a>
                                <?php else: ?>&mdash;<?php endif; ?>
                            </td>
                            <td class="muted"><?= h($r['message'] ?? '') ?></td>
                            <td class="muted small"><?= h(format_date((string)($r['created_at'] ?? ''))) ?></td>
                            <td class="muted small"><code><?= h($r['code'] ?? '') ?></code></td>
                            <td>
                                <span class="row-actions">
                                    <?php if (!empty($r['email'])): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="resend_mail">
                                        <input type="hidden" name="id" value="<?= h($g['id']) ?>">
                                        <input type="hidden" name="reservation" value="<?= h($r['id'] ?? '') ?>">
                                        <button class="btn btn-mini" type="submit">Mail&nbsp;erneut</button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Reservation von <?= h($r['name'] ?? '') ?> aufheben?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reset_gift">
                                        <input type="hidden" name="id" value="<?= h($g['id']) ?>">
                                        <input type="hidden" name="reservation" value="<?= h($r['id'] ?? '') ?>">
                                        <input type="hidden" name="back" value="reservations">
                                        <button class="btn btn-mini btn-danger" type="submit">Freigeben</button>
                                    </form>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

        <?php $verlauf = reservations_history($currentListId); ?>
        <section class="panel">
            <h2>&#128338;&nbsp;Verlauf
                <span class="panel-sub">aufgehobene Reservationen</span>
            </h2>
            <?php if (!$verlauf): ?>
                <p class="muted">Bisher wurde nichts storniert.</p>
            <?php else: ?>
                <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Wunsch</th><th>War reserviert von</th><th>E-Mail</th><th>Reserviert</th><th>Aufgehoben</th><th>Durch</th></tr></thead>
                    <tbody>
                    <?php foreach ($verlauf as $z): $g = $z['gift']; $e = $z['entry']; ?>
                        <tr>
                            <td><a href="gift.php?id=<?= h($g['id']) ?>"><?= h($g['title'] ?? '') ?></a></td>
                            <td><?= h($e['name'] ?? '') ?></td>
                            <td class="muted small"><?= h($e['email'] ?? '') ?></td>
                            <td class="muted small"><?= h(format_date((string)($e['created_at'] ?? ''))) ?></td>
                            <td class="muted small"><?= h(format_date((string)($e['cancelled_at'] ?? ''))) ?></td>
                            <td class="muted small"><?= ($e['cancelled_by'] ?? '') === 'admin' ? 'euch (Admin)' : 'die schenkende Person' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

    <?php else: ?>
        <?php if (!$editGift): ?>
        <section class="panel panel-import">
            <h2>&#128279;&nbsp;Produkt-Link importieren</h2>
            <p class="muted small">Link aus dem Online-Shop einfügen. Die Seite versucht Titel, Bild,
            Beschreibung und Preis selbst herauszulesen und füllt das Formular unten aus.
            Gespeichert wird erst, wenn du unten auf «Wunsch anlegen» klickst.</p>
            <form method="post" class="search-row">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_url">
                <input type="url" name="import_url" placeholder="https://www.shop.ch/produkt/…"
                       aria-label="Produkt-Link" required>
                <button class="btn btn-primary" type="submit">Daten holen</button>
            </form>
        </section>
        <?php endif; ?>

        <?php $formListe = $editGift ? (list_find((string)($editGift['list'] ?? '')) ?? $current) : $current; ?>
        <section class="panel" id="form">
            <h2><?= $editGift ? 'Wunsch bearbeiten' : 'Neuer Wunsch' ?>
                <span class="panel-sub">in <?= h(theme_of($formListe)['emoji']) ?> <?= h($formListe['name'] ?? '') ?></span>
            </h2>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_gift">
                <input type="hidden" name="id" value="<?= h($editGift['id'] ?? '') ?>">

                <?php if (count($allLists) > 1): ?>
                <div class="field field-full">
                    <label for="gift_list">Wunschliste</label>
                    <select id="gift_list" name="gift_list">
                        <?php foreach ($allLists as $l): ?>
                            <option value="<?= h($l['id']) ?>" <?= ($l['id'] === ($formListe['id'] ?? '')) ? 'selected' : '' ?>>
                                <?= h(theme_of($l)['emoji']) ?> <?= h($l['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="muted small">Die Tags unten gehören zu <strong><?= h($formListe['name'] ?? '') ?></strong>.
                    Wählst du eine andere Liste, wird der Wunsch dort gespeichert und du weist die Tags dort zu.</p>
                </div>
                <?php else: ?>
                    <input type="hidden" name="gift_list" value="<?= h($formListe['id'] ?? '') ?>">
                <?php endif; ?>

                <div class="field">
                    <label for="title">Titel *</label>
                    <input type="text" id="title" name="title" value="<?= h($editGift['title'] ?? pre($prefill, 'title')) ?>" required maxlength="150">
                </div>

                <div class="field">
                    <label for="url">Link zum Shop</label>
                    <input type="url" id="url" name="url" value="<?= h($editGift['url'] ?? pre($prefill, 'url')) ?>" placeholder="https://…">
                </div>

                <div class="field field-full">
                    <label for="description">Beschreibung</label>
                    <textarea id="description" name="description" rows="3" maxlength="2000"><?= h($editGift['description'] ?? pre($prefill, 'description')) ?></textarea>
                </div>

                <div class="field">
                    <label for="image_url">Bild-URL</label>
                    <input type="text" id="image_url" name="image_url"
                           value="<?= h(str_starts_with((string)($editGift['image'] ?? ''), 'uploads/') ? '' : ($editGift['image'] ?? pre($prefill, 'image_url'))) ?>"
                           placeholder="https://…">
                    <label class="check check-inline">
                        <input type="checkbox" name="copy_image" value="1" checked>
                        <span class="small">Bild beim Speichern auf den eigenen Server kopieren</span>
                    </label>
                </div>

                <div class="field">
                    <label for="image_file">… oder Bild hochladen</label>
                    <input type="file" id="image_file" name="image_file" accept="image/*">
                    <p class="muted small" data-paste-hint hidden>Oder das Bild im Shop kopieren
                    (Rechtsklick → «Bild kopieren») und hier einfach einfügen: <kbd>⌘</kbd>+<kbd>V</kbd>.</p>
                    <div class="paste-preview" data-paste-preview hidden>
                        <img alt=""><span class="muted small">Eingefügtes Bild wird beim Speichern übernommen.</span>
                    </div>
                </div>

                <?php if (!$editGift && pre($prefill, 'image_url') !== ''): ?>
                    <div class="field field-full current-image">
                        <img src="<?= h(pre($prefill, 'image_url')) ?>" alt="">
                        <span class="muted small">Gefundenes Bild, stimmt es?</span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($editGift['image'])): ?>
                    <div class="field field-full current-image">
                        <img src="<?= h(gift_image_src($editGift)) ?>" alt="">
                        <label class="check"><input type="checkbox" name="remove_image" value="1"> <span>Bild entfernen</span></label>
                    </div>
                <?php endif; ?>

                <div class="field field-sm">
                    <label for="price">Preis (ca.)</label>
                    <input type="text" id="price" name="price" value="<?= h($editGift['price'] ?? pre($prefill, 'price')) ?>" placeholder="z.B. 49.90">
                </div>

                <div class="field field-sm">
                    <label for="priority">Wunschstärke</label>
                    <select id="priority" name="priority">
                        <?php foreach ([3, 2, 1] as $p): ?>
                            <option value="<?= $p ?>" <?= (int)($editGift['priority'] ?? 2) === $p ? 'selected' : '' ?>><?= h(priority_label($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field field-sm">
                    <label for="quantity">Anzahl (mehrfach schenkbar)</label>
                    <input type="number" id="quantity" name="quantity" min="1" max="20" value="<?= h((string)gift_quantity($editGift ?? [])) ?>">
                </div>

                <div class="field field-full">
                    <label>Tags</label>
                    <?php if (!$allTags): ?>
                        <p class="muted small">Noch keine Tags, zuerst unter <a href="<?= h(admin_url(['view' => 'tags'])) ?>">Tags</a> anlegen.</p>
                    <?php else: ?>
                        <div class="tag-picker">
                            <?php foreach ($allTags as $tag):
                                $checked = in_array($tag['id'], (array)($editGift['tags'] ?? []), true); ?>
                                <label class="tag-check" style="--pill:<?= h(tag_color($tag)) ?>">
                                    <input type="checkbox" name="tags[]" value="<?= h($tag['id']) ?>" <?= $checked ? 'checked' : '' ?>>
                                    <span><?= h($tag['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="field field-actions">
                    <button class="btn btn-primary" type="submit"><?= $editGift ? 'Änderungen speichern' : 'Wunsch anlegen' ?></button>
                    <?php if ($editGift): ?><a class="btn btn-ghost" href="<?= h(admin_url()) ?>">Abbrechen</a><?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Alle Wünsche (<?= count($gifts) ?>)
                <span class="panel-sub">in <?= h(theme_of($current)['emoji']) ?> <?= h($current['name'] ?? '') ?></span>
            </h2>
            <?php if (!$gifts): ?>
                <p class="muted">Noch keine Wünsche erfasst.</p>
            <?php else: ?>
                <ul class="admin-list admin-gifts">
                    <?php foreach ($gifts as $g):
                        $free = gift_is_available($g); ?>
                        <li>
                            <div class="ag-main">
                                <?php if (gift_image_src($g) !== ''): ?>
                                    <img class="ag-thumb" src="<?= h(gift_image_src($g)) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="ag-thumb ag-thumb-empty">&#127873;</span>
                                <?php endif; ?>
                                <div>
                                    <strong><?= h($g['title'] ?? '') ?></strong>
                                    <div class="pill-row">
                                        <?php foreach ((array)($g['tags'] ?? []) as $tid):
                                            if (isset($tagsMap[$tid])) echo tag_pill($tagsMap[$tid], true);
                                        endforeach; ?>
                                    </div>
                                    <div class="muted small">
                                        <?= $free ? 'Verfügbar' : 'Reserviert' ?>
                                        <?php if (gift_reserved_count($g)): ?>
                                            von <?= h(implode(', ', array_column($g['reservations'], 'name'))) ?>
                                        <?php endif; ?>
                                        <?php if (gift_quantity($g) > 1): ?>
                                            &middot; <?= gift_reserved_count($g) ?>/<?= gift_quantity($g) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <span class="row-actions">
                                <a class="btn btn-mini" href="<?= h(admin_url(['edit' => $g['id']], '#form')) ?>">Bearbeiten</a>
                                <?php if (!$free || gift_reserved_count($g)): ?>
                                    <form method="post" onsubmit="return confirm('Alle Reservationen für «<?= h($g['title'] ?? '') ?>» aufheben?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reset_gift">
                                        <input type="hidden" name="id" value="<?= h($g['id']) ?>">
                                        <button class="btn btn-mini" type="submit">Zurücksetzen</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('«<?= h($g['title'] ?? '') ?>» wirklich löschen?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_gift">
                                    <input type="hidden" name="id" value="<?= h($g['id']) ?>">
                                    <button class="btn btn-mini btn-danger" type="submit">Löschen</button>
                                </form>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php layout_footer(); ?>
