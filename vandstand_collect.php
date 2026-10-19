<?php
/**
 * vandstand_collect.php — henter vandstand fra DMI oceanObs til MySQL
 *
 * Kørsel:
 *   php vandstand_collect.php            # normal kørsel (fra seneste måling)
 *   php vandstand_collect.php --dage=30  # hent historik bagud
 *   php vandstand_collect.php --test     # vis data, skriv intet i databasen
 *   php vandstand_collect.php --fra=2023-10-19 --til=2023-10-22
 *                                        # hent en bestemt periode (danske datoer, til-dagen med)
 *   php vandstand_collect.php --fra=1996-01-01 --til=2026-10-17
 *                                        # lange perioder hentes automatisk i bidder af 180 døgn
 *
 * Cron (hvert 10. min, 3 min efter DMI's måletidspunkt):
 *   3-59/10 * * * * php /home/kao/scripts/vandstand_collect.php >> /home/kao/scripts/vandstand.log 2>&1
 */

declare(strict_types=1);

// ---- Konfiguration ------------------------------------------------------
$cfg = require __DIR__ . '/config.php';   // ['dsn','user','pass']

const API        = 'https://opendataapi.dmi.dk/v2/oceanObs/collections/observation/items';
const STATIONER  = ['31573', '31616'];         // Rødbyhavn, Gedser
const PARAMETRE  = ['sealev_dvr', 'tw'];       // vandstand DVR90 (cm), vandtemperatur
const OVERLAP_H  = 2;                          // hent lidt bagud — DMI kan efterlevere
const START_DAGE = 7;                          // første kørsel uden data

// ---- Argumenter ---------------------------------------------------------
$opt   = getopt('', ['dage:', 'test', 'fra:', 'til:']);
$test  = isset($opt['test']);
$dage  = isset($opt['dage']) ? max(1, (int)$opt['dage']) : null;

// --fra/--til: danske datoer → UTC-tidspunkter til DMI's datetime-filter
$periodeFra = $periodeTil = null;
if (isset($opt['fra'])) {
    $tz = new DateTimeZone('Europe/Copenhagen');
    $f  = DateTime::createFromFormat('!Y-m-d', $opt['fra'], $tz);
    $t  = DateTime::createFromFormat('!Y-m-d', $opt['til'] ?? $opt['fra'], $tz);
    $gyldig = fn($d, $tekst) => $d && $d->format('Y-m-d') === $tekst;   // afviser fx 2023-13-01
    if (!$gyldig($f, (string)$opt['fra']) || !$gyldig($t, (string)($opt['til'] ?? $opt['fra']))) {
        fwrite(STDERR, "Brug datoformat ÅÅÅÅ-MM-DD, fx --fra=2023-10-19 --til=2023-10-22\n");
        exit(1);
    }
    $t->modify('+1 day');                              // til-dagen med
    $periodeFra = $f->getTimestamp();
    $periodeTil = min($t->getTimestamp(), time());
}

/**
 * Deler [fra, til] i bidder på højst 180 døgn. DMI giver max 300.000 målinger pr.
 * kald, og 180 døgn × 144 målinger/døgn = ca. 26.000 — god margen, korte kald.
 */
function bidder(int $fra, int $til): array {
    $ud = [];
    for ($a = $fra; $a < $til; $a += 180 * 86400) {
        $ud[] = [gmdate('Y-m-d\TH:i:s\Z', $a), gmdate('Y-m-d\TH:i:s\Z', min($a + 180 * 86400, $til))];
    }
    return $ud;
}

function logmsg(string $s): void {
    echo gmdate('Y-m-d H:i:s'), ' UTC  ', $s, PHP_EOL;
}

function hentJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Accept: application/geo+json'],
        CURLOPT_USERAGENT      => 'itkur.dk vandstand-collector',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        throw new RuntimeException("HTTP $code $err fra $url");
    }
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['features'])) {
        throw new RuntimeException("Uventet svar fra $url");
    }
    return $json;
}

// ---- Database -----------------------------------------------------------
$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec("SET time_zone = '+00:00'");

$sidste = $pdo->prepare(
    'SELECT MAX(observed) FROM vandstand_obs WHERE station_id = ? AND parameter_id = ?'
);
$upsert = $pdo->prepare(
    'INSERT INTO vandstand_obs (station_id, parameter_id, observed, value, qc_status, qc_result)
     VALUES (:s, :p, :o, :v, :qs, :qr)
     ON DUPLICATE KEY UPDATE value = VALUES(value),
                             qc_status = VALUES(qc_status),
                             qc_result = VALUES(qc_result)'
);

// ---- Hent pr. station/parameter -----------------------------------------
$total = 0;
foreach (STATIONER as $st) {
    foreach (PARAMETRE as $par) {
        if ($periodeFra !== null) {
            $stykker = bidder($periodeFra, $periodeTil);        // lang periode i bidder
        } elseif ($dage !== null) {
            $stykker = [[gmdate('Y-m-d\TH:i:s\Z', time() - $dage * 86400), '..']];
        } else {
            $sidste->execute([$st, $par]);
            $max = $sidste->fetchColumn();
            $stykker = [[$max
                ? gmdate('Y-m-d\TH:i:s\Z', strtotime($max . ' UTC') - OVERLAP_H * 3600)
                : gmdate('Y-m-d\TH:i:s\Z', time() - START_DAGE * 86400), '..']];
        }

        $n = 0;
        $maks = null;
        foreach ($stykker as [$fra, $til]) {
            $url = API . '?' . http_build_query([
                'stationId'   => $st,
                'parameterId' => $par,
                'datetime'    => $fra . '/' . $til,
                'limit'       => 300000,
            ]);

            try {
                $json = hentJson($url);
            } catch (Throwable $e) {
                logmsg("FEJL $st/$par $fra: " . $e->getMessage());
                continue;
            }

            if (!$test) $pdo->beginTransaction();
            foreach ($json['features'] as $f) {
                $p = $f['properties'];
                if (!isset($p['value'], $p['observed'])) continue;
                $row = [
                    ':s'  => $p['stationId'],
                    ':p'  => $p['parameterId'],
                    ':o'  => gmdate('Y-m-d H:i:s', strtotime($p['observed'])),
                    ':v'  => $p['value'],
                    ':qs' => $p['qcStatus'] ?? null,
                    ':qr' => $p['qcResult'] ?? null,
                ];
                if (!isset($p['qcResult']) && ($maks === null || $p['value'] > $maks[0])) {
                    $maks = [$p['value'], $p['observed']];
                }
                if ($test) {
                    if ($n < 3) echo '  ', implode(' | ', $row), PHP_EOL;
                } else {
                    $upsert->execute($row);
                }
                $n++;
            }
            if (!$test) $pdo->commit();
            if (count($stykker) > 1) {
                logmsg(sprintf('  %s/%s %s: %d rækker', $st, $par, substr($fra, 0, 10), count($json['features'])));
            }
        }

        [$forste, ] = $stykker[0];
        [, $sidst]  = end($stykker);
        logmsg(sprintf('%s/%-10s %7d rækker fra %s%s', $st, $par, $n, $forste,
            $sidst !== '..' ? " til $sidst" : '') . ($maks ? sprintf('  (maks %s kl. %s UTC)', $maks[0], $maks[1]) : ''));
        $total += $n;
    }
}
logmsg("Færdig: $total rækker" . ($test ? ' (test — intet gemt)' : ''));

