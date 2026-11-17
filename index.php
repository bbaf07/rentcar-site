<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
$aujourdhui = date('Y-m-d');
$demain = date('Y-m-d', strtotime('+3 days'));
$categories = $pdo->query('SELECT * FROM categorie ORDER BY code')->fetchAll();
$parc = $pdo->query("SELECT v.*, c.libelle AS categorie_libelle, c.caution, c.franchise FROM vehicule v JOIN categorie c ON c.code = v.code_categorie ORDER BY v.code_categorie, v.marque")->fetchAll();
$prixMin = $pdo->query("SELECT t.code_categorie, MIN(t.prix) FROM tarif t WHERE t.code_formule = 'JOUR' GROUP BY t.code_categorie")->fetchAll(PDO::FETCH_KEY_PAIR);
$c = cas();

ob_start(); ?>
<section class="hero">
  <div class="wrap hero__in">
    <div>
      <h1>Louez le bon véhicule, au bon prix</h1>
      <p>Citadines, compactes et utilitaires, disponibles à l'agence. Cherchez un véhicule libre, consultez les tarifs, puis réservez en ligne ; un employé vous remet les clés au comptoir.</p>
      <form class="recherche-rapide" action="recherche.php" method="get">
        <div><label for="q-cat">Catégorie</label><select id="q-cat" name="cat"><option value="">Toutes</option><?php foreach ($categories as $k): ?><option value="<?= h($k['code']) ?>"><?= h($k['code'] . ', ' . $k['libelle']) ?></option><?php endforeach; ?></select></div>
        <div><label for="q-deb">Du</label><input id="q-deb" type="date" name="debut" value="<?= $aujourdhui ?>" min="<?= $aujourdhui ?>"></div>
        <div><label for="q-fin">Au</label><input id="q-fin" type="date" name="fin" value="<?= $demain ?>" min="<?= $aujourdhui ?>"></div>
        <button class="btn" type="submit">Rechercher</button>
      </form>
    </div>
    <div class="hero__photo"><?php $vedette = null; foreach ($parc as $x) { if ($x['immatriculation'] === 'EF-456-GH') { $vedette = $x; } } echo voiture_3d($vedette ?? $parc[0], 'vue-3d vue-3d--hero', true); ?></div>
  </div>
</section>
<?php $hero = (string) ob_get_clean();
entete('Accueil', null, 0, [], ['hero' => $hero, 'sansTitre' => true]);

// Cas d'utilisation par acteur, tels que dans le diagramme des cas d'utilisation
$parActeur = [
    'Visiteur' => ['ico' => 'V', 'uc' => ['UC-01', 'UC-02', 'UC-03'], 'texte' => "Toute personne qui consulte le site sans être connectée.", 'lien' => ['recherche.php', 'Rechercher un véhicule']],
    'Client' => ['ico' => 'C', 'uc' => ['UC-04', 'UC-05'], 'texte' => "Personne inscrite ; hérite du Visiteur. Réserve en ligne et suit ses locations.", 'lien' => ['connexion.php', 'Espace client'], 'demo' => 'Compte de démonstration : lmartin / motdepasse'],
    'Employé' => ['ico' => 'E', 'uc' => ['UC-06', 'UC-07', 'UC-08', 'UC-05', 'UC-09', 'UC-10'], 'texte' => "Personnel de l'agence ; hérite du Visiteur. Gère le parc, les locations et les retours.", 'lien' => ['connexion.php', 'Espace employé'], 'demo' => 'Compte de démonstration : spetit / motdepasse'],
];
?>
<section class="section">
  <h2>Trois acteurs, chacun ses fonctionnalités</h2>
  <p class="gris">Chaque fonctionnalité réalise un cas d'utilisation du modèle UML. Sur sa page, la procédure s'affiche étape par étape, et l'étape en cours est signalée.</p>
  <div class="acteurs-grille">
    <?php foreach ($parActeur as $nom => $a): ?>
      <article class="acteur-carte">
        <h3><span class="ico" aria-hidden="true"><?= $a['ico'] ?></span><?= h($nom) ?></h3>
        <p class="gris"><?= h($a['texte']) ?></p>
        <ul>
          <?php foreach ($a['uc'] as $id): ?>
            <li><code><?= $id ?></code><a href="<?= h($c[$id]['page']) ?>"><?= h($c[$id]['titre']) ?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!empty($a['demo'])): ?><p class="compte-demo"><?= h($a['demo']) ?></p><?php endif; ?>
        <a class="btn btn--secondaire" href="<?= h($a['lien'][0]) ?>"><?= h($a['lien'][1]) ?></a>
      </article>
    <?php endforeach; ?>
  </div>
  <p class="gris" style="margin-top:14px">Deux acteurs secondaires complètent le modèle : le <b>système bancaire</b>, qui autorise les paiements lors d'une location, et l'<b>horloge</b>, qui déclenche chaque soir à 22h00 le rapport des retards.</p>
</section>

<section class="section">
  <h2>Le parc de l'agence</h2>
  <div class="parc">
    <?php foreach ($parc as $v):
        $action = '<div class="vehicule__prix"><div><small>à partir de</small><b>' . euros((float) ($prixMin[$v['code_categorie']] ?? 0)) . '</b><small>par jour</small></div>'
            . ($v['etat'] === 'EN_MAINTENANCE' ? '' : '<a class="btn btn--petit" href="reserver.php?immat=' . rawurlencode($v['immatriculation']) . '">Réserver</a>') . '</div>';
        echo carte_vehicule($v, $action);
    endforeach; ?>
  </div>
</section>
<?php pied();
