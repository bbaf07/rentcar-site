<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
$aujourdhui = date('Y-m-d');
$cat = (string) ($_GET['cat'] ?? '');
$debut = (string) ($_GET['debut'] ?? '');
$fin = (string) ($_GET['fin'] ?? '');
$categories = $pdo->query('SELECT * FROM categorie ORDER BY code')->fetchAll();
$formules = $pdo->query('SELECT * FROM formule ORDER BY duree_jours')->fetchAll();

$etape = 1; $ext = []; $erreur = null; $resultats = null;
if ($debut !== '' || $fin !== '') {
    $etape = 2;
    $d = DateTime::createFromFormat('Y-m-d', $debut);
    $f = DateTime::createFromFormat('Y-m-d', $fin);
    if (!$d || !$f) {
        $erreur = 'Indiquez une date de début et une date de fin.';
    } elseif ($debut < $aujourdhui) {
        $erreur = 'La date de début ne peut pas être passée.';
    } elseif ($fin < $debut) {
        $erreur = 'La date de fin doit suivre la date de début.';
    }
    if ($erreur) {
        $ext[] = '2a'; $etape = 1;
    } else {
        $resultats = vehicules_disponibles($pdo, $cat ?: null, $debut, $fin);
        $etape = 3;
        if (!$resultats) { $ext[] = '3a'; }
    }
}
$jours = $resultats !== null ? max(1, jours_entre($debut, $fin)) : 0;

entete("Rechercher la disponibilité d'un véhicule", 'UC-01', $etape, $ext);
?>
<form class="formulaire" method="get">
  <div class="grille-champs">
    <div class="champ"><label for="cat">Catégorie</label>
      <select id="cat" name="cat"><option value="">Toutes les catégories</option>
        <?php foreach ($categories as $k): ?><option value="<?= h($k['code']) ?>"<?= $cat === $k['code'] ? ' selected' : '' ?>><?= h($k['code'] . ', ' . $k['libelle']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="champ"><label for="debut">Date de début</label><input id="debut" type="date" name="debut" min="<?= $aujourdhui ?>" value="<?= h($debut ?: $aujourdhui) ?>" required></div>
    <div class="champ"><label for="fin">Date de fin</label><input id="fin" type="date" name="fin" min="<?= $aujourdhui ?>" value="<?= h($fin ?: date('Y-m-d', strtotime('+3 days'))) ?>" required></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Rechercher les véhicules libres</button></div>
</form>

<?php if ($erreur): ?>
  <div class="flash flash--erreur" role="alert"><?= h($erreur) ?></div>
<?php elseif ($resultats !== null): ?>
  <h2><?= count($resultats) ?> véhicule<?= count($resultats) > 1 ? 's' : '' ?> libre<?= count($resultats) > 1 ? 's' : '' ?> du <?= date_fr($debut) ?> au <?= date_fr($fin) ?></h2>
  <?php if (!$resultats): ?>
    <div class="vide">Aucun véhicule libre sur cette période<?= $cat ? ' dans la catégorie ' . h($cat) : '' ?>. Essayez une autre catégorie ou d'autres dates.
      <?php if ($cat): ?><br><a href="?debut=<?= h($debut) ?>&fin=<?= h($fin) ?>">Chercher dans toutes les catégories</a><?php endif; ?></div>
  <?php else: ?>
    <div class="parc">
      <?php foreach ($resultats as $v):
          // Formule la plus avantageuse pour cette durée
          $meilleur = null;
          foreach ($formules as $fo) {
              $m = montant_reservation($pdo, $v['code_categorie'], $fo['code'], $debut, $fin);
              if ($m && ($meilleur === null || $m['montant'] < $meilleur['montant'])) { $meilleur = $m + ['code' => $fo['code']]; }
          }
          $lien = 'reserver.php?' . http_build_query(['immat' => $v['immatriculation'], 'debut' => $debut, 'fin' => $fin, 'formule' => $meilleur['code'] ?? 'JOUR']);
          $action = '<div class="vehicule__prix"><div><small>' . $jours . ' jour' . ($jours > 1 ? 's' : '') . ', formule ' . h($meilleur['formule'] ?? '') . '</small><b>' . euros($meilleur['montant'] ?? 0) . '</b></div><a class="btn btn--petit" href="' . h($lien) . '">Réserver</a></div>';
          echo carte_vehicule($v, $action);
      endforeach; ?>
    </div>
    <p class="gris" style="margin-top:16px">Pour réserver (étape 4), il faut être client : <?= role() === 'visiteur' ? '<a href="connexion.php">connectez-vous</a> ou <a href="inscription.php">inscrivez-vous</a>.' : 'cliquez sur « Réserver ».' ?></p>
  <?php endif; ?>
<?php endif; ?>
<?php pied();
