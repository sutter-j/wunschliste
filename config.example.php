<?php
/**
 * Einstellungen der Wunschliste.
 *
 * Diese Datei nach config.php kopieren und dort anpassen:
 *
 *     cp config.example.php config.php
 *
 * config.php wird nicht ins Git aufgenommen, die Zugangsdaten bleiben also lokal.
 */

return [
    // Vorgabe für die allererste Wunschliste. Name und Untertitel jeder Liste
    // werden danach im Admin unter «Wunschlisten» gepflegt.
    'site_title'    => 'Weihnachtswunschliste',
    'site_subtitle' => 'Familie Muster',

    // Passwort für den Admin-Bereich (admin.php). UNBEDINGT ÄNDERN.
    // Darf auch ein Hash aus password_hash() sein (beginnt mit $2y$ oder $argon).
    'admin_password' => 'bitte-aendern',

    // Nur die Vorgabe für die allererste Wunschliste. Danach wird das
    // Gäste-Passwort im Admin bei der jeweiligen Liste gesetzt.
    // Leer = die Liste ist ohne Passwort erreichbar.
    'guest_password' => '',

    // --- Vorgaben für NEUE Wunschlisten -------------------------------
    // Diese drei Schalter werden pro Wunschliste im Admin gesetzt; hier steht
    // nur, womit eine neu angelegte Liste startet.

    // Sollen Besucher sehen, WER einen Wunsch reserviert hat?
    // false = es steht nur «reserviert» (sinnvoll, falls die Kinder mitschauen).
    'show_reserver_names' => false,

    // Sollen reservierte Wünsche für Besucher sichtbar bleiben?
    // true  = sichtbar, aber als «reserviert» markiert.
    // false = reservierte Wünsche verschwinden für Besucher aus der Liste.
    //         Im Admin bleiben sie natürlich sichtbar.
    'show_reserved_gifts' => true,

    // Bestätigungsmail nach dem Schenken verschicken?
    'send_mail' => true,

    // Hinweis für alle, die eine Reservation nicht selbst aufheben können
    // (zum Beispiel Mail verloren). Am besten eintragen, wie man euch erreicht.
    'contact_hint' => 'Oder melde dich einfach kurz bei uns, wir geben den Wunsch wieder frei.',

    // Nach wie vielen Tagen muss man Passwörter neu eingeben?
    // Gilt für Gäste und für den Admin-Bereich.
    'session_days' => 7,

    // --- Mailversand (SMTP) -------------------------------------------
    // Zugangsdaten des Postfachs, über das die Bestätigungen verschickt werden.
    // Beim Hoster meist unter «E-Mail-Konten» zu finden.
    // Alles leer lassen = es wird keine Mail verschickt, der Code zum Aufheben
    // der Reservation wird dann direkt auf der Seite angezeigt.
    'smtp_host'      => '',             // z.B. smtp.example.ch
    'smtp_port'      => 587,            // 587 mit STARTTLS, 465 mit SSL
    'smtp_security'  => 'tls',          // 'tls' (Port 587), 'ssl' (465) oder 'none'
    'smtp_user'      => '',             // meist die volle Mailadresse
    'smtp_password'  => '',
    'mail_from'      => '',             // Absenderadresse, z.B. wunschliste@example.ch
    'mail_from_name' => 'Wunschliste',  // Absendername

    // Adresse der Seite für die Links in der Mail.
    // Leer = wird aus dem Aufruf abgeleitet; bei Problemen hier fest eintragen,
    // z.B. 'https://wunschliste.example.ch'
    'site_url' => '',

    // Währung für Preisangaben
    'currency' => 'CHF',
];
