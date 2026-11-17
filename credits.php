<?php
require __DIR__ . '/inc/app.php';
$credits = require __DIR__ . '/assets/voitures/credits.php';
entete('Crédits photos', null);
?>
<p class="gris">Les photos des véhicules proviennent de Wikimedia Commons et sont réutilisées selon leur licence libre.</p>
<div class="defile"><table class="table">
  <thead><tr><th>Modèle</th><th>Fichier d'origine</th><th>Auteur</th><th>Licence</th></tr></thead>
  <tbody><?php foreach ($credits as $modele => [$fichier, $origine, $auteur, $licence]): ?>
    <tr><td><?= h(mb_convert_case($modele, MB_CASE_TITLE)) ?></td>
      <td><a href="https://commons.wikimedia.org/wiki/File:<?= h(rawurlencode(str_replace(' ', '_', $origine))) ?>" rel="noopener" target="_blank"><?= h($origine) ?></a></td>
      <td><?= h($auteur) ?></td><td><?= h($licence) ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php pied();
