<?php
/**
 * Datenhaltung + Hilfsfunktionen.
 * Storage sind zwei JSON-Textdateien in /data. Keine Datenbank nötig.
 */

declare(strict_types=1);

define('WL_ROOT', dirname(__DIR__));
define('WL_DATA', WL_ROOT . '/data');
define('WL_UPLOADS', WL_ROOT . '/uploads');

if (!is_file(WL_ROOT . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('<h1>config.php fehlt</h1><p>Bitte einmal <code>config.example.php</code> nach '
       . '<code>config.php</code> kopieren und dort Admin-Passwort und Einstellungen '
       . 'eintragen.</p>');
}

$GLOBALS['WL_CONFIG'] = require WL_ROOT . '/config.php';

function cfg(string $key, $default = null)
{
    return $GLOBALS['WL_CONFIG'][$key] ?? $default;
}

/* ---------- Session ---------- */

function session_ttl(): int
{
    return max(1, (int)cfg('session_days', 7)) * 86400;
}

function wl_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $ttl = session_ttl();

    // Eigener Ordner für die Sitzungen: sonst räumt der Server sie je nach
    // Einstellung schon nach 20 Minuten weg und man müsste täglich neu anmelden.
    $dir = WL_DATA . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        @ini_set('session.gc_maxlifetime', (string)$ttl);
        @ini_set('session.gc_probability', '1');
        @ini_set('session.gc_divisor', '100');
        @session_save_path($dir);
    }

    session_set_cookie_params([
        'lifetime' => $ttl,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('wunschliste');
    session_start();

    // Nach Ablauf wird die Sitzung geleert, Gäste und Admin müssen neu anmelden.
    $now = time();
    if (empty($_SESSION['started'])) {
        $_SESSION['started'] = $now;
    } elseif ($now - (int)$_SESSION['started'] > $ttl) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['started'] = $now;
    }
}

/* ---------- Ausgabe ---------- */

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function url_host(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return $host ? preg_replace('/^www\./', '', $host) : '';
}

/* ---------- IDs / Codes ---------- */

function new_id(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(6));
}

