<?php
declare(strict_types=1);
// Fonctions ajoutées après le benchmark (novembre 2026) : transparence du prix, notifications, état des lieux.

// ---------- Transparence (benchmark : frais cachés, caution découverte au paiement) ----------
const DELAI_ANNULATION_GRATUITE_H = 48;

function annulation_gratuite(string $dateDebut): bool
{
    return (strtotime($dateDebut . ' 00:00:00') - time()) >= DELAI_ANNULATION_GRATUITE_H * 3600;
}
function libelle_energie(string $e): string
{
    return ['ESSENCE' => 'Essence', 'DIESEL' => 'Diesel', 'HYBRIDE' => 'Hybride', 'ELECTRIQUE' => 'Électrique'][$e] ?? $e;
}
function libelle_boite(string $b): string
{
    return $b === 'AUTOMATIQUE' ? 'Automatique' : 'Manuelle';
}

/** Message de refus de paiement qui dit au client quoi faire (benchmark : erreurs de paiement sans explication). */
function explication_paiement(string $numero, string $mois, string $jour, string $reponse): string
{
    if (fin_validite($mois) === null || fin_validite($mois) < $jour) {
        return "Carte expirée : vérifiez la date d'expiration ou utilisez une autre carte.";
    }
    if (!luhn_valide($numero)) {
        return 'Numéro de carte invalide : vérifiez les 16 chiffres saisis.';
    }
    if ($reponse === 'INDISPONIBLE') {
        return "Le système bancaire ne répond pas : rien n'a été débité, réessayez dans quelques minutes.";
    }
    return "Paiement refusé par la banque (plafond atteint ou opposition) : rien n'a été débité, essayez une autre carte.";
}

// ---------- Notifications (e-mails simulés) ----------
function notifier(PDO $pdo, int $numClient, string $sujet, string $message): void
{
    $pdo->prepare('INSERT INTO notification (num_client, date_envoi, sujet, message) VALUES (?, NOW(), ?, ?)')
        ->execute([$numClient, $sujet, $message]);
}

// ---------- État des lieux (benchmark : dommages contestés, état des lieux photo de Sixt) ----------
const ZONES_VEHICULE = [
    'avant' => 'Avant (pare-chocs, capot, phares)',
    'arriere' => 'Arrière (pare-chocs, hayon, feux)',
    'gauche' => 'Côté gauche (portes, ailes, rétroviseur)',
    'droit' => 'Côté droit (portes, ailes, rétroviseur)',
    'jantes' => 'Jantes et pneus',
    'vitrage' => 'Pare-brise et vitres',
    'interieur' => 'Intérieur (sièges, tableau de bord)',
];
const ETATS_ZONE = ['OK' => 'Bon état', 'RAYURE' => 'Rayure', 'CHOC' => 'Choc ou bosse', 'CASSE' => 'Cassé'];
const PROPRETES = ['PROPRE' => 'Propre', 'A_NETTOYER' => 'À nettoyer', 'SALE' => 'Sale'];
const DOSSIER_PHOTOS = __DIR__ . '/../uploads';
const PHOTOS_MAX = 8;

