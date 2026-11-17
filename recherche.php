<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
$aujourdhui = date('Y-m-d');
$cat = (string) ($_GET['cat'] ?? '');
$debut = (string) ($_GET['debut'] ?? '');
$fin = (string) ($_GET['fin'] ?? '');
// Filtres (benchmark : filtres de Getaround, entrées par type d'Europcar)
$f = [
    'type' => in_array($_GET['type'] ?? '', ['TOURISTIQUE', 'UTILITAIRE'], true) ? $_GET['type'] : '',
    'energie' => in_array($_GET['energie'] ?? '', ['ESSENCE', 'DIESEL', 'HYBRIDE', 'ELECTRIQUE'], true) ? $_GET['energie'] : '',
    'boite' => in_array($_GET['boite'] ?? '', ['MANUELLE', 'AUTOMATIQUE'], true) ? $_GET['boite'] : '',
    'places' => in_array($_GET['places'] ?? '', ['2', '3', '5', '7'], true) ? $_GET['places'] : '',
    'prixmax' => ctype_digit((string) ($_GET['prixmax'] ?? '')) ? $_GET['prixmax'] : '',
];
$categories = $pdo->query('SELECT * FROM categorie ORDER BY code')->fetchAll();
$formules = $pdo->query('SELECT * FROM formule ORDER BY duree_jours')->fetchAll();

$etape = 1; $ext = []; $erreur = null; $resultats = null;
if ($debut !== '' || $fin !== '') {
    $etape = 2;
    $d = DateTime::createFromFormat('Y-m-d', $debut);
    $fi = DateTime::createFromFormat('Y-m-d', $fin);
    if (!$d || !$fi) {
        $erreur = 'Indiquez une date de début et une date de fin.';
    } elseif ($debut < $aujourdhui) {
        $erreur = 'La date de début ne peut pas être passée.';
    } elseif ($fin < $debut) {
        $erreur = 'La date de fin doit suivre la date de début.';
    }
    if ($erreur) {
        $ext[] = '2a'; $etape = 1;
    } else {
        $resultats = vehicules_disponibles($pdo, $cat ?: null, $debut, $fin, null, $f);
        $etape = 3;
        if (!$resultats) { $ext[] = '3a'; }
    }
}
$jours = $resultats !== null ? max(1, jours_entre($debut, $fin)) : 0;
$filtresActifs = count(array_filter($f)) + ($cat ? 1 : 0);
$opt = fn(string $nom, string $val, string $lib) => '<option value="' . h($val) . '"' . (($nom === 'cat' ? $cat : $f[$nom]) === $val ? ' selected' : '') . '>' . h($lib) . '</option>';

