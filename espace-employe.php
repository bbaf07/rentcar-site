<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
$u = exiger('employe');
$n = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
$stats = [
    'retraits' => $n("SELECT COUNT(*) FROM reservation WHERE statut = 'CONFIRMEE' AND CURDATE() BETWEEN date_debut AND date_fin"),
    'enCours' => $n('SELECT COUNT(*) FROM location WHERE date_retour_effective IS NULL'),
    'retards' => $n('SELECT COUNT(*) FROM location WHERE date_retour_effective IS NULL AND date_retour_prevue < CURDATE()'),
    'attente' => $n("SELECT COUNT(*) FROM reservation WHERE statut = 'EN_ATTENTE'"),
    'dispo' => $n("SELECT COUNT(*) FROM vehicule WHERE etat = 'DISPONIBLE'"),
];
$c = cas();
entete('Tableau de bord', null);
?>
<p class="gris">Bonjour <?= h($u['prenom']) ?>. Nous sommes le <?= date_fr(date('Y-m-d')) ?>.</p>
<div class="tuiles">
  <a class="tuile" href="louer.php"><b><?= $stats['retraits'] ?></b><span>retrait<?= $stats['retraits'] > 1 ? 's' : '' ?> prévu<?= $stats['retraits'] > 1 ? 's' : '' ?> aujourd'hui</span></a>
  <a class="tuile" href="rendre.php"><b><?= $stats['enCours'] ?></b><span>location<?= $stats['enCours'] > 1 ? 's' : '' ?> en cours</span></a>
  <a class="tuile<?= $stats['retards'] ? ' alerte-t' : '' ?>" href="retards.php"><b><?= $stats['retards'] ?></b><span>client<?= $stats['retards'] > 1 ? 's' : '' ?> en retard</span></a>
  <a class="tuile" href="reservations.php?statut=EN_ATTENTE"><b><?= $stats['attente'] ?></b><span>réservation<?= $stats['attente'] > 1 ? 's' : '' ?> à confirmer</span></a>
</div>
<h2>Mes fonctionnalités</h2>
<div class="raccourcis">
  <?php foreach (['UC-06' => 'Transformer une réservation en location, paiement par carte.', 'UC-07' => 'Enregistrer un retour, facturer un supplément si besoin.', 'UC-05' => 'Réserver pour un client, confirmer ou annuler.',
                  'UC-08' => "Ajouter un véhicule et lui affecter une place.", 'UC-09' => 'Rapport de 22h00 et lettres de relance.', 'UC-10' => 'Fiches client, véhicule et location.'] as $id => $texte):
      $page = $id === 'UC-05' ? 'reservations.php' : $c[$id]['page']; ?>
    <a class="raccourci" href="<?= h($page) ?>"><code><?= $id ?></code><b><?= h($c[$id]['titre']) ?></b><span><?= h($texte) ?></span></a>
  <?php endforeach; ?>
  <a class="raccourci" href="vehicules.php"><code>Parc</code><b>Le parc et le parking</b><span><?= $stats['dispo'] ?> véhicule<?= $stats['dispo'] > 1 ? 's' : '' ?> disponible<?= $stats['dispo'] > 1 ? 's' : '' ?>, état de chaque emplacement.</span></a>
  <a class="raccourci" href="recherche.php"><code>UC-01</code><b><?= h($c['UC-01']['titre']) ?></b><span>Hérité du Visiteur.</span></a>
  <a class="raccourci" href="tarifs.php"><code>UC-02</code><b><?= h($c['UC-02']['titre']) ?></b><span>Hérité du Visiteur.</span></a>
</div>
<?php pied();
