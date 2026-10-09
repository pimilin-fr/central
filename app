#!/usr/bin/env bash
#
# ./app — bascule prod / démo (la base démo est jetable, la prod n'est jamais modifiée)
#
#   ./app demo             bascule sur la démo (déjà construite) — ne copie RIEN
#   ./app prod             revient à la production (reprend simplement la connexion)
#   ./app build [options]  (re)construit la démo : sauvegarde la prod, copie + anonymise. Ne bascule pas.
#   ./app status           indique la base utilisée
#   ./app backup           sauvegarde de la prod (var/backups/)
#   ./app doctor           teste les connexions prod/démo et affiche l'erreur exacte
#   ./app help
#
# Configuration (.env ou .env.local, valeurs entre guillemets) :
#   DATABASE_PROD_URL="mysql://user:pass@127.0.0.1:3306/central?serverVersion=mariadb-11.8.3"
#   DATABASE_DEMO_URL="mysql://user:pass@127.0.0.1:3306/central_demo?serverVersion=mariadb-11.8.3"
#   (facultatif) DATABASE_PROD_RO_URL=  un utilisateur MySQL en LECTURE SEULE sur la prod, utilisé pour la copie
#   (facultatif) DEMO_BUILD_OPTS="--shift=auto --jitter=15 --strip-notes"
# La base utilisée est mémorisée dans var/db_mode (« prod » ou « demo ») : lu à chaque requête, aucun cache:clear.
# Supprimer var/db_mode = retour à la configuration du .env (la prod).
#
set -Eeuo pipefail
umask 002   # fichiers créés lisibles/modifiables par le groupe (toi + le serveur web)
cd "$(dirname "$0")"

ENV_FILE=".env.local"
MODE_FILE="var/db_mode"
STATUS_FILE="var/demo-build.status"
PHP="${PHP_BIN:-php}"
LOCK_DIR="var/demo.lock"
BACKUP_DIR="var/backups"
KEEP_BACKUPS=10

red()   { printf '\033[31m%s\033[0m\n' "$*" >&2; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
info()  { printf '\033[36m▶ %s\033[0m\n' "$*"; }
die()   { red "✗ $*"; exit 1; }

# ---------------------------------------------------------------- lecture de la configuration

# Valeur d'une variable d'environnement telle que Symfony la voit (.env, .env.local, ${VAR} résolus).
env_get() {
    "$PHP" -r '
        require "vendor/autoload.php";
        (new Symfony\Component\Dotenv\Dotenv())->loadEnv(".env");
        echo $_ENV[$argv[1]] ?? ($_SERVER[$argv[1]] ?? "");
    ' -- "$1"
}

# Nom de la base d'une URL (sans le mot de passe).
db_name() { "$PHP" -r '$u = parse_url($argv[1]); echo ltrim($u["path"] ?? "", "/");' -- "$1"; }
db_label() { "$PHP" -r '$u = parse_url($argv[1]); printf("%s@%s:%s/%s", $u["user"] ?? "?", $u["host"] ?? "?", $u["port"] ?? 3306, ltrim($u["path"] ?? "", "/"));' -- "$1"; }

load_config() {
    [ -f "bin/console" ] || die "Lancez ce script à la racine du projet (bin/console introuvable)."
    [ -f "$ENV_FILE" ] || : > "$ENV_FILE"
    PROD_URL="$(env_get DATABASE_PROD_URL)"
    DEMO_URL="$(env_get DATABASE_DEMO_URL)"
    RO_URL="$(env_get DATABASE_PROD_RO_URL)"
    BUILD_OPTS="$(env_get DEMO_BUILD_OPTS)"
    BUILD_OPTS="${BUILD_OPTS:---shift=auto --jitter=15 --strip-notes}"

    [ -n "$PROD_URL" ] || die "DATABASE_PROD_URL manquante (.env ou $ENV_FILE)."
    [ -n "$DEMO_URL" ] || die "DATABASE_DEMO_URL manquante (.env ou $ENV_FILE)."
    [ "$PROD_URL" != "$DEMO_URL" ] || die "DATABASE_PROD_URL et DATABASE_DEMO_URL sont identiques : refus."

    local prod_db demo_db
    prod_db="$(db_name "$PROD_URL")"; demo_db="$(db_name "$DEMO_URL")"
    [ -n "$demo_db" ] && [ "$prod_db" != "$demo_db" ] || die "La base démo ($demo_db) doit être différente de la prod ($prod_db)."
    case "$(printf '%s' "$demo_db" | tr '[:upper:]' '[:lower:]')" in
        *demo*) ;;
        *) die "Par précaution, le nom de la base démo « $demo_db » doit contenir « demo »." ;;
    esac
    SOURCE_URL="${RO_URL:-$PROD_URL}"
}