entete("Rechercher la disponibilité d'un véhicule", 'UC-01', $etape, $ext);
?>
<form class="formulaire" method="get">
  <div class="grille-champs">
    <div class="champ"><label for="debut">Date de début</label><input id="debut" type="date" name="debut" min="<?= $aujourdhui ?>" value="<?= h($debut ?: $aujourdhui) ?>" required></div>
    <div class="champ"><label for="fin">Date de fin</label><input id="fin" type="date" name="fin" min="<?= $aujourdhui ?>" value="<?= h($fin ?: date('Y-m-d', strtotime('+3 days'))) ?>" required></div>
    <div class="champ"><label for="type">Type</label><select id="type" name="type"><?= $opt('type', '', 'Tous') . $opt('type', 'TOURISTIQUE', 'Voiture') . $opt('type', 'UTILITAIRE', 'Utilitaire') ?></select></div>
  </div>
  <details class="filtres"<?= $filtresActifs ? ' open' : '' ?>>
    <summary>Plus de filtres<?= $filtresActifs ? ' (' . $filtresActifs . ' actif' . ($filtresActifs > 1 ? 's' : '') . ')' : '' ?></summary>
    <div class="grille-champs">
      <div class="champ"><label for="cat">Catégorie</label><select id="cat" name="cat"><?= $opt('cat', '', 'Toutes') ?><?php foreach ($categories as $k) { echo $opt('cat', $k['code'], $k['code'] . ', ' . $k['libelle']); } ?></select></div>
      <div class="champ"><label for="energie">Énergie</label><select id="energie" name="energie"><?= $opt('energie', '', 'Toutes') . $opt('energie', 'ESSENCE', 'Essence') . $opt('energie', 'DIESEL', 'Diesel') . $opt('energie', 'HYBRIDE', 'Hybride') . $opt('energie', 'ELECTRIQUE', 'Électrique') ?></select></div>
      <div class="champ"><label for="boite">Boîte de vitesses</label><select id="boite" name="boite"><?= $opt('boite', '', 'Indifférent') . $opt('boite', 'MANUELLE', 'Manuelle') . $opt('boite', 'AUTOMATIQUE', 'Automatique') ?></select></div>
      <div class="champ"><label for="places">Places</label><select id="places" name="places"><?= $opt('places', '', 'Indifférent') . $opt('places', '2', '2 ou plus') . $opt('places', '3', '3 ou plus') . $opt('places', '5', '5 ou plus') . $opt('places', '7', '7 ou plus') ?></select></div>
      <div class="champ"><label for="prixmax">Prix maximum par jour</label><select id="prixmax" name="prixmax"><?= $opt('prixmax', '', 'Indifférent') . $opt('prixmax', '40', '40 € ou moins') . $opt('prixmax', '50', '50 € ou moins') . $opt('prixmax', '80', '80 € ou moins') ?></select></div>
    </div>
  </details>
  <div class="actions"><button class="btn" type="submit">Rechercher les véhicules libres</button><?php if ($filtresActifs): ?><a href="recherche.php?debut=<?= h($debut) ?>&fin=<?= h($fin) ?>">Effacer les filtres</a><?php endif; ?></div>
</form>

<?php if ($erreur): ?>
  <div class="flash flash--erreur" role="alert"><?= h($erreur) ?></div>
<?php elseif ($resultats !== null): ?>
  <h2><?= count($resultats) ?> véhicule<?= count($resultats) > 1 ? 's' : '' ?> libre<?= count($resultats) > 1 ? 's' : '' ?> du <?= date_fr($debut) ?> au <?= date_fr($fin) ?></h2>
  <p class="gris">Prix tout compris pour <?= $jours ?> jour<?= $jours > 1 ? 's' : '' ?>, avec la formule la plus avantageuse ; triés du moins cher au plus cher. La disponibilité est calculée sur les réservations réelles de l'agence.</p>
  <?php if (!$resultats): ?>
    <div class="vide">Aucun véhicule libre sur cette période avec ces critères. Retirez un filtre ou essayez d'autres dates.
      <?php if ($filtresActifs): ?><br><a href="recherche.php?debut=<?= h($debut) ?>&fin=<?= h($fin) ?>">Voir tous les véhicules libres</a><?php endif; ?></div>
  <?php else: ?>
    <div class="parc">
      <?php foreach ($resultats as $v):
          $meilleur = null;
          foreach ($formules as $fo) {
              $m = montant_reservation($pdo, $v['code_categorie'], $fo['code'], $debut, $fin);
              if ($m && ($meilleur === null || $m['montant'] < $meilleur['montant'])) { $meilleur = $m + ['code' => $fo['code']]; }
          }
          $lien = 'reserver.php?' . http_build_query(['immat' => $v['immatriculation'], 'debut' => $debut, 'fin' => $fin, 'formule' => $meilleur['code'] ?? 'JOUR']);
          $action = '<div class="vehicule__prix"><div><small>Total, ' . $jours . ' jour' . ($jours > 1 ? 's' : '') . ' (' . h($meilleur['formule'] ?? '') . ')</small><b>' . euros($meilleur['montant'] ?? 0) . '</b><small>sans frais ajoutés</small></div><a class="btn btn--petit" href="' . h($lien) . '">Réserver</a></div>';
          echo carte_vehicule($v, $action);
      endforeach; ?>
    </div>
    <p class="gris" style="margin-top:16px">Pour réserver (étape 4), il faut être client : <?= role() === 'visiteur' ? '<a href="connexion.php">connectez-vous</a> ou <a href="inscription.php">inscrivez-vous</a>.' : 'cliquez sur « Réserver ».' ?></p>
  <?php endif; ?>
<?php endif; ?>
<?php pied();
