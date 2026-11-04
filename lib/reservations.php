<?php
/**
 * Reservationen: anlegen, stornieren, Verlauf und die Bestätigungsmail.
 */

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

/**
 * Reserviert einen Wunsch. Gibt die neue Reservation zurück oder null,
 * wenn in der Zwischenzeit jemand anders schneller war.
 */
function reservation_create(string $giftId, string $name, string $email, string $message): ?array
{
    $neu = [
        'id'         => new_id('r_'),
        'name'       => mb_substr(trim($name), 0, 80),
        'email'      => mb_substr(trim($email), 0, 120),
        'message'    => mb_substr(trim($message), 0, 500),
        'code'       => new_code(8),
        'created_at' => date('c'),
    ];

    $ok = store_mutate('gifts', function (array &$gifts) use ($giftId, $neu) {
        foreach ($gifts as &$g) {
            if (($g['id'] ?? '') !== $giftId) {
                continue;
            }
            if (!gift_is_available($g)) {
                return false;              // war gerade schon weg
            }
            $g['reservations'][] = $neu;
            return true;
        }
        return false;
    });

    return $ok ? $neu : null;
}

/** Sucht Wunsch und Reservation zu einem Storno-Code. */
function reservation_find_by_code(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    foreach (store_read('gifts') as $gift) {
        foreach (($gift['reservations'] ?? []) as $res) {
            if (hash_equals((string)($res['code'] ?? ''), $code)) {
                return ['gift' => $gift, 'reservation' => $res];
            }
        }
    }
    return null;
}

/**
 * Hebt eine Reservation auf und legt sie in den Verlauf.
 *
 * @param string $wer 'giver' (selbst, per Link) oder 'admin'
 */
function reservation_cancel(string $giftId, string $resId, string $wer): bool
{
    return (bool)store_mutate('gifts', function (array &$gifts) use ($giftId, $resId, $wer) {
        foreach ($gifts as &$g) {
            if (($g['id'] ?? '') !== $giftId) {
                continue;
            }
            foreach (($g['reservations'] ?? []) as $i => $res) {
                if (($res['id'] ?? '') !== $resId) {
                    continue;
                }
                array_splice($g['reservations'], $i, 1);
                $res['cancelled_at'] = date('c');
                $res['cancelled_by'] = $wer;
                $g['history'][] = $res;
                return true;
            }
        }
        return false;
    });
}

/** Alle Stornos einer Liste, neuste zuerst. */
function reservations_history(string $listId): array
{
    $zeilen = [];
    foreach (gifts_all($listId) as $gift) {
        foreach (($gift['history'] ?? []) as $eintrag) {
            $zeilen[] = ['gift' => $gift, 'entry' => $eintrag];
        }
    }
    usort($zeilen, fn($a, $b) => strcmp((string)($b['entry']['cancelled_at'] ?? ''),
                                        (string)($a['entry']['cancelled_at'] ?? '')));
    return $zeilen;
}

/* ---------------- Bestätigungsmail ---------------- */

function storno_url(string $code): string
{
    return site_base_url() . '/storno.php?code=' . urlencode($code);
}

/**
 * Schickt die Geschenkbestätigung. Gibt false zurück, wenn der Versand
 * ausgeschaltet ist oder nicht geklappt hat ($fehler erklärt warum).
 */
