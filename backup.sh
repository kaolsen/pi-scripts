#!/bin/bash
# ==============================================================================
#  backup.sh — Samlet backup: MariaDB + brugere + /var/www/html + scripts
#  GFS-rotation sky:   daily=7d | weekly=56d (8 uger) | monthly=240d (8 mdr)
#  GFS-rotation lokal: daily=7d | weekly=21d (3 uger) | monthly=62d (2 mdr)
#
#  NØDKNAP — stop scriptet straks:
#    touch /data/db-backup/STOP
#  Scriptet afslutter pænt efter næste check-punkt og sletter filen selv.
# ==============================================================================

############################
# KONFIG
############################
BACKUP_DIR="/data/db-backup"
REMOTE="nextcloud:Backup"
LOGFILE="$BACKUP_DIR/backup.log"
RCLONE_LOG="$BACKUP_DIR/rclone.log"
RCLONE_CLEAN_LOG="$BACKUP_DIR/rclone-clean.log"
SLACK_WEBHOOK="https://hooks.slack.com/services/XXXXX"
MAIL_TO="kao@purpurdata.dk"
DATE=$(date +"%Y-%m-%d_%H-%M")
ERROR=0

# Kilder til fil-backup
WWW_SOURCE="/var/www/html"
SCRIPTS_SOURCE="/home/kao/scripts"

# GFS retention (dage)
RETAIN_DAILY=7
RETAIN_WEEKLY=56    # 8 uger
RETAIN_MONTHLY=240  # 8 måneder
RETAIN_LOCAL_WEEKLY=21    # 3 uger lokalt – skyen har 8
RETAIN_LOCAL_MONTHLY=62   # 2 måneder lokalt – skyen har 8

# Nødknap-fil — opret denne fil for at stoppe scriptet
STOP_FILE="$BACKUP_DIR/STOP"

# Statusfiler til sundhedsdashboardet
#   last_success: skrives KUN når hele backuppen er gået fejlfrit igennem
#   last_status:  skrives altid ved afslutning (OK / FEJL / AFBRUDT + tidspunkt)
SUCCESS_FILE="$BACKUP_DIR/last_success"
STATUS_FILE="$BACKUP_DIR/last_status"

############################
# STATS-MODUL
############################
# Modulets DB-login (bruger, password, database) hentes fra /etc/itkur/db.ini [vejradmin]
export STATS_TRIGGERED_BY="cron"
export STATS_SLACK_WEBHOOK="$SLACK_WEBHOOK"
export STATS_GROWTH_THRESHOLD="20"
source /home/kao/scripts/backup_stats.sh

############################
# MYSQL-LOGIN FRA db.ini
############################
# mysql/mysqldump får login fra /etc/itkur/db.ini [drift] via en midlertidig
# fil. --defaults-file gør, at ~/.my.cnf IKKE læses, så der kun er én kilde.
DUMP_PROFILE="drift"
DB_INI="/etc/itkur/db.ini"
MYCNF=$(mktemp)            # oprettes med rettigheder 600
trap 'rm -f "$MYCNF"' EXIT # fjern login-filen uanset hvordan scriptet slutter
{
    echo "[client]"
    echo "host=$(_ini_get "$DB_INI" "$DUMP_PROFILE" host)"
    echo "user=$(_ini_get "$DB_INI" "$DUMP_PROFILE" user)"
    echo "password=\"$(_ini_get "$DB_INI" "$DUMP_PROFILE" password)\""
} > "$MYCNF"

############################
# GFS: BESTEM BACKUP-TYPE
# Lørdag        = weekly
# Sidste lørdag = monthly
############################
DOW=$(date +%u)           # 1=man … 6=lør … 7=søn
DOM=$(date +%d)
LAST_DOM=$(date -d "$(date +%Y-%m-01) +1 month -1 day" +%d)
DAYS_LEFT=$(( LAST_DOM - DOM ))

if   [ "$DOW" -eq 6 ] && [ "$DAYS_LEFT" -lt 7 ]; then
    BACKUP_TYPE="monthly"
elif [ "$DOW" -eq 6 ]; then
    BACKUP_TYPE="weekly"
else
    BACKUP_TYPE="daily"
fi

############################
# FUNKTIONER
############################
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOGFILE"
}

fail() {
    log "ERROR: $1"
    # Aktiver ved behov:
    # curl -s -X POST -H 'Content-type: application/json' \
    #   --data "{\"text\":\"🚨 Backup FEJL på $(hostname): $1\"}" \
    #   "$SLACK_WEBHOOK" > /dev/null
    # echo "$1" | mail -s "🚨 Backup FEJL på $(hostname)" "$MAIL_TO"
    ERROR=1
}

