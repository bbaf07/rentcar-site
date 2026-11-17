<?php
require __DIR__ . '/inc/app.php';
// Espace client (benchmark : contrat introuvable, historique faux, absence de notification).
// Onglets : notifications, locations (contrat, facture, états des lieux, caution), réservations, profil.
$pdo = bd();
$u = exiger('client');
$num = (int) $u['num_client'];
$onglets = ['locations' => 'Mes locations', 'reservations' => 'Mes réservations', 'notifications' => 'Notifications', 'profil' => 'Mon profil'];
$onglet = isset($onglets[$_GET['onglet'] ?? '']) ? $_GET['onglet'] : 'locations';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_jeton();
    $action = post('action');
    if ($action === 'annuler') {
        // Annulation : libre pour une réservation en attente, gratuite jusqu'à 48 h avant le départ si elle est confirmée.
        $st = $pdo->prepare("SELECT * FROM reservation WHERE num_reservation = ? AND num_client = ? AND statut IN ('EN_ATTENTE','CONFIRMEE')");
        $st->execute([(int) post('num'), $num]);
        $r = $st->fetch();
        if (!$r) {
            flash('erreur', 'Cette réservation ne peut plus être annulée.');
        } elseif ($r['statut'] === 'CONFIRMEE' && !annulation_gratuite($r['date_debut'])) {
            flash('erreur', 'Le départ a lieu dans moins de ' . DELAI_ANNULATION_GRATUITE_H . ' h : contactez l\'agence pour annuler cette réservation.');
        } else {
            $pdo->prepare("UPDATE reservation SET statut = 'ANNULEE' WHERE num_reservation = ?")->execute([$r['num_reservation']]);
            liberer_vehicule($pdo, $r['immatriculation']);
            notifier($pdo, $num, "Réservation n° {$r['num_reservation']} annulée", 'Votre réservation du ' . date_fr($r['date_debut']) . ' au ' . date_fr($r['date_fin']) . ' est annulée, sans frais.');
            flash('ok', 'Réservation annulée, sans frais.');
        }
        rediriger('espace-client.php?onglet=reservations');
    }
    if ($action === 'profil') {
        $email = post('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('erreur', "L'adresse e-mail n'est pas valide.");
        } else {
            $pdo->prepare('UPDATE personne SET adresse = ?, telephone = ?, email = ? WHERE id_personne = ?')
                ->execute([post('adresse') ?: null, post('telephone') ?: null, $email ?: null, $u['id_personne']]);
            flash('ok', 'Coordonnées enregistrées.');
        }
        rediriger('espace-client.php?onglet=profil');
    }
    if ($action === 'mdp') {
        $st = $pdo->prepare('SELECT mot_de_passe FROM personne WHERE id_personne = ?');
        $st->execute([$u['id_personne']]);
        $nouveau = (string) ($_POST['nouveau'] ?? '');
        if (!mdp_verifier((string) ($_POST['actuel'] ?? ''), (string) $st->fetchColumn())) {
            flash('erreur', 'Mot de passe actuel incorrect.');
        } elseif (strlen($nouveau) < 8) {
            flash('erreur', 'Le nouveau mot de passe fait au moins 8 caractères.');
        } else {
            $pdo->prepare('UPDATE personne SET mot_de_passe = ? WHERE id_personne = ?')->execute([mdp_hacher($nouveau), $u['id_personne']]);
            flash('ok', 'Mot de passe modifié.');
        }
        rediriger('espace-client.php?onglet=profil');
    }
}

$client = $pdo->prepare('SELECT p.*, c.num_client, c.num_permis, c.date_inscription FROM client c JOIN personne p ON p.id_personne = c.id_personne WHERE c.num_client = ?');
$client->execute([$num]);
$client = $client->fetch();
$nbNonLues = (int) $pdo->query("SELECT COUNT(*) FROM notification WHERE lue = 0 AND num_client = $num")->fetchColumn();
$notifs = [];
if ($onglet === 'notifications') {
    $st = $pdo->prepare('SELECT * FROM notification WHERE num_client = ? ORDER BY date_envoi DESC, id_notification DESC LIMIT 50');
    $st->execute([$num]);
    $notifs = $st->fetchAll();
    $pdo->prepare('UPDATE notification SET lue = 1 WHERE num_client = ? AND lue = 0')->execute([$num]);
    $nbNonLues = 0;
}
$enCours = location_en_cours($pdo, $num);

