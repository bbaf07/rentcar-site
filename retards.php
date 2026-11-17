<?php
require __DIR__ . '/inc/app.php';
// UC-14 Générer le rapport des retards (acteur Horloge, 22h00) et UC-09 Relancer les clients en retard (Employé).
$pdo = bd();
exiger('employe');
require_once __DIR__ . '/inc/rapport.php';

$ext = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'generer') {
    verifier_jeton();
    $id = generer_rapport_retards($pdo);
    flash('ok', "Rapport n° $id généré (déclenchement de l'horloge simulé).");
    rediriger('retards.php');
}
$dernier = $pdo->query('SELECT * FROM rapport_retards ORDER BY date_generation DESC, id_rapport DESC LIMIT 1')->fetch();
$lignes = [];
if ($dernier) {
    $st = $pdo->prepare("SELECT l.*, r.immatriculation, v.marque, v.modele, p.nom, p.prenom, p.adresse, p.telephone,
                                DATEDIFF(DATE(:d), l.date_retour_prevue) AS jours_retard
                         FROM rapport_location rl JOIN location l ON l.num_location = rl.num_location
                         JOIN reservation r ON r.num_reservation = l.num_reservation JOIN vehicule v ON v.immatriculation = r.immatriculation
                         JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                         WHERE rl.id_rapport = :id ORDER BY l.date_retour_prevue");
    $st->execute([':d' => $dernier['date_generation'], ':id' => $dernier['id_rapport']]);
    $lignes = $st->fetchAll();
} else {
    $ext[] = '1a';
}
$historique = $pdo->query('SELECT rr.*, (SELECT COUNT(*) FROM rapport_location rl WHERE rl.id_rapport = rr.id_rapport) AS nb FROM rapport_retards rr ORDER BY date_generation DESC LIMIT 10')->fetchAll();
$actuels = count(locations_en_retard($pdo, date('Y-m-d')));
entete('Relancer les clients en retard', 'UC-09', $dernier ? ($lignes ? 3 : 2) : 1, $ext);
$uc14 = cas()['UC-14'];
?>
<section class="formulaire" style="border-color:var(--marquage);border-width:2px">
  <h2><span class="baie">UC-14</span> Horloge : rapport de 22h00</h2>
  <p class="gris">Acteur temporel : chaque soir à 22h00, le système recense les locations en retard et produit le rapport PDF (RG9). En production, une tâche planifiée lance <code>php batch_retards.php</code> ; ici, vous pouvez simuler le déclenchement.</p>
  <ol><?php foreach ($uc14['etapes'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ol>
  <form method="post" class="actions"><?= champ_jeton() ?><button class="btn" name="action" value="generer">Simuler le déclenchement de 22h00</button><span class="gris"><?= $actuels ?> location<?= $actuels > 1 ? 's' : '' ?> en retard aujourd'hui</span></form>
</section>

<h2>Dernier rapport</h2>
<?php if (!$dernier): ?>
  <div class="vide">Aucun rapport n'a encore été généré. Utilisez le bouton ci-dessus pour simuler l'horloge.</div>
<?php else: ?>
  <p>Rapport n° <?= (int) $dernier['id_rapport'] ?> du <?= (new DateTime($dernier['date_generation']))->format('d/m/Y à H:i') ?> : <b><?= count($lignes) ?> client<?= count($lignes) > 1 ? 's' : '' ?> en retard</b>. <a href="<?= h($dernier['fichier_pdf']) ?>" target="_blank">Ouvrir le rapport imprimable (PDF)</a></p>
  <?php if (!$lignes): ?><div class="vide">Aucun retard dans ce rapport : personne à relancer.</div>
  <?php else: ?>
    <div class="defile"><table class="table">
      <thead><tr><th>Client</th><th>Adresse</th><th>Véhicule</th><th>Retour prévu</th><th>Retard</th><th>3. Relance</th></tr></thead>
      <tbody><?php foreach ($lignes as $l): ?><tr>
        <td><?= h($l['prenom'] . ' ' . $l['nom']) ?><br><small class="gris"><?= h((string) $l['telephone']) ?></small></td><td><?= h((string) $l['adresse']) ?></td>
        <td><?= h($l['marque'] . ' ' . $l['modele']) ?> <?= plaque($l['immatriculation']) ?></td><td><?= date_fr($l['date_retour_prevue']) ?></td>
        <td><span class="badge badge--RETARD"><?= (int) $l['jours_retard'] ?> j</span></td>
        <td><a class="btn btn--petit" href="lettre.php?loc=<?= (int) $l['num_location'] ?>" target="_blank">Lettre de relance</a></td></tr><?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($historique): ?>
  <h2 style="margin-top:32px">Historique des rapports</h2>
  <div class="defile"><table class="table">
    <thead><tr><th>N°</th><th>Généré le</th><th>Retards</th><th></th></tr></thead>
    <tbody><?php foreach ($historique as $hst): ?><tr><td><?= (int) $hst['id_rapport'] ?></td><td><?= (new DateTime($hst['date_generation']))->format('d/m/Y H:i') ?></td><td><?= (int) $hst['nb'] ?></td><td><a href="<?= h($hst['fichier_pdf']) ?>" target="_blank">Rapport imprimable</a></td></tr><?php endforeach; ?></tbody>
  </table></div>
<?php endif; ?>
<?php pied();
