<?php
// Le site lit les identifiants MySQL dans le fichier de configuration du projet Java :
// un seul endroit pour le mot de passe, et il ne se retrouve jamais dans le dossier www.
// Chemin modifiable par la variable d'environnement RENTCAR_DB_PROPERTIES ; par défaut, le projet Java voisin du site.
define('RENTCAR_DB_PROPERTIES', getenv('RENTCAR_DB_PROPERTIES')
    ?: dirname(realpath(__DIR__), 2) . '/RentCar-Java/src/main/resources/db.properties');