# ---------------------------------------------------------------- bascule (.env.local)

# À la sortie de cmd_build : libère le verrou et, si l'état est resté « running » (abandon), restaure l'état précédent.
build_cleanup() {
    local prev="$1"
    rmdir "$LOCK_DIR" 2>/dev/null || true
    if [ "$({ cut -d'|' -f1 < "$STATUS_FILE"; } 2>/dev/null)" = "running" ]; then
        if [ -n "$prev" ]; then
            local tmp; tmp="$(mktemp "$STATUS_FILE.XXXXXX")"
            printf '%s\n' "$prev" > "$tmp"; chmod 664 "$tmp" 2>/dev/null || true; mv -f "$tmp" "$STATUS_FILE"
        else
            rm -f "$STATUS_FILE"
        fi
    fi
}

# Nettoie d'anciennes versions de ce script : DATABASE_URL / DEMO_DATABASE_URL / APP_DB_MODE ne sont plus
# écrites dans .env.local (le mode vit dans var/db_mode). Sans ce nettoyage, une ancienne ligne forcerait la démo.
migrate_env_local() {
    [ -f "$ENV_FILE" ] || return 0
    grep -Eq '^(DATABASE_URL|DEMO_DATABASE_URL|APP_DB_MODE)=' "$ENV_FILE" || return 0
    cp "$ENV_FILE" "$ENV_FILE.bak"
    local tmp; tmp="$(mktemp "$ENV_FILE.XXXXXX")"
    grep -Ev '^(DATABASE_URL|DEMO_DATABASE_URL|APP_DB_MODE)=' "$ENV_FILE" > "$tmp" || true
    chmod --reference="$ENV_FILE" "$tmp" 2>/dev/null || true
    mv "$tmp" "$ENV_FILE"
    info "$ENV_FILE nettoyé (ancien réglage de bascule retiré ; copie dans $ENV_FILE.bak)"
}

# Mémorise l'environnement (écriture atomique) : lu par le Kernel à chaque démarrage.
set_mode() {
    local mode="$1" tmp
    case "$mode" in prod|demo) ;; *) die "mode inconnu: $mode" ;; esac
    mkdir -p var
    tmp="$(mktemp "$MODE_FILE.XXXXXX")"
    printf '%s\n' "$mode" > "$tmp"
    chmod 664 "$tmp" 2>/dev/null || true   # le serveur web (menu de l'appli) doit pouvoir le réécrire
    mv "$tmp" "$MODE_FILE"
}

current_mode() {
    local m
    m="$({ tr -d '\r\n "' < "$MODE_FILE"; } 2>/dev/null || true)"
    case "$m" in demo) echo demo ;; *) echo prod ;; esac
}

