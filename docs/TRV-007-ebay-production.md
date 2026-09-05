# TRV-007 — Activation production eBay

## Contexte

Après obtention d'un jeu de clés eBay **production** (« Trouvailles prod »)
via developer.ebay.com, l'authentification OAuth `client_credentials`
échouait systématiquement (`HTTP 401 invalid_client`), malgré des
identifiants confirmés exacts par copier-coller à deux reprises (App ID
et Cert ID). Cause identifiée : eBay exige la configuration d'un endpoint
**« Marketplace Account Deletion »** (conformité RGPD) avant d'activer
l'accès production, même pour une application en lecture seule.

## Ce qui a été construit

`public/ebay-account-deletion.php` — implémente le contrat documenté par
eBay (Marketplace Account Deletion Notification API) :

- **GET `?challenge_code=<token>`** : réponse `{"challengeResponse": <hash>}`,
  `<hash>` = SHA-256 hex de `challenge_code + verification_token + endpoint_url`
  (ordre exact, URL identique caractère pour caractère à celle enregistrée
  côté eBay) — vérifie la maîtrise de l'endpoint.
- **POST** : accusé de réception `{"acknowledged": true}` uniquement.
  Trouvailles n'obtient jamais de jeton utilisateur eBay (uniquement
  `client_credentials`, app-only, pour la recherche publique Browse) —
  il n'existe donc structurellement **aucune donnée utilisateur eBay à
  supprimer** ici, jamais de traitement inventé au-delà de ce qui est
  réellement nécessaire.

`config/ebay.php` complété avec `deletion_verification_token` et
`deletion_endpoint_url` (lus depuis `.env`, jamais codés en dur).

## Vérification

- Hash calculé localement (`cf5d75c2...`) identique à celui renvoyé par
  l'endpoint déployé en production pour le même `challenge_code` de test.
- Validation réussie côté eBay (bouton de vérification du formulaire
  Marketplace Account Deletion).
- **Authentification production confirmée fonctionnelle** : recherche
  réelle "canon eos 90d" → 5 annonces réelles récupérées (ex. « Canon EOS
  90D + Canon EF 28-80mm f/3.5-5.6 » à 668,68 EUR, occasion) — première
  vraie donnée de marché du projet, marketplace `EBAY_FR`.

## Configuration production

`.env` de production mis à jour (fusion, aucune autre valeur touchée) :
`EBAY_CLIENT_ID`/`EBAY_CLIENT_SECRET` (production), `EBAY_MARKETPLACE_ID=EBAY_FR`,
`EBAY_SANDBOX=false`, `EBAY_DELETION_VERIFICATION_TOKEN`,
`EBAY_DELETION_ENDPOINT_URL`.

## Conclusion

eBay est maintenant pleinement fonctionnel en production. Le pipeline
complet (`chasses.php`, `ListingPersister`, `app/Pricing/`) peut
désormais traiter de vraies annonces — reste à exécuter
`tools/pricing_engine.php` en conditions réelles pour voir émerger de
vraies opportunités (prochaine étape, hors périmètre de ce rapport).
