<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
$u = exiger('client');
$num = (int) $u['num_client'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_jeton();
    // Le client peut annuler une réservation qu'il a faite, tant qu'elle n'est pas devenue une location.
    $st = $pdo->prepare("UPDATE reservation SET statut = 'ANNULEE' WHERE num_reservation = ? AND num_client = ? AND statut IN ('EN_ATTENTE','CONFIRMEE')");
    $st->execute([(int) post('num'), $num]);
    if ($st->rowCount()) { $im = $pdo->prepare('SELECT immatriculation FROM reservation WHERE num_reservation = ?'); $im->execute([(int) post('num')]); liberer_vehicule($pdo, (string) $im->fetchColumn()); }
    flash($st->rowCount() ? 'ok' : 'erreur', $st->rowCount() ? 'Réservation annulée.' : 'Cette réservation ne peut plus être annulée.');
    rediriger('espace-client.php');
}

$client = $pdo->prepare('SELECT p.*, c.num_client, c.num_permis, c.date_inscription FROM client c JOIN personne p ON p.id_personne = c.id_personne WHERE c.num_client = ?');
$client->execute([$num]);
$client = $client->fetch();
$res = $pdo->prepare("SELECT r.*, v.marque, v.modele, v.code_categorie, v.immatriculation, v.couleur, v.type, f.libelle AS formule,
                             l.num_location, l.date_retour_prevue, l.date_retour_effective, l.montant_paye
                      FROM reservation r JOIN vehicule v ON v.immatriculation = r.immatriculation JOIN formule f ON f.code = r.code_formule
                      LEFT JOIN location l ON l.num_reservation = r.num_reservation
                      WHERE r.num_client = ? ORDER BY r.date_debut DESC");
$res->execute([$num]);
$res = $res->fetchAll();
$enCours = location_en_cours($pdo, $num);

entete('Mon espace client', null);
?>
<p class="gris"><?= h($client['prenom'] . ' ' . $client['nom']) ?>, client n° <?= $num ?> depuis le <?= date_fr($client['date_inscription']) ?>. Permis <?= h($client['num_permis']) ?>.</p>

<?php if ($enCours):
    $retard = $enCours['date_retour_prevue'] < date('Y-m-d'); ?>
  <div class="flash <?= $retard ? 'flash--erreur' : 'flash--info' ?>">Location en cours : véhicule <?= h($enCours['immatriculation']) ?>, retour prévu le <?= date_fr($enCours['date_retour_prevue']) ?><?= $retard ? ' : le retour est en retard, merci de rapporter le véhicule à l\'agence.' : '.' ?> Une seule location à la fois (RG6).</div>
<?php endif; ?>

<div class="actions no-print" style="margin:0 0 24px"><a class="btn" href="reserver.php">Réserver un véhicule</a><a class="btn btn--secondaire" href="recherche.php">Rechercher un véhicule libre</a></div>

<h2>Mes réservations et locations</h2>
<?php if (!$res): ?>
  <div class="vide">Vous n'avez encore aucune réservation. <a href="recherche.php">Trouvez un véhicule libre</a>.</div>
<?php else: ?>
  <div class="parc">
    <?php foreach ($res as $r):
        $etat = $r['num_location'] ? ($r['date_retour_effective'] ? 'Rendu le ' . date_fr($r['date_retour_effective']) : 'Location en cours') : '';
        $action = '<p class="vehicule__meta"><span>Réservation n° ' . (int) $r['num_reservation'] . '</span><span>' . h($r['formule']) . '</span></p>'
            . '<p style="margin:0">Du <b>' . date_fr($r['date_debut']) . '</b> au <b>' . date_fr($r['date_fin']) . '</b></p>'
            . '<div class="vehicule__prix"><div>' . badge($r['statut']) . ($etat ? ' <small>' . h($etat) . '</small>' : '') . '</div>';
        if (in_array($r['statut'], ['EN_ATTENTE', 'CONFIRMEE'], true)) {
            $action .= '<form method="post" data-confirmer="Annuler la réservation n° ' . (int) $r['num_reservation'] . ' ?">' . champ_jeton() . '<input type="hidden" name="num" value="' . (int) $r['num_reservation'] . '"><button class="btn btn--danger btn--petit">Annuler</button></form>';
        }
        $action .= '</div>';
        unset($r['etat']);
        echo carte_vehicule($r, $action);
    endforeach; ?>
  </div>
<?php endif; ?>
<?php pied();
