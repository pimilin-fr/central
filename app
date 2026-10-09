#!/usr/bin/env bash
#
# ./app — bascule prod / démo (la base démo est jetable, la prod n'est jamais modifiée)
#
#   ./app demo [options]   sauvegarde la prod, recrée la base démo (structure + données) puis bascule dessus
#   ./app prod             revient à la production (reprend simplement la connexion)
#   ./app status           indique la base utilisée
#   ./app backup           sauvegarde de la prod (var/backups/)
#   ./app doctor           teste les connexions prod/démo et affiche l'erreur exacte
#   ./app help
#
# Prérequis dans .env ou .env.local (valeurs entre guillemets) :
#   DATABASE_PROD_URL="mysql://user:pass@127.0.0.1:3306/central?serverVersion=8.0&charset=utf8mb4"
#   DATABASE_DEMO_URL="mysql://user:pass@127.0.0.1:3306/central_demo?serverVersion=8.0&charset=utf8mb4"
#   (facultatif) DATABASE_PROD_RO_URL=  un utilisateur MySQL en LECTURE SEULE sur la prod, utilisé pour la copie
#   (facultatif) DEMO_BUILD_OPTS="--shift=auto --jitter=15 --strip-notes"
# Les lignes DATABASE_URL, DEMO_DATABASE_URL et APP_DB_MODE sont gérées par ce script.
#
set -Eeuo pipefail
cd "$(dirname "$0")"

ENV_FILE=".env.local"
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
    php -r '
        require "vendor/autoload.php";
        (new Symfony\Component\Dotenv\Dotenv())->loadEnv(".env");
        echo $_ENV[$argv[1]] ?? ($_SERVER[$argv[1]] ?? "");
    ' -- "$1"
}

# Nom de la base d'une URL (sans le mot de passe).
db_name() { php -r '$u = parse_url($argv[1]); echo ltrim($u["path"] ?? "", "/");' -- "$1"; }
db_label() { php -r '$u = parse_url($argv[1]); printf("%s@%s:%s/%s", $u["user"] ?? "?", $u["host"] ?? "?", $u["port"] ?? 3306, ltrim($u["path"] ?? "", "/"));' -- "$1"; }

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

# Fixe DATABASE_URL / DEMO_DATABASE_URL / APP_DB_MODE (les autres lignes ne sont pas touchées).
set_mode() {
    local mode="$1" target tmp
    case "$mode" in prod) target='${DATABASE_PROD_URL}' ;; demo) target='${DATABASE_DEMO_URL}' ;; *) die "mode inconnu: $mode" ;; esac

    cp "$ENV_FILE" "$ENV_FILE.bak"
    tmp="$(mktemp "$ENV_FILE.XXXXXX")"
    awk -v target="$target" -v mode="$mode" '
        { line = $0; sub(/\r$/, "", line) }
        line ~ /^DATABASE_URL=/      { if (!a) { print "DATABASE_URL=\"" target "\""; a = 1 } ; next }
        line ~ /^DEMO_DATABASE_URL=/ { if (!b) { print "DEMO_DATABASE_URL=\"${DATABASE_DEMO_URL}\""; b = 1 } ; next }
        line ~ /^APP_DB_MODE=/       { if (!c) { print "APP_DB_MODE=" mode; c = 1 } ; next }
        { print $0 }
        END {
            if (!a) print "DATABASE_URL=\"" target "\""
            if (!b) print "DEMO_DATABASE_URL=\"${DATABASE_DEMO_URL}\""
            if (!c) print "APP_DB_MODE=" mode
        }
    ' "$ENV_FILE" > "$tmp"
    chmod --reference="$ENV_FILE" "$tmp" 2>/dev/null || true
    mv "$tmp" "$ENV_FILE"
}

current_mode() {
    local m
    m="$(grep -E '^APP_DB_MODE=' "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2 | tr -d '\r"' || true)"
    echo "${m:-prod}"
}

clear_cache() {
    php bin/console cache:clear --no-warmup -q || red "⚠ cache:clear a échoué : relancez « php bin/console cache:clear »."
    php bin/console cache:warmup -q 2>/dev/null || true
}

# ---------------------------------------------------------------- sauvegarde de la prod

backup_prod() {
    command -v mysqldump >/dev/null || return 2
    mkdir -p "$BACKUP_DIR"
    local file="$BACKUP_DIR/prod-$(date +%Y%m%d-%H%M%S).sql.gz"
    local parts host port user pass name
    mapfile -t parts < <(php -r '$u = parse_url($argv[1]); echo implode("\n", [$u["host"] ?? "127.0.0.1", $u["port"] ?? 3306, rawurldecode($u["user"] ?? ""), rawurldecode($u["pass"] ?? ""), ltrim($u["path"] ?? "", "/")]);' -- "$SOURCE_URL")
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

# ---------------------------------------------------------------- commandes

cmd_demo() {
    local skip_backup=0 extra=()
    for arg in "$@"; do
        case "$arg" in
            --no-backup) skip_backup=1 ;;
            *) extra+=("$arg") ;;
        esac
    done

    load_config
    acquire_lock

    info "Prod : $(db_label "$PROD_URL")"
    [ "$SOURCE_URL" = "$PROD_URL" ] || info "Lecture de la prod avec l'utilisateur en lecture seule (DATABASE_PROD_RO_URL)"
    info "Démo : $(db_label "$DEMO_URL")  (sera VIDÉE puis reconstruite)"

    # 1. l'application ne doit pas servir une base en cours de reconstruction : retour sur la prod d'abord
    if [ "$(current_mode)" = "demo" ]; then
        info "Retour temporaire sur la prod pendant la reconstruction"
        set_mode prod
        clear_cache
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
    if ! DATABASE_URL="$SOURCE_URL" DEMO_DATABASE_URL="$DEMO_URL" \
            php bin/console app:demo:build --fresh --yes $BUILD_OPTS ${extra[@]+"${extra[@]}"}; then
        red "✗ La construction a échoué. La production n'a pas été modifiée ; l'application reste sur la PROD."
        exit 1
    fi

    # 4. bascule
    set_mode demo
    clear_cache
    green "✔ Application basculée sur la DÉMO ($(db_label "$DEMO_URL")). Retour à la prod : ./app prod"
}

cmd_prod() {
    load_config
    set_mode prod
    clear_cache
    green "✔ Application sur la PROD ($(db_label "$PROD_URL"))."
}

cmd_status() {
    load_config
    local mode; mode="$(current_mode)"
    echo "Mode        : $mode"
    echo "Prod        : $(db_label "$PROD_URL")"
    echo "Démo        : $(db_label "$DEMO_URL")"
    echo "DATABASE_URL: $(grep -E '^DATABASE_URL=' "$ENV_FILE" | tail -1 | tr -d '\r')"
    ls -1t "$BACKUP_DIR"/prod-*.sql.gz 2>/dev/null | head -1 | sed 's/^/Dernière sauvegarde : /' || true
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
    echo "Mode : $(current_mode)    DATABASE_URL effective : $(db_label "$(env_get DATABASE_URL)")"
    local name url rc=0
    for name in PROD DEMO; do
        if [ "$name" = PROD ]; then url="$PROD_URL"; else url="$DEMO_URL"; fi
        php -r '
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

cmd_help() { sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'; }

case "${1:-help}" in
    demo)   shift; cmd_demo "$@" ;;
    prod)   cmd_prod ;;
    status) cmd_status ;;
    backup) cmd_backup ;;
    doctor) cmd_doctor ;;
    help|-h|--help) cmd_help ;;
    *) cmd_help; exit 1 ;;
esac
