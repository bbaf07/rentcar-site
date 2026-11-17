<?php
require __DIR__ . '/inc/app.php';
// UC-10 Imprimer une fiche récapitulative, spécialisé en fiche client, fiche véhicule et fiche location (RG10).
$pdo = bd();
$u = exiger('employe');
$type = (string) ($_GET['type'] ?? '');
$id = (string) ($_GET['id'] ?? '');
$types = ['client' => 'Fiche client', 'vehicule' => 'Fiche véhicule', 'location' => 'Fiche location'];
if (!isset($types[$type])) { $type = ''; }

$choix = match ($type) {
    'client' => $pdo->query("SELECT c.num_client AS id, CONCAT(p.nom, ' ', p.prenom, ' (n° ', c.num_client, ')') AS lib FROM client c JOIN personne p ON p.id_personne = c.id_personne ORDER BY p.nom")->fetchAll(),
    'vehicule' => $pdo->query("SELECT immatriculation AS id, CONCAT(immatriculation, ' | ', marque, ' ', modele) AS lib FROM vehicule ORDER BY immatriculation")->fetchAll(),
    'location' => $pdo->query("SELECT l.num_location AS id, CONCAT('n° ', l.num_location, ' | ', p.nom, ' ', p.prenom, ' | ', r.immatriculation, ' | ', DATE_FORMAT(l.date_location, '%d/%m/%Y')) AS lib
                               FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN client cl ON cl.num_client = r.num_client
                               JOIN personne p ON p.id_personne = cl.id_personne ORDER BY l.num_location DESC")->fetchAll(),
    default => [],
};
$fiche = null; $sous = [];
if ($type && $id !== '') {
    if ($type === 'client') {
        $st = $pdo->prepare('SELECT p.*, c.num_client, c.num_permis, c.date_inscription FROM client c JOIN personne p ON p.id_personne = c.id_personne WHERE c.num_client = ?');
        $st->execute([(int) $id]);
        $fiche = $st->fetch();
        if ($fiche) {
            $st = $pdo->prepare("SELECT r.num_reservation, r.date_debut, r.date_fin, r.statut, r.immatriculation, v.marque, v.modele, l.num_location, l.montant_paye, l.date_retour_effective
                                 FROM reservation r JOIN vehicule v ON v.immatriculation = r.immatriculation LEFT JOIN location l ON l.num_reservation = r.num_reservation
                                 WHERE r.num_client = ? ORDER BY r.date_debut DESC");
            $st->execute([(int) $id]);
            $sous = $st->fetchAll();
        }
    } elseif ($type === 'vehicule') {
        $st = $pdo->prepare('SELECT v.*, c.libelle AS categorie_libelle, e.zone FROM vehicule v JOIN categorie c ON c.code = v.code_categorie LEFT JOIN emplacement e ON e.numero = v.num_emplacement WHERE v.immatriculation = ?');
        $st->execute([$id]);
        $fiche = $st->fetch();
        if ($fiche) {
            $st = $pdo->prepare("SELECT l.num_location, l.date_location, l.date_retour_prevue, l.date_retour_effective, l.km_depart, l.km_retour, p.nom, p.prenom
                                 FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN client cl ON cl.num_client = r.num_client
                                 JOIN personne p ON p.id_personne = cl.id_personne WHERE r.immatriculation = ? ORDER BY l.date_location DESC");
            $st->execute([$id]);
            $sous = $st->fetchAll();
        }
    } else {
        $st = $pdo->prepare("SELECT l.*, r.num_reservation, r.date_debut, r.date_fin, r.immatriculation, f.libelle AS formule, v.marque, v.modele, v.code_categorie,
                                    p.nom, p.prenom, p.adresse, cl.num_client, cl.num_permis
                             FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN formule f ON f.code = r.code_formule
                             JOIN vehicule v ON v.immatriculation = r.immatriculation JOIN client cl ON cl.num_client = r.num_client
                             JOIN personne p ON p.id_personne = cl.id_personne WHERE l.num_location = ?");
        $st->execute([(int) $id]);
        $fiche = $st->fetch();
        if ($fiche) {
            $st = $pdo->prepare('SELECT pa.*, cb.numero_masque FROM paiement pa JOIN carte_bancaire cb ON cb.id_carte = pa.id_carte WHERE pa.num_location = ? ORDER BY pa.date_paiement');
            $st->execute([(int) $id]);
            $sous = $st->fetchAll();
        }
    }
}
$etape = !$type ? 1 : ($fiche ? 4 : 2);
entete('Imprimer une fiche récapitulative', 'UC-10', $etape);
?>
<form class="formulaire no-print" method="get">
  <div class="grille-champs">
    <div class="champ"><label for="type">1. Type de fiche</label><select id="type" name="type" onchange="this.form.id.value='';this.form.submit()"><option value="">Choisir…</option>
      <?php foreach ($types as $k => $lib): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= h($lib) ?></option><?php endforeach; ?></select></div>
    <div class="champ"><label for="id">2. Élément</label><select id="id" name="id"<?= $type ? '' : ' disabled' ?>><option value="">Choisir…</option>
      <?php foreach ($choix as $c): ?><option value="<?= h((string) $c['id']) ?>"<?= (string) $c['id'] === $id ? ' selected' : '' ?>><?= h($c['lib']) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="actions"><button class="btn" type="submit">3. Composer la fiche</button></div>
</form>

<?php if ($type && $id !== '' && !$fiche): ?><div class="vide">Élément introuvable.</div><?php endif; ?>
<?php if ($fiche): ?>
  <div class="actions no-print" style="margin:0 0 16px"><button class="btn" data-imprimer>4. Imprimer la fiche</button></div>
  <div class="contrat">
    <div class="entete-doc"><div><h2 style="margin:0">RentCar, <?= h(mb_strtolower($types[$type])) ?></h2>
      <?= $type === 'client' ? 'Client n° ' . (int) $fiche['num_client'] : ($type === 'vehicule' ? h($fiche['immatriculation']) : 'Location n° ' . (int) $fiche['num_location']) ?></div>
      <div style="text-align:right">Imprimée le <?= date_fr(date('Y-m-d')) ?><br>par <?= h($u['prenom'] . ' ' . $u['nom']) ?></div></div>

    <?php if ($type === 'client'): ?>
      <table>
        <tr><th>Nom</th><td><?= h($fiche['prenom'] . ' ' . $fiche['nom']) ?></td><th>Permis</th><td><?= h($fiche['num_permis']) ?></td></tr>
        <tr><th>Adresse</th><td><?= h((string) $fiche['adresse']) ?></td><th>Inscrit le</th><td><?= date_fr($fiche['date_inscription']) ?></td></tr>
        <tr><th>Téléphone</th><td><?= h((string) $fiche['telephone']) ?></td><th>E-mail</th><td><?= h((string) $fiche['email']) ?></td></tr>
      </table>
      <h3>Réservations et locations</h3>
      <table><thead><tr><th>Réservation</th><th>Véhicule</th><th>Période</th><th>Statut</th><th>Location</th></tr></thead>
        <tbody><?php foreach ($sous as $s): ?><tr><td>n° <?= (int) $s['num_reservation'] ?></td><td><?= h($s['marque'] . ' ' . $s['modele'] . ', ' . $s['immatriculation']) ?></td><td><?= date_fr($s['date_debut']) ?> au <?= date_fr($s['date_fin']) ?></td><td><?= h(libelle_etat($s['statut'])) ?></td>
          <td><?= $s['num_location'] ? 'n° ' . (int) $s['num_location'] . ', ' . euros((float) $s['montant_paye']) . ($s['date_retour_effective'] ? ', rendue' : ', en cours') : '–' ?></td></tr><?php endforeach; ?>
        <?php if (!$sous): ?><tr><td colspan="5">Aucune réservation.</td></tr><?php endif; ?></tbody></table>

    <?php elseif ($type === 'vehicule'): [$photo] = photo_vehicule($fiche); ?>
      <div style="display:grid;grid-template-columns:260px 1fr;gap:20px;align-items:start">
        <img src="<?= h($photo) ?>" alt="" style="width:100%;border-radius:4px">
        <table style="margin:0">
          <tr><th>Véhicule</th><td><?= h($fiche['marque'] . ' ' . $fiche['modele']) ?>, <?= h((string) $fiche['couleur']) ?></td></tr>
          <tr><th>Type, catégorie</th><td><?= $fiche['type'] === 'UTILITAIRE' ? 'Utilitaire' : 'Touristique' ?>, <?= h($fiche['code_categorie'] . ' ' . $fiche['categorie_libelle']) ?></td></tr>
          <tr><th>Compteurs</th><td><?= number_format((int) $fiche['nb_kms'], 0, ',', ' ') ?> km, <?= number_format((float) $fiche['qte_carburant'], 0, ',', ' ') ?> L</td></tr>
          <tr><th>État</th><td><?= h(libelle_etat($fiche['etat'])) ?></td></tr>
          <tr><th>Emplacement</th><td><?= h((string) $fiche['num_emplacement']) ?>, <?= h((string) $fiche['zone']) ?></td></tr>
        </table>
      </div>
      <h3>Historique des locations</h3>
      <table><thead><tr><th>Location</th><th>Client</th><th>Départ</th><th>Retour</th><th>Km</th></tr></thead>
        <tbody><?php foreach ($sous as $s): ?><tr><td>n° <?= (int) $s['num_location'] ?></td><td><?= h($s['prenom'] . ' ' . $s['nom']) ?></td><td><?= date_fr($s['date_location']) ?></td><td><?= $s['date_retour_effective'] ? date_fr($s['date_retour_effective']) : 'prévu le ' . date_fr($s['date_retour_prevue']) ?></td><td><?= number_format((int) $s['km_depart'], 0, ',', ' ') ?><?= $s['km_retour'] ? ' → ' . number_format((int) $s['km_retour'], 0, ',', ' ') : '' ?></td></tr><?php endforeach; ?>
        <?php if (!$sous): ?><tr><td colspan="5">Jamais loué.</td></tr><?php endif; ?></tbody></table>

    <?php else: ?>
      <table>
        <tr><th>Client</th><td><?= h($fiche['prenom'] . ' ' . $fiche['nom']) ?> (n° <?= (int) $fiche['num_client'] ?>), permis <?= h($fiche['num_permis']) ?></td></tr>
        <tr><th>Véhicule</th><td><?= h($fiche['marque'] . ' ' . $fiche['modele']) ?>, <?= h($fiche['immatriculation']) ?>, catégorie <?= h($fiche['code_categorie']) ?></td></tr>
        <tr><th>Réservation</th><td>n° <?= (int) $fiche['num_reservation'] ?>, formule <?= h($fiche['formule']) ?>, du <?= date_fr($fiche['date_debut']) ?> au <?= date_fr($fiche['date_fin']) ?></td></tr>
        <tr><th>Départ</th><td><?= date_fr($fiche['date_location']) ?>, <?= number_format((int) $fiche['km_depart'], 0, ',', ' ') ?> km</td></tr>
        <tr><th>Retour</th><td><?= $fiche['date_retour_effective'] ? date_fr($fiche['date_retour_effective']) . ', ' . number_format((int) $fiche['km_retour'], 0, ',', ' ') . ' km' : 'en cours, prévu le ' . date_fr($fiche['date_retour_prevue']) ?></td></tr>
      </table>
      <h3>Paiements</h3>
      <table><thead><tr><th>Transaction</th><th>Date</th><th>Carte</th><th>Montant</th></tr></thead>
        <tbody><?php $tot = 0; foreach ($sous as $s): $tot += (float) $s['montant']; ?><tr><td><?= h($s['num_transaction']) ?><?= str_starts_with($s['num_transaction'], 'SUP-') ? ' (supplément)' : '' ?></td><td><?= date_fr($s['date_paiement']) ?></td><td><?= h($s['numero_masque']) ?></td><td><?= euros((float) $s['montant']) ?></td></tr><?php endforeach; ?>
          <tr><th colspan="3">Total payé</th><th><?= euros($tot) ?></th></tr></tbody></table>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php pied();
