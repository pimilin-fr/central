#!/bin/bash
# À lancer UNE fois sur le Pi, depuis /var/www/central après avoir dézippé : sudo bash pi/installer-suivi.sh
# Prépare var/jobs (le web peut y poser des demandes), installe le nouveau backup-mariadb.sh (ancien conservé en .ancien),
# corrige logrotate. NE TOUCHE PAS au crontab (voir pi/crontab-pi.txt).
set -e
cd "$(dirname "$0")/.."
install -d -m 2775 -o pi -g www-data var/jobs var/jobs/state var/jobs/history var/jobs/logs var/jobs/requests var/jobs/locks
chmod +x bin/jobs
[ -f /usr/local/bin/backup-mariadb.sh ] && cp -n /usr/local/bin/backup-mariadb.sh /usr/local/bin/backup-mariadb.sh.ancien || true
install -m 755 -o root -g root pi/backup-mariadb.sh /usr/local/bin/backup-mariadb.sh
[ -f /etc/logrotate.d/backup ] && sed -i 's/create 640 root root/create 664 pi pi/' /etc/logrotate.d/backup || true
touch /var/log/backup/jobs.log /var/log/backup/mariadb.log
chown pi:pi /var/log/backup/jobs.log /var/log/backup/mariadb.log
chmod 664 /var/log/backup/jobs.log /var/log/backup/mariadb.log
echo "OK. Reste : crontab -e (utilisateur pi) avec pi/crontab-pi.txt, puis cache:clear."
