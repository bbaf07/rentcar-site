<?php
require __DIR__ . '/inc/app.php';
// Facture d'une location : prix de location et suppléments éventuels (benchmark : accès au contrat et aux paiements).
$pdo = bd();
if (!utilisateur()) { exiger('client'); }
$numLoc = (int) ($_GET['loc'] ?? 0);
if (!peut_voir_location($pdo, $numLoc)) { http_response_code(404); exit('Facture introuvable.'); }
$st = $pdo->prepare("SELECT l.*, r.num_reservation, r.immatriculation, f.libelle AS formule, v.marque, v.modele, v.code_categorie,
                            p.nom, p.prenom, p.adresse, cl.num_client
                     FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN formule f ON f.code = r.code_formule
                     JOIN vehicule v ON v.immatriculation = r.immatriculation JOIN client cl ON cl.num_client = r.num_client
                     JOIN personne p ON p.id_personne = cl.id_personne WHERE l.num_location = ?");
$st->execute([$numLoc]);
$l = $st->fetch();
$pa = $pdo->prepare('SELECT pa.*, cb.numero_masque FROM paiement pa JOIN carte_bancaire cb ON cb.id_carte = pa.id_carte WHERE pa.num_location = ? ORDER BY pa.date_paiement, pa.num_transaction');
$pa->execute([$numLoc]);
$paiements = $pa->fetchAll();
$total = array_sum(array_map(fn($p) => (float) $p['montant'], $paiements));
entete('Facture, location n° ' . $numLoc, null);
?>
<div class="actions no-print" style="margin:0 0 16px"><button class="btn" type="button" data-imprimer>Imprimer ou enregistrer en PDF</button><a class="btn btn--secondaire" href="contrat.php?loc=<?= $numLoc ?>">Contrat</a></div>
<div class="contrat">
  <div class="entete-doc"><div><h2 style="margin:0">RentCar, facture</h2>Facture F-<?= str_pad((string) $numLoc, 5, '0', STR_PAD_LEFT) ?>, location n° <?= $numLoc ?></div>
    <div style="text-align:right"><?= h($l['prenom'] . ' ' . $l['nom']) ?><br><?= h((string) $l['adresse']) ?><br>Client n° <?= (int) $l['num_client'] ?></div></div>
  <p><?= h($l['marque'] . ' ' . $l['modele']) ?>, <?= h($l['immatriculation']) ?>, catégorie <?= h($l['code_categorie']) ?>, du <?= date_fr($l['date_location']) ?> au <?= date_fr($l['date_retour_effective'] ?? $l['date_retour_prevue']) ?><?= $l['date_retour_effective'] ? '' : ' (retour prévu)' ?>.</p>
  <table>
    <thead><tr><th>Désignation</th><th>Date</th><th>Payé par</th><th>Montant TTC</th></tr></thead>
    <tbody>
      <?php foreach ($paiements as $p): ?>
        <tr><td><?= str_starts_with($p['num_transaction'], 'SUP-') ? 'Supplément au retour (retard, carburant ou dommage)' : 'Location, formule ' . h($l['formule']) ?><br><small><?= h($p['num_transaction']) ?></small></td>
          <td><?= date_fr($p['date_paiement']) ?></td><td><?= h($p['numero_masque']) ?></td><td><?= euros((float) $p['montant']) ?></td></tr>
      <?php endforeach; ?>
      <tr><th colspan="3">Total payé</th><th><?= euros($total) ?></th></tr>
    </tbody>
  </table>
  <p>Caution : <?= euros((float) $l['caution']) ?>, empreinte bancaire non débitée, <?= h(mb_strtolower(libelle_etat($l['statut_caution']))) ?><?= $l['date_liberation'] ? ' le ' . date_fr($l['date_liberation']) : '' ?>.</p>
  <p style="font-size:.9rem">Aucun frais de dossier ni assurance ajoutés en dehors des lignes ci-dessus.</p>
</div>
<?php pied();
