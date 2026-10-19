<?php
/**
 * luft_collect.php — henter luftkvalitet, pollen og UV for Errindlev til MySQL
 *
 * Kilde: Open-Meteo Air Quality API (CAMS Europa, ca. 11 km net). Gratis til
 * ikke-kommerciel brug, ingen nøgle. Siden skal skrive "Data: CAMS / Open-Meteo".
 *
 * Kørsel:
 *   php luft_collect.php                 # normal: 3 døgn tilbage + 5 døgns prognose
 *   php luft_collect.php --test          # vis hvad der hentes, gem intet
 *   php luft_collect.php --opret         # opret tabellen (luft_schema.sql) med config.php's login
 *   php luft_collect.php --fra=2013-01-01 --til=2026-10-19
 *                                        # historik i bidder af 90 døgn (CAMS-genberegning fra 2013)
 *
 * Cron (hver time, 7 min over):
 *   7 * * * * php /home/kao/scripts/luft_collect.php >> /home/kao/scripts/luft.log 2>&1
 */

declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';   // ['dsn','user','pass'] — samme som vandstand

const API = 'https://air-quality-api.open-meteo.com/v1/air-quality';
const LAT = 54.66;    // Errindlev Havn
const LON = 11.44;

// Open-Meteo-navn → kolonne i luft_time
const FELTER = [
    'european_aqi'     => 'aqi',
    'pm2_5'            => 'pm2_5',
    'pm10'             => 'pm10',
    'nitrogen_dioxide' => 'no2',
    'ozone'            => 'o3',
    'sulphur_dioxide'  => 'so2',
    'uv_index'         => 'uv',
    'alder_pollen'     => 'el',
    'birch_pollen'     => 'birk',
    'grass_pollen'     => 'graes',
    'mugwort_pollen'   => 'bynke',
    'ragweed_pollen'   => 'ambrosie',
];
// Delindeks pr. stof — det højeste bestemmer det samlede indeks
const DELINDEKS = [
    'european_aqi_pm2_5'            => 'pm2_5',
    'european_aqi_pm10'             => 'pm10',
    'european_aqi_nitrogen_dioxide' => 'no2',
    'european_aqi_ozone'            => 'o3',
    'european_aqi_sulphur_dioxide'  => 'so2',
];

function logmsg(string $s): void {
    echo gmdate('Y-m-d H:i:s'), ' UTC  ', $s, PHP_EOL;
}

function hentJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'itkur.dk luftkvalitet',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    if ($code !== 200 || !is_array($json) || !isset($json['hourly']['time'])) {
        $grund = is_array($json) && isset($json['reason']) ? $json['reason'] : trim("$err");
        throw new RuntimeException("HTTP $code $grund");
    }
    return $json;
}

// ---- Argumenter ---------------------------------------------------------
$opt  = getopt('', ['test', 'opret', 'fra:', 'til:']);
$test = isset($opt['test']);

$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET time_zone = '+00:00'");

if (isset($opt['opret'])) {
    $pdo->exec((string)file_get_contents(__DIR__ . '/luft_schema.sql'));
    $ok = $pdo->query("SHOW TABLES LIKE 'luft_time'")->fetchColumn();
    logmsg($ok ? 'Tabellen luft_time findes nu' : 'FEJL: luft_time blev ikke oprettet');
    exit($ok ? 0 : 1);
}

// Perioder der skal hentes: [ekstra parametre, beskrivelse]
$kald = [];
if (isset($opt['fra'])) {
    $gyldig = fn($t) => ($d = DateTime::createFromFormat('!Y-m-d', $t, new DateTimeZone('UTC'))) && $d->format('Y-m-d') === $t;
    $fra = (string)$opt['fra'];
    $til = (string)($opt['til'] ?? gmdate('Y-m-d'));
    if (!$gyldig($fra) || !$gyldig($til) || $fra > $til) {
        fwrite(STDERR, "Brug gyldige datoer ÅÅÅÅ-MM-DD, fx --fra=2013-01-01 --til=2026-10-19\n");
        exit(1);
    }
    for ($a = strtotime("$fra UTC"); $a <= strtotime("$til UTC"); $a += 90 * 86400) {
        $b = min($a + 89 * 86400, strtotime("$til UTC"));
        $kald[] = [['start_date' => gmdate('Y-m-d', $a), 'end_date' => gmdate('Y-m-d', $b)], gmdate('Y-m-d', $a)];
    }
} else {
    $kald[] = [['past_days' => 3, 'forecast_days' => 5], 'nu'];
}

// Opdater kun med værdier der faktisk kom: en senere prognose må gerne
// overskrive en tidligere, men et hul (fx pollen uden for sæsonen) sletter ikke data.
$kol = array_merge(['time_utc', 'aqi_kilde'], array_values(FELTER));
$upd = implode(', ', array_map(fn($k) => "$k = COALESCE(VALUES($k), $k)", array_slice($kol, 1)));
$ins = $pdo->prepare('INSERT INTO luft_time (' . implode(', ', $kol) . ') VALUES ('
    . implode(', ', array_fill(0, count($kol), '?')) . ") ON DUPLICATE KEY UPDATE $upd");

$total = 0;
foreach ($kald as [$ekstra, $navn]) {
    $url = API . '?' . http_build_query([
        'latitude'   => LAT,
        'longitude'  => LON,
        'hourly'     => implode(',', array_merge(array_keys(FELTER), array_keys(DELINDEKS))),
        'timezone'   => 'GMT',
        'timeformat' => 'unixtime',
    ] + $ekstra);

    try {
        $json = hentJson($url);
    } catch (Throwable $e) {
        logmsg("FEJL $navn: " . $e->getMessage());
        continue;
    }
    $h = $json['hourly'];
    $n = 0;
    $maks = null;
    if (!$test) $pdo->beginTransaction();
    foreach ($h['time'] as $i => $t) {
        $raekke = [gmdate('Y-m-d H:i:s', (int)$t), null];
        $harData = false;
        foreach (FELTER as $api => $k) {
            $v = $h[$api][$i] ?? null;
            $raekke[] = $v;
            if ($v !== null) $harData = true;
        }
        if (!$harData) continue;                      // timer uden nogen værdier springes over

        // Det stof med højest delindeks bestemmer luftkvaliteten
        $bedst = null;
        foreach (DELINDEKS as $api => $k) {
            $v = $h[$api][$i] ?? null;
            if ($v !== null && ($bedst === null || $v > $bedst[0])) $bedst = [$v, $k];
        }
        $raekke[1] = $bedst[1] ?? null;

        $aqi = $h['european_aqi'][$i] ?? null;
        if ($aqi !== null && ($maks === null || $aqi > $maks[0])) $maks = [$aqi, $raekke[0], $raekke[1]];

        if ($test) {
            if ($n < 3) echo '  ', implode(' | ', array_map(fn($v) => $v ?? '–', $raekke)), PHP_EOL;
        } else {
            $ins->execute($raekke);
        }
        $n++;
    }
    if (!$test) $pdo->commit();
    $total += $n;
    logmsg(sprintf('%s: %d timer%s', $navn, $n,
        $maks ? sprintf('  (højeste indeks %s kl. %s UTC, %s)', $maks[0], substr($maks[1], 0, 16), $maks[2] ?? '?') : ''));
}
logmsg("Færdig: $total timer" . ($test ? ' (test — intet gemt)' : ''));
