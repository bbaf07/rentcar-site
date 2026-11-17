<?php
declare(strict_types=1);
// Socle de l'application RentCar : session, base, acteur connecté, jeton anti-CSRF, mise en page.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/metier.php';
require_once __DIR__ . '/amelioration.php';

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);
date_default_timezone_set('Europe/Paris');

const UC_CATALOGUE = __DIR__ . '/uc.php';

function cas(): array
{
    static $c = null;
    return $c ??= require UC_CATALOGUE;
}

function bd(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $err = null;
        $pdo = connexion_rentcar($err);
        if (!$pdo) {
            http_response_code(503);
            entete('Base indisponible', null);
            echo '<div class="alerte"><h2>La base rentcar ne répond pas</h2><p>Démarrez WampServer et attendez que son icône passe au vert, puis rechargez la page.</p><p class="gris">Détail : ' . h($err) . '</p></div>';
            pied();
            exit;
        }
    }
    return $pdo;
}

// ---------- Acteur connecté ----------
function utilisateur(): ?array
{
    return $_SESSION['utilisateur'] ?? null;
}
function role(): string
{
    return utilisateur()['role'] ?? 'visiteur';
}
function exiger(string $role): array
{
    $u = utilisateur();
    if (!$u || $u['role'] !== $role) {
        flash('info', $role === 'employe' ? 'Cette fonctionnalité est réservée aux employés : connectez-vous.' : 'Connectez-vous avec votre compte client pour continuer.');
        header('Location: connexion.php?suite=' . rawurlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    return $u;
}

// ---------- Messages et sécurité des formulaires ----------
function flash(string $type, string $texte): void
{
    $_SESSION['flash'][] = [$type, $texte];
}
function jeton(): string
{
    return $_SESSION['jeton'] ??= bin2hex(random_bytes(16));
}
function champ_jeton(): string
{
    return '<input type="hidden" name="jeton" value="' . h(jeton()) . '">';
}
function verifier_jeton(): void
{
    if (!hash_equals(jeton(), (string) ($_POST['jeton'] ?? ''))) {
        http_response_code(400);
        exit('Formulaire expiré : rechargez la page et recommencez.');
    }
}
function post(string $cle, string $defaut = ''): string
{
    return trim((string) ($_POST[$cle] ?? $defaut));
}
function rediriger(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// ---------- Mise en page ----------
function entete(string $titre, ?string $uc, int $etape = 0, array $ext = [], array $opts = []): void
{
    $u = utilisateur();
    $r = role();
    $menus = [
        'visiteur' => [['index.php', 'Accueil'], ['recherche.php', 'Rechercher'], ['tarifs.php', 'Tarifs'], ['inscription.php', "S'inscrire"]],
        'client' => [['index.php', 'Accueil'], ['recherche.php', 'Rechercher'], ['reserver.php', 'Réserver'], ['tarifs.php', 'Tarifs'], ['espace-client.php', 'Mon espace']],
        'employe' => [['espace-employe.php', 'Tableau de bord'], ['louer.php', 'Louer'], ['rendre.php', 'Retours'], ['reservations.php', 'Réservations'], ['vehicules.php', 'Parc'], ['retards.php', 'Retards'], ['fiches.php', 'Fiches']],
    ][$r];
    $courant = basename($_SERVER['SCRIPT_NAME']);
    $libelleActeur = ['visiteur' => 'Visiteur', 'client' => 'Client', 'employe' => 'Employé'][$r];
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titre) ?> | RentCar</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='5' fill='%23F2C230'/><text x='16' y='23' font-family='Arial' font-weight='700' font-size='17' text-anchor='middle' fill='%23262A31'>RC</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:ital,wght@0,400;0,600;1,400&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
<script>document.documentElement.classList.add('js');try{const t=localStorage.getItem('rentcar-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
</head>
<body class="<?= h($opts['classe'] ?? '') ?>">
<a class="evitement" href="#contenu">Aller au contenu</a>
<header class="barre">
  <div class="wrap barre__in">
    <a class="marque" href="<?= $r === 'employe' ? 'espace-employe.php' : 'index.php' ?>"><b>RC</b> RentCar</a>
    <button class="burger" type="button" aria-expanded="false" aria-controls="menu">Menu</button>
    <nav id="menu" aria-label="Navigation principale"><ul>
      <?php foreach ($menus as [$lien, $lib]): ?>
        <li><a href="<?= $lien ?>"<?= $lien === $courant ? ' aria-current="page"' : '' ?>><?= h($lib) ?></a></li>
      <?php endforeach; ?>
    </ul></nav>
    <div class="acteur">
      <span class="acteur__role acteur__role--<?= $r ?>"><?= $libelleActeur ?></span>
      <?php if ($u): ?>
        <span class="acteur__nom"><?= h($u['prenom'] . ' ' . $u['nom']) ?></span>
        <form method="post" action="deconnexion.php"><?= champ_jeton() ?><button class="lien" type="submit">Se déconnecter</button></form>
      <?php else: ?>
        <a class="lien" href="connexion.php">Se connecter</a>
      <?php endif; ?>
      <button class="theme" type="button" id="theme" aria-label="Changer de thème">◐</button>
    </div>
  </div>
</header>
<?php if (!empty($opts['hero'])) { echo $opts['hero']; } ?>
<div class="wrap page<?= $uc ? ' page--uc' : '' ?>">
  <main id="contenu">
    <?php foreach ($_SESSION['flash'] ?? [] as [$type, $texte]): ?>
      <div class="flash flash--<?= h($type) ?>" role="status"><?= h($texte) ?></div>
    <?php endforeach; unset($_SESSION['flash']); ?>
    <?php if (empty($opts['sansTitre'])): ?>
      <div class="titre-page">
        <?php if ($uc): ?><span class="baie"><?= h($uc) ?></span><?php endif; ?>
        <h1><?= h($titre) ?></h1>
        <?php if ($uc && isset(cas()[$uc]) && $etape > 0): $nb = count(cas()[$uc]['etapes']); ?><a class="repere-etape" href="#procedure"><?= $etape > $nb ? 'Procédure terminée' : 'Étape ' . $etape . ' sur ' . $nb ?></a><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php
    $GLOBALS['__uc'] = [$uc, $etape, $ext];
}

function pied(): void
{
    [$uc, $etape, $ext] = $GLOBALS['__uc'] ?? [null, 0, []];
    echo '</main>';
    if ($uc) {
        panneau_uc($uc, $etape, $ext);
    }
    echo '</div>';
    ?>
<footer class="pied">
  <div class="wrap pied__in">
    <span>RentCar, agence de location de véhicules</span>
    <span class="pied__liens"><a href="credits.php">Crédits photos</a></span>
  </div>
</footer>
<script src="assets/js/app.js"></script>
</body>
</html>
    <?php
}

/** Panneau « Procédure » : le cas d'utilisation, ses acteurs, ses relations et ses étapes. */
function panneau_uc(string $id, int $etape, array $ext): void
{
    $c = cas()[$id] ?? null;
    if (!$c) {
        return;
    }
    $n = count($c['etapes']);
    ?>
  <aside class="procedure" id="procedure" aria-label="Procédure du cas d'utilisation">
    <div class="procedure__tete">
      <span class="procedure__id"><?= h($id) ?></span>
      <h2><?= h($c['titre']) ?></h2>
      <p class="procedure__acteurs"><?php foreach ($c['acteurs'] as $a): ?><span class="puce-acteur"><?= h($a) ?></span><?php endforeach; ?></p>
    </div>
    <?php if (!empty($c['include']) || !empty($c['extend']) || !empty($c['specialisations']) || !empty($c['regles'])): ?>
      <ul class="procedure__liens">
        <?php foreach ($c['include'] ?? [] as $i): ?><li><span class="stereo">«include»</span> <?= h(cas()[$i]['titre']) ?></li><?php endforeach; ?>
        <?php foreach ($c['extend'] ?? [] as $i): ?><li><span class="stereo">«extend»</span> <?= h(cas()[$i]['titre']) ?></li><?php endforeach; ?>
        <?php if (!empty($c['specialisations'])): ?><li><span class="stereo">spécialisé en</span> <?= h(implode(', ', $c['specialisations'])) ?></li><?php endif; ?>
        <?php if (!empty($c['regles'])): ?><li><span class="stereo">règles</span> <?= h(implode(', ', $c['regles'])) ?></li><?php endif; ?>
      </ul>
    <?php endif; ?>
    <ol class="etapes-uc">
      <?php foreach ($c['etapes'] as $i => $texte):
          $num = $i + 1;
          $etat = $etape > $n ? 'fait' : ($num < $etape ? 'fait' : ($num === $etape ? 'actif' : 'avenir')); ?>
        <li class="etape-uc etape-uc--<?= $etat ?>"<?= $etat === 'actif' ? ' aria-current="step"' : '' ?>><b><?= $num ?></b><span><?= h($texte) ?></span></li>
      <?php endforeach; ?>
    </ol>
    <?php if ($c['extensions']): ?>
      <details class="procedure__ext"<?= $ext ? ' open' : '' ?>>
        <summary>Extensions (<?= count($c['extensions']) ?>)</summary>
        <ul>
          <?php foreach ($c['extensions'] as $code => $texte): ?>
            <li class="<?= in_array($code, $ext, true) ? 'declenchee' : '' ?>"><b><?= h($code) ?></b><span><?= h($texte) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
  </aside>
    <?php
}

// ---------- Affichage commun ----------
function photo_vehicule(array $v): array
{
    static $credits = null;
    $credits ??= require dirname(__DIR__) . '/assets/voitures/credits.php';
    foreach (['jpg', 'png', 'webp'] as $x) {
        $fichier = 'assets/voitures/' . strtolower($v['immatriculation']) . '.' . $x;
        if (is_file(dirname(__DIR__) . '/' . $fichier)) {
            return [$fichier, null];
        }
    }
    $cle = mb_strtolower($v['marque'] . ' ' . $v['modele']);
    if (isset($credits[$cle])) {
        return ['assets/voitures/' . $credits[$cle][0], $credits[$cle]];
    }
    return ['assets/voitures/silhouette.svg', null];
}
function plaque(string $immat): string
{
    return '<span class="mini-plaque"><span>' . h($immat) . '</span></span>';
}
function badge(string $etat): string
{
    return '<span class="badge badge--' . h($etat) . '">' . h(libelle_etat($etat)) . '</span>';
}
function libelle_etat(string $e): string
{
    return ['DISPONIBLE' => 'Disponible', 'RESERVE' => 'Réservé', 'LOUE' => 'Loué', 'EN_MAINTENANCE' => 'En maintenance',
        'CONFIRMEE' => 'Confirmée', 'EN_ATTENTE' => 'En attente', 'HONOREE' => 'Honorée', 'ANNULEE' => 'Annulée', 'EXPIREE' => 'Expirée', 'BLOQUEE' => 'Caution bloquée', 'LIBEREE' => 'Caution libérée', 'RETENUE' => 'Caution retenue'][$e] ?? $e;
}
function date_fr(?string $d): string
{
    return $d ? (new DateTime($d))->format('d/m/Y') : '–';
}
function euros(float $m): string
{
    return number_format($m, 2, ',', ' ') . ' €';
}
function carte_vehicule(array $v, string $action = ''): string
{
    [$src, $cr] = photo_vehicule($v);
    ob_start(); ?>
    <article class="vehicule">
      <div class="vehicule__photo"><img src="<?= h($src) ?>" alt="<?= h($v['marque'] . ' ' . $v['modele']) ?>" loading="lazy"><?= plaque($v['immatriculation']) ?></div>
      <div class="vehicule__corps">
        <h3><?= h($v['marque'] . ' ' . $v['modele']) ?></h3>
        <p class="vehicule__meta"><span>Catégorie <?= h($v['code_categorie'] . (!empty($v['categorie_libelle']) ? ', ' . $v['categorie_libelle'] : '')) ?></span><span><?= $v['type'] === 'UTILITAIRE' ? 'Utilitaire' : 'Touristique' ?></span><span><?= h((string) $v['couleur']) ?></span></p>
        <?php if (isset($v['nb_places'])): ?>
          <ul class="atouts" aria-label="Caractéristiques">
            <li><?= (int) $v['nb_places'] ?> places</li><li><?= h(libelle_boite($v['boite'])) ?></li><li><?= h(libelle_energie($v['energie'])) ?></li>
          </ul>
        <?php endif; ?>
        <?php if (isset($v['caution'])): ?><p class="caution-ligne">Caution <?= euros((float) $v['caution']) ?>, bloquée sur la carte et non débitée</p><?php endif; ?>
        <?php if (isset($v['etat'])): ?><?= badge($v['etat']) ?><?php endif; ?>
        <?= $action ?>
      </div>
    </article>
    <?php return (string) ob_get_clean();
}
