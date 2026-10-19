-- vandstand_top10.sql — de 10 højeste stormflodshændelser (Rødbyhavn og Gedser)
--
-- Kør:  mysql -t -u vejradmin -p vejrdata < vandstand_top10.sql
--
-- Læser tabellen vandstand_haendelser, som vandstand_collect.php bygger efter
-- hver hentning (rensede timemidler, krydstjek mellem stationerne).
-- Se vandstand_lib.php for reglerne. Tider er UTC. cm over DVR90.

SELECT RANK() OVER (ORDER BY h.top_cm DESC)               AS plads,
       DATE_FORMAT(h.top_utc, '%Y-%m-%d %H:00')          AS `top (UTC)`,
       h.roedbyhavn_cm                                   AS `Rødbyhavn cm`,
       h.gedser_cm                                       AS `Gedser cm`,
       h.timer_over                                      AS `timer over 60 cm`,
       CONCAT(DATE_FORMAT(h.start_utc, '%d/%m %H:00'), ' – ',
              DATE_FORMAT(h.slut_utc,  '%d/%m %H:00'))   AS `periode (UTC)`,
       (SELECT n.navn FROM vandstand_haendelse_navn n
         WHERE n.dato BETWEEN DATE(h.start_utc) AND DATE(h.slut_utc) LIMIT 1) AS navn
  FROM vandstand_haendelser h
 ORDER BY h.top_cm DESC, h.top_utc DESC
 LIMIT 10;