# La démo est-elle construite ? (au moins une table dans la base démo)
demo_ready() {
    "$PHP" -r '
        $u = parse_url($argv[1]); parse_str($u["query"] ?? "", $q);
        $dsn = sprintf("mysql:host=%s;port=%d;dbname=%s;charset=%s", $u["host"] ?? "127.0.0.1", $u["port"] ?? 3306, ltrim($u["path"] ?? "", "/"), $q["charset"] ?? "utf8mb4");
        try { $p = new PDO($dsn, rawurldecode($u["user"] ?? ""), rawurldecode($u["pass"] ?? ""), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
              exit((int) $p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn() > 0 ? 0 : 1); }
        catch (Throwable $e) { exit(1); }
    ' -- "$DEMO_URL"
}

write_build_status() {
    mkdir -p var
    local tmp; tmp="$(mktemp "$STATUS_FILE.XXXXXX")"
    printf '%s|%s\n' "$1" "$(date '+%Y-%m-%d %H:%M')" > "$tmp"
    chmod 664 "$tmp" 2>/dev/null || true
    mv -f "$tmp" "$STATUS_FILE"   # rename : fonctionne même si l'ancien fichier appartient à un autre utilisateur
}

clear_cache() {
    "$PHP" bin/console cache:clear --no-warmup -q || red "⚠ cache:clear a échoué : relancez « php bin/console cache:clear »."
    "$PHP" bin/console cache:warmup -q 2>/dev/null || true
}

# ---------------------------------------------------------------- sauvegarde de la prod

backup_prod() {
    command -v mysqldump >/dev/null || return 2
    mkdir -p "$BACKUP_DIR" 2>/dev/null || true
    if [ ! -w "$BACKUP_DIR" ]; then
        red "✗ $BACKUP_DIR n'est pas modifiable par l'utilisateur « $(id -un) »."
        red "  Une fois, en administrateur :  sudo chgrp -R www-data $BACKUP_DIR && sudo chmod -R g+rwX $BACKUP_DIR && sudo chmod g+s $BACKUP_DIR"
        return 1
    fi
    local file="$BACKUP_DIR/prod-$(date +%Y%m%d-%H%M%S).sql.gz"
    local parts host port user pass name
    mapfile -t parts < <("$PHP" -r '$u = parse_url($argv[1]); echo implode("\n", [$u["host"] ?? "127.0.0.1", $u["port"] ?? 3306, rawurldecode($u["user"] ?? ""), rawurldecode($u["pass"] ?? ""), ltrim($u["path"] ?? "", "/")]);' -- "$SOURCE_URL")
    host="${parts[0]}"; port="${parts[1]}"; user="${parts[2]}"; pass="${parts[3]}"; name="${parts[4]}"

    info "Sauvegarde de « $name » → $file"
    if ! MYSQL_PWD="$pass" mysqldump -h "$host" -P "$port" -u "$user" \
            --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 \
            "$name" | gzip > "$file"; then
        rm -f "$file"; return 1
    fi
    gzip -t "$file" && [ "$(wc -c < "$file")" -gt 200 ] || { rm -f "$file"; return 1; }
    green "  sauvegarde OK ($(du -h "$file" | cut -f1))"

    # ne garder que les N dernières
    ls -1t "$BACKUP_DIR"/prod-*.sql.gz 2>/dev/null | tail -n +"$((KEEP_BACKUPS + 1))" | xargs -r rm -f
}

# ---------------------------------------------------------------- verrou (un seul ./app demo à la fois)

acquire_lock() {
    mkdir -p var
    mkdir "$LOCK_DIR" 2>/dev/null || die "Une construction de la démo est déjà en cours ($LOCK_DIR). Si ce n'est pas le cas : rmdir $LOCK_DIR"
    trap 'rmdir "$LOCK_DIR" 2>/dev/null || true' EXIT
}

release_lock() { rmdir "$LOCK_DIR" 2>/dev/null || true; }

# ---------------------------------------------------------------- commandes

# Construit la démo (structure + données). Ne change PAS d'environnement.
cmd_build() {
    printf '\n=== ./app build — %s (utilisateur %s, php %s) ===\n' "$(date '+%F %T')" "$(id -un)" "$PHP"
    local skip_backup=0 extra=()
    for arg in "$@"; do
        case "$arg" in
            --no-backup) skip_backup=1 ;;
            *) extra+=("$arg") ;;
        esac
    done

    load_config
    migrate_env_local
    acquire_lock

    # état « running » (lu par le menu de l'appli) ; si on abandonne avant de toucher à la démo, on restaure l'ancien état
    local prev_status=""
    [ -f "$STATUS_FILE" ] && prev_status="$(cat "$STATUS_FILE" 2>/dev/null || true)"
    # shellcheck disable=SC2064
    trap "build_cleanup '$prev_status'" EXIT
    write_build_status running

    info "Prod : $(db_label "$PROD_URL")"
    [ "$SOURCE_URL" = "$PROD_URL" ] || info "Lecture de la prod avec l'utilisateur en lecture seule (DATABASE_PROD_RO_URL)"
    info "Démo : $(db_label "$DEMO_URL")  (sera VIDÉE puis reconstruite)"

    # 1. l'application ne doit pas servir une base en cours de reconstruction : retour sur la prod d'abord
    if [ "$(current_mode)" = "demo" ]; then
        info "Retour sur la prod pendant la reconstruction (./app demo pour revenir ensuite)"
        set_mode prod
    fi

    # 2. filet de sécurité : sauvegarde de la prod (abandon si impossible, sauf --no-backup)
    if [ "$skip_backup" -eq 0 ]; then
        local rc=0
        backup_prod || rc=$?
        case "$rc" in
            0) ;;
            2) die "mysqldump introuvable : installez-le, ou relancez avec --no-backup (à vos risques)." ;;
            *) die "La sauvegarde a échoué : rien n'a été modifié. (--no-backup pour continuer sans.)" ;;
        esac
    else
        red "⚠ --no-backup : aucune sauvegarde de la prod."
    fi

    # 3. structure + données : la source (prod) en lecture seule, la cible (démo) jetable
    info "Construction de la base démo (structure + données)"
    # shellcheck disable=SC2086
    if ! APP_DB_MODE_IGNORE=1 DATABASE_URL="$SOURCE_URL" DEMO_DATABASE_URL="$DEMO_URL" \
            "$PHP" bin/console app:demo:build --fresh --yes $BUILD_OPTS ${extra[@]+"${extra[@]}"}; then
        write_build_status failed
        red "✗ La construction a échoué. La production n'a pas été modifiée ; l'application reste sur la PROD."
        exit 1
    fi

    write_build_status ok
    release_lock
    green "✔ Démo reconstruite ($(db_label "$DEMO_URL")). Pour l'utiliser : ./app demo  (ou le menu en haut à droite)."
}

