#!/bin/bash
# Sauvegarde hebdomadaire de TOUTES les bases accessibles à l'utilisateur de /home/pi/.my-backup.cnf
# (remplace mariadb-backup, non installé). Un dump gzip par exécution ; échec = fichier supprimé + code 1.
set -u
LOG="${LOG:-/var/log/backup/mariadb.log}"
CNF="${CNF:-/home/pi/.my-backup.cnf}"
DIR="${DIR:-/mnt/backup_hdd/mariadb/weekly_full}"
FILE="$DIR/bases_$(date +"%Y-%m-%d_%H-%M").sql.gz"
START=$(date +%s)

echo "[MARIADB] Début : $(date)" > "$LOG"
mkdir -p "$DIR"

DBS=$(mysql --defaults-extra-file="$CNF" -N -B -e "SHOW DATABASES" 2>> "$LOG" \
      | grep -Ev '^(information_schema|performance_schema|mysql|sys)$' | tr '\n' ' ')
if [ -z "${DBS// /}" ]; then
    echo "[MARIADB] ERREUR : aucune base accessible" | tee -a "$LOG"
    exit 1
fi

# pipefail : l'échec de mysqldump n'est pas masqué par gzip
set -o pipefail
if ! mysqldump --defaults-extra-file="$CNF" --single-transaction --routines --triggers --databases $DBS 2>> "$LOG" | gzip > "$FILE"; then
    echo "[MARIADB] ERREUR : mysqldump a échoué" | tee -a "$LOG"
    rm -f "$FILE"
    exit 1
fi

if ! zcat "$FILE" | tail -n 3 | grep -q "Dump completed"; then
    echo "[MARIADB] ERREUR : dump vide ou incomplet" | tee -a "$LOG"
    rm -f "$FILE"
    exit 1
fi

echo "[MARIADB] OK : $FILE ($(stat -c %s "$FILE") octets) — bases : $DBS" | tee -a "$LOG"
echo "[MARIADB] Durée : $(( $(date +%s) - START ))s" >> "$LOG"