function new_code(int $laenge = 8): string
{
    // Gut ablesbarer Code ohne verwechselbare Zeichen
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $laenge; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    wl_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): bool
{
    wl_session_start();
    $sent = $_POST['_csrf'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/* ---------- Datei-Storage ---------- */

function store_path(string $name): string
{
    return WL_DATA . '/' . $name . '.json';
}

function store_read(string $name): array
{
    $file = store_path($name);
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Schreibt atomar (temp-Datei + rename) und legt eine Sicherungskopie an.
 */
function store_write(string $name, array $data): bool
{
    if (!is_dir(WL_DATA)) {
        @mkdir(WL_DATA, 0775, true);
    }
    $file = store_path($name);
    if (is_file($file)) {
        @copy($file, $file . '.bak');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $file);
}

/**
 * Lesen + Schreiben unter einer Sperre, damit parallele Reservationen
 * sich nicht gegenseitig überschreiben.
 */
function store_mutate(string $name, callable $fn)
{
    if (!is_dir(WL_DATA)) {
        @mkdir(WL_DATA, 0775, true);
    }
    $lockFile = WL_DATA . '/' . $name . '.lock';
    $lock = fopen($lockFile, 'c');
    if ($lock === false) {
        throw new RuntimeException('Datenverzeichnis ist nicht beschreibbar.');
    }
    flock($lock, LOCK_EX);
    try {
        $data = store_read($name);
        $result = $fn($data);
        store_write($name, $data);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/* ---------- Wunschlisten ---------- */

function slugify(string $text): string
{
    $text = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtr($text, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
    ]));
    $text = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $text) ?? '');
    return trim($text, '-') ?: 'liste';
}

/**
 * Einstellung einer Wunschliste. Fehlt sie, gilt der Wert aus config.php
 * (der dort nur noch als Vorgabe für neue Listen steht).
 */
function list_setting(?array $list, string $key, $default = null)
{
    if ($list !== null && array_key_exists($key, $list) && $list[$key] !== null && $list[$key] !== '') {
        return $list[$key];
    }
    return cfg($key, $default);
}

/** Die Einstellungen, die jede Liste für sich hat. */
function list_setting_defaults(): array
{
    return [
        'show_reserver_names' => false,   // Besucher sehen nur "reserviert", nicht von wem
        'show_reserved_gifts' => true,    // reservierte Wünsche bleiben sichtbar
        'send_mail'           => true,    // Bestätigungsmail nach dem Schenken
    ];
}

function lists_all(): array
{
    migrate_to_lists();
    migrate_list_settings();
    $lists = store_read('lists');
    usort($lists, function ($a, $b) {
        return [!empty($b['is_default']), (string)($a['name'] ?? '')]
            <=> [!empty($a['is_default']), (string)($b['name'] ?? '')];
    });
    return $lists;
}

function list_find(string $id): ?array
{
    foreach (lists_all() as $list) {
        if (($list['id'] ?? '') === $id) {
            return $list;
        }
    }
    return null;
}

function list_by_slug(string $slug): ?array
{
    foreach (lists_all() as $list) {
        if (($list['slug'] ?? '') === $slug) {
            return $list;
        }
    }
    return null;
}

/** Die Liste, die ohne weitere Angabe gezeigt wird. */
function list_default(): ?array
{
    $lists = lists_all();
    foreach ($lists as $list) {
        if (!empty($list['is_default'])) {
            return $list;
        }
    }
    return $lists[0] ?? null;
}

/** Freien Slug finden (listen-name-2, -3 …). */
function list_free_slug(string $wish, string $exceptId = ''): string
{
    $base = slugify($wish);
    $slug = $base;
    $n = 2;
    while (true) {
        $taken = false;
        foreach (store_read('lists') as $list) {
            if (($list['slug'] ?? '') === $slug && ($list['id'] ?? '') !== $exceptId) {
                $taken = true;
                break;
            }
        }
        if (!$taken) {
            return $slug;
        }
        $slug = $base . '-' . $n++;
    }
}

/**
 * Einmalige Umstellung: aus der bisherigen einzelnen Liste wird die erste
 * Wunschliste, alle vorhandenen Wünsche und Tags werden ihr zugeordnet.
 */
function migrate_to_lists(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (store_read('lists')) {
        return;
    }
    $name = (string)cfg('site_title', 'Wunschliste');
    $list = [
        'id'         => new_id('l_'),
        'name'       => $name,
        'subtitle'   => (string)cfg('site_subtitle', ''),
        'slug'       => slugify($name),
        'theme'      => 'weihnachten',
        'password'   => (string)cfg('guest_password', ''),
        'is_default' => true,
        'created_at' => date('c'),
    ];
    store_write('lists', [$list]);

    foreach (['gifts', 'tags'] as $what) {
        $rows = store_read($what);
        if (!$rows) {
            continue;
        }
        foreach ($rows as &$row) {
            if (empty($row['list'])) {
                $row['list'] = $list['id'];
            }
        }
        unset($row);
        store_write($what, $rows);
    }
}

/**
 * Ergänzt bei bestehenden Listen die Einstellungen, die früher global
 * in der config.php standen.
 */
function migrate_list_settings(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $lists   = store_read('lists');
    $aendern = false;
    foreach ($lists as &$list) {
        foreach (list_setting_defaults() as $key => $default) {
            if (!array_key_exists($key, $list)) {
                $list[$key] = (bool)cfg($key, $default);
                $aendern = true;
            }
        }
    }
    unset($list);
    if ($aendern) {
        store_write('lists', $lists);
    }
}

/* ---------- Tags ---------- */

function tags_all(?string $listId = null): array
{
    $tags = store_read('tags');
    if ($listId !== null) {
        $tags = array_values(array_filter($tags, fn($t) => ($t['list'] ?? '') === $listId));
    }
    usort($tags, function ($a, $b) {
        return [$a['sort'] ?? 0, mb_strtolower($a['name'] ?? '')]
            <=> [$b['sort'] ?? 0, mb_strtolower($b['name'] ?? '')];
    });
    return $tags;
}

function tags_map(?string $listId = null): array
{
    $map = [];
    foreach (tags_all($listId) as $tag) {
        $map[$tag['id']] = $tag;
    }
    return $map;
}

function tag_color(array $tag): string
{
    $color = $tag['color'] ?? '';
    return preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#1f6f4a';
}

/* ---------- Geschenke ---------- */

function gifts_all(?string $listId = null): array
{
    $gifts = store_read('gifts');
    if ($listId !== null) {
        $gifts = array_values(array_filter($gifts, fn($g) => ($g['list'] ?? '') === $listId));
    }
    usort($gifts, function ($a, $b) {
        // Höhere Priorität zuerst, danach neueste zuerst
        return [(int)($b['priority'] ?? 2), (string)($b['created_at'] ?? '')]
            <=> [(int)($a['priority'] ?? 2), (string)($a['created_at'] ?? '')];
    });
    return $gifts;
}

function gift_find(string $id): ?array
{
    foreach (store_read('gifts') as $gift) {
        if (($gift['id'] ?? '') === $id) {
            return $gift;
        }
    }
    return null;
}

function gift_reserved_count(array $gift): int
{
    return count($gift['reservations'] ?? []);
}

function gift_quantity(array $gift): int
{
    return max(1, (int)($gift['quantity'] ?? 1));
}

function gift_is_available(array $gift): bool
{
    return gift_reserved_count($gift) < gift_quantity($gift);
}

function gift_image_src(array $gift): string
{
    $image = trim((string)($gift['image'] ?? ''));
    if ($image === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $image)) {
        return $image;
    }
    return $image; // relativer Pfad, z.B. uploads/xyz.jpg
}

function priority_label(int $priority): string
{
    return [1 => 'Nice to have', 2 => 'Normal', 3 => 'Grosser Wunsch'][$priority] ?? 'Normal';
}

function format_price($price): string
{
    $price = trim((string)$price);
    if ($price === '') {
        return '';
    }
    if (is_numeric($price)) {
        return cfg('currency', 'CHF') . ' ' . number_format((float)$price, 2, '.', "'");
    }
    return $price;
}

function format_date(string $iso): string
{
    $ts = strtotime($iso);
    return $ts ? date('d.m.Y H:i', $ts) : $iso;
}

/* ---------- Passwörter ---------- */

function password_matches(string $input, string $stored): bool
{
    if ($stored === '') {
        return false;
    }
    if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon') || str_starts_with($stored, '$2a$')) {
        return password_verify($input, $stored);
    }
    return hash_equals($stored, $input);
}

/* ---------- Flash-Meldungen ---------- */

function flash_set(string $type, string $message): void
{
    wl_session_start();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take(): array
{
    wl_session_start();
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}
