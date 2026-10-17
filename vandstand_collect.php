<?php
/**
 * vandstand_collect.php — henter vandstand fra DMI oceanObs til MySQL
 *
 * Kørsel:
 *   php vandstand_collect.php            # normal kørsel (fra seneste måling)
 *   php vandstand_collect.php --dage=30  # hent historik bagud
 *   php vandstand_collect.php --test     # vis data, skriv intet i databasen
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
$opt   = getopt('', ['dage:', 'test']);
$test  = isset($opt['test']);
$dage  = isset($opt['dage']) ? max(1, (int)$opt['dage']) : null;

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
        if ($dage !== null) {
            $fra = gmdate('Y-m-d\TH:i:s\Z', time() - $dage * 86400);
        } else {
            $sidste->execute([$st, $par]);
            $max = $sidste->fetchColumn();
            $fra = $max
                ? gmdate('Y-m-d\TH:i:s\Z', strtotime($max . ' UTC') - OVERLAP_H * 3600)
                : gmdate('Y-m-d\TH:i:s\Z', time() - START_DAGE * 86400);
        }

        $url = API . '?' . http_build_query([
            'stationId'   => $st,
            'parameterId' => $par,
            'datetime'    => $fra . '/..',
            'limit'       => 300000,
        ]);

        try {
            $json = hentJson($url);
        } catch (Throwable $e) {
            logmsg("FEJL $st/$par: " . $e->getMessage());
            continue;
        }

        $n = 0;
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
            if ($test) {
                if ($n < 3) echo '  ', implode(' | ', $row), PHP_EOL;
            } else {
                $upsert->execute($row);
            }
            $n++;
        }
        if (!$test) $pdo->commit();

        logmsg(sprintf('%s/%-10s %5d rækker fra %s', $st, $par, $n, $fra));
        $total += $n;
    }
}
logmsg("Færdig: $total rækker" . ($test ? ' (test — intet gemt)' : ''));

