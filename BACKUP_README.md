# 🔄 Automatisk WWW Backup System

Daglig backup af /var/www/html med ugentlig sync til Nextcloud.

## 🎯 Features

- ✅ **Daglig automatisk backup** af /var/www/html
- ✅ **Komprimerede filer** (.tar.gz) med tidsstempel
- ✅ **Automatisk cleanup** - sletter backups ældre end 30 dage
- ✅ **Ugentlig upload** til Nextcloud via WebDAV eller rclone
- ✅ **Komplet logging** af alle operationer
- ✅ **Nem gendan** fra enhver backup

## 📁 Filer

1. `www_backup.sh` - Dagligt backup script
2. `nextcloud_upload.sh` - Upload til Nextcloud via curl/WebDAV
3. `nextcloud_upload_rclone.sh` - Upload til Nextcloud via rclone (anbefalet)
4. `BACKUP_INSTALLATION.md` - Detaljeret installationsguide

## ⚡ Hurtig start

### 1. Opret backup directory
```bash
sudo mkdir -p /data/backup
```

### 2. Installer backup script
```bash
sudo cp www_backup.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/www_backup.sh
```

### 3. Test backup
```bash
sudo /usr/local/bin/www_backup.sh
```

### 4. Opsæt daglig cron
```bash
sudo crontab -e
```
Tilføj:
```
0 2 * * * /usr/local/bin/www_backup.sh >> /var/log/www_backup.log 2>&1
```

### 5. Vælg Nextcloud metode

**METODE A: WebDAV med curl** (simpel)
- Kræver kun curl (standard installeret)
- Se `BACKUP_INSTALLATION.md` for opsætning

**METODE B: rclone** (anbefalet)
- Mere robust og funktionsrig
- Installer: `curl https://rclone.org/install.sh | sudo bash`
- Konfigurer: `rclone config`
- Se `BACKUP_INSTALLATION.md` for detaljer

## 📊 Backup navngivning

Filer gemmes som:
```
www_backup_20240205_140530.tar.gz
           ÅÅÅÅMMDD_TTMMSS
```

Eksempler:
- `www_backup_20240205_020000.tar.gz` - 5. feb 2024 kl. 02:00
- `www_backup_20240212_020000.tar.gz` - 12. feb 2024 kl. 02:00

## 🔄 Backup proces

```
┌─────────────────┐
│  /var/www/html  │
└────────┬────────┘
         │ Dagligt kl. 02:00
         ▼
┌─────────────────────────────────┐
│  /data/backup/                  │
│  www_backup_ÅÅÅÅMMDD_TTMMSS.gz  │
└────────┬────────────────────────┘
         │ Ugentligt søndag kl. 04:00
         ▼
┌─────────────────┐
│   Nextcloud     │
│   /Backups/     │
└─────────────────┘
```

## 📅 Standard tidsplan

| Handling | Tidspunkt | Hyppighed |
|----------|-----------|-----------|
| Lokal backup | 02:00 | Dagligt |
| Nextcloud upload | 04:00 | Ugentligt (søndag) |
| Cleanup gamle filer | Automatisk | Ved hver backup |

## 💾 Disk forbrug

Med daglige backups i 30 dage:
- Typisk backup størrelse: 10-50 MB (afhænger af dit website)
- 30 dage × 20 MB = ~600 MB
- Nextcloud: ~80 MB (behold kun 4 seneste)

## 🔧 Almindelige kommandoer

### Se alle backups
```bash
ls -lh /data/backup/
```

### Tjek seneste backup
```bash
ls -lht /data/backup/ | head -2
```

### Se backup log
```bash
tail -f /var/log/www_backup.log
```

### Manuel backup nu
```bash
sudo /usr/local/bin/www_backup.sh
```

### Gendan fra backup
```bash
sudo systemctl stop apache2
sudo tar -xzf /data/backup/www_backup_ÅÅÅÅMMDD_TTMMSS.tar.gz -C /var/www/
sudo systemctl start apache2
```

## ⚙️ Konfiguration

### Skift retention periode

Rediger `/usr/local/bin/www_backup.sh`:
```bash
RETENTION_DAYS=30  # Ændr til 60, 90, etc.
```

### Skift backup tidspunkt

Rediger crontab:
```bash
sudo crontab -e
```

Eksempler:
- `0 2 * * *` = Kl. 02:00 dagligt
- `0 3 * * *` = Kl. 03:00 dagligt  
- `30 1 * * *` = Kl. 01:30 dagligt

### Skift Nextcloud upload dag

```bash
0 4 * * 0  # Søndag
0 4 * * 1  # Mandag
0 4 * * 6  # Lørdag
```

## 📈 Integration med disk overvågning

Backup directory'et kan tilføjes til disk overvågning:

Rediger `disk_monitor.sh`:
```bash
DIRECTORIES=(
    "/data/db-backup"
    "/var/www"
    "/home/kao"
    "/var/lib/mysql"
    "/data/backup"  # <-- Tilføj denne linje
)
```

## 🆘 Nødstilfælde - Quick restore

Hvis dit website går ned:

```bash
# 1. Find seneste backup
BACKUP=$(ls -t /data/backup/www_backup_*.tar.gz | head -1)

# 2. Stop Apache
sudo systemctl stop apache2

# 3. Backup nuværende (bare i tilfælde)
sudo mv /var/www/html /var/www/html.broken

# 4. Gendan fra backup
sudo tar -xzf $BACKUP -C /var/www/

# 5. Start Apache
sudo systemctl start apache2

# 6. Test website
curl http://localhost
```

## 🔐 Sikkerhed

- Backup filer har permissions: `640` (kun root kan læse)
- Nextcloud App Password bruges (ikke hovedkodeord)
- Logs indeholder ikke passwords
- WebDAV forbindelse via HTTPS

## ✅ Tjekliste efter installation

- [ ] Backup directory oprettet (`/data/backup`)
- [ ] Backup script installeret og eksekverbart
- [ ] Første backup kørt succesfuldt
- [ ] Crontab konfigureret for daglig backup
- [ ] Nextcloud forbindelse testet
- [ ] Upload script installeret (curl eller rclone)
- [ ] Crontab konfigureret for ugentlig upload
- [ ] Logs er tilgængelige og viser ingen fejl

## 📚 Yderligere dokumentation

Se `BACKUP_INSTALLATION.md` for:
- Detaljeret installations trin-for-trin guide
- Nextcloud WebDAV opsætning
- rclone konfiguration
- Troubleshooting
- Avancerede use cases

---

**Automatisk backup system til Raspberry Pi** 🥧💾
