#!/bin/bash
# Sauvegarde SQL quotidienne de la base « central ».
# Identifiants lus dans /home/pi/.my-backup.cnf (droits 600) : plus aucun mot de passe dans ce script.
# Un dump vide ou incomplet est supprimé et signalé en ERREUR (code de sortie 1) : plus de fichier de 0 octet.
set -u
LOG="${LOG:-/var/log/backup/sql.log}"
CNF="${CNF:-/home/pi/.my-backup.cnf}"
DIR="${DIR:-/mnt/backup_hdd/mariadb/daily_sql}"
DB="${DB:-central}"
FILE="$DIR/central_$(date +"%Y-%m-%d_%H-%M").sql"
START=$(date +%s)

echo "[SQL] Début : $(date)" > "$LOG"
mkdir -p "$DIR"

if ! mysqldump --defaults-extra-file="$CNF" --single-transaction --routines --triggers "$DB" > "$FILE" 2>> "$LOG"; then
    echo "[SQL] ERREUR : mysqldump a échoué" | tee -a "$LOG"
    rm -f "$FILE"
    exit 1
fi

# Garde-fou : un dump complet se termine par « Dump completed »
if ! tail -n 3 "$FILE" | grep -q "Dump completed"; then
    echo "[SQL] ERREUR : dump vide ou incomplet" | tee -a "$LOG"
    rm -f "$FILE"
    exit 1
fi

echo "[SQL] OK : $FILE ($(stat -c %s "$FILE") octets)" | tee -a "$LOG"
echo "[SQL] Durée : $(( $(date +%s) - START ))s" >> "$LOG"
echo "[SQL] Fin : $(date)" >> "$LOG"
