<?php
require __DIR__ . '/inc/app.php';
// UC-06 Louer un véhicule : suit la description textuelle et le diagramme de séquence, étape par étape.
// L'état de la location en cours de saisie est gardé en session, comme les attributs de CtrlLocation.
$pdo = bd();
$u = exiger('employe');
$aujourdhui = date('Y-m-d');
const TENTATIVES_MAX = 3;

function charger_reservation(PDO $pdo, int $num): ?array
{
    $st = $pdo->prepare("SELECT r.*, v.marque, v.modele, v.couleur, v.type, v.code_categorie, v.nb_kms, v.qte_carburant, v.etat, v.num_emplacement,
                                c.libelle AS categorie_libelle, e.zone, p.nom, p.prenom, p.adresse, p.telephone, cl.num_permis, f.libelle AS formule_libelle
                         FROM reservation r JOIN vehicule v ON v.immatriculation = r.immatriculation
                         JOIN categorie c ON c.code = v.code_categorie LEFT JOIN emplacement e ON e.numero = v.num_emplacement
                         JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                         JOIN formule f ON f.code = r.code_formule WHERE r.num_reservation = ?");
    $st->execute([$num]);
    return $st->fetch() ?: null;
}
function abandonner(): void
{
    unset($_SESSION['louer']);
}

$etape = 1; $ext = []; $message = null; $echec = false; $resultats = null;
$etat = $_SESSION['louer'] ?? null;     // ['num' => …, 'formule' => …, 'tentatives' => …]
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('action') : '';
if ($action) { verifier_jeton(); }

// Contrat d'une location qui vient d'être enregistrée (étape 10, après redirection)
if (isset($_GET['contrat'])) {
    $st = $pdo->prepare('SELECT l.*, pa.num_transaction, pa.montant AS paye, cb.numero_masque, cb.titulaire FROM location l
                         LEFT JOIN paiement pa ON pa.num_location = l.num_location LEFT JOIN carte_bancaire cb ON cb.id_carte = pa.id_carte
                         WHERE l.num_location = ? ORDER BY pa.date_paiement LIMIT 1');
    $st->execute([(int) $_GET['contrat']]);
    $loc = $st->fetch();
    if ($loc) {
        $r = charger_reservation($pdo, (int) $loc['num_reservation']);
        entete('Contrat de location n° ' . $loc['num_location'], 'UC-06', 11);
        [$photo] = photo_vehicule($r); ?>
        <div class="flash flash--ok no-print" role="status">Paiement autorisé, location enregistrée : le véhicule est loué et la réservation est honorée.</div>
        <div class="contrat">
          <div class="entete-doc"><div><h2 style="margin:0">RentCar, contrat de location</h2>Location n° <?= (int) $loc['num_location'] ?>, réservation n° <?= (int) $r['num_reservation'] ?></div><div style="text-align:right">Établi le <?= date_fr($loc['date_location']) ?><br>par <?= h($u['prenom'] . ' ' . $u['nom']) ?> (<?= h((string) $u['matricule']) ?>)</div></div>
          <table>
            <tr><th>Client</th><td><?= h($r['prenom'] . ' ' . $r['nom']) ?>, permis <?= h($r['num_permis']) ?><br><?= h((string) $r['adresse']) ?></td></tr>
            <tr><th>Véhicule</th><td><?= h($r['marque'] . ' ' . $r['modele']) ?>, <?= h($r['immatriculation']) ?>, catégorie <?= h($r['code_categorie']) ?></td></tr>
            <tr><th>Période</th><td>du <?= date_fr($loc['date_location']) ?> au <?= date_fr($loc['date_retour_prevue']) ?> (retour prévu)</td></tr>
            <tr><th>Formule</th><td><?= h($r['formule_libelle']) ?></td></tr>
            <tr><th>Compteur au départ</th><td><?= number_format((int) $loc['km_depart'], 0, ',', ' ') ?> km, carburant <?= number_format((float) $r['qte_carburant'], 0, ',', ' ') ?> L</td></tr>
            <tr><th>Paiement</th><td><?= euros((float) $loc['montant_paye']) ?>, carte <?= h((string) $loc['numero_masque']) ?>, transaction <?= h((string) $loc['num_transaction']) ?></td></tr>
          </table>
          <div class="emplacement-final">Le véhicule vous attend à l'emplacement <span><?= h((string) $r['num_emplacement']) ?><?= $r['zone'] ? ', ' . h($r['zone']) : '' ?></span></div>
          <p style="font-size:.9rem">Tout retour après la date prévue ou avec un réservoir incomplet donne lieu à un supplément (cas « Facturer un supplément »).</p>
        </div>
        <div class="actions"><button class="btn" type="button" data-imprimer>Imprimer la fiche de location</button><a class="btn btn--secondaire" href="louer.php">Nouvelle location</a></div>
        <?php pied();
        exit;
    }
}

if ($action === 'annuler') {
    abandonner(); $etat = null;
    flash('info', 'Saisie abandonnée : aucune location n\'a été créée.');
    rediriger('louer.php');
}

// Étape 1a : recherche de la réservation par le nom du client
if ($action === 'chercher') {
    $ext[] = '1a';
    $nom = post('nom');
    $st = $pdo->prepare("SELECT r.num_reservation, r.date_debut, r.date_fin, r.statut, r.immatriculation, p.nom, p.prenom, v.marque, v.modele
                         FROM reservation r JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                         JOIN vehicule v ON v.immatriculation = r.immatriculation
                         WHERE (p.nom LIKE ? OR p.prenom LIKE ?) AND r.statut IN ('EN_ATTENTE','CONFIRMEE') ORDER BY r.date_debut");
    $like = '%' . $nom . '%';
    $st->execute([$like, $like]);
    $resultats = $st->fetchAll();
}

// Étapes 1 à 5 : saisie du numéro, vérifications, calcul du montant, récapitulatif
$num = 0;
if ($action === 'saisir') { $num = (int) post('num'); }
elseif (isset($_GET['num']) && !$action) { $num = (int) $_GET['num']; }
if ($num) {
    abandonner();
    $etape = 2;
    $r = charger_reservation($pdo, $num);
    if (!$r || $r['statut'] !== 'CONFIRMEE' || $aujourdhui < $r['date_debut'] || $aujourdhui > $r['date_fin']) {
        $ext[] = '2a'; $echec = true;
        if (!$r) { $message = "La réservation n° $num n'existe pas."; }
        elseif ($r['statut'] !== 'CONFIRMEE') { $message = "La réservation n° $num n'est pas confirmée (statut : " . libelle_etat($r['statut']) . ').' . ($r['statut'] === 'EN_ATTENTE' ? ' Confirmez-la d\'abord depuis la liste des réservations.' : ''); }
        elseif ($aujourdhui < $r['date_debut']) { $message = "La réservation n° $num ne commence que le " . date_fr($r['date_debut']) . '.'; }
        else { $message = "La réservation n° $num a expiré le " . date_fr($r['date_fin']) . '.'; }
    } else {
        $etape = 3;
        if ($lc = location_en_cours($pdo, (int) $r['num_client'])) {
            $ext[] = '3a'; $echec = true;
            $message = "{$r['prenom']} {$r['nom']} a déjà une location en cours (véhicule {$lc['immatriculation']}) : un client ne loue qu'un véhicule à la fois (RG6).";
        } else {
            $_SESSION['louer'] = $etat = ['num' => $num, 'formule' => $r['code_formule'], 'tentatives' => 0];
            $etape = 5;
        }
    }
}

// Étape 5a : changement de formule, le montant est recalculé
if ($action === 'formule' && $etat) {
    $f = post('formule');
    $r = charger_reservation($pdo, $etat['num']);
    if (tarif($pdo, $r['code_categorie'], $f)) { $_SESSION['louer']['formule'] = $etat['formule'] = $f; }
    $ext[] = '5a'; $etape = 5;
}

// Étapes 6 à 10 : carte bancaire, autorisation (UC-11 inclus), enregistrement, contrat
if ($action === 'payer' && $etat) {
    $r = charger_reservation($pdo, $etat['num']);
    $m = montant_reservation($pdo, $r['code_categorie'], $etat['formule'], $r['date_debut'], $r['date_fin']);
    $numero = post('numero'); $mois = post('expiration'); $titulaire = post('titulaire');
    $_SESSION['louer']['tentatives'] = ++$etat['tentatives'];
    $etape = 7;
    // UC-11 : contrôle local (expiration, Luhn) avant d'interroger la banque
    $reponse = carte_valide($numero, $mois, $aujourdhui) ? banque_autoriser($numero, $m['montant']) : 'REFUSE';
    if ($reponse === 'INDISPONIBLE') {
        $ext[] = '7a'; $echec = true;
        $message = 'Le système bancaire est indisponible : la location n\'est pas enregistrée. La réservation reste valable.';
        abandonner(); $etat = null;
    } elseif ($reponse === 'REFUSE') {
        $etape = 8;
        if ($etat['tentatives'] < TENTATIVES_MAX) {
            $ext[] = '8a'; $etape = 6;
            $message = (carte_valide($numero, $mois, $aujourdhui) ? 'Paiement refusé par la banque.' : 'Carte invalide ou expirée.') . ' Demandez une autre carte au client (tentative ' . $etat['tentatives'] . ' sur ' . TENTATIVES_MAX . ').';
        } else {
            $ext[] = '8b'; $echec = true;
            $message = 'Paiement refusé ' . TENTATIVES_MAX . ' fois : la location est abandonnée, la réservation est conservée.';
            abandonner(); $etat = null;
        }
    } else {
        // Étape 9 : toutes les écritures dans une seule transaction (tout ou rien)
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO location (num_reservation, date_location, date_retour_prevue, km_depart, montant_paye) VALUES (?, ?, ?, ?, ?)')
                ->execute([$r['num_reservation'], $aujourdhui, $r['date_fin'], $r['nb_kms'], $m['montant']]);
            $numLoc = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO carte_bancaire (num_client, numero_masque, titulaire, date_expiration) VALUES (?, ?, ?, ?)')
                ->execute([$r['num_client'], masquer($numero), $titulaire ?: $r['prenom'] . ' ' . $r['nom'], fin_validite($mois)]);
            $idCarte = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO paiement (num_transaction, date_paiement, montant, statut, num_location, id_carte) VALUES (?, ?, ?, 'AUTORISE', ?, ?)")
                ->execute(['TX-' . strtoupper(bin2hex(random_bytes(6))), $aujourdhui, $m['montant'], $numLoc, $idCarte]);
            $pdo->prepare("UPDATE vehicule SET etat = 'LOUE' WHERE immatriculation = ?")->execute([$r['immatriculation']]);
            $pdo->prepare("UPDATE reservation SET statut = 'HONOREE', code_formule = ? WHERE num_reservation = ?")->execute([$etat['formule'], $r['num_reservation']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        abandonner();
        rediriger('louer.php?contrat=' . $numLoc);
    }
    if ($etat) { $etape = max($etape, 6); }
}

// Reprise d'une saisie en cours (rechargement de page)
if ($etat && !$num && !$action) { $etape = 6; }
$r = $etat ? charger_reservation($pdo, $etat['num']) : ($r ?? null);
$m = $etat ? montant_reservation($pdo, $r['code_categorie'], $etat['formule'], $r['date_debut'], $r['date_fin']) : null;
$formules = $pdo->query('SELECT * FROM formule ORDER BY duree_jours')->fetchAll();
$aRetirer = $pdo->query("SELECT r.num_reservation, r.date_debut, r.date_fin, r.immatriculation, p.prenom, p.nom, v.marque, v.modele
                         FROM reservation r JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                         JOIN vehicule v ON v.immatriculation = r.immatriculation
                         WHERE r.statut = 'CONFIRMEE' AND CURDATE() BETWEEN r.date_debut AND r.date_fin ORDER BY r.date_debut")->fetchAll();

entete('Louer un véhicule', 'UC-06', $etape, $ext);
?>
<?php if ($message): ?><div class="flash <?= $echec ? 'flash--erreur' : 'flash--info' ?>" role="alert"><?= h($message) ?><?= $echec ? ' Fin du cas d\'utilisation (échec) : rien n\'a été modifié.' : '' ?></div><?php endif; ?>

<?php if ($etat && $r && $m): ?>
  <h2>Récapitulatif</h2>
  <?php [$photo] = photo_vehicule($r); ?>
  <div class="recap">
    <img src="<?= h($photo) ?>" alt="<?= h($r['marque'] . ' ' . $r['modele']) ?>">
    <dl>
      <dt>Réservation</dt><dd>n° <?= (int) $r['num_reservation'] ?> <?= badge($r['statut']) ?></dd>
      <dt>Client</dt><dd><?= h($r['prenom'] . ' ' . $r['nom']) ?>, permis <?= h($r['num_permis']) ?></dd>
      <dt>Véhicule</dt><dd><?= h($r['marque'] . ' ' . $r['modele']) ?> <?= plaque($r['immatriculation']) ?></dd>
      <dt>Dates</dt><dd>du <?= date_fr($r['date_debut']) ?> au <?= date_fr($r['date_fin']) ?> (<?= $m['jours'] ?> jour<?= $m['jours'] > 1 ? 's' : '' ?>)</dd>
      <dt>Formule</dt><dd><?= h($m['formule']) ?> : <?= $m['nb'] ?> × <?= euros($m['prix']) ?> (catégorie <?= h($r['code_categorie']) ?>)</dd>
      <div class="total"><span>Montant à payer</span><b><?= euros($m['montant']) ?></b></div>
    </dl>
  </div>
  <form method="post" class="formulaire">
    <?= champ_jeton() ?>
    <h3>5a. Le client change de formule</h3>
    <div class="grille-champs"><div class="champ"><label for="formule">Formule</label><select id="formule" name="formule">
      <?php foreach ($formules as $f): $mf = montant_reservation($pdo, $r['code_categorie'], $f['code'], $r['date_debut'], $r['date_fin']); ?>
        <option value="<?= h($f['code']) ?>"<?= $etat['formule'] === $f['code'] ? ' selected' : '' ?>><?= h($f['libelle']) ?> : <?= $mf ? euros($mf['montant']) : '–' ?></option>
      <?php endforeach; ?></select></div></div>
    <div class="actions"><button class="btn btn--secondaire" name="action" value="formule">Recalculer le montant</button></div>
  </form>
  <form method="post" class="formulaire" autocomplete="off">
    <?= champ_jeton() ?>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <h3 style="margin:0">6. Carte bancaire du client</h3>
      <span class="tentatives" aria-label="Tentatives de paiement">Tentatives <?php for ($i = 1; $i <= TENTATIVES_MAX; $i++): ?><i class="<?= $i <= $etat['tentatives'] ? 'utilisee' : '' ?>"></i><?php endfor; ?></span>
    </div>
    <div class="grille-champs" style="margin-top:14px">
      <div class="champ"><label for="numero">Numéro de carte</label><input id="numero" name="numero" inputmode="numeric" required placeholder="4970 1012 3006 4242"></div>
      <div class="champ"><label for="expiration">Expiration</label><input id="expiration" type="month" name="expiration" min="<?= date('Y-m') ?>" required></div>
      <div class="champ"><label for="titulaire">Titulaire</label><input id="titulaire" name="titulaire" value="<?= h($r['prenom'] . ' ' . $r['nom']) ?>"></div>
    </div>
    <p class="gris" style="margin-top:12px;font-size:.9rem">Banque simulée : <code>4970 1012 3006 4242</code> est acceptée ; une carte finissant par <code>0000</code> est refusée (<code>4970 1012 3006 0000</code>) ; par <code>9999</code>, la banque est indisponible (<code>4970 1012 3000 9999</code>). Seuls les 4 derniers chiffres sont enregistrés.</p>
    <div class="actions">
      <button class="btn" name="action" value="payer">7. Demander l'autorisation de paiement</button>
      <button class="btn btn--danger" name="action" value="annuler" formnovalidate>Abandonner</button>
    </div>
  </form>

<?php else: ?>
  <form method="post" class="formulaire">
    <?= champ_jeton() ?>
    <h2>1. Numéro de réservation</h2>
    <div class="grille-champs"><div class="champ"><label for="num">N° de réservation</label><input id="num" name="num" type="number" min="1" required value="<?= $num ?: '' ?>"></div></div>
    <div class="actions"><button class="btn" name="action" value="saisir">Vérifier la réservation</button></div>
  </form>
  <form method="post" class="formulaire">
    <?= champ_jeton() ?>
    <h3>1a. Le client n'a pas son numéro</h3>
    <div class="grille-champs"><div class="champ"><label for="nom">Nom ou prénom du client</label><input id="nom" name="nom" required value="<?= h(post('nom')) ?>"></div></div>
    <div class="actions"><button class="btn btn--secondaire" name="action" value="chercher">Rechercher ses réservations</button></div>
    <?php if ($resultats !== null): ?>
      <?php if (!$resultats): ?><div class="vide" style="margin-top:14px">Aucune réservation en cours pour ce nom.</div>
      <?php else: ?>
        <div class="defile" style="margin-top:14px"><table class="table">
          <thead><tr><th>N°</th><th>Client</th><th>Véhicule</th><th>Période</th><th>Statut</th><th></th></tr></thead>
          <tbody><?php foreach ($resultats as $x): ?><tr>
            <td><?= (int) $x['num_reservation'] ?></td><td><?= h($x['prenom'] . ' ' . $x['nom']) ?></td><td><?= h($x['marque'] . ' ' . $x['modele']) ?></td>
            <td><?= date_fr($x['date_debut']) ?> au <?= date_fr($x['date_fin']) ?></td><td><?= badge($x['statut']) ?></td>
            <td><a class="btn btn--petit" href="louer.php?num=<?= (int) $x['num_reservation'] ?>">Choisir</a></td></tr><?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    <?php endif; ?>
  </form>
  <h2>Retraits prévus aujourd'hui</h2>
  <?php if (!$aRetirer): ?><div class="vide">Aucune réservation confirmée ne couvre la date du jour.</div>
  <?php else: ?>
    <div class="defile"><table class="table">
      <thead><tr><th>N°</th><th>Client</th><th>Véhicule</th><th>Période</th><th></th></tr></thead>
      <tbody><?php foreach ($aRetirer as $x): ?><tr>
        <td><?= (int) $x['num_reservation'] ?></td><td><?= h($x['prenom'] . ' ' . $x['nom']) ?></td><td><?= h($x['marque'] . ' ' . $x['modele']) ?> <?= plaque($x['immatriculation']) ?></td>
        <td><?= date_fr($x['date_debut']) ?> au <?= date_fr($x['date_fin']) ?></td><td><a class="btn btn--petit" href="louer.php?num=<?= (int) $x['num_reservation'] ?>">Louer</a></td></tr><?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
<?php endif; ?>
<?php pied();