function charger_etat(PDO $pdo, int $numLoc, string $moment): ?array
{
    $st = $pdo->prepare('SELECT e.*, p.prenom AS emp_prenom, p.nom AS emp_nom FROM etat_des_lieux e
                         JOIN employe em ON em.matricule = e.matricule JOIN personne p ON p.id_personne = em.id_personne
                         WHERE e.num_location = ? AND e.moment = ?');
    $st->execute([$numLoc, $moment]);
    $e = $st->fetch();
    if (!$e) {
        return null;
    }
    $z = $pdo->prepare('SELECT zone, etat, note FROM constat_zone WHERE id_etat = ?');
    $z->execute([$e['id_etat']]);
    $e['zones'] = [];
    foreach ($z->fetchAll() as $row) { $e['zones'][$row['zone']] = $row; }
    $p = $pdo->prepare('SELECT id_photo FROM photo_etat WHERE id_etat = ? ORDER BY id_photo');
    $p->execute([$e['id_etat']]);
    $e['photos'] = array_column($p->fetchAll(), 'id_photo');
    return $e;
}

function photos_envoyees(array $fichiers): array
{
    $liste = [];
    if (empty($fichiers['photos']['name']) || !is_array($fichiers['photos']['name'])) {
        return $liste;
    }
    foreach ($fichiers['photos']['name'] as $i => $nom) {
        if ($fichiers['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
        $liste[] = ['tmp_name' => $fichiers['photos']['tmp_name'][$i], 'error' => $fichiers['photos']['error'][$i], 'size' => $fichiers['photos']['size'][$i]];
    }
    return $liste;
}
function extension_photo(string $chemin): ?string
{
    $info = @getimagesize($chemin);
    return $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null) : null;
}

/** Contrôle le formulaire d'état des lieux ; renvoie la liste des erreurs (vide si tout va bien). */
function verifier_etat(array $post, array $fichiers, int $kmMin): array
{
    $err = [];
    if (!ctype_digit((string) ($post['km'] ?? '')) || (int) $post['km'] < $kmMin) {
        $err[] = 'Le kilométrage doit être un nombre au moins égal à ' . number_format($kmMin, 0, ',', ' ') . ' km.';
    }
    $c = str_replace(',', '.', (string) ($post['carburant'] ?? ''));
    if (!is_numeric($c) || (float) $c < 0 || (float) $c > 150) {
        $err[] = 'Indiquez le carburant en litres (entre 0 et 150).';
    }
    if (!isset(PROPRETES[$post['proprete'] ?? ''])) {
        $err[] = 'Indiquez la propreté du véhicule.';
    }
    foreach (ZONES_VEHICULE as $cle => $lib) {
        if (!isset(ETATS_ZONE[$post['zone'][$cle] ?? ''])) {
            $err[] = "Indiquez l'état de la zone « $lib ».";
        }
    }
    $photos = photos_envoyees($fichiers);
    if (count($photos) > PHOTOS_MAX) {
        $err[] = 'Huit photos au plus par état des lieux.';
    }
    foreach ($photos as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024 || !extension_photo($f['tmp_name'])) {
            $err[] = 'Chaque photo doit être une image JPEG, PNG ou WebP de 5 Mo au plus.';
            break;
        }
    }
    return $err;
}

/** Enregistre un état des lieux (dans la transaction de l'appelant) et ses photos. */
function enregistrer_etat(PDO $pdo, int $numLoc, string $moment, array $post, array $fichiers, string $matricule): int
{
    $pdo->prepare('INSERT INTO etat_des_lieux (num_location, moment, date_etat, kilometrage, carburant, proprete, remarques, matricule) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)')
        ->execute([$numLoc, $moment, (int) $post['km'], (float) str_replace(',', '.', $post['carburant']), $post['proprete'],
                   trim((string) ($post['remarques'] ?? '')) !== '' ? mb_substr(trim($post['remarques']), 0, 500) : null, $matricule]);
    $id = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO constat_zone (id_etat, zone, etat, note) VALUES (?, ?, ?, ?)');
    foreach (ZONES_VEHICULE as $cle => $_) {
        $note = trim((string) ($post['note'][$cle] ?? ''));
        $ins->execute([$id, $cle, $post['zone'][$cle], $note !== '' ? mb_substr($note, 0, 200) : null]);
    }
    if (!is_dir(DOSSIER_PHOTOS)) { mkdir(DOSSIER_PHOTOS, 0775, true); }
    $insP = $pdo->prepare('INSERT INTO photo_etat (id_etat, fichier) VALUES (?, ?)');
    foreach (photos_envoyees($fichiers) as $i => $f) {
        $nom = sprintf('loc%d-%s-%d-%s.%s', $numLoc, strtolower($moment), $i + 1, bin2hex(random_bytes(4)), extension_photo($f['tmp_name']));
        if (move_uploaded_file($f['tmp_name'], DOSSIER_PHOTOS . '/' . $nom)) {
            $insP->execute([$id, $nom]);
        }
    }
    return $id;
}

/** Zones dont l'état s'est dégradé entre le départ et le retour : [zone => [avant, après]]. */
function nouveaux_dommages(?array $depart, array $zonesRetour): array
{
    $rang = ['OK' => 0, 'RAYURE' => 1, 'CHOC' => 2, 'CASSE' => 3];
    $liste = [];
    foreach ($zonesRetour as $cle => $etat) {
        $avant = $depart['zones'][$cle]['etat'] ?? 'OK';
        if (($rang[$etat] ?? 0) > ($rang[$avant] ?? 0)) { $liste[$cle] = [$avant, $etat]; }
    }
    return $liste;
}

/** Droit de consulter une location (contrat, facture, état des lieux) : l'employé, ou le client concerné. */
function peut_voir_location(PDO $pdo, int $numLoc): bool
{
    $u = utilisateur();
    if (!$u) { return false; }
    if ($u['role'] === 'employe') { return true; }
    $st = $pdo->prepare('SELECT r.num_client FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation WHERE l.num_location = ?');
    $st->execute([$numLoc]);
    return (int) $st->fetchColumn() === (int) ($u['num_client'] ?? -1);
}

/**
 * Détail du prix tout compris (benchmark : « le prix affiché n'est pas le prix payé »).
 * $m = résultat de montant_reservation().
 */
function bloc_prix(array $m, float $caution, float $franchise, ?string $dateDebut = null): string
{
    $gratuite = $dateDebut ? annulation_gratuite($dateDebut) : true;
    ob_start(); ?>
    <div class="prix-detail" aria-label="Détail du prix">
      <table>
        <tr><td>Location : <?= $m['nb'] ?> × formule <?= h($m['formule']) ?> (<?= euros($m['prix']) ?>)</td><td class="num"><?= euros($m['montant']) ?></td></tr>
        <tr class="total"><td>Total à payer au retrait</td><td class="num"><?= euros($m['montant']) ?></td></tr>
      </table>
      <p class="prix-detail__sans">Aucun frais de dossier ni assurance ajoutés au comptoir.</p>
      <ul class="prix-detail__regles">
        <li><b>Caution : <?= euros($caution) ?></b>, empreinte bancaire bloquée au départ et libérée au retour ; elle n'est pas débitée.</li>
        <li><b>Franchise : <?= euros($franchise) ?></b> au plus, en cas de dommage constaté entre l'état des lieux de départ et celui du retour.</li>
        <li><b>Carburant</b> : rendez le véhicule avec le même niveau qu'au départ, sinon <?= euros(PRIX_LITRE_CARBURANT) ?> par litre manquant.</li>
        <li><b>Retard</b> : chaque jour de retard est facturé au tarif journée de la catégorie.</li>
        <li><b>Annulation</b> : gratuite jusqu'à <?= DELAI_ANNULATION_GRATUITE_H ?> h avant le départ<?= $gratuite ? '' : ' (délai dépassé pour cette date : annulation auprès de l\'agence)' ?>.</li>
      </ul>
    </div>
    <?php return (string) ob_get_clean();
}
