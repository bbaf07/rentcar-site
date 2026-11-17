<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
exiger('employe');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_jeton();
    $num = (int) post('num');
    $act = post('action');
    $r = $pdo->prepare('SELECT * FROM reservation WHERE num_reservation = ?');
    $r->execute([$num]);
    $r = $r->fetch();
    if ($r && $act === 'confirmer' && $r['statut'] === 'EN_ATTENTE') {
        // On ne confirme que si le véhicule est toujours libre sur la période
        $conflit = $pdo->prepare("SELECT 1 FROM reservation WHERE immatriculation = ? AND num_reservation <> ? AND statut = 'CONFIRMEE' AND date_debut <= ? AND date_fin >= ?");
        $conflit->execute([$r['immatriculation'], $num, $r['date_fin'], $r['date_debut']]);
        if ($conflit->fetch()) {
            flash('erreur', "Impossible de confirmer la réservation n° $num : le véhicule est déjà réservé sur cette période.");
        } else {
            $pdo->prepare("UPDATE reservation SET statut = 'CONFIRMEE' WHERE num_reservation = ?")->execute([$num]);
            $pdo->prepare("UPDATE vehicule SET etat = 'RESERVE' WHERE immatriculation = ? AND etat = 'DISPONIBLE'")->execute([$r['immatriculation']]);
            notifier($pdo, (int) $r['num_client'], "Réservation n° $num confirmée", 'Votre réservation du ' . date_fr($r['date_debut']) . ' au ' . date_fr($r['date_fin']) . " est confirmée par l'agence. Présentez-vous au comptoir le jour du départ avec votre carte bancaire. Annulation gratuite jusqu'à " . DELAI_ANNULATION_GRATUITE_H . ' h avant le départ.');
            flash('ok', "Réservation n° $num confirmée.");
        }
    } elseif ($r && $act === 'annuler' && in_array($r['statut'], ['EN_ATTENTE', 'CONFIRMEE'], true)) {
        $pdo->prepare("UPDATE reservation SET statut = 'ANNULEE' WHERE num_reservation = ?")->execute([$num]);
        liberer_vehicule($pdo, $r['immatriculation']);
        notifier($pdo, (int) $r['num_client'], "Réservation n° $num annulée par l'agence", 'Votre réservation du ' . date_fr($r['date_debut']) . ' au ' . date_fr($r['date_fin']) . " a été annulée par l'agence, sans frais pour vous.");
        flash('ok', "Réservation n° $num annulée.");
    }
    rediriger('reservations.php' . (isset($_GET['statut']) ? '?statut=' . rawurlencode((string) $_GET['statut']) : ''));
}

$filtre = (string) ($_GET['statut'] ?? '');
$sql = "SELECT r.*, p.prenom, p.nom, v.marque, v.modele, f.libelle AS formule FROM reservation r
        JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
        JOIN vehicule v ON v.immatriculation = r.immatriculation JOIN formule f ON f.code = r.code_formule";
$st = $pdo->prepare($sql . ($filtre ? ' WHERE r.statut = ?' : '') . ' ORDER BY r.date_debut DESC');
$st->execute($filtre ? [$filtre] : []);
$liste = $st->fetchAll();
$aujourdhui = date('Y-m-d');

entete('Réservations', 'UC-05', 0);
?>
<div class="actions" style="margin:0 0 18px"><a class="btn" href="reserver.php">Réserver pour un client</a></div>
<nav class="onglets" aria-label="Filtrer par statut">
  <a href="reservations.php"<?= !$filtre ? ' aria-current="page"' : '' ?>>Toutes</a>
  <?php foreach (['EN_ATTENTE', 'CONFIRMEE', 'HONOREE', 'ANNULEE'] as $s): ?><a href="?statut=<?= $s ?>"<?= $filtre === $s ? ' aria-current="page"' : '' ?>><?= libelle_etat($s) ?></a><?php endforeach; ?>
</nav>
<?php if (!$liste): ?><div class="vide">Aucune réservation<?= $filtre ? ' avec ce statut' : '' ?>.</div>
<?php else: ?>
<div class="defile"><table class="table">
  <thead><tr><th>N°</th><th>Client</th><th>Véhicule</th><th>Formule</th><th>Période</th><th>Statut</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($liste as $r): ?>
    <tr>
      <td><?= (int) $r['num_reservation'] ?></td><td><?= h($r['prenom'] . ' ' . $r['nom']) ?></td>
      <td><?= h($r['marque'] . ' ' . $r['modele']) ?> <?= plaque($r['immatriculation']) ?></td><td><?= h($r['formule']) ?></td>
      <td><?= date_fr($r['date_debut']) ?> au <?= date_fr($r['date_fin']) ?></td><td><?= badge($r['statut']) ?></td>
      <td>
        <?php if ($r['statut'] === 'EN_ATTENTE'): ?>
          <form method="post"><?= champ_jeton() ?><input type="hidden" name="num" value="<?= (int) $r['num_reservation'] ?>"><button class="btn btn--petit" name="action" value="confirmer">Confirmer</button></form>
        <?php endif; ?>
        <?php if ($r['statut'] === 'CONFIRMEE' && $aujourdhui >= $r['date_debut'] && $aujourdhui <= $r['date_fin']): ?>
          <a class="btn btn--petit" href="louer.php?num=<?= (int) $r['num_reservation'] ?>">Louer</a>
        <?php endif; ?>
        <?php if (in_array($r['statut'], ['EN_ATTENTE', 'CONFIRMEE'], true)): ?>
          <form method="post" data-confirmer="Annuler la réservation n° <?= (int) $r['num_reservation'] ?> ?"><?= champ_jeton() ?><input type="hidden" name="num" value="<?= (int) $r['num_reservation'] ?>"><button class="btn btn--danger btn--petit" name="action" value="annuler">Annuler</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php pied();
