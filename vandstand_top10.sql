-- vandstand_top10.sql — de 10 højeste stormflodshændelser (Rødbyhavn og Gedser)
--
-- Kør:  mysql -u vejradmin -p vejrdata < vandstand_top10.sql
--       (eller med -t for tabelramme:  mysql -t -u vejradmin -p vejrdata < vandstand_top10.sql)
--
-- Sådan regnes der (samme rensning som vandstand.php):
--   1. DMI-fejlmarkerede målinger (qc_result) udelades
--   2. Enkeltspidser udelades: afviger > 50 cm fra både målingen før og efter
--   3. Timemiddel pr. station — dæmper bølger og støj
--   4. Timer hvor mindst én station er >= 60 cm samles til hændelser;
--      under 24 timer mellem to sådanne timer = samme storm
--   5. Hver hændelse rangeres efter den højeste timemiddel på en af stationerne
--
-- Tider er UTC (dansk sommertid = +2, vintertid = +1). cm over DVR90.
-- Kræver MariaDB 10.2+ / MySQL 8 (CTE og vinduesfunktioner).

WITH
renset AS (
    SELECT station_id, observed, value,
           LAG(value)  OVER (PARTITION BY station_id ORDER BY observed) AS foer,
           LEAD(value) OVER (PARTITION BY station_id ORDER BY observed) AS efter
      FROM vandstand_obs
     WHERE parameter_id = 'sealev_dvr'
       AND qc_result IS NULL
       AND station_id IN ('31573', '31616')
),
timer AS (
    SELECT CAST(DATE_FORMAT(observed, '%Y-%m-%d %H:00:00') AS DATETIME) AS tid,
           AVG(CASE WHEN station_id = '31573' THEN value END) AS rb,
           AVG(CASE WHEN station_id = '31616' THEN value END) AS gd
      FROM renset
     WHERE NOT (foer IS NOT NULL AND efter IS NOT NULL
                AND ABS(value - foer) > 50 AND ABS(value - efter) > 50)
     GROUP BY tid
),
hoeje AS (
    SELECT tid, rb, gd,
           GREATEST(COALESCE(rb, -999), COALESCE(gd, -999)) AS top,
           CASE WHEN TIMESTAMPDIFF(HOUR, LAG(tid) OVER (ORDER BY tid), tid) <= 24
                THEN 0 ELSE 1 END AS ny_haendelse
      FROM timer
     WHERE GREATEST(COALESCE(rb, -999), COALESCE(gd, -999)) >= 60
),
haendelser AS (
    SELECT h.*,
           SUM(ny_haendelse) OVER (ORDER BY tid) AS nr
      FROM hoeje h
),
pr_haendelse AS (
    SELECT nr,
           MIN(tid)            AS start_utc,
           MAX(tid)            AS slut_utc,
           COUNT(*)            AS timer_over_60,
           ROUND(MAX(rb))      AS roedbyhavn_maks,
           ROUND(MAX(gd))      AS gedser_maks,
           MAX(top)            AS top
      FROM haendelser
     GROUP BY nr
)
SELECT RANK() OVER (ORDER BY p.top DESC)                AS plads,
       DATE_FORMAT(p.start_utc, '%Y-%m-%d')            AS dato,
       p.roedbyhavn_maks                               AS `Rødbyhavn cm`,
       p.gedser_maks                                   AS `Gedser cm`,
       (SELECT DATE_FORMAT(h.tid, '%d/%m %H:00')
          FROM haendelser h
         WHERE h.nr = p.nr ORDER BY h.top DESC LIMIT 1)  AS `top kl. (UTC)`,
       p.timer_over_60                                 AS `timer over 60 cm`,
       CONCAT(DATE_FORMAT(p.start_utc, '%d/%m %H:00'), ' – ',
              DATE_FORMAT(p.slut_utc,  '%d/%m %H:00')) AS `periode (UTC)`
  FROM pr_haendelse p
 ORDER BY p.top DESC
 LIMIT 10;
