# RentCar, application web de location de véhicules

Application PHP / MySQL réalisée à partir du modèle UML du TP 2 (ECE Mastères, *Conception de systèmes d'information*). Les trois acteurs, Visiteur, Client et Employé, y réalisent les 14 cas d'utilisation du modèle. Chaque page affiche sa procédure étape par étape.

Les véhicules sont présentés **en 3D, en rotation**, dans leur couleur réelle.

![Accueil de RentCar](docs/accueil.png)

## Fonctionnalités

- **Recherche** avec filtres (type, catégorie, énergie, boîte, places, prix maximum). Le **prix tout compris** est affiché, avec la caution et la franchise.
- **Réservation** avec annulation gratuite jusqu'à 48 h avant le départ, et **notifications**.
- **Location au comptoir** : vérification de la carte, caution bloquée, contrat consultable par le client.
- **État des lieux avec photos** au départ et au retour. La comparaison est automatique : retard, carburant manquant, dommages nouveaux plafonnés à la franchise.
- **Espace client** : locations, contrat, facture, états des lieux, réservations, notifications, profil.
- **Espace employé** : parc, nouveau véhicule et place de parking, retards et rapport PDF, fiches imprimables.

## Technologies

PHP 8.3, MySQL 8.4 (WampServer), HTML et CSS sans framework, JavaScript, et `<model-viewer>` 3.5 pour la 3D, hébergé dans `assets/js/vendor`.

Sécurité :
- mots de passe hachés en PBKDF2, compatibles avec l'application Java ;
- jeton CSRF ;
- requêtes préparées (PDO) ;
- photos servies uniquement aux personnes autorisées.

## Installation

1. Installer WampServer.
2. Créer la base : exécuter `RentCar-Java/src/main/resources/sql/schema.sql` et `donnees_test.sql`, puis `RentCar-Java/sql/evolution_site.sql` et `donnees_site.sql`. Pour revenir à l'état de démonstration, lancer depuis `RentCar-Java/sql/` :

   ```bash
   mysql --default-character-set=utf8mb4 -h 127.0.0.1 -u root < reinitialiser_demo.sql
   ```

3. Exposer ce dossier sous `C:\wamp64\www\rentcar`, par exemple avec une jonction : `mklink /J C:\wamp64\www\rentcar <chemin>\Site`.
4. Indiquer les identifiants MySQL dans le fichier `db.properties` du projet Java. Son chemin est défini dans `inc/config.php`.
5. Ouvrir http://localhost/rentcar/.

## Comptes de démonstration

Mot de passe : `motdepasse`. Ce sont des données de test, pas de vrais comptes.

| Rôle | Identifiant |
|---|---|
| Employé | `spetit` |
| Client (location en retard) | `kdurand` |
| Client | `lmartin` |

## Documentation

- [RAPPORT.md](RAPPORT.md) : rapport de conception complet (benchmark, choix technique, architecture, tests).
- Page « Crédits photos et 3D » du site : licences des photos (Wikimedia Commons) et des modèles 3D (Poly Pizza).

## Auteurs

NGONGANG NOUDJI Fournier · WABO MBA Miguel Jovan · MPACKO DISSAKE Allegra Anaelle · BOYEKON BAFALAK Alane Bryan. ECE Mastères, 2026-2027.