# Bascule sur la démo SANS la reconstruire (--build pour reconstruire d'abord).
cmd_demo() {
    local rebuild=0 extra=()
    for arg in "$@"; do
        case "$arg" in
            --build|--rebuild) rebuild=1 ;;
            *) extra+=("$arg") ;;
        esac
    done

    load_config
    migrate_env_local
    if [ "$rebuild" -eq 1 ]; then
        cmd_build ${extra[@]+"${extra[@]}"}
    fi
    [ -d "$LOCK_DIR" ] && die "Une construction de la démo est en cours ($LOCK_DIR)."
    demo_ready || die "La démo n'est pas construite. Lancez d'abord : ./app build   (ou ./app demo --build)"
    [ "$({ tr -d '\r\n ' < "$STATUS_FILE"; } 2>/dev/null | cut -d'|' -f1)" != "failed" ] || die "La dernière construction de la démo a échoué : relancez ./app build"

    set_mode demo
    green "✔ Application basculée sur la DÉMO ($(db_label "$DEMO_URL")). Retour à la prod : ./app prod"
}

cmd_prod() {
    load_config
    migrate_env_local
    set_mode prod
    green "✔ Application sur la PROD ($(db_label "$PROD_URL"))."
}

cmd_status() {
    load_config
    local mode; mode="$(current_mode)"
    echo "Mode        : $mode"
    echo "Prod        : $(db_label "$PROD_URL")"
    echo "Démo        : $(db_label "$DEMO_URL")"
    [ -f "$STATUS_FILE" ] && echo "Dernière construction de la démo : $(tr '|' ' ' < "$STATUS_FILE")"
    ls -1t "$BACKUP_DIR"/prod-*.sql.gz 2>/dev/null | head -1 | sed 's/^/Dernière sauvegarde : /' || true
    if grep -Eq '^(DATABASE_URL|DEMO_DATABASE_URL|APP_DB_MODE)=' "$ENV_FILE" 2>/dev/null; then
        red "⚠ $ENV_FILE contient d'anciennes lignes DATABASE_URL/APP_DB_MODE : lancez « ./app prod » pour les nettoyer."
    fi
}

cmd_backup() {
    load_config
    local rc=0
    backup_prod || rc=$?
    [ "$rc" -eq 2 ] && die "mysqldump introuvable."
    [ "$rc" -eq 0 ] || die "La sauvegarde a échoué."
}

# Teste réellement les connexions (affiche l'erreur MySQL brute en cas d'échec) : ne fait QUE « SELECT 1 ».
cmd_doctor() {
    load_config
    echo "Mode : $(current_mode)   (fichier $MODE_FILE ; absent = prod)"
    local name url rc=0
    for name in PROD DEMO; do
        if [ "$name" = PROD ]; then url="$PROD_URL"; else url="$DEMO_URL"; fi
        "$PHP" -r '
            $u = parse_url($argv[1]); parse_str($u["query"] ?? "", $q);
            $dsn = sprintf("mysql:host=%s;port=%d;dbname=%s;charset=%s", $u["host"] ?? "127.0.0.1", $u["port"] ?? 3306, ltrim($u["path"] ?? "", "/"), $q["charset"] ?? "utf8mb4");
            try { $p = new PDO($dsn, rawurldecode($u["user"] ?? ""), rawurldecode($u["pass"] ?? ""), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $p->query("SELECT 1"); echo "OK   "; }
            catch (Throwable $e) { echo "ÉCHEC ", $e->getMessage(); exit(1); }
        ' -- "$url" > /tmp/.app_doctor.$$ 2>&1 && green "  $name : $(cat /tmp/.app_doctor.$$)$(db_label "$url")" || { red "  $name : $(cat /tmp/.app_doctor.$$)  [$(db_label "$url")]"; rc=1; }
        rm -f /tmp/.app_doctor.$$
    done
    [ "$rc" -eq 0 ] || echo "→ Si la PROD échoue : ./app prod  (ou supprimez .env.local pour retrouver exactement le .env)."
    return "$rc"
}

cmd_help() { sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; }

case "${1:-help}" in
    demo)   shift; cmd_demo "$@" ;;
    build)  shift; cmd_build "$@" ;;
    prod)   cmd_prod ;;
    status) cmd_status ;;
    backup) cmd_backup ;;
    doctor) cmd_doctor ;;
    help|-h|--help) cmd_help ;;
    *) cmd_help; exit 1 ;;
esac
