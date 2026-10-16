#!/usr/bin/env python3
"""
vejr_regn_beregn.py
Kører hvert 5. minut via cron.
Beregner regnmængde som positiv forskel i vevor.regn_total
og skriver til vejr_regn_interval og vejr_regn_daglig.
 
Håndterer:
  - Beholder-nulstillinger (negativ delta ignoreres)
  - Manglende målinger (station ikke set i intervallet)
  - Dagens dagstotal holdes løbende opdateret
"""
 
import pymysql
import pymysql.cursors
import logging
from datetime import datetime, date
 
# ── Konfiguration ────────────────────────────────────────────
DB = dict(
    host     = 'localhost',
    user     = 'vejradmin',
    password = 'y3GTiBh7mIn7fNwsscu7LhUzNZUNCa4k',   # ← ret til dit password
    database = 'vejrdata',
    cursorclass = pymysql.cursors.DictCursor,
)
LOG = '/home/kao/log/vejr_regn.log'
# ─────────────────────────────────────────────────────────────
 
logging.basicConfig(
    filename = LOG,
    level    = logging.INFO,
    format   = '%(asctime)s %(levelname)s %(message)s',
)
 
def main():
    try:
        con = pymysql.connect(**DB)
        cur = con.cursor()
    except Exception as e:
        logging.error(f"DB-forbindelsesfejl: {e}")
        return
 
    nu   = datetime.now().replace(second=0, microsecond=0)
    idag = date.today()
 
    # ── 1. Hvad er seneste kendte regn_total fra daglig-tabellen? ──
    cur.execute(
        "SELECT sidst_total FROM vejr_regn_daglig WHERE dato = %s",
        (idag,)
    )
    row = cur.fetchone()
    prev_total = float(row['sidst_total']) if row and row['sidst_total'] is not None else None
 
    # ── 2. Nyeste regn_total fra vevor i de seneste 6 min ─────────
    cur.execute("""
        SELECT regn_total
        FROM vevor
        WHERE tidspunkt >= DATE_SUB(NOW(), INTERVAL 6 MINUTE)
          AND regn_total IS NOT NULL
	  AND enhed_id = 48412
        ORDER BY tidspunkt DESC
        LIMIT 1
    """)
    ny = cur.fetchone()
 
    if ny is None:
        logging.info("Ingen vevor-måling i de seneste 6 min – springer over")
        con.close()
        return
 
    ny_total = float(ny['regn_total'])
 
    # ── 3. Beregn delta ──────────────────────────────────────────
    if prev_total is None:
        # Første kørsel i dag – vi kender ikke forrige total
        delta = 0.0
        logging.info(f"Første kørsel i dag, regn_total={ny_total:.1f} – delta sat til 0")
    elif ny_total < prev_total:
        # Beholderen er nulstillet – tæl ikke som nedbør
        delta = 0.0
        logging.info(f"Beholder-nulstilling detekteret ({prev_total:.1f} → {ny_total:.1f})")
    else:
        delta = round(ny_total - prev_total, 1)
 
    intensitet = round(delta * 12, 1)   # mm/5min → mm/h
 
    # ── 4. Indsæt i vejr_regn_interval ───────────────────────────
    cur.execute("""
        INSERT IGNORE INTO vejr_regn_interval
            (tidspunkt, delta_mm, intensitet_mmh, regn_total_fra, regn_total_til)
        VALUES (%s, %s, %s, %s, %s)
    """, (nu, delta, intensitet, prev_total, ny_total))
 
    # ── 5. Opdater vejr_regn_daglig ───────────────────────────────
    cur.execute("""
        INSERT INTO vejr_regn_daglig (dato, regn_mm, sidst_total, sidst_opdateret)
        VALUES (%s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            regn_mm         = regn_mm + VALUES(regn_mm),
            sidst_total     = VALUES(sidst_total),
            sidst_opdateret = VALUES(sidst_opdateret)
    """, (idag, delta, ny_total, nu))
 
    con.commit()
    con.close()
 
    if delta > 0:
        logging.info(f"Regn: {delta:.1f} mm ({intensitet:.1f} mm/h) – dagtotal opdateret")
    else:
        logging.debug(f"Ingen nedbør i intervallet – total={ny_total:.1f}")
 
if __name__ == '__main__':
    main()
 
 


