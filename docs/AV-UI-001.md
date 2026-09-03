# AV-UI-001 — Intégration de la charte graphique Trouvailles V1

## 1. Audit de l'existant (préalable)

- **Front-end** : PHP natif, aucun framework, aucun moteur de templates. Un
  seul fichier de vue : `public/index.php` (rendu inline, `<?= ?>`).
- **CSS existant** : aucun. Aucune feuille de style, aucune variable,
  aucun breakpoint avant cette mission.
- **Layouts/composants/écrans existants** : aucun — un seul écran
  fonctionnel (page de statut confirmant la connexion PDO/MariaDB, issue
  du squelette initial), sans navigation ni contenu produit.
- **Gestion des assets** : inexistante — pas de dossier `public/assets/`.
- **Breakpoints responsive** : aucun.

Conséquence directe pour le périmètre de cette mission : il n'existe qu'un
seul écran réel à habiller. Conformément à l'interdiction §10 (« ne pas
inventer de nouveaux écrans »), aucun nouvel écran produit (fiche détaillée,
liste de trouvailles, etc.) n'a été créé — voir §5 « Limites ».

## 2. Assets intégrés

Décompression de `trouvailles-brand-assets-v1.zip`, intégration **sans
modification du dessin** des SVG, en respectant la structure du pack
(aucune convention existante à respecter, puisqu'aucun `public/assets/`
ne préexistait) :

```
public/assets/logo/       trouvailles-{horizontal,stacked,symbol,monochrome}.svg
public/assets/icons/      arrow, clock, confidence, detect, external, heart,
                           pin, shield, tag, tag-percent (.svg)
public/assets/illustrations/ chercheur-editorial.svg
public/assets/patterns/   contours, dots, sparkles (.svg)
public/assets/ui/         confidence-high.svg, decote-meter.svg
public/css/trouvailles.css   pack de charte fourni, copié tel quel (intact)
docs/brand-v1/{README,usage}.md   documentation du pack, conservée pour référence
```

## 3. CSS de charte intégré

`public/css/trouvailles.css` (pack fourni) est chargé **sans modification**
— variables (`--tv-ivory`, `--tv-ink`, `--tv-green`, `--tv-green-light`,
`--tv-orange`, `--tv-sand`, `--tv-muted`), typographies (`--tv-font-display`
= Georgia/Times New Roman/serif, `--tv-font-ui` = Inter/system-ui, **aucune
dépendance externe ajoutée** — fallback système utilisé tel quel §5),
composants (`.tv-button`, `.tv-badge`, `.tv-card`, `.tv-price`,
`.tv-market-value`, `.tv-discount`, `.tv-opportunity`, `.tv-nav`,
`.tv-mobile-nav`) et media queries (1024px, 720px) déjà conformes au §8.

Un second fichier, `public/css/app.css`, a été ajouté **en plus** (jamais à
la place) : c'est de la glue de mise en page propre à la page d'accueil
(grille du hero, dégagement pour la nav mobile fixe) — n'utilise que les
variables `--tv-*` déjà définies, aucune couleur nouvelle introduite.
Séparé délibérément du pack fourni pour que celui-ci reste remplaçable tel
quel lors d'une future mise à jour de charte.

## 4. Composants adaptés / page modifiée

Seul fichier applicatif modifié : `public/index.php`. Logique métier
**inchangée** (même vérification `Database::connection()`, même résolution
`ROOT`/`app-root.php`) — uniquement l'habillage HTML/CSS autour :

- **En-tête** : logo horizontal (`trouvailles-horizontal.svg`) + navigation
  desktop (`.tv-nav`), items `Accueil / Chasses / Trouvailles / Réglages`
  (§8, `Accueil` marqué `aria-current`).
- **Favicon** : `trouvailles-symbol.svg` (règle §6 : symbole pour petits
  emplacements).
