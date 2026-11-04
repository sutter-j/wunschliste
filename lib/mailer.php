<?php
/**
 * Kleiner SMTP-Versand ohne Fremdbibliothek.
 * Zugangsdaten stehen global in der config.php, ob eine Liste Mails
 * verschickt, steht bei der Liste selbst.
 */

declare(strict_types=1);

const SMTP_TIMEOUT = 15;

function smtp_settings(): array
{
    return [
        'host'      => trim((string)cfg('smtp_host', '')),
        'port'      => (int)cfg('smtp_port', 587),
        'user'      => (string)cfg('smtp_user', ''),
        'pass'      => (string)cfg('smtp_password', ''),
        'security'  => strtolower((string)cfg('smtp_security', 'tls')),   // tls | ssl | none
        'from'      => trim((string)cfg('mail_from', '')),
        'from_name' => trim((string)cfg('mail_from_name', '')),
    ];
}

function mail_is_configured(): bool
{
    $s = smtp_settings();
    return $s['host'] !== '' && $s['from'] !== '';
}

/** Eine SMTP-Antwort lesen (mehrzeilige Antworten zusammenfassen). */
function smtp_read($socket, ?string &$text = null): int
{
    $text = '';
    while (($line = fgets($socket, 515)) !== false) {
        $text .= $line;
        // "250-" heisst: es kommt noch mehr, "250 " ist die letzte Zeile
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return (int)substr(trim($text), 0, 3);
}

function smtp_write($socket, string $befehl): void
{
    fwrite($socket, $befehl . "\r\n");
}

/**
 * Erwartet einen bestimmten Antwortcode, sonst Ausnahme.
 */
function smtp_expect($socket, array $erlaubt, string $schritt): string
{
    $code = smtp_read($socket, $antwort);
    if (!in_array($code, $erlaubt, true)) {
        throw new RuntimeException($schritt . ': ' . trim($antwort ?? '') ?: $schritt . ' fehlgeschlagen');
    }
    return (string)$antwort;
}

/** Betreff und Namen für Kopfzeilen kodieren (Umlaute). */
function mail_encode_header(string $text): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $text)) {
        return $text;
    }
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function mail_address(string $adresse, string $name = ''): string
{
    return $name !== '' ? mail_encode_header($name) . ' <' . $adresse . '>' : $adresse;
}

/**
 * Verschickt eine Mail über SMTP.
 *
 * @param string|null $fehler  Fehlertext, falls es nicht geklappt hat
 */
function send_mail(string $an, string $betreff, string $html, string $text, ?string &$fehler = null): bool
{
    $fehler = null;
    $s = smtp_settings();

    if (!mail_is_configured()) {
        $fehler = 'In der config.php fehlen die SMTP-Angaben (smtp_host / mail_from).';
        return false;
    }
    if (!filter_var($an, FILTER_VALIDATE_EMAIL)) {
        $fehler = 'Die Empfängeradresse ist ungültig.';
        return false;
    }

    $ziel = ($s['security'] === 'ssl' ? 'ssl://' : '') . $s['host'] . ':' . $s['port'];
    $kontext = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $s['host']]]);
    $socket = @stream_socket_client($ziel, $errno, $errstr, SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT, $kontext);
    if (!$socket) {
        $fehler = 'Keine Verbindung zu ' . $s['host'] . ':' . $s['port'] . ' (' . $errstr . ')';
        return false;
    }
    stream_set_timeout($socket, SMTP_TIMEOUT);

    try {
        smtp_expect($socket, [220], 'Begrüssung');

        $eigenerName = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $eigenerName = preg_replace('/[^a-zA-Z0-9.\-]/', '', $eigenerName) ?: 'localhost';

        smtp_write($socket, 'EHLO ' . $eigenerName);
        $faehigkeiten = smtp_expect($socket, [250], 'EHLO');

        if ($s['security'] === 'tls') {
            smtp_write($socket, 'STARTTLS');
            smtp_expect($socket, [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Die TLS-Verschlüsselung konnte nicht aufgebaut werden.');
            }
            smtp_write($socket, 'EHLO ' . $eigenerName);
            $faehigkeiten = smtp_expect($socket, [250], 'EHLO nach STARTTLS');
        }

        if ($s['user'] !== '') {
            if (stripos($faehigkeiten, 'AUTH') === false) {
                throw new RuntimeException('Der Server bietet keine Anmeldung an.');
            }
            if (stripos($faehigkeiten, 'LOGIN') !== false) {
                smtp_write($socket, 'AUTH LOGIN');
                smtp_expect($socket, [334], 'AUTH LOGIN');
                smtp_write($socket, base64_encode($s['user']));
                smtp_expect($socket, [334], 'Benutzername');
                smtp_write($socket, base64_encode($s['pass']));
                smtp_expect($socket, [235], 'Passwort');
            } else {
                smtp_write($socket, 'AUTH PLAIN ' . base64_encode("\0" . $s['user'] . "\0" . $s['pass']));
                smtp_expect($socket, [235], 'AUTH PLAIN');
            }
        }

        smtp_write($socket, 'MAIL FROM:<' . $s['from'] . '>');
        smtp_expect($socket, [250], 'MAIL FROM');
        smtp_write($socket, 'RCPT TO:<' . $an . '>');
        smtp_expect($socket, [250, 251], 'RCPT TO');
        smtp_write($socket, 'DATA');
        smtp_expect($socket, [354], 'DATA');

        $grenze = 'wl-' . bin2hex(random_bytes(8));
        $kopf = [
            'Date: ' . date('r'),
            'From: ' . mail_address($s['from'], $s['from_name']),
            'To: ' . $an,
            'Subject: ' . mail_encode_header($betreff),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $eigenerName . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $grenze . '"',
            'Auto-Submitted: auto-generated',
        ];
        $koerper = [
            '--' . $grenze,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($text), 76, "\r\n"),
            '--' . $grenze,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($html), 76, "\r\n"),
            '--' . $grenze . '--',
            '',
        ];
        fwrite($socket, implode("\r\n", $kopf) . "\r\n\r\n" . implode("\r\n", $koerper) . "\r\n.\r\n");
        smtp_expect($socket, [250], 'Nachricht senden');

        smtp_write($socket, 'QUIT');
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        $fehler = $e->getMessage();
        @fclose($socket);
        return false;
    }
}

/** Adresse der Seite, für Links in der Mail. */
function site_base_url(): string
{
    $aus_config = trim((string)cfg('site_url', ''));
    if ($aus_config !== '') {
        return rtrim($aus_config, '/');
    }
    $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $pfad   = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $schema . '://' . $host . $pfad;
}
