<?php
require __DIR__ . '/inc/app.php';
// Lettre de relance imprimable (UC-09, étape 3).
$pdo = bd();
$u = exiger('employe');
$st = $pdo->prepare("SELECT l.*, r.immatriculation, v.marque, v.modele, p.nom, p.prenom, p.adresse, DATEDIFF(CURDATE(), l.date_retour_prevue) AS jours
                     FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN vehicule v ON v.immatriculation = r.immatriculation
                     JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne WHERE l.num_location = ?");
$st->execute([(int) ($_GET['loc'] ?? 0)]);
$l = $st->fetch();
if (!$l) { http_response_code(404); exit('Location introuvable.'); }
entete('Lettre de relance', 'UC-09', 3);
?>
<div class="actions no-print" style="margin:0 0 16px"><button class="btn" data-imprimer>Imprimer la lettre</button><a class="btn btn--secondaire" href="retards.php">Retour</a></div>
<div class="contrat" style="max-width:760px">
  <p><b>RentCar</b><br>Agence de location de véhicules</p>
  <p style="margin-left:55%"><?= h($l['prenom'] . ' ' . $l['nom']) ?><br><?= nl2br(h((string) $l['adresse'])) ?></p>
  <p style="margin-left:55%">Le <?= date_fr(date('Y-m-d')) ?></p>
  <p><b>Objet : retour de votre véhicule de location n° <?= (int) $l['num_location'] ?></b></p>
  <p>Madame, Monsieur,</p>
  <p>Le véhicule <?= h($l['marque'] . ' ' . $l['modele']) ?> immatriculé <?= h($l['immatriculation']) ?>, que vous avez loué le <?= date_fr($l['date_location']) ?>, devait être rendu le <?= date_fr($l['date_retour_prevue']) ?>. À ce jour, il n'a pas été restitué, soit un retard de <?= (int) $l['jours'] ?> jour<?= $l['jours'] > 1 ? 's' : '' ?>.</p>
  <p>Nous vous remercions de le rapporter à l'agence dans les meilleurs délais. Conformément aux conditions de location, chaque jour de retard est facturé au tarif journée de la catégorie du véhicule, ainsi que le carburant manquant.</p>
  <p>Veuillez agréer, Madame, Monsieur, nos salutations distinguées.</p>
  <p style="margin-left:55%"><?= h($u['prenom'] . ' ' . $u['nom']) ?><br>RentCar</p>
</div>
<?php pied();