function send_confirmation_mail(array $list, array $gift, array $res, ?string &$fehler = null): bool
{
    $fehler = null;

    if (!list_setting($list, 'send_mail', true)) {
        $fehler = 'Für diese Wunschliste ist der Mailversand ausgeschaltet.';
        return false;
    }
    if (trim((string)($res['email'] ?? '')) === '') {
        $fehler = 'Es wurde keine Mailadresse angegeben.';
        return false;
    }

    $titel   = (string)($gift['title'] ?? 'Wunsch');
    $shop    = safe_url((string)($gift['url'] ?? ''));
    $preis   = format_price($gift['price'] ?? '');
    $bild    = gift_image_src($gift);
    $bildUrl = $bild === '' ? '' : (preg_match('~^https?://~i', $bild) ? $bild : site_base_url() . '/' . ltrim($bild, '/'));
    $storno  = storno_url((string)$res['code']);
    $liste   = (string)($list['name'] ?? 'Wunschliste');
    $listeUrl = site_base_url() . '/index.php?l=' . urlencode((string)($list['slug'] ?? ''));
    $name    = (string)($res['name'] ?? '');
    $notiz   = trim((string)($res['message'] ?? ''));
    $kontakt = trim((string)cfg('contact_hint', ''));

    $betreff = 'Geschenkbestätigung: ' . $titel;

    /* ----- Nur-Text-Fassung ----- */
    $text = "Hallo $name\n\n"
        . "danke, dass du etwas von der Wunschliste «{$liste}» übernimmst!\n"
        . "Dieses Geschenk ist jetzt für dich reserviert:\n\n"
        . "  $titel\n"
        . ($preis !== '' ? "  Richtpreis: $preis\n" : '')
        . ($shop !== '' ? "  Im Shop ansehen: $shop\n" : '')
        . ($notiz !== '' ? "\n  Deine Notiz: $notiz\n" : '')
        . "\nFalls du es dir anders überlegst, kannst du die Reservation hier wieder aufheben:\n"
        . "  $storno\n"
        . "  Storno-Code: {$res['code']}\n"
        . "\nZur Wunschliste: $listeUrl\n"
        . ($kontakt !== '' ? "\n$kontakt\n" : '')
        . "\nDanke und liebe Grüsse!\n";

    /* ----- HTML-Fassung ----- */
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#fff8f1;'
        . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;color:#3a2318;">'
        . '<div style="max-width:560px;margin:0 auto;background:#fffdfa;border:1px solid #ecd9c6;'
        . 'border-radius:16px;overflow:hidden;">'

        . '<div style="background:#9c1c26;color:#fff;padding:20px 24px;">'
        . '<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#e8b64c;">'
        . 'Geschenkbestätigung</div>'
        . '<div style="font-size:22px;font-weight:700;margin-top:4px;">' . $h($liste) . '</div></div>'

        . '<div style="padding:24px;">'
        . '<p style="margin:0 0 16px;">Hallo ' . $h($name) . ',</p>'
        . '<p style="margin:0 0 20px;">danke, dass du etwas von der Wunschliste übernimmst! '
        . 'Dieses Geschenk ist jetzt <strong>für dich reserviert</strong>, niemand sonst kann es noch auswählen.</p>'

        . '<div style="border:1px solid #ecd9c6;border-radius:12px;padding:16px;margin-bottom:20px;">'
        . ($bildUrl !== '' ? '<img src="' . $h($bildUrl) . '" alt="" width="200" '
            . 'style="display:block;max-width:100%;height:auto;margin:0 auto 14px;">' : '')
        . '<div style="font-size:17px;font-weight:700;line-height:1.3;">' . $h($titel) . '</div>'
        . ($preis !== '' ? '<div style="color:#c0202e;font-weight:700;margin-top:6px;">' . $h($preis) . '</div>' : '')
        . (trim((string)($gift['description'] ?? '')) !== ''
            ? '<div style="color:#806452;font-size:14px;margin-top:10px;">'
              . nl2br($h($gift['description'])) . '</div>' : '')
        . ($shop !== '' ? '<div style="margin-top:16px;"><a href="' . $h($shop) . '" '
            . 'style="display:inline-block;background:#c0202e;color:#fff;text-decoration:none;'
            . 'padding:12px 20px;border-radius:999px;font-weight:700;">Im Shop ansehen &amp; kaufen</a></div>'
            . '<div style="font-size:12px;color:#806452;margin-top:8px;word-break:break-all;">'
            . $h($shop) . '</div>' : '')
        . '</div>'

        . ($notiz !== '' ? '<p style="margin:0 0 20px;color:#806452;font-size:14px;">'
            . '<strong>Deine Notiz:</strong> ' . $h($notiz) . '</p>' : '')

        . '<div style="border-top:1px solid #ecd9c6;padding-top:16px;font-size:14px;color:#806452;">'
        . '<p style="margin:0 0 10px;">Doch nicht? Dann gib das Geschenk bitte wieder frei, '
        . 'damit es jemand anders übernehmen kann:</p>'
        . '<p style="margin:0 0 10px;"><a href="' . $h($storno) . '" '
        . 'style="color:#9c1c26;font-weight:700;">Reservation aufheben</a></p>'
        . '<p style="margin:0;">Storno-Code: <strong style="letter-spacing:.1em;">'
        . $h($res['code']) . '</strong></p>'
        . ($kontakt !== '' ? '<p style="margin:12px 0 0;">' . $h($kontakt) . '</p>' : '')
        . '</div>'

        . '<p style="margin:20px 0 0;font-size:14px;">'
        . '<a href="' . $h($listeUrl) . '" style="color:#9c1c26;">Zurück zur Wunschliste</a></p>'
        . '</div>'

        . '<div style="background:#f2dcc3;color:#806452;font-size:12px;padding:14px 24px;text-align:center;">'
        . 'Diese Mail wurde automatisch verschickt, weil du ein Geschenk reserviert hast.'
        . '</div>'
        . '</div></body></html>';

    return send_mail((string)$res['email'], $betreff, $html, $text, $fehler);
}