- **Statut base de données** (donnée réelle, inchangée) : reformulé en
  badge de confiance (`.tv-badge--high`/`--insufficient` selon l'état réel).
- **Bloc éditorial** : titre `Trouvailles` (`.tv-display`, Georgia),
  signature de marque « Ce qui vaut vraiment le coup. » (reprise du pack,
  non inventée), rappel du principe produit Annonce→Prix→Valeur
  estimée→Décote→Preuves→Confiance→Action (donné par la mission), et
  illustration éditoriale (`chercheur-editorial.svg`).
- **Aperçu des composants** (section clairement étiquetée « Exemple —
  aucune donnée réelle ») : boutons principal/secondaire, les trois badges
  de confiance, et une carte de trouvaille (`.tv-card.tv-opportunity`)
  démontrant la hiérarchie imposée §7 (Produit → Prix demandé → Valeur
  estimée → Décote → Confiance → Source → Fraîcheur → Action), avec valeurs
  d'exemple neutres (129 €/189 €/−32 %) — voir §5 « Limites » sur ce choix.
- **Navigation mobile** : `.tv-mobile-nav` (barre fixe basse), mêmes 4 items.

Aucun composant existant n'a été supprimé (il n'y en avait aucun à
supprimer) ; le squelette LAMP (TRV-001-C) et les adapters marketplace
(TRV-002) sont restés intégralement intacts.

## 5. Limites et choix assumés

- **Aucun écran produit fabriqué** : la carte de trouvaille affichée est un
  exemple explicitement étiqueté (`tv-example-label`), pas un écran
  connecté à `listings`/`price_observations` — ce branchement (requête
  réelle, boucle d'affichage) relève d'une mission produit ultérieure,
  hors périmètre ici (§10 : ne pas inventer d'écran, ne pas modifier le
  backend).
- **Icônes de navigation** : aucune icône du pack ne correspond
  sémantiquement à `Accueil/Chasses/Trouvailles/Réglages` — la navigation
  reste texte seul plutôt que d'associer une icône au hasard.
- Les liens de navigation (`Chasses`, `Trouvailles`, `Réglages`) pointent
  vers `#` : aucune route correspondante n'existe encore dans l'application
  (un seul fichier `public/index.php`).

## 6. Tests effectués

**Technique**
- `php -l public/index.php` : aucune erreur de syntaxe.
- Serveur PHP intégré (`php -S localhost:8905 -t public`) : page et 12
  assets référencés (CSS + SVG) vérifiés un par un via `curl`, tous en
  HTTP 200 — aucun asset manquant, aucun chemin cassé.
- Aucun warning/notice dans le journal du serveur PHP pendant les requêtes.

**Responsive (visuel réel, pas seulement statique)**
Captures d'écran Chrome headless à 1280px (desktop), 900px (tablette) et
390px (mobile) : en-tête, navigation (desktop → nav mobile fixe en
dessous de 720px), carte, boutons, badges, prix/valeur/décote tous
vérifiés visuellement conformes à chaque largeur. Un ajustement mineur
(largeur de la carte d'exemple, 360→420px, dans `app.css` uniquement) a
été fait après la première capture pour éviter un retour à la ligne du
prix en desktop — le pack `trouvailles.css` n'a pas été touché.

**Fonctionnel**
- Les 53 tests automatisés (`tests/run*.php`, TRV-001-C + TRV-002)
  ré-exécutés après intégration : **53/53 toujours au vert**, aucune
  régression (l'intégration ne touche que la vue, jamais le backend).

## 7. Fichiers modifiés/créés

**Créés** : `public/css/trouvailles.css`, `public/css/app.css`,
20 fichiers SVG sous `public/assets/{logo,icons,illustrations,patterns,ui}/`,
`docs/brand-v1/{README,usage}.md`, `docs/AV-UI-001.md` (ce rapport).

**Modifiés** : `public/index.php`, `README.md`.

## 8. Problèmes rencontrés

Aucun blocage technique. Seule limite : l'absence d'écrans produit
préexistants réduit cette mission à l'habillage du squelette + une
démonstration explicitement étiquetée des composants, en attendant les
écrans réels (recherche, fiche détaillée, liste de trouvailles) d'une
mission produit ultérieure.
