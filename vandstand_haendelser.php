<?php
/**
 * vandstand_haendelser.php — genberegner tabellen vandstand_haendelser
 *
 * En hændelse = sammenhængende timer hvor Rødbyhavn eller Gedser har timemiddel
 * >= 60 cm (tabellen vandstand_time). Under 24 timer mellem to sådanne timer =
 * samme storm. Ses en top kun på én station, mens den anden samtidig er mere end
 * 70 cm lavere, er det en sensorfejl og tælles ikke med.
 *
 * vandstand_collect.php gør det samme efter hver hentning, så dette script er kun
 * en sikkerhed om natten og til at kigge på resultatet.
 *
 * Kørsel:
 *   php vandstand_haendelser.php          # genberegn tabellen
 *   php vandstand_haendelser.php --test   # vis top 10, skriv intet
 *
 * Cron (hver nat):
 *   17 3 * * * php /home/kao/scripts/vandstand_haendelser.php >> /home/kao/scripts/vandstand.log 2>&1
 */

declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';   // ['dsn','user','pass'] — samme som vandstand_collect.php
require_once __DIR__ . '/vandstand_lib.php';

function logmsg(string $s): void {
    echo gmdate('Y-m-d H:i:s'), ' UTC  ', $s, PHP_EOL;
}

vs_pdo(new PDO($cfg['dsn'], $cfg['user'], $cfg['pass']));
$t0 = microtime(true);

if (in_array('--test', $argv, true)) {
    $rows = vs_find_haendelser();
    usort($rows, fn($a, $b) => $b['top'] <=> $a['top']);
    printf("%d hændelser (%.1f s). Top 10:\n", count($rows), microtime(true) - $t0);
    foreach (array_slice($rows, 0, 10) as $i => $r) {
        printf("%2d. %s  top %4d cm  (Rødbyhavn %4s, Gedser %4s)  %3d timer\n",
            $i + 1, vs_utc($r['top_t']), round($r['top']),
            $r['rb'] === null ? '-' : round($r['rb']), $r['gd'] === null ? '-' : round($r['gd']), $r['timer']);
    }
    exit;
}

$n = vs_byg_haendelser();
logmsg(sprintf('Hændelser: %d gemt, beregnet på %.1f s', $n, microtime(true) - $t0));
