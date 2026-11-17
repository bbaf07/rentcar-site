<?php
require __DIR__ . '/inc/app.php';
// État des lieux de départ (UC-06, étape 10) ; consultation des états des lieux par l'employé ou le client concerné.
$pdo = bd();
$u = utilisateur();
if (!$u) { exiger('client'); }
$numLoc = (int) ($_GET['loc'] ?? $_POST['loc'] ?? 0);
if (!peut_voir_location($pdo, $numLoc)) { http_response_code(404); exit('État des lieux introuvable.'); }
$st = $pdo->prepare('SELECT l.*, r.num_client, v.marque, v.modele, v.nb_kms, v.qte_carburant, r.immatriculation FROM location l
                     JOIN reservation r ON r.num_reservation = l.num_reservation JOIN vehicule v ON v.immatriculation = r.immatriculation WHERE l.num_location = ?');
$st->execute([$numLoc]);
$l = $st->fetch();
$depart = charger_etat($pdo, $numLoc, 'DEPART');
$retour = charger_etat($pdo, $numLoc, 'RETOUR');
$estEmploye = $u['role'] === 'employe';
$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $estEmploye && !$depart) {
    verifier_jeton();
    $erreurs = verifier_etat($_POST, $_FILES, (int) $l['km_depart']);
    if (!$erreurs) {
        $pdo->beginTransaction();
        try {
            enregistrer_etat($pdo, $numLoc, 'DEPART', $_POST, $_FILES, (string) $u['matricule']);
            notifier($pdo, (int) $l['num_client'], "État des lieux de départ, location n° $numLoc",
                "L'état des lieux de départ de votre {$l['marque']} {$l['modele']} est enregistré, avec ses photos. Il sera comparé à celui du retour : seuls les dommages nouveaux peuvent être facturés.");
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        flash('ok', 'État des lieux de départ enregistré : la location peut commencer.');
        rediriger('contrat.php?loc=' . $numLoc);
    }
}
entete('État des lieux, location n° ' . $numLoc, $estEmploye && !$depart ? 'UC-06' : null, $estEmploye && !$depart ? 10 : 0);
?>
<p class="gris"><?= h($l['marque'] . ' ' . $l['modele']) ?> <?= plaque($l['immatriculation']) ?></p>
<?php if ($erreurs): ?><div class="flash flash--erreur" role="alert"><?= implode('<br>', array_map('h', $erreurs)) ?></div><?php endif; ?>
<?php if (!$depart && $estEmploye): ?>
  <form class="formulaire" method="post" enctype="multipart/form-data">
    <?= champ_jeton() ?><input type="hidden" name="loc" value="<?= $numLoc ?>">
    <h2>État des lieux de départ</h2>
    <p class="gris">Faites le tour du véhicule avec le client. Ce constat sert de référence au retour : un dommage noté ici ne pourra pas lui être facturé.</p>
    <?= champs_etat($_POST, (int) $l['nb_kms'], (float) $l['qte_carburant']) ?>
    <div class="actions"><button class="btn" type="submit">Enregistrer l'état des lieux</button><a href="contrat.php?loc=<?= $numLoc ?>">Retour au contrat</a></div>
  </form>
<?php elseif (!$depart): ?>
  <div class="vide">L'état des lieux de départ n'a pas encore été réalisé.</div>
<?php else: ?>
  <section class="formulaire"><h2>Départ</h2><?= vue_etat($depart) ?></section>
  <?php if ($retour): ?><section class="formulaire"><h2>Retour</h2><?= vue_etat($retour, $depart) ?></section><?php endif; ?>
<?php endif; ?>
<?php pied();
