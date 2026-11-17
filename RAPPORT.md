# Rapport de conception : RentCar, application web de location de véhicules

**Auteurs** : NGONGANG NOUDJI Fournier, WABO MBA Miguel Jovan, MPACKO DISSAKE Allegra Anaelle, BOYEKON BAFALAK Alane Bryan (ECE Mastères, groupe du TP 2)
**Date** : 17/11/2026
**Dépôt** : à créer sur GitHub
**Site** : local, servi par WampServer à l'adresse `http://localhost/rentcar/`

## Résumé

RentCar est l'application web d'une agence de location de voitures et d'utilitaires. Elle a été réalisée à partir du modèle UML du TP 2 *Conception de systèmes d'information*. Ses trois acteurs, Visiteur, Client et Employé, y réalisent les 14 cas d'utilisation du modèle. Chaque page affiche la procédure du cas d'utilisation, étape par étape, et signale l'étape en cours.

Avant d'améliorer le site, nous avons étudié cinq loueurs (Europcar, Sixt, Hertz, ADA, Getaround) et les avis de leurs clients. Quatre améliorations en ont découlé :

- un **prix tout compris** ;
- un **état des lieux avec photos** au départ et au retour ;
- un **espace client complet** ;
- des **filtres et des notifications**.

Les véhicules sont présentés **en 3D, en rotation**. Techniquement, RentCar est en PHP 8.3 et MySQL 8.4 et partage sa base avec l'application Java du TP.

## 1. Contexte et objectifs

- **Besoin.** L'énoncé demande de modéliser et de prototyper le système d'information d'une agence de location. Le site doit représenter les acteurs, leurs fonctionnalités et les étapes de chaque procédure.
- **Publics.**
  - Les **visiteurs** comparent et cherchent un véhicule.
  - Les **clients** réservent et suivent leurs locations.
  - Les **employés** gèrent le comptoir : location, retour, parc, retards et fiches.
- **Contraintes.**
  - Une solution de type LAMP, avec WampServer.
  - La même base MySQL que le projet Java, et les mêmes règles de gestion (RG1 à RG10).
  - Des mots de passe hachés de façon compatible avec Java (PBKDF2).
- **Critères de réussite.**
  - Chaque cas d'utilisation est jouable de bout en bout.
  - Les erreurs relevées dans les avis du benchmark sont corrigées.
  - Le site est lisible sur téléphone.

## 2. Étude des sites similaires

L'étude complète se trouve dans `recherche/benchmark.md`. Les avis viennent de 5 fiches Trustpilot et de 3 applications sur Google Play.

| Site | Note Trustpilot | Ce que les clients apprécient | Ce qu'ils reprochent |
|---|---|---|---|
| Europcar | 1,5 / 5 (7 884 avis) | réservation facile (appli notée 4,8) | frais et caution annoncés trop tard, dégâts contestés |
| Sixt | 3,8 / 5 (9 101 avis) | état des lieux avec photos, parcours simple | véhicule réservé mais absent, paiement en erreur sans explication |
| Hertz | 1,2 / 5 (1 874 avis) | programme de fidélité | contrat introuvable, frais imprévus |
| ADA | 4,2 / 5 (20 948 avis) | prix, proximité | qualité variable selon les agences |
| Getaround | 4,2 / 5 (112 490 avis) | filtres, appli fluide, annulation gratuite 48 h avant | litiges sur le carburant |

**Ce que nous avons repris :**
- la réservation en quelques étapes ;
- les filtres de recherche (Getaround) ;
- l'état des lieux avec photos (Sixt) ;
- l'annulation gratuite jusqu'à 48 h avant le départ.

**Ce que nous avons évité :**
- les frais cachés : le prix total, la caution et la franchise sont affichés dès la recherche ;
- le contrat introuvable : il est consultable dans l'espace client ;
- les refus de paiement sans explication : le message dit pourquoi et quoi faire ;
- l'absence de nouvelles après la réservation : le client reçoit des notifications.

Les témoignages publiés par les loueurs eux-mêmes sont sélectionnés par la marque. Nous ne les avons pas utilisés comme preuve.

## 3. Choix technique

