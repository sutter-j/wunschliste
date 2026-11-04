<?php
/**
 * Produktdaten von einer Shop-Seite lesen (Open Graph, JSON-LD, Titel).
 *
 * Manche Shops blocken automatische Zugriffe.
 * Dann liefert diese Datei immerhin einen aus der Adresse abgeleiteten Titel,
 * und der Rest wird von Hand ergänzt.
 */

declare(strict_types=1);

const META_TIMEOUT   = 12;
const META_MAX_BYTES = 2097152;   // 2 MB
const META_UA        = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
                     . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/**
 * Prüft, dass die Adresse nach aussen zeigt und nicht ins eigene Netz
 * (Schutz gegen SSRF).
 */
function meta_url_is_safe(string $url): bool
{
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
        return false;
    }
    $host = $parts['host'] ?? '';
    if ($host === '') {
        return false;
    }
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) {
            if (!empty($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
    }
    if (!$ips) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

/**
 * Holt eine Seite (oder ein Bild). Folgt Weiterleitungen selbst, damit
 * jede Station geprüft werden kann.
 *
 * @return array{ok:bool, body:string, url:string, type:string, status:int, error:string}
 */
function meta_fetch(string $url, int $maxBytes = META_MAX_BYTES): array
{
    $result = ['ok' => false, 'body' => '', 'url' => $url, 'type' => '', 'status' => 0, 'error' => ''];

    for ($hop = 0; $hop < 4; $hop++) {
        if (!meta_url_is_safe($url)) {
            $result['error'] = 'Diese Adresse ist nicht erlaubt.';
            return $result;
        }
        if (!function_exists('curl_init')) {
            $result['error'] = 'Auf diesem Server fehlt die cURL-Erweiterung.';
            return $result;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => META_TIMEOUT,
            CURLOPT_USERAGENT      => META_UA,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,image/*;q=0.8,*/*;q=0.5',
                'Accept-Language: de-CH,de;q=0.9,en;q=0.6',
            ],
            CURLOPT_BUFFERSIZE     => 16384,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use ($maxBytes) {
                return $dlNow > $maxBytes ? 1 : 0;   // zu gross: abbrechen
            },
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err    = curl_error($ch);
        $next   = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if (in_array($status, [301, 302, 303, 307, 308], true) && $next !== '') {
            $url = $next;
            continue;
        }

        $result['url']    = $url;
        $result['status'] = $status;
        $result['type']   = $type;

        if ($body === false) {
            $result['error'] = $err !== '' ? $err : 'Die Seite konnte nicht geladen werden.';
            return $result;
        }
        if ($status >= 400) {
            $result['error'] = 'Der Shop hat den Zugriff abgelehnt (HTTP ' . $status . ').';
            return $result;
        }

        $result['ok']   = true;
        $result['body'] = substr((string)$body, 0, $maxBytes);
        return $result;
    }

    $result['error'] = 'Zu viele Weiterleitungen.';
    return $result;
}

/**
 * Titel aus der Adresse ableiten, wenn ein Shop keine Angaben herausgibt:
 * …/lego-city-60502-flughafen-mit-flugzeug/p/253720  ->  "Lego City 60502 Flughafen mit Flugzeug"
 *
 * Gesucht wird das Stück der Adresse, das am meisten nach einem Produktnamen
 * aussieht, nicht einfach das letzte.
 */
function meta_title_from_url(string $url): string
{
    $path  = (string)parse_url($url, PHP_URL_PATH);
    $teile = array_values(array_filter(explode('/', $path)));
    $ignorieren = ['p', 'dp', 'gp', 'product', 'produkt', 'id', 'item', 'artikel', 'de', 'ch', 'de-ch', 'fr-ch', 'en'];

    $bester = '';
    $punkte = 0;
    foreach ($teile as $teil) {
        $teil = preg_replace('/\.(html?|php|aspx)$/i', '', $teil) ?? $teil;
        if (in_array(strtolower($teil), $ignorieren, true) || preg_match('/^\d+$/', $teil)) {
            continue;
        }
        $teil = preg_replace('/-\d{6,}$/', '', $teil) ?? $teil;          // angehängte Artikelnummer
        $woerter = preg_split('/[-_+]/', $teil) ?: [];
        $wert = 0;
        foreach ($woerter as $w) {
            if (preg_match('/[a-zA-ZäöüÄÖÜ]{3,}/u', $w)) {
                $wert++;
            }
        }
        if ($wert >= $punkte && $wert >= 2) {    // bei Gleichstand gewinnt das spätere, genauere Stück
            $punkte = $wert;
            $bester = $teil;
        }
    }
    if ($bester === '') {
        return '';
    }

    $klein = ['mit', 'und', 'für', 'fuer', 'am', 'im', 'in', 'an', 'auf', 'aus', 'bei', 'von', 'vom',
              'zu', 'zum', 'zur', 'der', 'die', 'das', 'den', 'dem', 'ein', 'eine', 'einen', 'als',
              'the', 'of', 'and', 'with', 'for'];
    $marken = ['vtech' => 'VTech', 'rc' => 'RC', 'led' => 'LED', '3d' => '3D', 'tv' => 'TV',
               'usb' => 'USB', 'xl' => 'XL', 'xxl' => 'XXL', 'diy' => 'DIY', 'bmx' => 'BMX',
               'fuer' => 'für', 'ueber' => 'über'];

    $woerter = preg_split('/[-_+]/', $bester) ?: [];
    $fertig  = [];
    foreach ($woerter as $i => $w) {
        $w = trim($w);
        if ($w === '') {
            continue;
        }
        $kleinW = mb_strtolower($w, 'UTF-8');
        if (isset($marken[$kleinW])) {
            $fertig[] = $marken[$kleinW];
        } elseif (preg_match('/^[0-9]+$/', $w)) {
            $fertig[] = $w;                                   // Artikelnummern bleiben
        } elseif (preg_match('/^[0-9]+[a-zäöü]{1,4}$/iu', $w)) {
            $fertig[] = $kleinW;                              // 5er, 125tlg, 200g
        } elseif ($i > 0 && in_array($kleinW, $klein, true)) {
            $fertig[] = $kleinW;                              // Füllwörter klein
        } else {
            $fertig[] = mb_convert_case($kleinW, MB_CASE_TITLE, 'UTF-8');
        }
    }
    return trim(implode(' ', $fertig));
}

function meta_clean(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

/** Sucht in einem JSON-LD-Baum nach dem Product-Knoten. */
function meta_find_product($node): ?array
{
    if (!is_array($node)) {
        return null;
    }
    $type = $node['@type'] ?? '';
    $types = is_array($type) ? $type : [$type];
    foreach ($types as $t) {
        if (is_string($t) && strcasecmp($t, 'Product') === 0) {
            return $node;
        }
    }
    foreach ($node as $child) {
        if (is_array($child)) {
            $found = meta_find_product($child);
            if ($found) {
                return $found;
            }
        }
    }
    return null;
}

/**
 * Liest Titel, Beschreibung, Bild und Preis aus einer Produktseite.
 *
 * @return array{title:string,description:string,image:string,price:string,url:string,
 *               ok:bool,notice:string,source:string}
 */
function fetch_product_meta(string $url): array
{
    $out = ['title' => '', 'description' => '', 'image' => '', 'price' => '',
            'url' => $url, 'ok' => false, 'notice' => '', 'source' => ''];

    $url = safe_url($url);
    if ($url === '') {
        $out['notice'] = 'Das sieht nicht nach einer gültigen Adresse aus.';
        return $out;
    }
    $out['url'] = $url;

    $res = meta_fetch($url);
    if (!$res['ok']) {
        $out['title']  = meta_title_from_url($url);
        $out['notice'] = $res['error'] . ' Titel aus der Adresse übernommen, Bild und Beschreibung bitte von Hand ergänzen.';
        return $out;
    }
    $out['url'] = $res['url'];

    $html = $res['body'];
    if (!preg_match('~charset=["\']?utf-?8~i', $res['type'] . ' ' . substr($html, 0, 2000))) {
        $enc = mb_detect_encoding($html, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($enc && $enc !== 'UTF-8') {
            $html = mb_convert_encoding($html, 'UTF-8', $enc);
        }
    }

    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xp = new DOMXPath($doc);

    $meta = function (array $names) use ($xp): string {
        foreach ($names as $name) {
            $attr = str_starts_with($name, 'og:') || str_starts_with($name, 'product:') ? 'property' : 'name';
            $nodes = $xp->query("//meta[@$attr='" . $name . "'][@content]");
            if ($nodes && $nodes->length) {
                $value = meta_clean($nodes->item(0)->getAttribute('content'));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    };

    $strong = false;                       // echte Produktangaben gefunden?
    $out['title']       = $meta(['og:title', 'twitter:title']);
    $strong = $strong || $out['title'] !== '';
    $out['description'] = $meta(['og:description', 'twitter:description', 'description']);
    $out['image']       = $meta(['og:image', 'og:image:secure_url', 'twitter:image']);
    $out['price']       = $meta(['product:price:amount', 'og:price:amount']);

    /* JSON-LD füllt die Lücken */
    foreach ($xp->query("//script[@type='application/ld+json']") ?: [] as $script) {
        $data = json_decode(trim($script->textContent), true);
        $product = meta_find_product($data);
        if (!$product) {
            continue;
        }
        if (!empty($product['name'])) {
            $strong = true;
            if ($out['title'] === '') {
                $out['title'] = meta_clean((string)$product['name']);
            }
        }
        if ($out['description'] === '' && !empty($product['description'])) {
            $out['description'] = meta_clean((string)$product['description']);
        }
        if ($out['image'] === '' && !empty($product['image'])) {
            $image = $product['image'];
            if (is_array($image)) {
                $image = $image[0] ?? ($image['url'] ?? '');
            }
            if (is_array($image)) {
                $image = $image['url'] ?? '';
            }
            $out['image'] = (string)$image;
        }
        if ($out['price'] === '' && !empty($product['offers'])) {
            $offers = $product['offers'];
            $offer  = isset($offers[0]) && is_array($offers[0]) ? $offers[0] : $offers;
            if (is_array($offer) && isset($offer['price'])) {
                $out['price'] = (string)$offer['price'];
            }
        }
        break;
    }

    if ($out['title'] === '') {
        $titleNode = $xp->query('//title');
        if ($titleNode && $titleNode->length) {
            $out['title'] = meta_clean($titleNode->item(0)->textContent);
        }
    }

    /* Bot-Sperre erkennen: solche Shops liefern eine Hinweisseite statt des Produkts */
    $blocked = '/(pardon our interruption|just a moment|attention required|access denied'
             . '|checking your browser|are you a human|robot check|bot detection|request blocked)/i';
    if (!$strong && (preg_match($blocked, $out['title']) || preg_match($blocked, substr($html, 0, 4000)))) {
        return [
            'title' => meta_title_from_url($url), 'description' => '', 'image' => '', 'price' => '',
            'url' => $out['url'], 'ok' => false, 'source' => url_host($out['url']),
            'notice' => 'Dieser Shop blockt automatische Abfragen. Titel aus der Adresse übernommen, '
                      . 'Bild und Beschreibung bitte von Hand ergänzen.',
        ];
    }

    /* Shop-Namen am Titelende abschneiden: "Produkt | Shop" */
    $out['title'] = preg_replace('/\s*[|–—-]\s*[^|–—-]{2,40}$/u', '', $out['title']) ?: $out['title'];
    $out['title'] = mb_substr($out['title'], 0, 150);
    $out['description'] = mb_substr($out['description'], 0, 600);

    /* Relative Bildadressen absolut machen */
    if ($out['image'] !== '' && !preg_match('~^https?://~i', $out['image'])) {
        $base = parse_url($out['url']);
        if (str_starts_with($out['image'], '//')) {
            $out['image'] = ($base['scheme'] ?? 'https') . ':' . $out['image'];
        } elseif (str_starts_with($out['image'], '/')) {
            $out['image'] = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '') . $out['image'];
        }
    }

    $out['price']  = preg_replace('/[^\d.,]/', '', $out['price']) ?? '';
    $out['ok']     = $strong || $out['title'] !== '';
    $out['source'] = url_host($out['url']);

    if (!$out['ok']) {
        $out['title']  = meta_title_from_url($url);
        $out['notice'] = 'Die Seite liess sich lesen, enthielt aber keine Produktangaben. Bitte von Hand ergänzen.';
    }
    return $out;
}

/**
 * Lädt ein Bild von einer fremden Adresse in den uploads-Ordner.
 * Gibt den relativen Pfad zurück oder null.
 */
function download_remote_image(string $url): ?string
{
    $url = safe_url($url);
    if ($url === '') {
        return null;
    }
    $res = meta_fetch($url, 8 * 1024 * 1024);
    if (!$res['ok'] || $res['body'] === '') {
        return null;
    }
    if (!is_dir(WL_UPLOADS)) {
        @mkdir(WL_UPLOADS, 0775, true);
    }
    $tmp = WL_UPLOADS . '/.dl_' . bin2hex(random_bytes(5));
    if (file_put_contents($tmp, $res['body']) === false) {
        return null;
    }
    $info = @getimagesize($tmp);
    $ext  = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ][$info[2] ?? 0] ?? null;
    if (!$info || $ext === null) {
        @unlink($tmp);
        return null;
    }
    $name = 'img_' . date('Ymd') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!rename($tmp, WL_UPLOADS . '/' . $name)) {
        @unlink($tmp);
        return null;
    }
    @chmod(WL_UPLOADS . '/' . $name, 0644);
    return 'uploads/' . $name;
}
