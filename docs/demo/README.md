# Base démo : une commande pour construire et basculer

> **Règle n°1 : la production n'est jamais modifiée.** Elle n'est lue qu'en lecture seule, et sauvegardée avant chaque construction.

## En pratique

```bash
./app demo      # sauvegarde la prod → recrée la démo (structure + données) → bascule l'application dessus
./app prod      # retour à la production : on reprend simplement la connexion
./app status    # quelle base est utilisée ?
./app backup    # sauvegarde de la prod à la demande (var/backups/, 10 conservées)
```

`./app demo` accepte des options de la commande de construction, par exemple `./app demo --shift=0 --jitter=0`,
ou `./app demo --no-backup` (déconseillé).

## Ce que fait `./app demo`

1. **Retour sur la prod** si l'application était déjà sur la démo (elle ne doit jamais servir une base en reconstruction).
2. **Sauvegarde de la prod** (`mysqldump` → `var/backups/prod-AAAAMMJJ-HHMMSS.sql.gz`, contrôlée). Si elle échoue : **arrêt, rien n'est modifié**.
3. **Structure** : la base démo est vidée (tables et vues) puis **recréée à l'identique de la prod** — tables, index, clés étrangères **et vues SQL** (`SHOW CREATE`). Elle est donc jetable : rien à entretenir, plus de migrations à rejouer.
4. **Données** : copie / anonymisation / exclusion selon les règles ci-dessous, dans une seule transaction.
5. **Bascule** : `DATABASE_URL` pointe sur la démo (`.env.local`), cache vidé.

Si une étape échoue, l'application **reste sur la prod** et le script le dit.

### Garanties de sécurité (plusieurs niveaux)

* La copie lit la prod dans une transaction **`READ ONLY`** imposée par MySQL : toute écriture sur la prod serait refusée par la base elle-même, et la source n'est jamais « commitée ».
* Les `DROP` / `CREATE` ne passent que par la connexion démo, après vérification que **source ≠ cible** (hôte, port, nom de base).
* Le nom de la base démo **doit contenir « demo »** (contrôlé par le script et par la commande).
* **Sauvegarde automatique** avant toute reconstruction.
* *(recommandé)* `DATABASE_PROD_RO_URL` : un utilisateur MySQL **en lecture seule** sur la prod, utilisé pour la copie. Même un bug ne pourrait alors rien écrire.
* Un verrou empêche deux `./app demo` simultanés.
* Le retour à la prod ne touche qu'à une ligne de `.env.local` (copie de secours : `.env.local.bak`).

## Installation (une fois)

1. **Fichiers** : dézipper à la racine du projet ; `chmod +x app`.
   Si l'ancien script `app` avait des fins de ligne Windows (`^M`), le remplacer par celui-ci (fins de ligne LF ; ajouter `.gitattributes.dist` à votre `.gitattributes`).
2. **Base démo vide** (une seule fois — le script s'occupe ensuite de tout) :
   ```sql
   CREATE DATABASE central_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   GRANT ALL PRIVILEGES ON central_demo.* TO 'central_demo'@'%' IDENTIFIED BY '…';
   -- recommandé : accès lecture seule à la prod pour la copie
   GRANT SELECT, SHOW VIEW, TRIGGER ON central.* TO 'central_ro'@'%' IDENTIFIED BY '…';
   ```
3. **`.env.local`** — les définitions d'abord, `DATABASE_URL` ensuite (Symfony résout `${…}` dans l'ordre) :
   ```dotenv
   DATABASE_PROD_URL="mysql://user:pass@127.0.0.1:3306/central?serverVersion=8.0&charset=utf8mb4"
   DATABASE_DEMO_URL="mysql://demo:pass@127.0.0.1:3306/central_demo?serverVersion=8.0&charset=utf8mb4"
   DATABASE_PROD_RO_URL="mysql://central_ro:pass@127.0.0.1:3306/central?serverVersion=8.0&charset=utf8mb4"   # facultatif
   DEMO_SALT="<openssl rand -hex 32>"          # SECRET : rend les faux noms reproductibles mais non réversibles
   DEMO_BUILD_OPTS="--shift=auto --jitter=15 --strip-notes"   # facultatif (valeurs par défaut)
   DATABASE_URL="${DATABASE_PROD_URL}"          # géré ensuite par ./app
   ```