# ── NØDKNAP ──────────────────────────────────────────────────────────────────
check_stop() {
    if [ -f "$STOP_FILE" ]; then
        log "🛑 NØDKNAP aktiveret — $STOP_FILE fundet. Stopper backup."
        rm -f "$STOP_FILE"
        stats_end_run 2>/dev/null
        rm -f "$MYCNF"
        log "=== Backup afbrudt via nødknap ==="
        echo "AFBRUDT $(date '+%Y-%m-%d %H:%M:%S')" > "$STATUS_FILE"
        exit 1
    fi
}

# Slet lokale filer ældre end $1 dage der matcher mønster $2
clean_local() {
    local days="$1"
    local pattern="$2"
    find "$BACKUP_DIR" -name "$pattern" -mtime +"$days" -delete
}

# Slet cloud-filer ældre end $1 dage der matcher mønster $2
clean_cloud() {
    local age="$1"
    local pattern="$2"

    log "Cloud-oprydning: $pattern (ældre end ${age} dage)"
    rclone delete "$REMOTE" \
        --min-age "${age}d" \
        --include "$pattern" \
        --log-file="$RCLONE_CLEAN_LOG" \
        --log-level INFO \
        2>&1 | tee -a "$LOGFILE"

    RCLONE_EXIT=${PIPESTATUS[0]}
    if [ $RCLONE_EXIT -ne 0 ]; then
        fail "Cloud-oprydning fejlede for mønster: $pattern (exit kode: $RCLONE_EXIT)"
        log "--- Rclone clean fejlbesked ---"
        tail -10 "$RCLONE_CLEAN_LOG" | tee -a "$LOGFILE"
        log "--- Rclone clean fejl slut ---"
    else
        log "✓ Cloud-oprydning OK: $pattern"
    fi
}

############################
# START
############################
mkdir -p "$BACKUP_DIR"
log "=== Backup startet — type: $BACKUP_TYPE ==="
log "    (Nødknap: touch $STOP_FILE)"
stats_begin_run

check_stop  # ── check 1

############################
# BACKUP: /var/www/html
# Laver én zip-fil per undermappe
############################
log "--- Filbackup: $WWW_SOURCE ---"
if [ ! -d "$WWW_SOURCE" ]; then
    fail "Kildemappe findes ikke: $WWW_SOURCE"
