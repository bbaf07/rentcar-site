<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
if (role() !== 'visiteur') {
    rediriger(role() === 'employe' ? 'espace-employe.php' : 'espace-client.php');
}
$etape = 1; $ext = []; $erreurs = [];
$v = ['nom' => '', 'prenom' => '', 'adresse' => '', 'telephone' => '', 'email' => '', 'permis' => '', 'login' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_jeton();
    $etape = 2;
    foreach ($v as $k => $_) { $v[$k] = post($k); }
    $mdp = (string) ($_POST['mdp'] ?? '');
    foreach (['nom' => 'le nom', 'prenom' => 'le prénom', 'permis' => 'le numéro de permis', 'login' => 'le login'] as $k => $lib) {
        if ($v[$k] === '') { $erreurs[$k] = "Indiquez $lib."; }
    }
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) { $erreurs['email'] = "L'adresse e-mail n'est pas valide."; }
    if ($v['login'] !== '' && !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $v['login'])) { $erreurs['login'] = 'Le login fait 3 à 50 caractères : lettres, chiffres, point, tiret.'; }
    if (strlen($mdp) < 8) { $erreurs['mdp'] = 'Le mot de passe fait au moins 8 caractères.'; }
    if (!isset($erreurs['login'])) {
        $st = $pdo->prepare('SELECT 1 FROM personne WHERE login = ?');
        $st->execute([$v['login']]);
        if ($st->fetch()) { $erreurs['login'] = 'Ce login est déjà utilisé : choisissez-en un autre.'; }
    }
    if ($erreurs) {
        $ext[] = '2a'; $etape = 1;
    } else {
        // Étape 3 : Personne + Compte (une seule table) puis Client, dans une transaction
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO personne (nom, prenom, adresse, telephone, email, login, mot_de_passe) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$v['nom'], $v['prenom'], $v['adresse'] ?: null, $v['telephone'] ?: null, $v['email'] ?: null, $v['login'], mdp_hacher($mdp)]);
            $idp = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO client (id_personne, num_permis, date_inscription) VALUES (?, ?, CURDATE())')->execute([$idp, $v['permis']]);
            $num = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }
        session_regenerate_id(true);
        $_SESSION['utilisateur'] = ['role' => 'client', 'id_personne' => $idp, 'num_client' => $num, 'nom' => $v['nom'], 'prenom' => $v['prenom'], 'login' => $v['login']];
        flash('ok', "Bienvenue {$v['prenom']} : votre compte client n° $num est créé, vous êtes connecté.");
        rediriger('espace-client.php');
    }
}
entete("S'inscrire", 'UC-03', $etape, $ext);
$err = fn($k) => isset($erreurs[$k]) ? '<small style="color:var(--alerte)">' . h($erreurs[$k]) . '</small>' : '';
?>
<p class="gris">Devenez client pour réserver en ligne. Déjà inscrit ? <a href="connexion.php">Connectez-vous</a>.</p>
<?php if ($erreurs): ?><div class="flash flash--erreur" role="alert">Le formulaire contient <?= count($erreurs) ?> erreur<?= count($erreurs) > 1 ? 's' : '' ?> : corrigez les champs signalés.</div><?php endif; ?>
<form class="formulaire" method="post" novalidate>
  <?= champ_jeton() ?>
  <h2>Identité</h2>
  <div class="grille-champs">
    <div class="champ"><label for="prenom">Prénom</label><input id="prenom" name="prenom" value="<?= h($v['prenom']) ?>" autocomplete="given-name" required><?= $err('prenom') ?></div>
    <div class="champ"><label for="nom">Nom</label><input id="nom" name="nom" value="<?= h($v['nom']) ?>" autocomplete="family-name" required><?= $err('nom') ?></div>
    <div class="champ"><label for="permis">Numéro de permis</label><input id="permis" name="permis" value="<?= h($v['permis']) ?>" required><?= $err('permis') ?></div>
  </div>
  <div class="grille-champs" style="margin-top:16px">
    <div class="champ"><label for="adresse">Adresse postale</label><input id="adresse" name="adresse" value="<?= h($v['adresse']) ?>" autocomplete="street-address"><small>Utilisée pour les relances par courrier.</small></div>
    <div class="champ"><label for="telephone">Téléphone</label><input id="telephone" name="telephone" value="<?= h($v['telephone']) ?>" autocomplete="tel"></div>
    <div class="champ"><label for="email">E-mail</label><input id="email" type="email" name="email" value="<?= h($v['email']) ?>" autocomplete="email"><?= $err('email') ?></div>
  </div>
  <h2 style="margin-top:24px">Compte</h2>
  <div class="grille-champs">
    <div class="champ"><label for="login">Login</label><input id="login" name="login" value="<?= h($v['login']) ?>" autocomplete="username" required><?= $err('login') ?></div>
    <div class="champ"><label for="mdp">Mot de passe</label><input id="mdp" type="password" name="mdp" autocomplete="new-password" required><small>8 caractères au moins. Il est stocké sous forme d'empreinte, jamais en clair.</small><?= $err('mdp') ?></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Créer mon compte client</button></div>
</form>
<?php pied();