4. **Doctrine** : fusionner `config/packages/doctrine.demo.yaml.dist` dans `doctrine.yaml` (deux connexions / EntityManagers ; `default` en premier ; même `naming_strategy` que l'existant).
5. **Bandeau « DÉMO »** (facultatif) : fusionner `twig.demo.yaml.dist` dans `twig.yaml` et ajouter `{% include 'layout/modern/struct/_db_mode_banner.html.twig' %}` après `<body>` dans `modern.html.twig`.
6. **Essai à blanc** (aucune écriture, rien n'est vidé) :
   ```bash
   php bin/console app:demo:build --dry-run
   ```
   *Le schéma de la démo doit déjà exister pour un dry-run ; au premier essai, lancez directement `./app demo`.*

## Règles de copie / anonymisation

| Entité | Règle |
|---|---|
| Types de tiers / d'adresse / de projet, catégories | toujours copiés |
| **Tiers**, **Portefeuilles**, **Projets** | champ « Mode démo » : copier / anonymiser / exclure |
| Tiers anonymisé | faux nom stable (« Prénom Nom » si le type évoque un particulier, sinon « Boulangerie du Centre »), texte de recherche remplacé |
| Portefeuille / projet anonymisé | « Compte courant 1 », « Travaux 2 »… ; origine vidée |
| **Adresses** | déduites des tiers liés : uniquement des tiers exclus → exclue ; un tiers anonymisé → anonymisée (avec parents/enfants, **sauf** niveaux ville / département / région / pays…) |
| Adresse anonymisée | même ville / CP / pays ; numéro, voie et GPS fictifs (≤ 600 m) ; adresse forcée / exacte supprimées |
| Liens tiers ↔ adresse, relevés | suivent leurs tiers / adresses / portefeuilles |
| **Opérations** | exclue si un lien (tiers, catégorie, portefeuille, projet) est exclu ; anonymisée si l'un l'est : note vidée, n° de commande fictif, montant ± `--jitter` % |

Options de `app:demo:build` : `--fresh` (recrée la structure), `--dry-run`, `--yes`, `--shift=auto|N`, `--jitter=N`, `--strip-notes`.

## Revenir en arrière / restaurer

* **Retour à la prod** : `./app prod`.
* **Restaurer la prod** (ne devrait jamais servir) :
  ```bash
  gunzip < var/backups/prod-AAAAMMJJ-HHMMSS.sql.gz | mysql -u USER -p central
  ```
* **Détruire la démo** : elle est jetable (`DROP DATABASE central_demo`) ; `./app demo` la recrée.

## Suggestions d'anonymisation

* `DEMO_SALT` secret ; ne pas le versionner.
* Texte libre = principal risque : `note`, `searchText`, `label` de relevé → `--strip-notes`.
* `--jitter` empêche de retrouver une personne par un montant exact ; `--shift=auto` rend la démo « fraîche ».
* La ville reste visible : mettre en « Exclure » les tiers dont la ville est sensible.
* Fichiers téléversés, logs et sauvegardes ne sont pas couverts par la commande ; `var/backups/` contient des données réelles : à protéger, hors dépôt Git.
* Site démo : `noindex`, import / suppression en masse désactivés.

## Points relevés dans les entités (hors périmètre)

* `TiersAdresse::getType()/setType()` : propriété `$type` inexistante.
* `Releve::getLignes()`, `getIsClosed()/setIsClosed()` : propriétés inexistantes.
