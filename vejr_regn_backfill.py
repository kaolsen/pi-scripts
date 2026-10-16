#!/usr/bin/env python3
"""
vejr_regn_backfill.py
Engangs-kørsel: genberegner alle historiske regndata fra vevor.regn_total
og fylder vejr_regn_interval og vejr_regn_daglig.

Kør én gang:
    python3 vejr_regn_backfill.py

Scriptet er idempotent (INSERT IGNORE / ON DUPLICATE KEY), så det er
sikkert at køre igen – eksisterende rækker overskrives ikke.
"""

import pymysql
import pymysql.cursors
import logging
from datetime import datetime, date, timedelta
from collections import defaultdict

# ── Konfiguration ────────────────────────────────────────────
DB = dict(
    host        = 'localhost',
    user        = 'vejradmin',
    password    = 'y3GTiBh7mIn7fNwsscu7LhUzNZUNCa4k',   # ← ret
    database    = 'vejrdata',
    cursorclass = pymysql.cursors.DictCursor,
)
LOG = '/home/kao/log/vejr_regn.log'
# ─────────────────────────────────────────────────────────────

logging.basicConfig(
    filename = LOG,
    level    = logging.INFO,
    format   = '%(asctime)s %(levelname)s %(message)s',
)

def bucket5(dt):
    """Rund datetime ned til nærmeste 5 minutter."""
    return dt.replace(second=0, microsecond=0,
                      minute=(dt.minute // 5) * 5)

def main():
    con = pymysql.connect(**DB)
    cur = con.cursor()

    # Hent alle vevor-rækker med regn_total i kronologisk orden
    cur.execute("""
        SELECT tidspunkt, regn_total
        FROM vevor
        WHERE regn_total IS NOT NULL
        ORDER BY tidspunkt ASC
    """)
    rows = cur.fetchall()
    print(f"Fandt {len(rows)} vevor-rækker med regn_total")

    if not rows:
        print("Ingen data – afslutter.")
        con.close()
        return

    # ── Beregn deltas og akkumuler per 5-min bucket ──────────
    # bucket_data[tidspunkt] = {delta_mm, fra, til, dato}
    bucket_data   = {}
    # daglig_data[dato] = {regn_mm, sidst_total}
    daglig_data   = {}

    prev_total   = None
    prev_bucket  = None

    for r in rows:
        ts    = r['tidspunkt']       # datetime-objekt
        total = float(r['regn_total'])
        bkt   = bucket5(ts)
        dag   = ts.date()

        # Første observation overhovedet
        if prev_total is None:
            prev_total  = total
            prev_bucket = bkt
            # Initiér bucket uden delta
            if bkt not in bucket_data:
                bucket_data[bkt] = {'delta_mm': 0.0, 'fra': total, 'til': total, 'dato': dag}
            continue

        # Beregn delta (ignorer nulstillinger og urealistiske spring)
        if total < prev_total:
            delta = 0.0
            logging.info(f"Nulstilling: {prev_total:.1f} → {total:.1f} ved {ts}")
        else:
            delta = round(total - prev_total, 1)
            if delta > 100:
                # Mere end 100 mm på ét interval er fysisk umuligt → sensor-fejl/udskiftning
                print(f"  ⚠ Spring ignoreret: {prev_total:.1f} → {total:.1f} ({delta:.1f} mm) ved {ts}")
                logging.warning(f"Spring ignoreret: {prev_total:.1f} → {total:.1f} ({delta:.1f} mm) ved {ts}")
                delta = 0.0

        # Initiér bucket hvis ny
        if bkt not in bucket_data:
            bucket_data[bkt] = {'delta_mm': 0.0, 'fra': prev_total, 'til': total, 'dato': dag}

        bucket_data[bkt]['delta_mm'] = round(bucket_data[bkt]['delta_mm'] + delta, 1)
        bucket_data[bkt]['til']      = total

        # Dagstotal
        if dag not in daglig_data:
            daglig_data[dag] = {'regn_mm': 0.0, 'sidst_total': total}
        daglig_data[dag]['regn_mm']    = round(daglig_data[dag]['regn_mm'] + delta, 1)
        daglig_data[dag]['sidst_total'] = total

        prev_total  = total
        prev_bucket = bkt

    print(f"Beregnede {len(bucket_data)} 5-min buckets over {len(daglig_data)} dage")

    # ── Indsæt vejr_regn_interval ────────────────────────────
    interval_rows = 0
    for bkt in sorted(bucket_data.keys()):
        d = bucket_data[bkt]
        intensitet = round(d['delta_mm'] * 12, 1)
        cur.execute("""
            INSERT IGNORE INTO vejr_regn_interval
                (tidspunkt, delta_mm, intensitet_mmh, regn_total_fra, regn_total_til)
            VALUES (%s, %s, %s, %s, %s)
        """, (bkt, d['delta_mm'], intensitet, d['fra'], d['til']))
        interval_rows += 1

    # ── Indsæt vejr_regn_daglig ──────────────────────────────
    daglig_rows = 0
    for dag in sorted(daglig_data.keys()):
        d = daglig_data[dag]
        cur.execute("""
            INSERT INTO vejr_regn_daglig (dato, regn_mm, sidst_total, sidst_opdateret)
            VALUES (%s, %s, %s, NOW())
            ON DUPLICATE KEY UPDATE
                regn_mm         = VALUES(regn_mm),
                sidst_total     = VALUES(sidst_total),
                sidst_opdateret = NOW()
        """, (dag, d['regn_mm'], d['sidst_total']))
        daglig_rows += 1

    con.commit()
    con.close()

    print(f"✅ Indsat {interval_rows} interval-rækker og {daglig_rows} daglige totaler")
    logging.info(f"Backfill færdig: {interval_rows} intervaller, {daglig_rows} dage")

if __name__ == '__main__':
    main()