entete('Mon espace client', null);
?>
<p class="gris"><?= h($client['prenom'] . ' ' . $client['nom']) ?>, client n° <?= $num ?> depuis le <?= date_fr($client['date_inscription']) ?>.</p>
<?php if ($enCours): $retard = $enCours['date_retour_prevue'] < date('Y-m-d'); ?>
  <div class="flash <?= $retard ? 'flash--erreur' : 'flash--info' ?>">Location en cours : véhicule <?= h($enCours['immatriculation']) ?>, retour prévu le <?= date_fr($enCours['date_retour_prevue']) ?><?= $retard ? ' : le retour est en retard, merci de rapporter le véhicule à l\'agence.' : '.' ?></div>
<?php endif; ?>

<nav class="onglets-client" aria-label="Rubriques de l'espace client">
  <?php foreach ($onglets as $k => $lib): ?>
    <a href="?onglet=<?= $k ?>"<?= $onglet === $k ? ' aria-current="page"' : '' ?>><?= h($lib) ?><?= $k === 'notifications' && $nbNonLues ? '<span class="pastille">' . $nbNonLues . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($onglet === 'locations'):
    $st = $pdo->prepare("SELECT l.*, r.immatriculation, r.date_debut, r.date_fin, v.marque, v.modele, v.code_categorie, v.couleur, f.libelle AS formule,
                                (SELECT COALESCE(SUM(montant), 0) FROM paiement pa WHERE pa.num_location = l.num_location) AS total_paye,
                                (SELECT COUNT(*) FROM etat_des_lieux e WHERE e.num_location = l.num_location) AS nb_etats
                         FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN vehicule v ON v.immatriculation = r.immatriculation
                         JOIN formule f ON f.code = r.code_formule WHERE r.num_client = ? ORDER BY l.date_location DESC");
    $st->execute([$num]);
    $locations = $st->fetchAll(); ?>
  <?php if (!$locations): ?>
    <div class="vide">Vous n'avez pas encore loué de véhicule. Vos contrats, factures et états des lieux apparaîtront ici.</div>
  <?php endif; ?>
  <?php foreach ($locations as $l): [$photo] = photo_vehicule($l); ?>
    <article class="location-client">
      <img src="<?= h($photo) ?>" alt="">
      <div>
        <h3><?= h($l['marque'] . ' ' . $l['modele']) ?> <?= plaque($l['immatriculation']) ?></h3>
        <p class="vehicule__meta"><span>Location n° <?= (int) $l['num_location'] ?></span><span>Formule <?= h($l['formule']) ?></span><span>Payé <?= euros((float) $l['total_paye']) ?></span></p>
        <p style="margin:6px 0 0">Du <b><?= date_fr($l['date_location']) ?></b> au <b><?= date_fr($l['date_retour_prevue']) ?></b><?= $l['date_retour_effective'] ? ', rendu le ' . date_fr($l['date_retour_effective']) : ' (en cours)' ?></p>
        <p class="caution-etat" style="margin:6px 0 0">Caution <?= euros((float) $l['caution']) ?> <?= badge($l['statut_caution']) ?><?= $l['date_liberation'] ? ' le ' . date_fr($l['date_liberation']) : '' ?></p>
      </div>
      <div class="liens">
        <a class="btn btn--petit" href="contrat.php?loc=<?= (int) $l['num_location'] ?>">Contrat</a>
        <a class="btn btn--secondaire btn--petit" href="facture.php?loc=<?= (int) $l['num_location'] ?>">Facture</a>
        <a class="btn btn--secondaire btn--petit" href="etat-des-lieux.php?loc=<?= (int) $l['num_location'] ?>">États des lieux<?= $l['nb_etats'] ? ' (' . (int) $l['nb_etats'] . ')' : '' ?></a>
      </div>
    </article>
  <?php endforeach; ?>

<?php elseif ($onglet === 'reservations'):
    $res = $pdo->prepare("SELECT r.*, v.marque, v.modele, v.code_categorie, v.immatriculation, v.couleur, v.type, v.nb_places, v.boite, v.energie,
                                 c.libelle AS categorie_libelle, c.caution, f.libelle AS formule
                          FROM reservation r JOIN vehicule v ON v.immatriculation = r.immatriculation JOIN formule f ON f.code = r.code_formule
                          JOIN categorie c ON c.code = v.code_categorie
                          WHERE r.num_client = ? AND r.statut <> 'HONOREE' ORDER BY r.date_debut DESC");
    $res->execute([$num]);
    $res = $res->fetchAll(); ?>
  <div class="actions no-print" style="margin:0 0 20px"><a class="btn" href="reserver.php">Réserver un véhicule</a><a class="btn btn--secondaire" href="recherche.php">Rechercher un véhicule libre</a></div>
  <p class="gris">Annulation gratuite jusqu'à <?= DELAI_ANNULATION_GRATUITE_H ?> h avant le départ. Les réservations devenues des locations sont dans « Mes locations ».</p>
  <?php if (!$res): ?>
    <div class="vide">Aucune réservation en cours. <a href="recherche.php">Trouvez un véhicule libre</a>.</div>
  <?php else: ?>
    <div class="parc">
      <?php foreach ($res as $r):
          $annulable = in_array($r['statut'], ['EN_ATTENTE', 'CONFIRMEE'], true);
          $gratuit = $r['statut'] === 'EN_ATTENTE' || annulation_gratuite($r['date_debut']);
          $action = '<p class="vehicule__meta"><span>Réservation n° ' . (int) $r['num_reservation'] . '</span><span>' . h($r['formule']) . '</span></p>'
              . '<p style="margin:0">Du <b>' . date_fr($r['date_debut']) . '</b> au <b>' . date_fr($r['date_fin']) . '</b></p>'
              . '<div class="vehicule__prix"><div>' . badge($r['statut']) . '</div>';
          if ($annulable && $gratuit) {
              $action .= '<form method="post" data-confirmer="Annuler la réservation n° ' . (int) $r['num_reservation'] . ' ? Elle sera annulée sans frais.">' . champ_jeton()
                  . '<input type="hidden" name="action" value="annuler"><input type="hidden" name="num" value="' . (int) $r['num_reservation'] . '"><button class="btn btn--danger btn--petit">Annuler sans frais</button></form>';
          } elseif ($annulable) {
              $action .= '<small>Moins de ' . DELAI_ANNULATION_GRATUITE_H . ' h avant le départ : contactez l\'agence</small>';
          }
          $action .= '</div>';
          echo carte_vehicule($r, $action);
      endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($onglet === 'notifications'): ?>
  <p class="gris">Chaque étape importante vous est confirmée ici : réservation reçue ou confirmée, contrat, état des lieux, retour et caution. Dans la version en ligne, ces messages seraient aussi envoyés par e-mail<?= $client['email'] ? ' à ' . h($client['email']) : '' ?>.</p>
  <?php if (!$notifs): ?><div class="vide">Aucune notification pour l'instant.</div><?php endif; ?>
  <?php foreach ($notifs as $n): ?>
    <article class="notif<?= $n['lue'] ? '' : ' non-lue' ?>">
      <header><b><?= h($n['sujet']) ?></b><small><?= (new DateTime($n['date_envoi']))->format('d/m/Y à H:i') ?><?= $n['lue'] ? '' : ', nouveau' ?></small></header>
      <p><?= h($n['message']) ?></p>
    </article>
  <?php endforeach; ?>

<?php else: ?>
  <div class="deux-colonnes">
    <form class="formulaire" method="post">
      <?= champ_jeton() ?><input type="hidden" name="action" value="profil">
      <h2>Coordonnées</h2>
      <p class="gris">Permis <?= h($client['num_permis']) ?>, login <code><?= h($client['login']) ?></code>.</p>
      <div class="champ"><label for="adresse">Adresse postale</label><input id="adresse" name="adresse" value="<?= h((string) $client['adresse']) ?>" autocomplete="street-address"></div>
      <div class="champ" style="margin-top:12px"><label for="telephone">Téléphone</label><input id="telephone" name="telephone" value="<?= h((string) $client['telephone']) ?>" autocomplete="tel"></div>
      <div class="champ" style="margin-top:12px"><label for="email">E-mail</label><input id="email" type="email" name="email" value="<?= h((string) $client['email']) ?>" autocomplete="email"></div>
      <div class="actions"><button class="btn" type="submit">Enregistrer les coordonnées</button></div>
    </form>
    <form class="formulaire" method="post">
      <?= champ_jeton() ?><input type="hidden" name="action" value="mdp">
      <h2>Mot de passe</h2>
      <div class="champ"><label for="actuel">Mot de passe actuel</label><input id="actuel" type="password" name="actuel" autocomplete="current-password" required></div>
      <div class="champ" style="margin-top:12px"><label for="nouveau">Nouveau mot de passe</label><input id="nouveau" type="password" name="nouveau" autocomplete="new-password" minlength="8" required><small>8 caractères au moins.</small></div>
      <div class="actions"><button class="btn" type="submit">Changer le mot de passe</button></div>
    </form>
  </div>
<?php endif; ?>
<?php pied();
