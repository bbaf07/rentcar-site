<?php
require __DIR__ . '/inc/app.php';
// UC-08 Enregistrer un nouveau véhicule, qui inclut UC-13 Affecter un emplacement (RG3).
$pdo = bd();
exiger('employe');
$categories = $pdo->query('SELECT * FROM categorie ORDER BY code')->fetchAll();
$libres = emplacements_libres($pdo);
$etape = 1; $ext = []; $erreurs = [];
$v = ['immat' => '', 'type' => 'TOURISTIQUE', 'marque' => '', 'modele' => '', 'couleur' => '', 'cat' => 'A', 'km' => '0', 'carburant' => '40', 'place' => ''];

if (!$libres) { $ext[] = '3a'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_jeton();
    foreach ($v as $k => $_) { $v[$k] = post($k); }
    $v['immat'] = strtoupper(str_replace(' ', '-', $v['immat']));
    $etape = 2;
    if (!preg_match('/^[A-Z]{2}-\d{3}-[A-Z]{2}$/', $v['immat'])) { $erreurs[] = "L'immatriculation doit suivre le format AB-123-CD."; }
    else {
        $st = $pdo->prepare('SELECT 1 FROM vehicule WHERE immatriculation = ?');
        $st->execute([$v['immat']]);
        if ($st->fetch()) { $erreurs[] = "Le véhicule {$v['immat']} est déjà enregistré."; }
    }
    if ($v['marque'] === '' || $v['modele'] === '') { $erreurs[] = 'Indiquez la marque et le modèle.'; }
    if (!in_array($v['type'], ['TOURISTIQUE', 'UTILITAIRE'], true)) { $erreurs[] = 'Type de véhicule inconnu.'; }
    if (!in_array($v['cat'], array_column($categories, 'code'), true)) { $erreurs[] = 'Catégorie inconnue.'; }
    if (!ctype_digit($v['km']) || !is_numeric(str_replace(',', '.', $v['carburant']))) { $erreurs[] = 'Kilométrage et carburant doivent être des nombres.'; }
    // Photo facultative : JPEG, PNG ou WebP, 4 Mo au plus, vérifiée par son contenu
    $photo = $_FILES['photo'] ?? null;
    $extPhoto = null;
    if ($photo && $photo['error'] !== UPLOAD_ERR_NO_FILE) {
        $info = $photo['error'] === UPLOAD_ERR_OK && $photo['size'] <= 4 * 1024 * 1024 ? @getimagesize($photo['tmp_name']) : false;
        $extPhoto = $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null) : null;
        if (!$extPhoto) { $erreurs[] = 'La photo doit être une image JPEG, PNG ou WebP de 4 Mo au plus.'; }
    }
    if ($erreurs) {
        $ext[] = '2a'; $etape = 1;
    } else {
        // Étape 3 (UC-13) : emplacement choisi s'il est libre, sinon le premier libre
        $etape = 3;
        $numerosLibres = array_column($libres, 'numero');
        $place = in_array($v['place'], $numerosLibres, true) ? $v['place'] : ($numerosLibres[0] ?? null);
        if (!$place) {
            $ext[] = '3a';
            $erreurs[] = 'Aucun emplacement libre dans le parking : le véhicule ne peut pas être enregistré.';
        } else {
            $pdo->prepare("INSERT INTO vehicule (immatriculation, type, marque, modele, couleur, nb_kms, qte_carburant, etat, code_categorie, num_emplacement) VALUES (?, ?, ?, ?, ?, ?, ?, 'DISPONIBLE', ?, ?)")
                ->execute([$v['immat'], $v['type'], $v['marque'], $v['modele'], $v['couleur'] ?: null, (int) $v['km'], (float) str_replace(',', '.', $v['carburant']), $v['cat'], $place]);
            if ($extPhoto) {
                move_uploaded_file($photo['tmp_name'], __DIR__ . '/assets/voitures/' . strtolower($v['immat']) . '.' . $extPhoto);
            }
            flash('ok', "Véhicule {$v['immat']} enregistré, DISPONIBLE, à l'emplacement $place.");
            rediriger('vehicules.php');
        }
    }
}
entete('Enregistrer un nouveau véhicule', 'UC-08', $etape, $ext);
?>
<?php if ($erreurs): ?><div class="flash flash--erreur" role="alert"><?= implode('<br>', array_map('h', $erreurs)) ?></div><?php endif; ?>
<form class="formulaire" method="post" enctype="multipart/form-data">
  <?= champ_jeton() ?>
  <h2>1. Caractéristiques</h2>
  <div class="grille-champs">
    <div class="champ"><label for="immat">Immatriculation</label><input id="immat" name="immat" value="<?= h($v['immat']) ?>" placeholder="AB-123-CD" required pattern="[A-Za-z]{2}[- ]?\d{3}[- ]?[A-Za-z]{2}"></div>
    <div class="champ"><label for="type">Type</label><select id="type" name="type"><option value="TOURISTIQUE"<?= $v['type'] === 'TOURISTIQUE' ? ' selected' : '' ?>>Touristique</option><option value="UTILITAIRE"<?= $v['type'] === 'UTILITAIRE' ? ' selected' : '' ?>>Utilitaire</option></select></div>
    <div class="champ"><label for="cat">Catégorie</label><select id="cat" name="cat"><?php foreach ($categories as $k): ?><option value="<?= h($k['code']) ?>"<?= $v['cat'] === $k['code'] ? ' selected' : '' ?>><?= h($k['code'] . ', ' . $k['libelle']) ?></option><?php endforeach; ?></select></div>
    <div class="champ"><label for="marque">Marque</label><input id="marque" name="marque" value="<?= h($v['marque']) ?>" required></div>
    <div class="champ"><label for="modele">Modèle</label><input id="modele" name="modele" value="<?= h($v['modele']) ?>" required></div>
    <div class="champ"><label for="couleur">Couleur</label><input id="couleur" name="couleur" value="<?= h($v['couleur']) ?>"></div>
    <div class="champ"><label for="km">Kilométrage</label><input id="km" name="km" inputmode="numeric" value="<?= h($v['km']) ?>"></div>
    <div class="champ"><label for="carburant">Carburant (L)</label><input id="carburant" name="carburant" inputmode="decimal" value="<?= h($v['carburant']) ?>"></div>
    <div class="champ"><label for="photo">Photo (facultatif)</label><input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp"><small>Sinon, la photo du modèle est utilisée si elle existe.</small></div>
  </div>
  <h2 style="margin-top:24px">3. Emplacement <span class="gris" style="font-size:1rem;font-weight:500">(cas inclus UC-13)</span></h2>
  <?php if (!$libres): ?>
    <div class="vide">Le parking est complet : libérez un emplacement avant d'enregistrer un véhicule.</div>
  <?php else: ?>
    <div class="grille-champs"><div class="champ"><label for="place">Emplacement</label><select id="place" name="place"><option value="">Premier libre (<?= h($libres[0]['numero']) ?>)</option><?php foreach ($libres as $e): ?><option value="<?= h($e['numero']) ?>"<?= $v['place'] === $e['numero'] ? ' selected' : '' ?>><?= h($e['numero'] . ', ' . $e['zone']) ?></option><?php endforeach; ?></select><small><?= count($libres) ?> emplacement<?= count($libres) > 1 ? 's' : '' ?> libre<?= count($libres) > 1 ? 's' : '' ?>.</small></div></div>
  <?php endif; ?>
  <div class="actions"><button class="btn" type="submit"<?= $libres ? '' : ' disabled' ?>>Enregistrer le véhicule</button><a href="vehicules.php">Retour au parc</a></div>
</form>
<?php pied();
