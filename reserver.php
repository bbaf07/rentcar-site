<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
$u = utilisateur();
if (!$u) { exiger('client'); }
$estEmploye = $u['role'] === 'employe';
$aujourdhui = date('Y-m-d');

$vehicules = $pdo->query("SELECT v.*, c.libelle AS categorie_libelle FROM vehicule v JOIN categorie c ON c.code = v.code_categorie WHERE v.etat <> 'EN_MAINTENANCE' ORDER BY v.code_categorie, v.marque")->fetchAll();
$formules = $pdo->query('SELECT * FROM formule ORDER BY duree_jours')->fetchAll();
$clients = $estEmploye ? $pdo->query('SELECT c.num_client, p.nom, p.prenom FROM client c JOIN personne p ON p.id_personne = c.id_personne ORDER BY p.nom')->fetchAll() : [];

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$choix = [
    'immat' => (string) ($src['immat'] ?? ''), 'formule' => (string) ($src['formule'] ?? 'JOUR'),
    'debut' => (string) ($src['debut'] ?? $aujourdhui), 'fin' => (string) ($src['fin'] ?? date('Y-m-d', strtotime('+2 days'))),
    'client' => $estEmploye ? (int) ($src['client'] ?? 0) : (int) $u['num_client'],
];
$etape = 1; $ext = []; $erreur = null; $recap = null; $vehicule = null; $creee = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_jeton();
    $action = post('action');
    if ($action === 'modifier') {
        $ext[] = '4a';
    } else {
        $etape = 2;
        if (!$choix['immat'] || $choix['debut'] < $aujourdhui || $choix['fin'] < $choix['debut'] || ($estEmploye && !$choix['client'])) {
            $erreur = $estEmploye && !$choix['client'] ? 'Choisissez le client pour qui vous réservez.' : 'Vérifiez le véhicule et la période : la date de début ne peut pas être passée et la fin suit le début.';
            $etape = 1;
        } else {
            $libre = vehicules_disponibles($pdo, null, $choix['debut'], $choix['fin'], $choix['immat']);
            if (!$libre) {
                $erreur = 'Ce véhicule est déjà réservé ou loué sur cette période. Choisissez une autre période ou un autre véhicule.';
                $ext[] = '2a'; $etape = 1;
            } else {
                $vehicule = $libre[0];
                $recap = montant_reservation($pdo, $vehicule['code_categorie'], $choix['formule'], $choix['debut'], $choix['fin']);
                $etape = 4;
                if ($action === 'confirmer' && $recap) {
                    // Étape 5 : enregistrement
                    $statut = $estEmploye ? 'CONFIRMEE' : 'EN_ATTENTE';
                    $pdo->beginTransaction();
                    $pdo->prepare('INSERT INTO reservation (date_reservation, date_debut, date_fin, statut, num_client, immatriculation, code_formule) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?)')
                        ->execute([$choix['debut'], $choix['fin'], $statut, $choix['client'], $choix['immat'], $choix['formule']]);
                    $creee = (int) $pdo->lastInsertId();
                    if ($estEmploye) {
                        $pdo->prepare("UPDATE vehicule SET etat = 'RESERVE' WHERE immatriculation = ? AND etat = 'DISPONIBLE'")->execute([$choix['immat']]);
                    }
                    $pdo->commit();
                    $etape = 6;
                }
            }
        }
    }
}
if (!$vehicule && $choix['immat']) {
    foreach ($vehicules as $v) { if ($v['immatriculation'] === $choix['immat']) { $vehicule = $v; } }
}
entete('Réserver un véhicule', 'UC-05', $etape, $ext);
?>
<?php if ($creee): ?>
  <div class="flash flash--ok" role="status">Réservation n° <?= $creee ?> enregistrée<?= $estEmploye ? ' et confirmée.' : ' : elle est en attente de confirmation par l\'agence.' ?></div>
  <?php [$photo] = photo_vehicule($vehicule); ?>
  <div class="recap">
    <img src="<?= h($photo) ?>" alt="">
    <dl>
      <dt>Réservation</dt><dd>n° <?= $creee ?> <?= badge($estEmploye ? 'CONFIRMEE' : 'EN_ATTENTE') ?></dd>
      <dt>Véhicule</dt><dd><?= h($vehicule['marque'] . ' ' . $vehicule['modele']) ?> <?= plaque($vehicule['immatriculation']) ?></dd>
      <dt>Période</dt><dd>du <?= date_fr($choix['debut']) ?> au <?= date_fr($choix['fin']) ?></dd>
      <dt>Formule</dt><dd><?= h($recap['formule']) ?></dd>
      <div class="total"><span>Montant prévisionnel</span><b><?= euros($recap['montant']) ?></b></div>
    </dl>
  </div>
  <p class="gris">Le jour du retrait, présentez-vous au comptoir avec votre carte bancaire : l'employé transformera cette réservation en location (cas UC-06).</p>
  <div class="actions"><a class="btn" href="<?= $estEmploye ? 'reservations.php' : 'espace-client.php' ?>"><?= $estEmploye ? 'Voir les réservations' : 'Voir mes réservations' ?></a><a class="btn btn--secondaire" href="reserver.php">Nouvelle réservation</a></div>

