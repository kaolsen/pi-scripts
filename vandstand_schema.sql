-- Vandstand fra DMI oceanObs
-- Kør: mysql -u <bruger> -p <database> < vandstand_schema.sql

CREATE TABLE IF NOT EXISTS vandstand_station (
    station_id   VARCHAR(10)  NOT NULL PRIMARY KEY,
    navn         VARCHAR(80)  NOT NULL,
    lat          DECIMAL(8,5) NOT NULL,
    lon          DECIMAL(8,5) NOT NULL,
    afstand_km   DECIMAL(5,1) NULL COMMENT 'Afstand til Errindlev Havn',
    aktiv        TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vandstand_obs (
    station_id   VARCHAR(10)  NOT NULL,
    parameter_id VARCHAR(20)  NOT NULL COMMENT 'sealev_dvr = cm ift. DVR90, tw = vandtemp °C',
    observed     DATETIME     NOT NULL COMMENT 'UTC',
    value        DECIMAL(7,2) NOT NULL,
    qc_status    VARCHAR(10)  NULL,
    qc_result    DECIMAL(3,1) NULL COMMENT 'NULL = ingen fejl fundet',
    hentet       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (station_id, parameter_id, observed),
    KEY idx_observed (observed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO vandstand_station (station_id, navn, lat, lon, afstand_km) VALUES
    ('31573', 'Rødbyhavn', 54.65610, 11.34750, 13.0),
    ('31616', 'Gedser',    54.57210, 11.92450, 26.0)
ON DUPLICATE KEY UPDATE navn = VALUES(navn), lat = VALUES(lat),
                        lon = VALUES(lon), afstand_km = VALUES(afstand_km);

-- Højvandshændelser: genberegnes hver nat af vandstand_haendelser.php
-- (timer hvor Rødbyhavn eller Gedser har timemiddel >= 60 cm; < 24 t pause = samme storm)
CREATE TABLE IF NOT EXISTS vandstand_haendelser (
    start_utc     DATETIME     NOT NULL PRIMARY KEY COMMENT 'Første time over grænsen',
    slut_utc      DATETIME     NOT NULL COMMENT 'Sidste time over grænsen',
    top_utc       DATETIME     NOT NULL COMMENT 'Timen med højeste timemiddel',
    top_cm        SMALLINT     NOT NULL COMMENT 'Højeste timemiddel på en af stationerne',
    roedbyhavn_cm SMALLINT     NULL,
    gedser_cm     SMALLINT     NULL,
    timer_over    INT          NOT NULL COMMENT 'Antal timer over grænsen',
    beregnet      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_top (top_cm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Egne navne på storme. Matcher en hændelse når datoen ligger inden for stormen,
-- så det overlever at nattens genberegning flytter start/slut en time.
CREATE TABLE IF NOT EXISTS vandstand_haendelse_navn (
    dato  DATE         NOT NULL PRIMARY KEY COMMENT 'En dato (UTC) inden for stormen',
    navn  VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO vandstand_haendelse_navn (dato, navn) VALUES
    ('2023-10-20', 'Stormfloden oktober 2023');
