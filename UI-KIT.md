# Kit UI de Central — conventions

Référence vivante : **/styleguide** (chaque exemple a son code copiable). Ce fichier résume les règles.

## Primitives (public/css/ui-kit.css, chargé en dernier)

| Besoin | Classes | Remarques |
|---|---|---|
| Bouton | `.btn` + teinte (`btn-primary` `btn-neutral` `btn-info` `btn-danger` `btn-success` `btn-warning` `btn-soft` `btn-ghost`) | tailles `btn-sm` (32px) `btn-xs` (24px) ; formes `btn-icon` (carré) `btn-link` (texte). Dans une page : `layout/modern/components/_btns.html.twig` |
| Pastille | `.pill` + teinte (`pill-solid` `pill-accent` `pill-success` `pill-warning` `pill-muted` `pill-entity`) | `pill-count` = compteur rond d'onglet |
| En-tête de bloc | `.block-head` (+ `block-head-lg`, `block-head-meta`, `is-bar`, `is-clickable`) | titre à gauche, infos/actions à droite |
| Surface | `.card` (16px) · `.card-bar` (12px) · `.card-inset` (12px, fond atténué) · `.tile` (10px, ligne) | fond, bordure, rayon, ombre : seulement ici |

Les familles `rc-` (relevé), `pv-` (prévisions), `entity-`, `summary-`, `geo-`… **ne gardent que leur mise en page et leurs états**.
On ajoute la primitive dans le markup : `<section class="rc-panel card">`, `<div class="rc-recap card-bar">`.

## Règles

1. **Pas de nouvelle classe sans exemple** : un exemple vivant dans la thématique du styleguide, ou à défaut une ligne dans « Référence des classes ».
2. **Pas de fond/bordure/rayon/ombre dans une classe métier** : utiliser une primitive.
3. **Pas d'utilitaires de type Tailwind** (`px-4`, `text-right`, `gap-2`…) : aucun CSS ne les définit.
4. Couleurs : variables du thème (`var(--accent)`, `var(--surface)`…), jamais de couleur en dur ; entités : filtres `entity_color` / `entity_text`.
5. Quand un fichier JS change, changer aussi sa version interne (`version: 'vX.Y.Z'`) **et** le `?v=` dans `_head.html.twig` ; idem pour un CSS.

## Contrôle

```bash
php bin/console app:ui:audit          # résumé
php bin/console app:ui:audit --list   # + classes sans exemple
php bin/console app:ui:audit --strict # code de retour 1 si orphelines ou utilitaires morts
```
Lecture seule : ne modifie ni fichier ni base de données.
