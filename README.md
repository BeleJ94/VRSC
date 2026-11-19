# ETHAN VRSC

GMAO multi-sites minière de Perfect Logistics (CHEMAF, KAMAO, METALKOL : terrassement, ADT, bennes ; KINGAKILA, SICOMINES : bus) : parc d'engins par marque / modèle, conducteurs et équipes par shift, ordres de travail avec intervenants et causes de panne, maintenance préventive, pièces / pneus / lubrifiants avec compatibilité et consommations, besoins achats, alertes coûts, planning, rapports de direction, écrans terrain mobiles, journal d'activité.

## Démarrage

Prérequis : PHP ≥ 8.2 (pdo_mysql, mbstring, intl), MariaDB ≥ 10.11, Composer, Node ≥ 20 (tests JS uniquement).

```bash
composer install
cp .env.example .env          # renseigner DB_*
composer assets               # télécharge les librairies front (versions épinglées) si public/assets/vendor est vide
composer migrate              # applique database/migrations/*.sql
composer seed                 # (optionnel) jeu de démonstration, base vide uniquement
composer seed:mining          # (optionnel) démonstration minière : parc, personnel, équipes, pièces, historique (idempotent)
composer serve                # http://127.0.0.1:8080
```

Comptes de démonstration (mot de passe `PerfectMaint2026!`), après `composer seed` puis `composer seed:mining` (les sites « entrepôt » LYO / LIL / MRS du premier jeu sont alors archivés et retirés des accès) :

| Compte | Rôle | Sites |
|---|---|---|
| admin@perfect-logistics.test | Administrateur | tous |
| marc.responsable@perfect-logistics.test | Responsable maintenance | CHEMAF, KAMAO, METALKOL |
| sofia.tech@perfect-logistics.test | Technicien | KAMAO |
| karim.tech@perfect-logistics.test | Technicien | METALKOL |
| lea.lecture@perfect-logistics.test | Consultation | KINGAKILA, SICOMINES |

**Apache** : pointer le vhost sur `public/`. Si l'application est servie depuis la racine du projet ou un sous-dossier (ex. `http://localhost/MaintPerfect/`), le `.htaccess` racine redirige vers `public/` ; renseigner alors `APP_BASE_PATH=/MaintPerfect`.

**Nginx (ServBay)** : la racine du site DOIT être `…/MaintPerfect/public` (Nginx ignore les `.htaccess` : avec la racine du projet, `.env` et le code seraient téléchargeables). Réécriture : `try_files $uri $uri/ /index.php?$query_string;`.

**Devise** : `APP_CURRENCY` (USD par défaut ; CDF, EUR…) pour l'affichage des coûts.

**Fuseau horaire** : `APP_TIMEZONE` (`Africa/Lubumbashi` par défaut, UTC+2 : Lubumbashi, Kolwezi) ; appliqué à PHP et à la session MariaDB.

**Photos d'engins** : stockées dans `storage/uploads/equipment` (hors racine web, servies après contrôle du périmètre) ; redimensionnées dans le navigateur avant envoi (1 600 px, JPEG).

**Cron** (génération quotidienne des OT préventifs à échéance) :

```
0 5 * * *  php /chemin/perfectmaint/bin/generate-preventive.php
```

## Tests

```bash
composer test        # PHPUnit (unitaires, intégration, fonctionnels) + node:test
```

Les tests d'intégration utilisent la base `perfectmaint_test` (mêmes identifiants que `.env`), recréée via les migrations à chaque exécution.

## Architecture

```
app/
  Core/          noyau : App (pipeline HTTP), Router, Request/Response, Container, Database, Session, Csrf, I18n, View, Validator, Migrator
  Domain/        règles métier pures : statuts d'engin et d'OT, transitions, rôles/permissions, échéances préventives
  Security/      Auth, CurrentUser, SiteScope (périmètre de sites)
  Repositories/  accès SQL — TOUTES les requêtes sur des données de site passent par SiteScope::clause()
  Services/      opérations métier transactionnelles + ActivityLogger
  Controllers/   HTTP → services → vues/JSON
  Views/         gabarits PHP (sorties échappées avec e())
database/migrations/  NNNN_nom.sql — seule voie d'évolution du schéma
lang/            catalogues fr.php (défaut) / en.php
public/assets/   css/app.css, js/ (pm-core, pm-tables, pm-export : modules purs testés sous Node ; app.js, dashboard.js)
```

### Règles appliquées

