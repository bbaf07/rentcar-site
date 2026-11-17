<?php
require __DIR__ . '/inc/app.php';
// Version imprimable (PDF via l'impression du navigateur) d'un rapport des retards.
$pdo = bd();
exiger('employe');
$r = $pdo->prepare('SELECT * FROM rapport_retards WHERE id_rapport = ?');
$r->execute([(int) ($_GET['id'] ?? 0)]);
$r = $r->fetch();
if (!$r) { http_response_code(404); exit('Rapport introuvable.'); }
$st = $pdo->prepare("SELECT l.*, r.immatriculation, v.marque, v.modele, p.nom, p.prenom, p.adresse, p.telephone, DATEDIFF(DATE(:d), l.date_retour_prevue) AS jours
                     FROM rapport_location rl JOIN location l ON l.num_location = rl.num_location JOIN reservation r ON r.num_reservation = l.num_reservation
                     JOIN vehicule v ON v.immatriculation = r.immatriculation JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                     WHERE rl.id_rapport = :id ORDER BY l.date_retour_prevue");
$st->execute([':d' => $r['date_generation'], ':id' => $r['id_rapport']]);
$lignes = $st->fetchAll();
entete('Rapport des retards n° ' . $r['id_rapport'], null);
?>
<div class="actions no-print" style="margin:0 0 16px"><button class="btn" data-imprimer>Imprimer ou enregistrer en PDF</button><a class="btn btn--secondaire" href="retards.php">Retour</a></div>
<div class="contrat">
  <div class="entete-doc"><div><h2 style="margin:0">RentCar, rapport des retards</h2>Rapport n° <?= (int) $r['id_rapport'] ?></div><div style="text-align:right">Généré le <?= (new DateTime($r['date_generation']))->format('d/m/Y à H:i') ?><br>par l'horloge (22h00)</div></div>
  <?php if (!$lignes): ?><p>Aucune location en retard.</p><?php else: ?>
  <table>
    <thead><tr><th>Client</th><th>Adresse postale</th><th>Véhicule</th><th>Retour prévu</th><th>Retard</th></tr></thead>
    <tbody><?php foreach ($lignes as $l): ?><tr><td><?= h($l['prenom'] . ' ' . $l['nom']) ?><br><?= h((string) $l['telephone']) ?></td><td><?= h((string) $l['adresse']) ?></td><td><?= h($l['marque'] . ' ' . $l['modele'] . ', ' . $l['immatriculation']) ?></td><td><?= date_fr($l['date_retour_prevue']) ?></td><td><?= (int) $l['jours'] ?> jour<?= $l['jours'] > 1 ? 's' : '' ?></td></tr><?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <p style="font-size:.9rem">À relancer par courrier postal le lendemain (RG9).</p>
</div>
<?php pied();
