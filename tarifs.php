<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
$tarifs = $pdo->query('SELECT t.code_categorie AS c, t.code_formule AS f, t.prix AS p, fo.duree_jours AS d, fo.libelle AS l
                       FROM tarif t JOIN formule fo ON fo.code = t.code_formule ORDER BY t.code_categorie, fo.duree_jours')->fetchAll();
$categories = $pdo->query('SELECT * FROM categorie ORDER BY code')->fetchAll();
$formules = $pdo->query('SELECT * FROM formule ORDER BY duree_jours')->fetchAll();
$grille = [];
foreach ($tarifs as $t) { $grille[$t['c']][$t['f']] = (float) $t['p']; }
$exemples = [];
foreach ($pdo->query('SELECT immatriculation, marque, modele, code_categorie FROM vehicule ORDER BY immatriculation')->fetchAll() as $v) { $exemples[$v['code_categorie']] ??= $v; }

entete('Consulter les tarifs et les formules', 'UC-02', 3);
?>
<p class="gris">Le prix dépend de deux choses (RG7) : la <b>catégorie</b> du véhicule et la <b>formule</b> choisie. Dans le modèle, ce prix est porté par la classe-association <code>Tarif</code>, entre <code>Categorie</code> et <code>Formule</code>.</p>

<section class="section">
  <div class="defile">
    <table class="grille">
      <thead><tr><th>Catégorie</th><?php foreach ($formules as $f): ?><th><?= h($f['libelle']) ?><br><small class="gris"><?= (int) $f['duree_jours'] ?> jour<?= $f['duree_jours'] > 1 ? 's' : '' ?></small></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($categories as $k): ?>
          <tr><td><b><?= h($k['code']) ?></b>, <?= h($k['libelle']) ?><?php if (isset($exemples[$k['code']])): ?><br><small class="gris">ex. <?= h($exemples[$k['code']]['marque'] . ' ' . $exemples[$k['code']]['modele']) ?></small><?php endif; ?></td>
          <?php foreach ($formules as $f): ?><td data-c="<?= h($k['code']) ?>" data-f="<?= h($f['code']) ?>"><?= isset($grille[$k['code']][$f['code']]) ? euros($grille[$k['code']][$f['code']]) : '–' ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="formulaire" id="simulateur" data-tarifs="<?= h(json_encode(array_map(fn($t) => ['c' => $t['c'], 'f' => $t['f'], 'p' => (float) $t['p'], 'd' => (int) $t['d'], 'l' => $t['l']], $tarifs), JSON_UNESCAPED_UNICODE)) ?>">
  <h2>Simuler un prix</h2>
  <p class="gris">On compte combien de formules couvrent la durée, arrondi au supérieur : 3 jours en formule week-end (2 jours) font 2 week-ends.</p>
  <div class="grille-champs">
    <div class="champ"><label for="s-cat">Catégorie</label><select id="s-cat"><?php foreach ($categories as $k): ?><option value="<?= h($k['code']) ?>"><?= h($k['code'] . ', ' . $k['libelle']) ?></option><?php endforeach; ?></select></div>
    <div class="champ"><label for="s-for">Formule</label><select id="s-for"><?php foreach ($formules as $f): ?><option value="<?= h($f['code']) ?>"><?= h($f['libelle']) ?></option><?php endforeach; ?></select></div>
    <div class="champ"><label for="s-jours">Durée (jours)</label><input id="s-jours" type="number" min="1" max="365" value="3"></div>
  </div>
  <div aria-live="polite" style="margin-top:18px"><div class="resultat" id="s-total">–</div><p class="gris" id="s-detail"></p></div>
  <div class="actions"><a class="btn" href="recherche.php">Chercher un véhicule libre</a></div>
</section>
<?php pied();
