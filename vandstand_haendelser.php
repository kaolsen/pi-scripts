<?php
/**
 * vandstand_haendelser.php — genberegner tabellen vandstand_haendelser
 *
 * En hændelse = sammenhængende timer hvor Rødbyhavn eller Gedser har timemiddel
 * >= GRAENSE_CM. Under PAUSE_TIMER mellem to sådanne timer = samme storm.
 * Samme rensning som vandstand.php: DMI-fejlmarkerede målinger og enkeltspidser
 * (> SPIDS_CM fra både målingen før og efter) udelades.
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

const GRAENSE_CM  = 60;
const PAUSE_TIMER = 24;
const SPIDS_CM    = 50;

$test = in_array('--test', $argv, true);

function logmsg(string $s): void {
    echo gmdate('Y-m-d H:i:s'), ' UTC  ', $s, PHP_EOL;
}

$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET time_zone = '+00:00'");

$t0 = microtime(true);
$sql = "
WITH
renset AS (
    SELECT station_id, observed, value,
           LAG(value)  OVER (PARTITION BY station_id ORDER BY observed) AS foer,
           LEAD(value) OVER (PARTITION BY station_id ORDER BY observed) AS efter
      FROM vandstand_obs
     WHERE parameter_id = 'sealev_dvr' AND qc_result IS NULL
       AND station_id IN ('31573', '31616')
),
timer AS (
    SELECT CAST(DATE_FORMAT(observed, '%Y-%m-%d %H:00:00') AS DATETIME) AS tid,
           AVG(CASE WHEN station_id = '31573' THEN value END) AS rb,
           AVG(CASE WHEN station_id = '31616' THEN value END) AS gd
      FROM renset
     WHERE NOT (foer IS NOT NULL AND efter IS NOT NULL
                AND ABS(value - foer) > " . SPIDS_CM . " AND ABS(value - efter) > " . SPIDS_CM . ")
     GROUP BY tid
),
hoeje AS (
    SELECT tid, rb, gd,
           GREATEST(COALESCE(rb, -999), COALESCE(gd, -999)) AS top,
           CASE WHEN TIMESTAMPDIFF(HOUR, LAG(tid) OVER (ORDER BY tid), tid) <= " . PAUSE_TIMER . "
                THEN 0 ELSE 1 END AS ny
      FROM timer
     WHERE GREATEST(COALESCE(rb, -999), COALESCE(gd, -999)) >= " . GRAENSE_CM . "
),
nummereret AS (
    SELECT h.*, SUM(ny) OVER (ORDER BY tid) AS nr FROM hoeje h
),
rangeret AS (
    SELECT n.*, ROW_NUMBER() OVER (PARTITION BY nr ORDER BY top DESC, tid) AS rn FROM nummereret n
)
SELECT MIN(tid)                        AS start_utc,
       MAX(tid)                        AS slut_utc,
       MAX(CASE WHEN rn = 1 THEN tid END) AS top_utc,
       ROUND(MAX(top))                 AS top_cm,
       ROUND(MAX(rb))                  AS roedbyhavn_cm,
       ROUND(MAX(gd))                  AS gedser_cm,
       COUNT(*)                        AS timer_over
  FROM rangeret
 GROUP BY nr
 ORDER BY start_utc";

$rows = $pdo->query($sql)->fetchAll();
$sek  = round(microtime(true) - $t0, 1);

if ($test) {
    usort($rows, fn($a, $b) => $b['top_cm'] <=> $a['top_cm']);
    printf("%d hændelser (%.1f s). Top 10:\n", count($rows), $sek);
    foreach (array_slice($rows, 0, 10) as $i => $r) {
        printf("%2d. %s  top %4s cm  (Rødbyhavn %4s, Gedser %4s)  %3d timer\n",
            $i + 1, $r['top_utc'], $r['top_cm'], $r['roedbyhavn_cm'] ?? '-', $r['gedser_cm'] ?? '-', $r['timer_over']);
    }
    exit;
}

// Udskift hele tabellen i én transaktion, så siden aldrig ser den halvt fyldt
$pdo->beginTransaction();
$pdo->exec('DELETE FROM vandstand_haendelser');
$ins = $pdo->prepare(
    'INSERT INTO vandstand_haendelser (start_utc, slut_utc, top_utc, top_cm, roedbyhavn_cm, gedser_cm, timer_over)
     VALUES (:start_utc, :slut_utc, :top_utc, :top_cm, :roedbyhavn_cm, :gedser_cm, :timer_over)'
);
foreach ($rows as $r) $ins->execute($r);
$pdo->commit();

$top = $rows ? max(array_column($rows, 'top_cm')) : '-';
logmsg(sprintf('Hændelser: %d gemt (højeste %s cm), beregnet på %.1f s', count($rows), $top, $sek));