- **Filtrage par site dans les repositories** : `SiteScope` est construit depuis l'utilisateur connecté et injecté dans chaque repository ; un élément hors périmètre renvoie 404, une écriture hors périmètre 403. Admin et comptes « tous sites » ont un périmètre global.
- **CSRF** : jeton de session exigé sur toute requête non-GET (champ `_csrf` ou en-tête `X-CSRF-Token`, positionné globalement pour jQuery).
- **Échappement** : `e()` dans les vues, `PMCore.escapeHtml` côté JS ; aucune donnée injectée dans un script inline (blocs `application/json`), CSP `script-src 'self'`.
- **Journal d'activité** : chaque modification métier écrit qui / quoi / quand / avant-après (champs modifiés uniquement), dans la même transaction que la modification.
- **Transactions** : création d'OT, transitions, consommation de pièces (verrouillage `FOR UPDATE`, pas de stock négatif), mouvements de stock.

### Modules miniers

| Module | Écran | Règles clés |
|---|---|---|
| Fenêtre par site | `/sites/{id}` (menu « Sites ») | parc filtrable en direct, disponibilité, interventions en cours, équipes et dispatch |
| Référentiel | `/catalog` | 13 marques, modèles rattachés à une catégorie ; le modèle impose marque et catégorie de l'engin |
| Fiche engin | `/equipment/{id}` | châssis, plaque, heures, km, carburant, PO / contrat / location, affectation, photos, relevés (compteurs non décroissants) |
| Équipes & personnel | `/teams`, `/personnel` | équipes par shift (jour / nuit / rotation), chef d'équipe, n° du dispatch ; un conducteur par engin et par shift |
| OT | `/work-orders/{id}` | conducteur en poste capturé au signalement, cause de panne à la clôture, intervenants et minutes, remarques, compteurs à chaque pièce remplacée |
| Pièces & fluides | `/parts`, appoints sur la fiche engin | types (pièce, pneu, lubrifiant, filtre, consommable, carburant), compatibilité par modèle, durée de vie attendue, appoints / vidanges en sortie de stock |
| Besoins achats | `/procurement` | consommation mensuelle (90 j ÷ 3), stock cible = max(2 × mini, 2 mois), quantité à commander, exports Excel / PDF |
| Documents d'engin | `/documents` (menu « Documents de bord »), carte « Documents » de la fiche engin, `/alerts#documents` | tableau de bord engin × type (expirés, à renouveler, non renseignés, valides), filtres, exports Excel / PDF ; fichier joint optionnel (PDF / image, 10 Mo, stocké dans `storage/uploads/documents`, servi après contrôle du périmètre) ; assurance, contrôle technique, vignette, stationnement (types extensibles : table `document_types`, sans changement de schéma) ; un renouvellement crée un nouveau document, le plus récent par type est en vigueur ; alerte à J-30 (`alert_days`) et à expiration dans la cloche, l'e-mail quotidien et le planning ; saisie réservée aux responsables |
| Alertes coûts | `/alerts` | usure prématurée (< 70 % de la durée de vie entre deux remplacements), surconsommation de lubrifiant (> 1,5 × moyenne du modèle) |
| Planning | `/planning` | jour / semaine / mois : préventifs, OT à échéance, fins de contrat |
| Journal d'activité | `/activity` (responsables, dans leur périmètre) | qui a fait quoi : synthèse par utilisateur, filtres utilisateur / action / élément / période, détail d'une entrée (IP, appareil, avant / après), échecs de connexion |
| Rapports direction | `/reports` | disponibilité, pannes, immobilisation, MTTR, consommations, causes, conducteurs (responsables et consultation) |

### Socle VRSC (lot 1 de la refonte)

Adaptation au cahier des charges VRSC (§ 2 et § 9). Base de travail : `vrsc` ; base de test : `vrsc_test`.

| Brique | Où | Règles clés |
|---|---|---|
| Référentiels communs | `/references` (`ReferenceCatalog`) | projets / chantiers et magasins rattachés à un site (périmètre), départements, centres de coûts, fournisseurs, transporteurs, modes de paiement ; code unique en majuscules ; un seul écran générique |
| Import contrôlé | `/references/{type}/import` | fichier Excel / CSV lu dans le navigateur, contrôle ligne à ligne, doublons écartés, journal `import_logs` |
| Fonctions par site | fiche utilisateur, table `user_roles` | profil principal + fonctions supplémentaires par site (demandeur, responsable hiérarchique, Direction Logistique, magasinier, acheteur, caissier, finance) ; chaque requête est limitée aux sites où la permission de la route est détenue |
| Délégations | `/delegations` | on ne délègue que ce que l'on détient, période et motif obligatoires, révocation sans suppression |
| Circuit d'approbation | `ApprovalService` | étapes par rôle (avis consultatif, contrôle, autorisation), seuils de montant, demandeur ≠ valideur, une personne = une étape, dérogation motivée et tracée, actes par délégation tracés |
| Numérotation | `NumberingService` | `TYPE-AAAA-00001`, compteur verrouillé par type et par année |
| Pièces jointes | `AttachmentService`, `/attachments/{id}` | PDF / image, 10 Mo, versionnées, empreinte SHA-256, servies après contrôle du périmètre |

