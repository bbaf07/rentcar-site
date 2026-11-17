<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
exiger('employe');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_jeton();
    $immat = post('immat');
    if (post('action') === 'maintenance') {
        $st = $pdo->prepare("UPDATE vehicule SET etat = 'EN_MAINTENANCE' WHERE immatriculation = ? AND etat IN ('DISPONIBLE','RESERVE')");
        $st->execute([$immat]);
        flash($st->rowCount() ? 'ok' : 'erreur', $st->rowCount() ? "$immat est en maintenance." : 'Un véhicule loué ne peut pas passer en maintenance.');
    } elseif (post('action') === 'remettre') {
        $pdo->prepare('UPDATE vehicule SET etat = ? WHERE immatriculation = ? AND etat = \'EN_MAINTENANCE\'')->execute([etat_apres_liberation($pdo, $immat), $immat]);
        flash('ok', "$immat est remis en service.");
    }
    rediriger('vehicules.php');
}
$places = $pdo->query("SELECT e.numero, e.zone, v.*, c.libelle AS categorie_libelle FROM emplacement e
                       LEFT JOIN vehicule v ON v.num_emplacement = e.numero LEFT JOIN categorie c ON c.code = v.code_categorie
                       ORDER BY e.zone DESC, e.numero")->fetchAll();
entete('Le parc et le parking', null);
?>
<div class="actions" style="margin:0 0 20px"><a class="btn" href="vehicule-nouveau.php">Enregistrer un nouveau véhicule (UC-08)</a></div>
<p class="gris">Chaque véhicule occupe un emplacement, et un emplacement accueille au plus un véhicule (association Vehicule 0..1 — 1 Emplacement).</p>
<?php $zone = null; foreach ($places as $p):
    if ($p['zone'] !== $zone): if ($zone !== null) echo '</div>'; $zone = $p['zone']; ?>
      <h2 style="margin-top:24px"><?= h($zone ?? 'Sans zone') ?></h2><div class="parc">
    <?php endif;
    if (!$p['immatriculation']): ?>
      <article class="vehicule" style="border-style:dashed"><div class="vehicule__corps"><h3><?= h($p['numero']) ?></h3><p class="gris">Emplacement libre</p></div></article>
    <?php else:
        $bouton = '<p class="vehicule__meta"><span>Place ' . h($p['numero']) . '</span><span>' . number_format((int) $p['nb_kms'], 0, ',', ' ') . ' km</span><span>' . number_format((float) $p['qte_carburant'], 0, ',', ' ') . ' L</span></p><div class="vehicule__prix"><a href="fiches.php?type=vehicule&id=' . rawurlencode($p['immatriculation']) . '">Fiche véhicule</a>';
        if (in_array($p['etat'], ['DISPONIBLE', 'RESERVE'], true)) {
            $bouton .= '<form method="post">' . champ_jeton() . '<input type="hidden" name="immat" value="' . h($p['immatriculation']) . '"><button class="btn btn--secondaire btn--petit" name="action" value="maintenance">Maintenance</button></form>';
        } elseif ($p['etat'] === 'EN_MAINTENANCE') {
            $bouton .= '<form method="post">' . champ_jeton() . '<input type="hidden" name="immat" value="' . h($p['immatriculation']) . '"><button class="btn btn--petit" name="action" value="remettre">Remettre en service</button></form>';
        }
        echo carte_vehicule($p, $bouton . '</div>');
    endif;
endforeach; if ($zone !== null) echo '</div>'; ?>
<?php pied();
