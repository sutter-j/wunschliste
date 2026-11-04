<?php
/**
 * Designs, die beim Anlegen einer Wunschliste gewählt werden können.
 * Die Farben stecken in assets/style.css unter body[data-theme="…"].
 */

declare(strict_types=1);

function themes_all(): array
{
    return [
        'weihnachten' => [
            'name'  => 'Weihnachten, Rot und Lebkuchen',
            'emoji' => "\u{1F385}",          // Weihnachtsmann
            'hint'  => 'Weihnachtsmann-Rot, Lebkuchen, Schneeflocken',
            'deco'  => 'snow',
            'color' => '#7d1620',
            'swatch' => ['#c0202e', '#a9713f', '#e8b64c'],
        ],
        'weihnachten-schnee' => [
            'name'  => 'Weihnachten, Schnee und Eis',
            'emoji' => "\u{2744}\u{FE0F}",   // Schneeflocke
            'hint'  => 'Eisblau, Silber, viel Schnee',
            'deco'  => 'snow',
            'color' => '#11384d',
            'swatch' => ['#2a6f97', '#5e8ca7', '#7fc3e3'],
        ],
        'geburtstag-junge' => [
            'name'  => 'Geburtstag Junge',
            'emoji' => "\u{1F388}",          // Luftballon
            'hint'  => 'Blau und Türkis mit Konfetti',
            'deco'  => 'confetti',
            'color' => '#10456e',
            'swatch' => ['#1b7fc4', '#26a69a', '#ffcf5c'],
        ],
        'geburtstag-maedchen' => [
            'name'  => 'Geburtstag Mädchen',
            'emoji' => "\u{1F380}",          // Schleife
            'hint'  => 'Pink und Flieder mit Konfetti',
            'deco'  => 'confetti',
            'color' => '#7a1c48',
            'swatch' => ['#d6336c', '#a86fd1', '#ffb3d1'],
        ],
        'geburtstag-zusammen' => [
            'name'  => 'Geburtstag Junge und Mädchen',
            'emoji' => "\u{1F389}",          // Party-Popper
            'hint'  => 'Pink und Blau zusammen',
            'deco'  => 'confetti',
            'color' => '#40228a',
            'swatch' => ['#7048c4', '#ef5da8', '#ffd166'],
        ],
        'geburtstag-neutral' => [
            'name'  => 'Geburtstag neutral',
            'emoji' => "\u{1F382}",          // Torte
            'hint'  => 'Orange und Grün, für alle',
            'deco'  => 'confetti',
            'color' => '#8a4410',
            'swatch' => ['#e07a1f', '#2f9e6e', '#ffd166'],
        ],
        'geburtstag-erwachsen' => [
            'name'  => 'Geburtstag Erwachsene',
            'emoji' => "\u{1F942}",          // Gläser
            'hint'  => 'Dunkelblau mit Gold, ruhig und edel',
            'deco'  => 'none',
            'color' => '#1a2230',
            'swatch' => ['#37475c', '#b08d57', '#c4a265'],
        ],
        'ostern' => [
            'name'  => 'Ostern',
            'emoji' => "\u{1F423}",          // Küken
            'hint'  => 'Frühlingsgrün, Gelb und Flieder',
            'deco'  => 'confetti',
            'color' => '#46682f',
            'swatch' => ['#74a44b', '#a97bc4', '#f4d35e'],
        ],
        'wald' => [
            'name'  => 'Natur und Wald',
            'emoji' => "\u{1F33F}",          // Zweig
            'hint'  => 'Tannengrün und Holz, das ganze Jahr',
            'deco'  => 'none',
            'color' => '#17422b',
            'swatch' => ['#2f6b45', '#8a6f4b', '#c9a227'],
        ],
        'schlicht' => [
            'name'  => 'Schlicht',
            'emoji' => "\u{1F381}",          // Geschenk
            'hint'  => 'Zurückhaltend in Grau und Blau',
            'deco'  => 'none',
            'color' => '#1e293b',
            'swatch' => ['#334155', '#64748b', '#8aa0b8'],
        ],
    ];
}

function theme_key(?array $list): string
{
    $key = (string)($list['theme'] ?? 'weihnachten');
    return isset(themes_all()[$key]) ? $key : 'weihnachten';
}

function theme_of(?array $list): array
{
    return themes_all()[theme_key($list)];
}