else
    for DIR in "$WWW_SOURCE"/*/; do
        DIRNAME=$(basename "$DIR")
        WWW_FILE="$BACKUP_DIR/www_${DIRNAME}_${BACKUP_TYPE}_${DATE}.tar.gz"
        tar -czf "$WWW_FILE" -C "$WWW_SOURCE" "$DIRNAME" 2>>"$LOGFILE"
        if [ $? -eq 0 ]; then
            SIZE=$(du -h "$WWW_FILE" | cut -f1)
            log "✓ www-backup oprettet: $(basename "$WWW_FILE") ($SIZE)"
            chmod 640 "$WWW_FILE"
        else
            fail "tar fejlede for $DIR"
        fi
    done
fi

check_stop  # ── check 2

############################
# BACKUP: /home/kao/scripts
############################
log "--- Filbackup: $SCRIPTS_SOURCE ---"
if [ ! -d "$SCRIPTS_SOURCE" ]; then
    fail "Kildemappe findes ikke: $SCRIPTS_SOURCE"
else
    SCRIPTS_FILE="$BACKUP_DIR/scripts_${BACKUP_TYPE}_${DATE}.tar.gz"
    tar -czf "$SCRIPTS_FILE" -C /home/kao scripts 2>>"$LOGFILE"
    if [ $? -eq 0 ]; then
        SIZE=$(du -h "$SCRIPTS_FILE" | cut -f1)
        log "✓ scripts-backup oprettet: $(basename "$SCRIPTS_FILE") ($SIZE)"
        chmod 640 "$SCRIPTS_FILE"
    else
        fail "tar fejlede for $SCRIPTS_SOURCE"
    fi
fi

check_stop  # ── check 3

############################
# BACKUP: MariaDB databaser
############################
log "--- Database-backup ---"
ALL_DATABASES=$(mysql --defaults-file="$MYCNF" -N -e "SHOW DATABASES;" 2>>"$LOGFILE")
if [ $? -ne 0 ]; then
    fail "Kunne ikke hente databaser fra MariaDB"
    ALL_DATABASES=""
fi
# Kun præcise systemnavne udelukkes (-x), så fx "fugle_system" ikke springes over
DATABASES=$(printf '%s\n' "$ALL_DATABASES" \
    | grep -Exv "(information_schema|performance_schema|mysql|sys)" | grep -v '^$')

for DB in $DATABASES; do
    check_stop  # ── check per database

    log "Backupper DB: $DB [$BACKUP_TYPE]"
    DB_FILE="$BACKUP_DIR/${DB}_${BACKUP_TYPE}_${DATE}.sql"
    mysqldump --defaults-file="$MYCNF" --single-transaction --routines --triggers "$DB" > "$DB_FILE"
    if [ $? -ne 0 ]; then
        fail "mysqldump fejlede for $DB"
        rm -f "$DB_FILE"
        continue
    fi

    gzip "$DB_FILE"
    if [ $? -ne 0 ]; then
        fail "gzip fejlede for $DB"
        stats_record_db "$DB"
        continue
    fi

    stats_record_db "$DB" "${DB_FILE}.gz"
done

############################
# BACKUP: MariaDB brugere og rettigheder
############################
# CREATE USER + GRANT for alle brugere. Passwords er kun med som hash, ikke klartekst.
# Restore: mysql < users_....sql  – og sæt derefter nye passwords med ALTER USER.
log "Backupper brugere og rettigheder [$BACKUP_TYPE]"
USERS_FILE="$BACKUP_DIR/users_${BACKUP_TYPE}_${DATE}.sql"
mysqldump --defaults-file="$MYCNF" --system=users > "$USERS_FILE" 2>>"$LOGFILE"
if [ $? -ne 0 ]; then
    fail "Bruger-dump fejlede (har '$DUMP_PROFILE' læseret på mysql-databasen?)"
    rm -f "$USERS_FILE"
else
    gzip "$USERS_FILE" && chmod 600 "${USERS_FILE}.gz"
    log "✓ Bruger-dump oprettet: $(basename "${USERS_FILE}.gz")"
fi

check_stop  # ── check 4

############################
# UPLOAD TIL CLOUD
############################
log "--- Upload til Nextcloud ---"

rclone copy "$BACKUP_DIR" "$REMOTE" \
    --include "*.gz" \
    --log-file="$RCLONE_LOG" \
    --log-level INFO \
    --stats 30s \
    --stats-log-level INFO \
    2>&1 | tee -a "$LOGFILE"

RCLONE_EXIT=${PIPESTATUS[0]}
if [ $RCLONE_EXIT -ne 0 ]; then
    fail "Upload til Nextcloud fejlede (exit kode: $RCLONE_EXIT)"
    log "--- Rclone fejlbesked ---"
    tail -20 "$RCLONE_LOG" | tee -a "$LOGFILE"
    log "--- Rclone fejl slut ---"
else
    log "✓ Upload til Nextcloud OK"
fi

check_stop  # ── check 5

############################
# OPRYDNING LOKALT (GFS)
############################
log "--- Lokal oprydning (GFS) ---"

# Alle backupfiler (*.sql.gz og *.tar.gz): db, brugere, www og scripts
for EXT in sql.gz tar.gz; do
    clean_local "$RETAIN_DAILY"         "*_daily_*.$EXT"
    clean_local "$RETAIN_LOCAL_WEEKLY"  "*_weekly_*.$EXT"
    clean_local "$RETAIN_LOCAL_MONTHLY" "*_monthly_*.$EXT"
done

############################
# OPRYDNING CLOUD (GFS)
############################
log "--- Cloud-oprydning (GFS) ---"

# Alle backupfiler (*.sql.gz og *.tar.gz): db, brugere, www og scripts
for EXT in sql.gz tar.gz; do
    clean_cloud "$RETAIN_DAILY"   "*_daily_*.$EXT"
    clean_cloud "$RETAIN_WEEKLY"  "*_weekly_*.$EXT"
    clean_cloud "$RETAIN_MONTHLY" "*_monthly_*.$EXT"
done

############################
# SLUT
############################
stats_end_run
rm -f "$MYCNF"

if [ $ERROR -eq 0 ]; then
    date '+%Y-%m-%d %H:%M:%S' > "$SUCCESS_FILE"
    echo "OK $(date '+%Y-%m-%d %H:%M:%S')" > "$STATUS_FILE"
    log "=== Backup færdig OK ==="
else
    echo "FEJL $(date '+%Y-%m-%d %H:%M:%S')" > "$STATUS_FILE"
    log "=== Backup afsluttet med FEJL ==="
fi

exit $ERROR