### Stocks multisites (lot 2 de la refonte)

Cahier des charges § 5, recette R6 et R7. Le stock d'un site est celui de son magasin central.

| Brique | Où | Règles clés |
|---|---|---|
| Grand livre | `StockService::move`, table `stock_movements` | mouvement daté, signé, valorisé, jamais modifié ni supprimé ; types : réception, stock initial, retour, sortie (bénéficiaire obligatoire : engin, projet ou service), transferts, écart d'inventaire ; bons numérotés BR / BS / BT / INV |
| Valorisation | `part_stocks.avg_cost` | coût moyen pondéré par article et par magasin ; coût inconnu = valeur « manquante », jamais zéro |
| Fiche de magasin et état consolidé | `/stock` | stock initial, réceptions, retours, sorties, transferts, écarts, stock final, transit, valeur ; par mois ; exports Excel / PDF ; vue consolidée tous magasins |
| Journal | `/stock/journal` | toutes les écritures, filtrables par magasin, type et dates |
| Transferts | `/stock/transfers` | demande → contrôle de disponibilité → autorisation Logistique (quantité réservée) → sortie (transit) → réception par une autre personne → écart expliqué ; le transit n'est compté dans aucun magasin |
| Inventaires | `/stock/inventories` | photographie du théorique, comptage, écarts justifiés, approbation ; le stock n'est ajusté qu'après approbation, comptage verrouillé dès la soumission |
| Clôture mensuelle | `/stock/periods` | soldes figés, période verrouillée, clôture dans l'ordre, bloquée par un transit ou un inventaire ouvert |
| Mes validations | `/approvals` | demandes que les fonctions de l'utilisateur permettent de traiter ; décision enregistrée sur la page du document |

### États de besoins et achats (lot 3 de la refonte)

Cahier des charges § 6 et § 5.1, recette R1 (hors paiement, traité au lot Finance) et R2.

| Brique | Où | Règles clés |
|---|---|---|
| EDB | `/edb`, `PurchaseRequestService` | numéro `EDB-AAAA-n`, imputation site / service / projet / centre de coûts / engin, lignes d'articles ou besoins libres ; circuit : visa hiérarchique (avis) → Direction Logistique → Finance à partir de 5 000 (seuil dans `approval_flow_steps.min_amount`) |
| Statuts | `purchase_requests.status` | en attente → approuvé non exécuté (sous-statut « exécution partielle ») → approuvé exécuté ; annulé (motif obligatoire, refus compris) |
| Satisfaction | `purchase_fulfilments` | écritures datées et justifiées : sortie du stock local (BS), réception de transfert (BT), bon de réception (BR) ; l'EDB n'est exécuté que quand il ne reste rien à satisfaire — ni une commande ni un paiement ne le clôturent |
| Recherche de stock | fiche EDB | disponible local, puis autres magasins (transfert lié à la ligne), sinon achat ; jamais plus que le reste à sourcer |
| Devis et commande | fiche EDB, `/purchases` | devis comparés, choix d'un devis plus cher motivé, bon de commande `BC-AAAA-n` référencé à l'EDB |
| Réception | `/purchases/{id}` | bon de livraison obligatoire, accepté / refusé (motif), transporteur, plaque, chauffeur, scan ; l'acheteur ne réceptionne pas ; l'accepté entre en stock au prix de commande |
| Reste chez le fournisseur | `/purchases#outstanding` | commandé − reçu, valeur et retard par ligne |
| Rapports mensuels | `/edb/reports` | 1. EDB émis, 2. approuvés exécutés, 3. approuvés non intégralement exécutés ; soldes d'ouverture / clôture, nouveaux, annulés, taux d'exécution en nombre et en valeur ; filtres ; exports Excel / PDF |

Lots suivants : transferts d'engins (OTV), finance / caisse, carburant et coûts par équipement.

### Cycle de vie d'un OT et état de l'engin

| Événement | État de l'engin |
|---|---|
| OT ouvert « engin immobilisé » | En panne |
| OT démarré | En maintenance |
| OT en attente de pièce | Attente pièce |
| OT terminé / annulé | recalculé d'après les autres OT ouverts, sinon Disponible |
| Engin « Immobilisé » | ne change que par action manuelle (décision administrative) |

Un technicien n'agit que sur les OT de ses sites qui lui sont assignés ou non assignés (il se les voit attribuer en les démarrant) ; l'annulation est réservée aux responsables.
