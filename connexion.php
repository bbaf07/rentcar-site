<?php
require __DIR__ . '/inc/app.php';
$pdo = bd();
$suite = (string) ($_GET['suite'] ?? $_POST['suite'] ?? '');
// N'accepte qu'un chemin local de l'application (pas de redirection vers un autre site)
if (!preg_match('#^/rentcar/[a-z0-9\-]+\.php(\?[^\s]*)?$#i', $suite)) { $suite = ''; }

$etape = 1; $ext = []; $erreur = null; $login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_jeton();
    $etape = 2;
    $login = post('login');
    $st = $pdo->prepare('SELECT p.*, c.num_client, e.matricule, e.fonction FROM personne p
                         LEFT JOIN client c ON c.id_personne = p.id_personne LEFT JOIN employe e ON e.id_personne = p.id_personne
                         WHERE p.login = ?');
    $st->execute([$login]);
    $p = $st->fetch();
    if (!$p || !mdp_verifier((string) ($_POST['mdp'] ?? ''), $p['mot_de_passe'])) {
        $erreur = 'Login ou mot de passe incorrect.';
        $ext[] = '2a'; $etape = 1;
    } else {
        session_regenerate_id(true);
        $role = $p['matricule'] ? 'employe' : 'client';
        $_SESSION['utilisateur'] = ['role' => $role, 'id_personne' => (int) $p['id_personne'], 'num_client' => $p['num_client'] ? (int) $p['num_client'] : null,
            'matricule' => $p['matricule'], 'nom' => $p['nom'], 'prenom' => $p['prenom'], 'login' => $p['login']];
        flash('ok', 'Bonjour ' . $p['prenom'] . ', vous êtes connecté en tant que ' . ($role === 'employe' ? 'employé' : 'client') . '.');
        rediriger($suite ?: ($role === 'employe' ? 'espace-employe.php' : 'espace-client.php'));
    }
}
entete("S'authentifier", 'UC-04', $etape, $ext);
?>
<div class="formulaire" style="max-width:560px">
  <?php if ($erreur): ?><div class="flash flash--erreur" role="alert"><?= h($erreur) ?></div><?php endif; ?>
  <form method="post">
    <?= champ_jeton() ?><input type="hidden" name="suite" value="<?= h($suite) ?>">
    <div class="champ"><label for="login">Login</label><input id="login" name="login" value="<?= h($login) ?>" autocomplete="username" required autofocus></div>
    <div class="champ" style="margin-top:14px"><label for="mdp">Mot de passe</label><input id="mdp" type="password" name="mdp" autocomplete="current-password" required></div>
    <div class="actions"><button class="btn" type="submit">Se connecter</button><a href="inscription.php">Pas encore client ? S'inscrire</a></div>
  </form>
</div>
<div class="formulaire" style="max-width:560px">
  <h2>Comptes de démonstration</h2>
  <p class="gris">Mot de passe commun : <code>motdepasse</code></p>
  <table class="table">
    <thead><tr><th>Login</th><th>Acteur</th><th>Personne</th></tr></thead>
    <tbody>
      <tr><td><code>lmartin</code></td><td>Client</td><td>Lucie Martin, réservation n° 1 confirmée</td></tr>
      <tr><td><code>kdurand</code></td><td>Client</td><td>Karim Durand, une location en retard</td></tr>
      <tr><td><code>spetit</code></td><td>Employé</td><td>Sophie Petit, agent de comptoir</td></tr>
    </tbody>
  </table>
</div>
<?php pied();