<?php elseif ($recap && $etape === 4): ?>
  <h2>Vérifiez votre réservation</h2>
  <?php [$photo] = photo_vehicule($vehicule); ?>
  <div class="recap">
    <img src="<?= h($photo) ?>" alt="<?= h($vehicule['marque'] . ' ' . $vehicule['modele']) ?>">
    <dl>
      <?php if ($estEmploye): foreach ($clients as $c) { if ((int) $c['num_client'] === $choix['client']) { $nomClient = $c['prenom'] . ' ' . $c['nom']; } } ?>
        <dt>Client</dt><dd><?= h($nomClient ?? '') ?> (n° <?= $choix['client'] ?>)</dd>
      <?php endif; ?>
      <dt>Véhicule</dt><dd><?= h($vehicule['marque'] . ' ' . $vehicule['modele']) ?> <?= plaque($vehicule['immatriculation']) ?></dd>
      <dt>Catégorie</dt><dd><?= h($vehicule['code_categorie'] . ', ' . $vehicule['categorie_libelle']) ?></dd>
      <dt>Période</dt><dd>du <?= date_fr($choix['debut']) ?> au <?= date_fr($choix['fin']) ?> (<?= $recap['jours'] ?> jour<?= $recap['jours'] > 1 ? 's' : '' ?>)</dd>
      <dt>Formule</dt><dd><?= h($recap['formule']) ?> : <?= $recap['nb'] ?> × <?= euros($recap['prix']) ?></dd>
      <div class="total"><span>Montant prévisionnel</span><b><?= euros($recap['montant']) ?></b></div>
    </dl>
  </div>
  <form method="post" class="actions">
    <?= champ_jeton() ?>
    <?php foreach ($choix as $k => $val): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string) $val) ?>"><?php endforeach; ?>
    <button class="btn" name="action" value="confirmer">Confirmer la réservation</button>
    <button class="btn btn--secondaire" name="action" value="modifier">Modifier</button>
  </form>

<?php else: ?>
  <?php if ($erreur): ?><div class="flash flash--erreur" role="alert"><?= h($erreur) ?></div><?php endif; ?>
  <?php if ($vehicule): ?><div style="max-width:420px;margin-bottom:20px"><?= carte_vehicule($vehicule) ?></div><?php endif; ?>
  <form class="formulaire" method="post">
    <?= champ_jeton() ?>
    <div class="grille-champs">
      <?php if ($estEmploye): ?>
        <div class="champ"><label for="client">Client</label><select id="client" name="client" required><option value="">Choisir…</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int) $c['num_client'] ?>"<?= $choix['client'] === (int) $c['num_client'] ? ' selected' : '' ?>><?= h($c['nom'] . ' ' . $c['prenom']) ?> (n° <?= (int) $c['num_client'] ?>)</option><?php endforeach; ?>
        </select><small>RG4 : l'employé peut réserver pour un client.</small></div>
      <?php endif; ?>
      <div class="champ"><label for="immat">Véhicule</label><select id="immat" name="immat" required><option value="">Choisir…</option>
        <?php foreach ($vehicules as $v): ?><option value="<?= h($v['immatriculation']) ?>"<?= $choix['immat'] === $v['immatriculation'] ? ' selected' : '' ?>><?= h($v['code_categorie'] . ' | ' . $v['marque'] . ' ' . $v['modele'] . ' | ' . $v['immatriculation']) ?></option><?php endforeach; ?>
      </select></div>
      <div class="champ"><label for="formule">Formule</label><select id="formule" name="formule">
        <?php foreach ($formules as $f): ?><option value="<?= h($f['code']) ?>"<?= $choix['formule'] === $f['code'] ? ' selected' : '' ?>><?= h($f['libelle']) ?> (<?= (int) $f['duree_jours'] ?> j)</option><?php endforeach; ?>
      </select></div>
      <div class="champ"><label for="debut">Du</label><input id="debut" type="date" name="debut" min="<?= $aujourdhui ?>" value="<?= h($choix['debut']) ?>" required></div>
      <div class="champ"><label for="fin">Au</label><input id="fin" type="date" name="fin" min="<?= $aujourdhui ?>" value="<?= h($choix['fin']) ?>" required></div>
    </div>
    <div class="actions"><button class="btn" name="action" value="verifier">Vérifier la disponibilité et le prix</button><a href="recherche.php">Chercher d'abord un véhicule libre</a></div>
  </form>
<?php endif; ?>
<?php pied();
