<?php
require __DIR__ . '/inc/app.php';
// UC-07 Rendre un véhicule, étendu par UC-12 Facturer un supplément (point d'extension « retard, carburant manquant »).
$pdo = bd();
$u = exiger('employe');
$aujourdhui = date('Y-m-d');

function charger_location(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT l.*, r.immatriculation, r.num_client, r.code_formule, v.marque, v.modele, v.couleur, v.type, v.code_categorie, v.nb_kms,
                                v.qte_carburant, v.num_emplacement, e.zone, p.nom, p.prenom, (SELECT prix FROM tarif WHERE code_categorie = v.code_categorie AND code_formule = 'JOUR') AS prix_jour
                         FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN vehicule v ON v.immatriculation = r.immatriculation
                         LEFT JOIN emplacement e ON e.numero = v.num_emplacement
                         JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                         WHERE l.num_location = ? AND l.date_retour_effective IS NULL");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

$id = (int) ($_POST['loc'] ?? $_GET['loc'] ?? 0);
$loc = $id ? charger_location($pdo, $id) : null;
$etape = $loc ? 2 : 1; $ext = []; $erreur = null; $calcul = null;
$saisie = ['km' => (string) ($loc['nb_kms'] ?? ''), 'carburant' => (string) ($loc['qte_carburant'] ?? ''), 'date' => $aujourdhui];
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('action') : '';

if ($loc && $action) {
    verifier_jeton();
    $saisie = ['km' => post('km'), 'carburant' => str_replace(',', '.', post('carburant')), 'date' => post('date') ?: $aujourdhui];
    if (!ctype_digit($saisie['km']) || (int) $saisie['km'] < (int) $loc['km_depart']) {
        $erreur = 'Le kilométrage au retour doit être un nombre au moins égal au kilométrage du départ (' . number_format((int) $loc['km_depart'], 0, ',', ' ') . ' km).';
        $ext[] = '2a';
    } elseif (!is_numeric($saisie['carburant']) || (float) $saisie['carburant'] < 0 || $saisie['date'] < $loc['date_location'] || $saisie['date'] > $aujourdhui) {
        $erreur = 'Vérifiez le carburant (en litres) et la date de retour (entre le départ et aujourd\'hui).';
    } else {
        // Étape 3 : comparaison avec la date prévue et le carburant au départ
        $joursRetard = max(0, jours_entre($loc['date_retour_prevue'], $saisie['date']));
        $litres = max(0, (float) $loc['qte_carburant'] - (float) $saisie['carburant']);
        $calcul = ['jours' => $joursRetard, 'retard' => $joursRetard * (float) $loc['prix_jour'], 'litres' => $litres, 'carburant' => round($litres * PRIX_LITRE_CARBURANT, 2)];
        $calcul['total'] = $calcul['retard'] + $calcul['carburant'];
        $etape = $calcul['total'] > 0 ? 4 : 5;

        if ($action === 'enregistrer') {
            $idCarte = null; $refus = null;
            if ($calcul['total'] > 0) {
                // UC-12 : encaissement du supplément (même contrôle de carte que UC-11)
                $numero = post('numero'); $mois = post('expiration');
                $rep = carte_valide($numero, $mois, $aujourdhui) ? banque_autoriser($numero, $calcul['total']) : 'REFUSE';
                if ($rep !== 'AUTORISE') {
                    $refus = $rep === 'INDISPONIBLE' ? 'Système bancaire indisponible : réessayez plus tard, le retour n\'est pas encore enregistré.' : 'Paiement du supplément refusé : demandez une autre carte.';
                }
            }
            if ($refus) {
                $erreur = $refus;
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE location SET date_retour_effective = ?, km_retour = ? WHERE num_location = ?')->execute([$saisie['date'], (int) $saisie['km'], $id]);
                    if ($calcul['total'] > 0) {
                        $pdo->prepare('INSERT INTO carte_bancaire (num_client, numero_masque, titulaire, date_expiration) VALUES (?, ?, ?, ?)')
                            ->execute([$loc['num_client'], masquer($numero), $loc['prenom'] . ' ' . $loc['nom'], fin_validite($mois)]);
                        $idCarte = (int) $pdo->lastInsertId();
                        $pdo->prepare("INSERT INTO paiement (num_transaction, date_paiement, montant, statut, num_location, id_carte) VALUES (?, ?, ?, 'AUTORISE', ?, ?)")
                            ->execute(['SUP-' . strtoupper(bin2hex(random_bytes(6))), $aujourdhui, $calcul['total'], $id, $idCarte]);
                    }
                    $pdo->prepare('UPDATE vehicule SET nb_kms = ?, qte_carburant = ?, etat = ? WHERE immatriculation = ?')
                        ->execute([(int) $saisie['km'], (float) $saisie['carburant'], etat_apres_liberation($pdo, $loc['immatriculation']), $loc['immatriculation']]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                flash('ok', "Retour enregistré : {$loc['marque']} {$loc['modele']} ({$loc['immatriculation']}) remis à l'emplacement {$loc['num_emplacement']}" . ($calcul['total'] > 0 ? ', supplément de ' . euros($calcul['total']) . ' encaissé.' : ', sans supplément.'));
                rediriger('rendre.php?fait=' . $id);
            }
        }
    }
}

$enCours = $pdo->query("SELECT l.num_location, l.date_location, l.date_retour_prevue, r.immatriculation, v.marque, v.modele, p.prenom, p.nom,
                               DATEDIFF(CURDATE(), l.date_retour_prevue) AS retard
                        FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation JOIN vehicule v ON v.immatriculation = r.immatriculation
                        JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                        WHERE l.date_retour_effective IS NULL ORDER BY l.date_retour_prevue")->fetchAll();
if (isset($_GET['fait'])) { $etape = 6; }

entete('Rendre un véhicule', 'UC-07', $etape, $ext);
?>
<?php if ($erreur): ?><div class="flash flash--erreur" role="alert"><?= h($erreur) ?></div><?php endif; ?>

<?php if (!$loc): ?>
  <?php if (isset($_GET['fait'])): ?><div class="actions" style="margin:0 0 20px"><a class="btn btn--secondaire" href="fiches.php?type=location&id=<?= (int) $_GET['fait'] ?>">Imprimer la fiche de cette location</a></div><?php endif; ?>
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
    <img src="<?= h($photo) ?>" alt="<?= h($loc['marque'] . ' ' . $loc['modele']) ?>">
    <dl>
      <dt>Location</dt><dd>n° <?= (int) $loc['num_location'] ?>, <?= h($loc['prenom'] . ' ' . $loc['nom']) ?></dd>
      <dt>Véhicule</dt><dd><?= h($loc['marque'] . ' ' . $loc['modele']) ?> <?= plaque($loc['immatriculation']) ?></dd>
      <dt>Départ</dt><dd><?= date_fr($loc['date_location']) ?>, <?= number_format((int) $loc['km_depart'], 0, ',', ' ') ?> km, <?= number_format((float) $loc['qte_carburant'], 0, ',', ' ') ?> L</dd>
      <dt>Retour prévu</dt><dd><?= date_fr($loc['date_retour_prevue']) ?></dd>
      <dt>Tarif journée</dt><dd><?= euros((float) $loc['prix_jour']) ?> (catégorie <?= h($loc['code_categorie']) ?>)</dd>
    </dl>
  </div>
  <form method="post" class="formulaire" autocomplete="off">
    <?= champ_jeton() ?><input type="hidden" name="loc" value="<?= $id ?>">
    <h2>2. État du véhicule au retour</h2>
    <div class="grille-champs">
      <div class="champ"><label for="km">Kilométrage</label><input id="km" name="km" inputmode="numeric" value="<?= h($saisie['km']) ?>" required></div>
      <div class="champ"><label for="carburant">Carburant (litres)</label><input id="carburant" name="carburant" inputmode="decimal" value="<?= h($saisie['carburant']) ?>" required></div>
      <div class="champ"><label for="date">Date de retour</label><input id="date" type="date" name="date" value="<?= h($saisie['date']) ?>" min="<?= h($loc['date_location']) ?>" max="<?= $aujourdhui ?>" required></div>
    </div>
    <div class="actions"><button class="btn<?= $calcul ? ' btn--secondaire' : '' ?>" name="action" value="calculer" formnovalidate>3. Comparer avec le départ</button><a href="rendre.php">Changer de location</a></div>

    <?php if ($calcul): ?>
      <h2 style="margin-top:26px">3. Comparaison</h2>
      <div class="defile"><table class="table">
        <tbody>
          <tr><td>Retard</td><td><?= $calcul['jours'] ?> jour<?= $calcul['jours'] > 1 ? 's' : '' ?> × <?= euros((float) $loc['prix_jour']) ?></td><td class="num"><?= euros($calcul['retard']) ?></td></tr>
          <tr><td>Carburant manquant</td><td><?= number_format($calcul['litres'], 1, ',', ' ') ?> L × <?= euros(PRIX_LITRE_CARBURANT) ?></td><td class="num"><?= euros($calcul['carburant']) ?></td></tr>
          <tr><td><b>Supplément</b></td><td></td><td class="num"><b><?= euros($calcul['total']) ?></b></td></tr>
        </tbody>
      </table></div>
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
        <p class="gris" style="margin-top:12px">Retour dans les temps, réservoir complet : le point d'extension n'est pas atteint, aucun supplément.</p>
      <?php endif; ?>
      <div class="actions"><button class="btn" name="action" value="enregistrer">5. Enregistrer le retour<?= $calcul['total'] > 0 ? ' et encaisser ' . euros($calcul['total']) : '' ?></button></div>
    <?php endif; ?>
  </form>
<?php endif; ?>
<?php pied();
