# Trouvailles — État du projet (04/09/2026)

## Missions réalisées

1. **TRV-001-C** — Schéma SQL complet (10 tables + `schema_migrations`),
   migrations versionnées, seed des 3 sources (leboncoin/ebay/vinted).
   Déployé en local et en prod.
2. **TRV-002** — Adapters marketplace (Leboncoin, Vinted, eBay Browse API
   officielle) + `SourceManager` + `ListingPersister`. **Aucune donnée
   réelle ingérée à ce jour** : Leboncoin/Vinted bloqués par protection
   anti-bot (Datadome/Cloudflare, volontairement non contournée) ; eBay
   nécessite des identifiants API non fournis dans cet environnement.
3. **AV-UI-001** — Intégration de la charte graphique V1 (palette,
   typographie, composants CSS, assets SVG fournis).
4. **TRV-UI-002** — Construction du véritable écran d'accueil « Mes
   Trouvailles », branché sur `OpportunityRepository` (lecture réelle des
   tables existantes, aucun moteur de valorisation créé). Affiche
   honnêtement l'état vide tant qu'aucune opportunité réelle n'existe.

## État actuel

- **Local** : pleinement fonctionnel — 56 tests automatisés au vert,
  `php -S 0.0.0.0:8905 -t public` accessible sur le réseau local
  (http://192.168.1.123:8905/).
- **Prod** (`trouvailles.serviceproi.fr`) : code déployé et à jour (dernier
  déploiement le 04/09/2026), mais **l'accès public est actuellement
  compromis**. Un mécanisme anti-bot côté o2switch
  (`/o2s-cgi/security-challenge`, infrastructure PowerBoost, confirmé par
  le support — technicien Laurent) bloque au moins un visiteur réel sans
  jamais laisser passer vers le vrai contenu du site. Cause précise non
  confirmée (hypothèse VPN évoquée mais pas établie). **Ticket ouvert
  auprès du support o2switch, en attente de réponse** sur la possibilité
  de désactiver/assouplir ce mécanisme pour ce sous-domaine.
- **Base de données** : 0 opportunité réelle à ce jour, en local comme en
  prod — le pipeline d'ingestion n'a jamais pu écrire de ligne réelle.

## Points ouverts / bloquants

1. **Accès public prod bloqué** par le challenge de sécurité o2switch —
   en attente de résolution support. Tant que non résolu, le site n'est
   pas fiablement consultable par de vrais visiteurs externes.
2. **Aucune donnée réelle** pour peupler « Mes Trouvailles » — nécessiterait
   soit une évolution légale de l'accès Leboncoin/Vinted (hors mandat
   actuel, protection anti-bot non contournée par choix), soit des
   identifiants eBay Browse API à fournir.
3. **Aucun moteur de valorisation/décote** — hors périmètre de toutes les
   missions à ce jour (TRV-001-C à TRV-UI-002 l'excluent explicitement) ;
   nécessaire pour que `opportunities` contienne un jour des lignes.

## Hors périmètre (volontairement non traité)

Écrans Chasses / Fiche détaillée / Historique / Réglages, comptes
utilisateurs, notifications, moteur de recherche/filtres, dashboard
analytique — chacun ferait l'objet d'une mission dédiée si besoin.
