<?php
// Batch de 22h00 (acteur Horloge, UC-14). À lancer en ligne de commande par une tâche planifiée :
//   C:\wamp64\bin\php\php8.3.28\php.exe "C:\...\Site\batch_retards.php"
// Refuse d'être appelé depuis le navigateur.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script se lance en ligne de commande.');
}
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/rapport.php';
date_default_timezone_set('Europe/Paris');
$err = null;
$pdo = connexion_rentcar($err);
if (!$pdo) {
    fwrite(STDERR, "Base indisponible : $err\n");
    exit(1);
}
$id = generer_rapport_retards($pdo);
echo "Rapport des retards n° $id généré le " . date('d/m/Y H:i') . "\n";
