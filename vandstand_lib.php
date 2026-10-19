<?php
/**
 * vandstand_lib.php — fælles beregninger for vandstand på Pi'en
 *
 * Bruges af vandstand_collect.php og vandstand_haendelser.php.
 * Samme rensning og hændelseslogik som errindlevhavn.dk (vandstand/lib.php).
 *
 * Pi'en kører 32-bit PHP: unix-tid går kun til 1901-12-13, derfor starter
 * historikken 1902-01-01 (VS_TIDLIGST).
 */
declare(strict_types=1);

const VS_STATIONER    = ['31573' => 'Rødbyhavn', '31616' => 'Gedser'];
const VS_SPIDS_CM     = 50;     // afvigelse fra nabomålingernes median = fejlmåling
const VS_SPIDS_VINDUE = 5400;   // naboer inden for ±90 min (fanger fejlblokke op til en time)
const VS_HOEJVANDE_CM = 60;     // grænse for højvandshændelser
const VS_PAUSE_TIMER  = 24;     // kortere pause mellem timer over grænsen = samme storm
const VS_KRYDS_CM     = 70;     // max forskel mellem stationerne i en ægte storm
const VS_TIDLIGST     = '1902-01-01';

/** Sætter/henter forbindelsen. Første kald skal give en PDO. */
function vs_pdo(?PDO $ny = null): PDO
{
    static $pdo = null;
    if ($ny) {
        $pdo = $ny;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    if (!$pdo) throw new LogicException('vs_pdo() kaldt uden forbindelse');
    return $pdo;
}

function vs_utc(int $ts): string { return gmdate('Y-m-d H:i:s', $ts); }
function vs_ts(string $utc): int { return (int)strtotime($utc . ' UTC'); }

/** Runder ned til hel time/døgn – også før 1970, hvor PHP's % giver negativ rest. */
function vs_ned(int $ts, int $trin): int { return $ts - (($ts % $trin) + $trin) % $trin; }

/**
 * Markerer fejlmålinger i en tidsordnet liste ['t','v','fejl'].
 * DMI-fejl er allerede sat. Spidser findes mod medianen af alle gyldige
 * målinger inden for ±90 min.
 */
function vs_rens(array $r): array
{
    $n = count($r);
    $lo = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($r[$i]['fejl']) continue;
        while ($r[$lo]['t'] < $r[$i]['t'] - VS_SPIDS_VINDUE) $lo++;
        $nab = [];
        for ($j = $lo; $j < $n && $r[$j]['t'] <= $r[$i]['t'] + VS_SPIDS_VINDUE; $j++) {
            if ($j !== $i && $r[$j]['fejl'] !== 'DMI') $nab[] = $r[$j]['v'];
        }
        if (count($nab) >= 2) {
            sort($nab);
            $m = count($nab);
            $median = $m % 2 ? $nab[intdiv($m, 2)] : ($nab[$m / 2 - 1] + $nab[$m / 2]) / 2;
            if (abs($r[$i]['v'] - $median) > VS_SPIDS_CM) $r[$i]['fejl'] = 'spids';
        }
    }
    return $r;
}

/** Rå målinger for én station i [fra, til] (unix-sek), med fejl markeret. */
function vs_raa(string $station, int $fra, int $til): array
{
    static $st = null;
    $st ??= vs_pdo()->prepare(
        "SELECT observed, value, qc_result FROM vandstand_obs
          WHERE station_id = ? AND parameter_id = 'sealev_dvr' AND observed BETWEEN ? AND ?
          ORDER BY observed");
    $st->execute([$station, vs_utc($fra - 3 * 3600), vs_utc($til + 3 * 3600)]);   // margen til spidsfilteret
    $r = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $r[] = ['t' => vs_ts($row['observed']), 'v' => (float)$row['value'],
                'fejl' => $row['qc_result'] !== null ? 'DMI' : null];
    }
    $r = vs_rens($r);
    return array_values(array_filter($r, fn($p) => $p['t'] >= $fra && $p['t'] <= $til));
}

