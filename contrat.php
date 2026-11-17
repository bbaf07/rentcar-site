<?php
require __DIR__ . '/inc/app.php';
// Contrat de location (UC-06, étape 10) : consultable par l'employé et par le client concerné.
// Pour l'employé, la page enchaîne sur l'état des lieux de départ s'il n'est pas encore fait.
$pdo = bd();
$u = utilisateur();
$numLoc = (int) ($_GET['loc'] ?? 0);
if (!$u) { exiger('client'); }
if (!peut_voir_location($pdo, $numLoc)) {
    http_response_code(404);
    entete('Contrat introuvable', null);
    echo '<div class="vide">Ce contrat n\'existe pas ou ne vous concerne pas.</div>';
    pied();
    exit;
}
$st = $pdo->prepare("SELECT l.*, r.num_reservation, r.num_client, r.date_debut, r.date_fin, r.immatriculation, f.libelle AS formule_libelle,
                            v.marque, v.modele, v.couleur, v.type, v.code_categorie, v.num_emplacement, v.nb_places, v.boite, v.energie,
                            c.libelle AS categorie_libelle, c.franchise, e.zone, p.nom, p.prenom, p.adresse, cl.num_permis
                     FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN formule f ON f.code = r.code_formule
                     JOIN vehicule v ON v.immatriculation = r.immatriculation JOIN categorie c ON c.code = v.code_categorie
                     LEFT JOIN emplacement e ON e.numero = v.num_emplacement
                     JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                     WHERE l.num_location = ?");
$st->execute([$numLoc]);
$l = $st->fetch();
$pa = $pdo->prepare('SELECT pa.*, cb.numero_masque FROM paiement pa JOIN carte_bancaire cb ON cb.id_carte = pa.id_carte WHERE pa.num_location = ? ORDER BY pa.date_paiement, pa.num_transaction');
$pa->execute([$numLoc]);
$paiements = $pa->fetchAll();
$depart = charger_etat($pdo, $numLoc, 'DEPART');
$estEmploye = $u['role'] === 'employe';

entete('Contrat de location n° ' . $numLoc, $estEmploye ? 'UC-06' : null, $estEmploye ? ($depart ? 11 : 10) : 0);
?>
<?php if ($estEmploye && !$depart && !$l['date_retour_effective']): ?>
  <div class="etape-suivante no-print">
    <p><b>Étape suivante : l'état des lieux de départ.</b> Faites le tour du véhicule avec le client et notez chaque zone, avec des photos. Il servira de référence au retour.</p>
    <a class="btn" href="etat-des-lieux.php?loc=<?= $numLoc ?>">Faire l'état des lieux de départ</a>
  </div>
<?php endif; ?>
<div class="contrat">
  <div class="entete-doc"><div><h2 style="margin:0">RentCar, contrat de location</h2>Location n° <?= $numLoc ?>, réservation n° <?= (int) $l['num_reservation'] ?></div>
    <div style="text-align:right">Établi le <?= date_fr($l['date_location']) ?></div></div>
  <table>
    <tr><th>Client</th><td><?= h($l['prenom'] . ' ' . $l['nom']) ?>, permis <?= h($l['num_permis']) ?><br><?= h((string) $l['adresse']) ?></td></tr>
    <tr><th>Véhicule</th><td><?= h($l['marque'] . ' ' . $l['modele']) ?>, <?= h($l['immatriculation']) ?>, catégorie <?= h($l['code_categorie'] . ' ' . $l['categorie_libelle']) ?>, <?= (int) $l['nb_places'] ?> places, <?= h(mb_strtolower(libelle_boite($l['boite']))) ?>, <?= h(mb_strtolower(libelle_energie($l['energie']))) ?></td></tr>
    <tr><th>Période</th><td>du <?= date_fr($l['date_location']) ?> au <?= date_fr($l['date_retour_prevue']) ?> (retour prévu)<?= $l['date_retour_effective'] ? ', rendu le ' . date_fr($l['date_retour_effective']) : '' ?></td></tr>
    <tr><th>Formule</th><td><?= h($l['formule_libelle']) ?></td></tr>
    <tr><th>Compteur au départ</th><td><?= number_format((int) $l['km_depart'], 0, ',', ' ') ?> km<?= $depart ? ', carburant ' . number_format((float) $depart['carburant'], 1, ',', ' ') . ' L' : '' ?></td></tr>
    <tr><th>Prix payé</th><td><?= euros((float) $l['montant_paye']) ?>, tout compris (aucun frais de dossier ni assurance ajoutés)</td></tr>
    <tr><th>Caution</th><td><?= euros((float) $l['caution']) ?>, empreinte bancaire non débitée ; <?= h(mb_strtolower(libelle_etat($l['statut_caution']))) ?><?= $l['date_liberation'] ? ' le ' . date_fr($l['date_liberation']) : '' ?></td></tr>
    <tr><th>Franchise</th><td><?= euros((float) $l['franchise']) ?> au plus, en cas de dommage absent de l'état des lieux de départ</td></tr>
    <tr><th>Carburant et retard</th><td>Réservoir au même niveau qu'au départ, sinon <?= euros(PRIX_LITRE_CARBURANT) ?> par litre manquant ; retard facturé au tarif journée par jour</td></tr>
  </table>
  <h3>Paiements</h3>
  <table>
    <thead><tr><th>Transaction</th><th>Date</th><th>Carte</th><th>Montant</th></tr></thead>
    <tbody><?php foreach ($paiements as $p): ?><tr><td><?= h($p['num_transaction']) ?><?= str_starts_with($p['num_transaction'], 'SUP-') ? ' (supplément au retour)' : '' ?></td><td><?= date_fr($p['date_paiement']) ?></td><td><?= h($p['numero_masque']) ?></td><td><?= euros((float) $p['montant']) ?></td></tr><?php endforeach; ?></tbody>
  </table>
  <div class="emplacement-final">Emplacement du véhicule <span><?= h((string) $l['num_emplacement']) ?><?= $l['zone'] ? ', ' . h($l['zone']) : '' ?></span></div>
  <?php if ($depart): ?><h3>État des lieux de départ</h3><?= vue_etat($depart) ?><?php endif; ?>
</div>
<div class="actions no-print">
  <button class="btn" type="button" data-imprimer>Imprimer le contrat</button>
  <a class="btn btn--secondaire" href="facture.php?loc=<?= $numLoc ?>">Facture</a>
  <a class="btn btn--secondaire" href="etat-des-lieux.php?loc=<?= $numLoc ?>">États des lieux</a>
  <?php if ($estEmploye): ?><a href="louer.php">Nouvelle location</a><?php else: ?><a href="espace-client.php?onglet=locations">Retour à mon espace</a><?php endif; ?>
</div>
<?php pied();
