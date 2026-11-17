<?php
require __DIR__ . '/inc/app.php';
// Sert une photo d'état des lieux, seulement à l'employé ou au client concerné (le dossier uploads/ est fermé au public).
$pdo = bd();
$st = $pdo->prepare('SELECT p.fichier, e.num_location FROM photo_etat p JOIN etat_des_lieux e ON e.id_etat = p.id_etat WHERE p.id_photo = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$p = $st->fetch();
$chemin = $p ? realpath(DOSSIER_PHOTOS . '/' . basename($p['fichier'])) : false;
if (!$p || !$chemin || !peut_voir_location($pdo, (int) $p['num_location'])) {
    http_response_code(404);
    exit('Photo introuvable.');
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[pathinfo($chemin, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($chemin);
