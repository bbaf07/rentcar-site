<?php
require __DIR__ . '/inc/app.php';
// UC-07 Rendre un véhicule, étendu par UC-12 Facturer un supplément (point d'extension « retard, carburant manquant, dommage »).
// Le retour repose sur un état des lieux comparé à celui du départ (benchmark : dommages contestés).
$pdo = bd();
$u = exiger('employe');
$aujourdhui = date('Y-m-d');

function charger_location(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT l.*, r.immatriculation, r.num_client, r.code_formule, v.marque, v.modele, v.couleur, v.type, v.code_categorie, v.nb_kms,
                                v.qte_carburant, v.num_emplacement, c.franchise, e.zone, p.nom, p.prenom,
                                (SELECT prix FROM tarif WHERE code_categorie = v.code_categorie AND code_formule = 'JOUR') AS prix_jour
                         FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN vehicule v ON v.immatriculation = r.immatriculation
                         JOIN categorie c ON c.code = v.code_categorie LEFT JOIN emplacement e ON e.numero = v.num_emplacement
                         JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                         WHERE l.num_location = ? AND l.date_retour_effective IS NULL");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

$id = (int) ($_POST['loc'] ?? $_GET['loc'] ?? 0);
$loc = $id ? charger_location($pdo, $id) : null;
$depart = $loc ? charger_etat($pdo, $id, 'DEPART') : null;
$carbDepart = $depart ? (float) $depart['carburant'] : (float) ($loc['qte_carburant'] ?? 0);
$etape = $loc ? 2 : 1; $ext = []; $erreurs = []; $calcul = null;
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('action') : '';
$dateRetour = $aujourdhui;

if ($loc && $action) {
    verifier_jeton();
    $dateRetour = post('date') ?: $aujourdhui;
    $erreurs = verifier_etat($_POST, $action === 'enregistrer' ? $_FILES : [], (int) $loc['km_depart']);
    if ($dateRetour < $loc['date_location'] || $dateRetour > $aujourdhui) {
        $erreurs[] = "La date de retour doit être comprise entre le départ et aujourd'hui.";
    }
    if (!ctype_digit((string) post('km')) || (int) post('km') < (int) $loc['km_depart']) { $ext[] = '2a'; }
    if (!$erreurs) {
        // Étape 3 : comparaison avec la date prévue et l'état des lieux de départ
        $joursRetard = max(0, jours_entre($loc['date_retour_prevue'], $dateRetour));
        $litres = max(0, $carbDepart - (float) str_replace(',', '.', post('carburant')));
        $dommages = nouveaux_dommages($depart, $_POST['zone']);
        $reparation = $dommages ? min((float) $loc['franchise'], max(0, (float) str_replace(',', '.', post('reparation', '0')))) : 0.0;
        $calcul = ['jours' => $joursRetard, 'retard' => $joursRetard * (float) $loc['prix_jour'], 'litres' => $litres,
                   'carburant' => round($litres * PRIX_LITRE_CARBURANT, 2), 'dommages' => $dommages, 'reparation' => $reparation];
        $calcul['total'] = $calcul['retard'] + $calcul['carburant'] + $calcul['reparation'];
        $etape = ($calcul['total'] > 0 || $dommages) ? 4 : 5;

        if ($action === 'enregistrer') {
            $refus = null;
            if ($calcul['total'] > 0) {
                // UC-12 : encaissement du supplément (même contrôle de carte que UC-11)
                $numero = post('numero'); $mois = post('expiration');
                $rep = carte_valide($numero, $mois, $aujourdhui) ? banque_autoriser($numero, $calcul['total']) : 'REFUSE';
                if ($rep !== 'AUTORISE') { $refus = explication_paiement($numero, $mois, $aujourdhui, $rep) . " Le retour n'est pas encore enregistré."; }
            }
            if ($refus) {
                $erreurs[] = $refus;
            } else {
                $pdo->beginTransaction();
                try {
                    enregistrer_etat($pdo, $id, 'RETOUR', $_POST, $_FILES, (string) $u['matricule']);
                    $pdo->prepare("UPDATE location SET date_retour_effective = ?, km_retour = ?, statut_caution = 'LIBEREE', date_liberation = ? WHERE num_location = ?")
                        ->execute([$dateRetour, (int) post('km'), $aujourdhui, $id]);
                    if ($calcul['total'] > 0) {
                        $pdo->prepare('INSERT INTO carte_bancaire (num_client, numero_masque, titulaire, date_expiration) VALUES (?, ?, ?, ?)')
                            ->execute([$loc['num_client'], masquer($numero), $loc['prenom'] . ' ' . $loc['nom'], fin_validite($mois)]);
                        $idCarte = (int) $pdo->lastInsertId();
                        $pdo->prepare("INSERT INTO paiement (num_transaction, date_paiement, montant, statut, num_location, id_carte) VALUES (?, ?, ?, 'AUTORISE', ?, ?)")
                            ->execute(['SUP-' . strtoupper(bin2hex(random_bytes(6))), $aujourdhui, $calcul['total'], $id, $idCarte]);
                    }
                    $pdo->prepare('UPDATE vehicule SET nb_kms = ?, qte_carburant = ?, etat = ? WHERE immatriculation = ?')
                        ->execute([(int) post('km'), (float) str_replace(',', '.', post('carburant')), etat_apres_liberation($pdo, $loc['immatriculation']), $loc['immatriculation']]);
                    $detail = [];
                    if ($calcul['retard'] > 0) { $detail[] = "retard de {$calcul['jours']} j (" . euros($calcul['retard']) . ')'; }
                    if ($calcul['carburant'] > 0) { $detail[] = 'carburant manquant (' . euros($calcul['carburant']) . ')'; }
                    if ($calcul['reparation'] > 0) { $detail[] = 'dommage nouveau (' . euros($calcul['reparation']) . ')'; }
                    notifier($pdo, (int) $loc['num_client'], "Retour enregistré, location n° $id",
                        "Votre {$loc['marque']} {$loc['modele']} a été rendu le " . date_fr($dateRetour) . '. '
                        . ($detail ? 'Supplément payé : ' . implode(', ', $detail) . '.' : 'Aucun supplément.')
                        . ' Votre caution de ' . euros((float) $loc['caution']) . ' est libérée. L\'état des lieux du retour et la facture sont dans votre espace client.');
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                flash('ok', "Retour enregistré : {$loc['marque']} {$loc['modele']} ({$loc['immatriculation']}) remis à l'emplacement {$loc['num_emplacement']}"
                    . ($calcul['total'] > 0 ? ', supplément de ' . euros($calcul['total']) . ' encaissé' : ', sans supplément') . ', caution libérée.');
                rediriger('rendre.php?fait=' . $id);
            }
        }
    }
}

$enCours = $pdo->query("SELECT l.num_location, l.date_location, l.date_retour_prevue, r.immatriculation, v.marque, v.modele, p.prenom, p.nom,
                               DATEDIFF(CURDATE(), l.date_retour_prevue) AS retard,
                               (SELECT COUNT(*) FROM etat_des_lieux e WHERE e.num_location = l.num_location AND e.moment = 'DEPART') AS edl
                        FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN vehicule v ON v.immatriculation = r.immatriculation
                        JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                        WHERE l.date_retour_effective IS NULL ORDER BY l.date_retour_prevue")->fetchAll();
if (isset($_GET['fait'])) { $etape = 6; }

entete('Rendre un véhicule', 'UC-07', $etape, $ext);
?>
<?php if ($erreurs): ?><div class="flash flash--erreur" role="alert"><?= implode('<br>', array_map('h', $erreurs)) ?></div><?php endif; ?>

<?php if (!$loc): ?>
  <?php if (isset($_GET['fait'])): $f = (int) $_GET['fait']; ?>
    <div class="actions" style="margin:0 0 20px"><a class="btn btn--secondaire" href="etat-des-lieux.php?loc=<?= $f ?>">Voir les états des lieux</a><a class="btn btn--secondaire" href="facture.php?loc=<?= $f ?>">Facture</a></div>
  <?php endif; ?>
  <h2>1. Locations en cours</h2>
  <?php if (!$enCours): ?><div class="vide">Aucun véhicule n'est actuellement loué.</div>
  <?php else: ?>
    <div class="defile"><table class="table">
      <thead><tr><th>Location</th><th>Client</th><th>Véhicule</th><th>Retour prévu</th><th>État</th><th></th></tr></thead>
      <tbody><?php foreach ($enCours as $x): ?><tr>
        <td>n° <?= (int) $x['num_location'] ?></td><td><?= h($x['prenom'] . ' ' . $x['nom']) ?></td>
        <td><?= h($x['marque'] . ' ' . $x['modele']) ?> <?= plaque($x['immatriculation']) ?></td><td><?= date_fr($x['date_retour_prevue']) ?></td>
        <td><?= (int) $x['retard'] > 0 ? '<span class="badge badge--RETARD">' . (int) $x['retard'] . ' j de retard</span>' : '<span class="badge badge--LOUE">Dans les temps</span>' ?></td>
        <td><a class="btn btn--petit" href="rendre.php?loc=<?= (int) $x['num_location'] ?>">Enregistrer le retour</a></td></tr><?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>

<?php else: [$photo] = photo_vehicule($loc); ?>
  <div class="recap">
    <?= voiture_3d($loc) ?>
    <dl>
      <dt>Location</dt><dd>n° <?= (int) $loc['num_location'] ?>, <?= h($loc['prenom'] . ' ' . $loc['nom']) ?></dd>
      <dt>Véhicule</dt><dd><?= h($loc['marque'] . ' ' . $loc['modele']) ?> <?= plaque($loc['immatriculation']) ?></dd>
      <dt>Départ</dt><dd><?= date_fr($loc['date_location']) ?>, <?= number_format((int) $loc['km_depart'], 0, ',', ' ') ?> km, <?= number_format($carbDepart, 1, ',', ' ') ?> L</dd>
      <dt>Retour prévu</dt><dd><?= date_fr($loc['date_retour_prevue']) ?></dd>
      <dt>Caution</dt><dd><?= euros((float) $loc['caution']) ?> <?= badge($loc['statut_caution']) ?></dd>
      <dt>Franchise</dt><dd><?= euros((float) $loc['franchise']) ?> au plus pour un dommage nouveau</dd>
    </dl>
  </div>
  <?php if (!$depart): ?>
    <div class="flash flash--info">Aucun état des lieux de départ n'a été fait pour cette location : toutes les zones sont considérées en bon état au départ.</div>
  <?php endif; ?>
  <form method="post" class="formulaire" enctype="multipart/form-data" autocomplete="off">
    <?= champ_jeton() ?><input type="hidden" name="loc" value="<?= $id ?>">
    <h2>2. État des lieux du retour</h2>
    <div class="grille-champs" style="margin-bottom:16px">
      <div class="champ"><label for="date">Date de retour</label><input id="date" type="date" name="date" value="<?= h($dateRetour) ?>" min="<?= h($loc['date_location']) ?>" max="<?= $aujourdhui ?>" required></div>
    </div>
    <?= champs_etat($_POST, (int) $loc['nb_kms'], $carbDepart, $depart) ?>
    <p class="gris" style="font-size:.9rem;margin-top:8px">Les photos sont envoyées au moment d'enregistrer le retour : joignez-les juste avant de cliquer sur « Enregistrer ».</p>
    <div class="actions"><button class="btn<?= $calcul ? ' btn--secondaire' : '' ?>" name="action" value="calculer" formnovalidate>3. Comparer avec le départ</button><a href="rendre.php">Changer de location</a></div>

    <?php if ($calcul): ?>
      <h2 style="margin-top:26px">3. Comparaison avec le départ</h2>
      <div class="defile"><table class="table">
        <tbody>
          <tr><td>Retard</td><td><?= $calcul['jours'] ?> jour<?= $calcul['jours'] > 1 ? 's' : '' ?> × <?= euros((float) $loc['prix_jour']) ?></td><td class="num"><?= euros($calcul['retard']) ?></td></tr>
          <tr><td>Carburant manquant</td><td><?= number_format($calcul['litres'], 1, ',', ' ') ?> L × <?= euros(PRIX_LITRE_CARBURANT) ?></td><td class="num"><?= euros($calcul['carburant']) ?></td></tr>
          <tr><td>Dommages nouveaux</td><td><?= $calcul['dommages'] ? h(implode(', ', array_map(fn($k, $v) => ZONES_VEHICULE[$k] . ' : ' . mb_strtolower(ETATS_ZONE[$v[1]]), array_keys($calcul['dommages']), $calcul['dommages']))) : 'Aucun : état identique au départ' ?></td><td class="num"><?= euros($calcul['reparation']) ?></td></tr>
          <tr><td><b>Supplément</b></td><td></td><td class="num"><b><?= euros($calcul['total']) ?></b></td></tr>
        </tbody>
      </table></div>
      <?php if ($calcul['dommages']): ?>
        <div class="grille-champs" style="margin-top:14px"><div class="champ"><label for="reparation">Montant des réparations (dans la limite de la franchise)</label>
          <input id="reparation" name="reparation" inputmode="decimal" value="<?= h(post('reparation', '0')) ?>"><small>Plafonné à <?= euros((float) $loc['franchise']) ?>. Cliquez sur « Comparer » pour recalculer.</small></div></div>
      <?php endif; ?>
      <?php if ($calcul['total'] > 0): $uc12 = cas()['UC-12']; ?>
        <div class="formulaire" style="margin-top:20px;border-color:var(--marquage);border-width:2px">
          <h2><span class="baie">«extend»</span> 4. UC-12 Facturer un supplément</h2>
          <ol><?php foreach ($uc12['etapes'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ol>
          <div class="grille-champs">
            <div class="champ"><label for="numero">Carte bancaire</label><input id="numero" name="numero" inputmode="numeric" placeholder="4970 1012 3006 4242" required></div>
            <div class="champ"><label for="expiration">Expiration</label><input id="expiration" type="month" name="expiration" min="<?= date('Y-m') ?>" required></div>
          </div>
        </div>
      <?php else: ?>
        <p class="gris" style="margin-top:12px">Retour dans les temps, réservoir au même niveau, aucun dommage nouveau : le point d'extension n'est pas atteint, aucun supplément.</p>
      <?php endif; ?>
      <div class="actions"><button class="btn" name="action" value="enregistrer">5. Enregistrer le retour<?= $calcul['total'] > 0 ? ' et encaisser ' . euros($calcul['total']) : '' ?></button><span class="gris">La caution de <?= euros((float) $loc['caution']) ?> sera libérée.</span></div>
    <?php endif; ?>
  </form>
<?php endif; ?>
<?php pied();