Le détail se trouve dans `recherche/choix-technique.md`. Quatre options ont été comparées : PHP + MySQL (l'existant), PHP + services Java REST, Laravel, React + API PHP.

**Option retenue : PHP + MySQL**, servis par WampServer. Trois raisons :
- elle répond directement à l'exigence LAMP de l'énoncé ;
- elle conserve les 14 cas d'utilisation déjà réalisés ;
- le benchmark montre que les attentes des clients portent sur la transparence, pas sur la technologie.

L'hébergement est local. Un hébergement mutualisé PHP / MySQL suffirait pour une mise en ligne.

## 4. Architecture

```
Site/
├── index.php, recherche.php, tarifs.php, inscription.php, connexion.php    (visiteur)
├── reserver.php, espace-client.php, contrat.php, facture.php               (client)
├── louer.php, rendre.php, etat-des-lieux.php, reservations.php, vehicules.php,
│   vehicule-nouveau.php, retards.php, fiches.php, espace-employe.php       (employé)
├── photo.php        photos d'état des lieux servies après contrôle d'accès
├── inc/
│   ├── app.php          gabarit, session, CSRF, carte véhicule
│   ├── db.php           connexion PDO (identifiants lus dans db.properties du projet Java)
│   ├── metier.php       disponibilités, tarifs (RG1 à RG10)
│   ├── amelioration.php prix tout compris, caution, état des lieux, notifications
│   ├── uc.php           les 14 cas d'utilisation et leurs étapes
│   └── voiture3d.php    affichage 3D des véhicules
├── assets/ (css, js, 3d, voitures)
└── uploads/ (photos d'état des lieux ; hors Git, accès refusé par Apache)
```

**Base de données.** Les tables du TP sont : categorie, tarif, vehicule, client, employe, reservation, location et paiement. Le site y ajoute :
- les tables `etat_des_lieux`, `constat_zone`, `photo_etat` et `notification` ;
- des colonnes pour la caution, la franchise et les caractéristiques des véhicules.

Ces ajouts sont dans `RentCar-Java/sql/evolution_site.sql`.

**Flux principal, de la recherche au retour :**
1. Le client recherche un véhicule (UC-01) et voit le prix total, la caution et la franchise.
2. Il réserve (UC-05) et reçoit une notification.
3. Au comptoir, l'employé crée la location (UC-06). La carte est vérifiée (UC-11), la caution est bloquée, et le contrat est consultable par le client.
4. L'employé saisit l'état des lieux de départ, avec photos.
5. Au retour (UC-07), il saisit l'état des lieux de retour. Le site compare les deux et calcule le retard, le carburant manquant et les dommages nouveaux, plafonnés à la franchise.
6. Le supplément est payé (UC-12), la caution est libérée et la facture est disponible.

## 5. Design et expérience utilisateur

- **Thème : la route.**
  - Couleurs : bitume `#262A31`, béton `#E8E9E5`, jaune de marquage au sol `#F2C230`, bleu de plaque `#1F4EA6`.
  - Les immatriculations sont dessinées comme de vraies plaques françaises.
- **Typographie.** Barlow Condensed pour les titres, Barlow pour le texte, JetBrains Mono pour les codes.
- **Véhicules en 3D.**
  - Des modèles glTF libres (CC0 et CC BY) tournent lentement grâce au composant `<model-viewer>`, hébergé sur le site.
  - Chaque modèle est repeint avec la couleur réelle du véhicule.
  - La photo réelle s'affiche pendant le chargement et sert de solution de repli.
  - La rotation s'arrête si l'utilisateur a choisi de réduire les animations.
- **Panneau « Procédure ».** Chaque page rappelle son cas d'utilisation, ses acteurs, ses règles de gestion et ses étapes. L'étape en cours est mise en évidence.
- **Responsive et accessibilité.**
  - Un menu replié s'affiche sur téléphone, sans défilement horizontal à 390 px de large.
  - La navigation se fait au clavier, avec un focus visible.
  - Un thème sombre est disponible.

Captures : `rapport/captures/` (14 écrans, ordinateur et téléphone).

## 6. Fonctionnalités

| Fonctionnalité | Description | Page | Statut |
|---|---|---|---|
| UC-01 Rechercher | dates, type, filtres, prix tout compris, tri par prix | recherche.php | fait |
| UC-02 Tarifs | grille et simulateur de prix | tarifs.php | fait |
| UC-03 / UC-04 | inscription, connexion (PBKDF2, CSRF) | inscription.php, connexion.php | fait |
| UC-05 Réserver | récapitulatif avec caution et franchise, notification | reserver.php | fait |
| UC-06 Louer et UC-11 Vérifier la carte | caution bloquée, messages de refus clairs, contrat | louer.php, contrat.php | fait |
| État des lieux | 7 zones, carburant, kilométrage, propreté, 8 photos au plus | etat-des-lieux.php | fait |
| UC-07 Rendre et UC-12 Facturer un supplément | comparaison des états des lieux, supplément, libération de la caution, facture | rendre.php, facture.php | fait |
| UC-08 et UC-13 | nouveau véhicule et place de parking | vehicule-nouveau.php | fait |
| UC-09 et UC-14 | relance des retards et rapport PDF | retards.php | fait |
| UC-10 Fiches | fiche récapitulative imprimable | fiches.php | fait |
| Espace client | locations, réservations (annulation gratuite 48 h avant), notifications, profil | espace-client.php | fait |
| Vue 3D | véhicules en rotation, dans leur couleur réelle | toutes les pages véhicule | fait |

## 7. Historique des modifications

Le détail se trouve dans `rapport/journal-modifications.md`.

1. Le site de présentation devient une application complète : acteurs, cas d'utilisation et procédures.
2. Photos de vrais véhicules (Wikimedia Commons) ; page sur le modèle UML retirée.
3. Benchmark, puis choix technique : PHP + MySQL.
4. Prix tout compris, filtres et notifications.
5. État des lieux avec photos ; contrat consultable par le client.
6. Retour comparé, facture et espace client complet.
7. Véhicules en 3D, en rotation.

## 8. Tests et vérifications

Les scénarios ont été joués de bout en bout dans le navigateur, puis rejoués automatiquement par un script qui pilote Edge et prend les 14 captures.

- **Filtres.** La recherche « touristique, automatique » renvoie 2 véhicules, triés par prix.
- **Location.** Un refus de paiement affiche un message explicite. Une fois le paiement accepté, le contrat est créé et l'état des lieux de départ s'enregistre avec 2 photos.
- **Retour en retard.**
  - Karim rend son véhicule avec 5 jours de retard. Le site calcule 245 € de retard, 33 € de carburant et 300 € de réparation, soit 578 € de supplément.
  - La caution est libérée et la facture s'élève à 823 €.
- **Contrôle d'accès.** Le contrat, la facture ou les photos d'un autre client renvoient une erreur 404.
- **Téléphone.** Aucun débordement à 390 px de large.
- **3D.** Les 9 vues 3D sont chargées et prennent leur couleur réelle (vérifié dans Edge).

## 9. Déploiement et utilisation

1. Installer WampServer (PHP 8.3, MySQL 8.4).
2. Exécuter dans l'ordre `RentCar-Java/src/main/resources/sql/schema.sql`, puis `donnees_test.sql`, puis `RentCar-Java/sql/evolution_site.sql`, puis `donnees_site.sql`. Le script `reinitialiser_demo.sql` remet la démonstration à zéro ; lancez `mysql` avec `--default-character-set=utf8mb4` pour garder les accents.
3. Rendre le dossier `Site/` accessible sous `C:\wamp64\www\rentcar`, par exemple avec une jonction : `mklink /J C:\wamp64\www\rentcar <chemin>\Site`.
4. Renseigner les identifiants MySQL dans `RentCar-Java/src/main/resources/db.properties`. Ce fichier est situé hors du dossier web et n'est pas versionné avec le site.
5. Ouvrir `http://localhost/rentcar/`.

Les comptes de démonstration sont listés dans le README.

## 10. Limites et améliorations possibles

- Les paiements et les e-mails sont **simulés** : il faudrait brancher un prestataire de paiement en mode test et un serveur SMTP.
- Il n'y a pas de procédure « mot de passe oublié », alors que le benchmark en relève le besoin.
- Les modèles 3D sont génériques, un par silhouette, et non le modèle exact de chaque véhicule.
- La logique métier existe en double, en PHP et en Java. L'API Spring Boot `autoloc-api` (workshops UP ASI) montre la voie d'un service unique.
- Il n'y a pas de tests automatisés PHP. PHPUnit serait la prochaine étape.

## Annexes

### A. Sources du benchmark

Trustpilot (fiches Europcar, Sixt, Hertz, ADA, Getaround), Google Play (applications Europcar, Sixt, Getaround) et les sites officiels des loueurs. Toutes les sources sont détaillées dans `recherche/benchmark.md`.

### B. Correspondance avec les attentes de l'énoncé

| Attente | Où |
|---|---|
| Représenter les acteurs | menus par acteur, page d'accueil |
| Représenter les fonctionnalités (cas d'utilisation) | 14 pages, panneau « Procédure » |
| Afficher les étapes de chaque procédure | panneau « Procédure », étape en cours signalée |
| Respecter les règles de gestion RG1 à RG10 | `inc/metier.php`, règles citées sur chaque page |
| Utiliser WampServer et une solution LAMP | sections 3 et 9 |

### C. Organisation du groupe

La répartition détaillée de l'oral et du rapport figure dans le rapport complet du TP 2 (`TP2_UML_RentCar_Rapport_complet.docx`, annexe « Organisation du groupe »).
