-- Luftkvalitet, pollen og UV for Errindlev (Open-Meteo / CAMS Europa, ca. 11 km net)
-- Kør: mysql -u <bruger> -p <database> < luft_schema.sql
--   eller uden at taste kode: php luft_collect.php --opret

CREATE TABLE IF NOT EXISTS luft_time (
    time_utc     DATETIME      NOT NULL PRIMARY KEY COMMENT 'Timens start, UTC',
    aqi          SMALLINT      NULL COMMENT 'Europæisk luftkvalitetsindeks (0-20 god … >100 ekstremt dårlig)',
    aqi_kilde    VARCHAR(10)   NULL COMMENT 'Stoffet der bestemmer indekset: pm2_5, pm10, no2, o3, so2',
    pm2_5        DECIMAL(6,1)  NULL COMMENT 'µg/m³',
    pm10         DECIMAL(6,1)  NULL COMMENT 'µg/m³',
    no2          DECIMAL(6,1)  NULL COMMENT 'Kvælstofdioxid µg/m³',
    o3           DECIMAL(6,1)  NULL COMMENT 'Ozon µg/m³',
    so2          DECIMAL(6,1)  NULL COMMENT 'Svovldioxid µg/m³',
    uv           DECIMAL(4,2)  NULL COMMENT 'UV-indeks',
    el           DECIMAL(7,1)  NULL COMMENT 'Pollen, korn/m³',
    birk         DECIMAL(7,1)  NULL,
    graes        DECIMAL(7,1)  NULL,
    bynke        DECIMAL(7,1)  NULL,
    ambrosie     DECIMAL(7,1)  NULL,
    hentet       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_aqi (aqi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