/** Genberegner timemidler (vandstand_time) for [fra, til] ud fra rensede rå målinger. */
function vs_byg_timer(int $fra, int $til): int
{
    $fra = vs_ned($fra, 3600);
    $pdo  = vs_pdo();
    $slet = $pdo->prepare("DELETE FROM vandstand_time WHERE station_id = ? AND time_utc BETWEEN ? AND ?");
    $ind  = $pdo->prepare("INSERT INTO vandstand_time (station_id, time_utc, cm, antal) VALUES (?, ?, ?, ?)");
    $n = 0;
    foreach (array_keys(VS_STATIONER) as $station) {
        $station = (string)$station;
        // Bidder af 30 døgn, så hukommelsen holder ved store genberegninger
        for ($a = $fra; $a <= $til; $a += 30 * 86400) {
            $b = min($a + 30 * 86400 - 1, $til);
            $timer = [];
            foreach (vs_raa($station, $a, $b) as $p) {
                if ($p['fejl']) continue;
                $timer[vs_ned($p['t'], 3600)][] = $p['v'];
            }
            $pdo->beginTransaction();
            $slet->execute([$station, vs_utc($a), vs_utc($b)]);
            foreach ($timer as $t => $v) {
                $ind->execute([$station, vs_utc($t), round(array_sum($v) / count($v), 1), count($v)]);
                $n++;
            }
            $pdo->commit();
        }
    }
    return $n;
}

/**
 * Finder højvandshændelser ud fra timemidlerne: timer hvor en station er
 * >= VS_HOEJVANDE_CM, under VS_PAUSE_TIMER mellem dem = samme storm.
 * Ses toppen kun på én station, mens den anden samtidig ligger mere end
 * VS_KRYDS_CM lavere, er det en sensorfejl og ikke en stormflod.
 */
function vs_find_haendelser(): array
{
    $pdo = vs_pdo();
    $rows = $pdo->query(
        "SELECT time_utc, station_id, cm FROM vandstand_time
          WHERE cm >= " . VS_HOEJVANDE_CM . " ORDER BY time_utc")->fetchAll(PDO::FETCH_ASSOC);

    $timer = [];
    foreach ($rows as $r) $timer[vs_ts($r['time_utc'])][$r['station_id']] = (float)$r['cm'];
    ksort($timer);

    $haendelser = [];
    $cur = null;
    foreach ($timer as $t => $st) {
        $top = max($st);
        if ($cur === null || $t - $cur['slut'] > VS_PAUSE_TIMER * 3600) {
            if ($cur) $haendelser[] = $cur;
            $cur = ['start' => $t, 'slut' => $t, 'top_t' => $t, 'top' => $top, 'rb' => null, 'gd' => null, 'timer' => 0];
        }
        $cur['slut'] = $t;
        $cur['timer']++;
        if ($top > $cur['top']) { $cur['top'] = $top; $cur['top_t'] = $t; }
        if (isset($st['31573'])) $cur['rb'] = max($cur['rb'] ?? -999, $st['31573']);
        if (isset($st['31616'])) $cur['gd'] = max($cur['gd'] ?? -999, $st['31616']);
    }
    if ($cur) $haendelser[] = $cur;

    $anden = $pdo->prepare("SELECT cm FROM vandstand_time WHERE station_id = ? AND time_utc = ?");
    return array_values(array_filter($haendelser, function ($h) use ($anden) {
        if ($h['rb'] !== null && $h['gd'] !== null) return true;
        $mangler = $h['rb'] === null ? '31573' : '31616';
        $anden->execute([$mangler, vs_utc($h['top_t'])]);
        $v = $anden->fetchColumn();
        return $v === false || $h['top'] - (float)$v <= VS_KRYDS_CM;
    }));
}

/** Udskifter hele tabellen vandstand_haendelser i én transaktion. */
function vs_byg_haendelser(): int
{
    $haendelser = vs_find_haendelser();
    $pdo = vs_pdo();
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM vandstand_haendelser");
    $ind = $pdo->prepare(
        "INSERT INTO vandstand_haendelser (start_utc, slut_utc, top_utc, top_cm, roedbyhavn_cm, gedser_cm, timer_over)
         VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($haendelser as $h) {
        $ind->execute([vs_utc($h['start']), vs_utc($h['slut']), vs_utc($h['top_t']), (int)round($h['top']),
                       $h['rb'] === null ? null : (int)round($h['rb']),
                       $h['gd'] === null ? null : (int)round($h['gd']), $h['timer']]);
    }
    $pdo->commit();
    return count($haendelser);
}
