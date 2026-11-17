<?php
declare(strict_types=1);
// Règles métier, reprises des classes du modèle (mêmes calculs que le projet Java).

// ---------- Compte (RG2) : même format d'empreinte que fr.rentcar.securite.MotDePasse ----------
function mdp_hacher(string $mdp): string
{
    $sel = random_bytes(16);
    $empreinte = hash_pbkdf2('sha256', $mdp, $sel, 120000, 32, true);
    return 'pbkdf2_sha256$120000$' . base64_encode($sel) . '$' . base64_encode($empreinte);
}
function mdp_verifier(string $mdp, string $stocke): bool
{
    $p = explode('$', $stocke);
    if (count($p) !== 4 || $p[0] !== 'pbkdf2_sha256' || !ctype_digit($p[1])) {
        return false;
    }
    $sel = base64_decode($p[2], true);
    $attendue = base64_decode($p[3], true);
    if ($sel === false || $attendue === false) {
        return false;
    }
    return hash_equals($attendue, hash_pbkdf2('sha256', $mdp, $sel, (int) $p[1], strlen($attendue), true));
}

// ---------- Tarif.calculerPrix(duree) (RG7) ----------
function nb_formules(int $jours, int $dureeFormule): int
{
    $jours = max($jours, 1);
    return intdiv($jours + $dureeFormule - 1, $dureeFormule);
}
function jours_entre(string $debut, string $fin): int
{
    return (int) (new DateTime($debut))->diff(new DateTime($fin))->format('%r%a');
}
function tarif(PDO $pdo, string $cat, string $formule): ?array
{
    $st = $pdo->prepare('SELECT t.prix, f.libelle, f.duree_jours FROM tarif t JOIN formule f ON f.code = t.code_formule WHERE t.code_categorie = ? AND t.code_formule = ?');
    $st->execute([$cat, $formule]);
    return $st->fetch() ?: null;
}
function montant_reservation(PDO $pdo, string $cat, string $formule, string $debut, string $fin): ?array
{
    $t = tarif($pdo, $cat, $formule);
    if (!$t) {
        return null;
    }
    $jours = max(1, jours_entre($debut, $fin));
    $nb = nb_formules($jours, (int) $t['duree_jours']);
    return ['montant' => $nb * (float) $t['prix'], 'nb' => $nb, 'jours' => $jours, 'prix' => (float) $t['prix'], 'formule' => $t['libelle'], 'duree' => (int) $t['duree_jours']];
}

// ---------- Disponibilité (UC-01, UC-05) ----------
/**
 * Véhicules libres sur toute la période. $f (filtres de recherche, facultatifs) :
 * type, energie, boite, places (minimum), prixmax (tarif journée maximum).
 */
function vehicules_disponibles(PDO $pdo, ?string $cat, string $debut, string $fin, ?string $immat = null, array $f = []): array
{
    $sql = "SELECT v.*, c.libelle AS categorie_libelle, c.caution, c.franchise,
                   (SELECT prix FROM tarif t WHERE t.code_categorie = v.code_categorie AND t.code_formule = 'JOUR') AS prix_jour
            FROM vehicule v JOIN categorie c ON c.code = v.code_categorie
            WHERE v.etat <> 'EN_MAINTENANCE'
              AND NOT EXISTS (SELECT 1 FROM reservation r WHERE r.immatriculation = v.immatriculation
                              AND r.statut IN ('EN_ATTENTE','CONFIRMEE') AND r.date_debut <= :fin AND r.date_fin >= :debut)
              AND NOT EXISTS (SELECT 1 FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation
                              WHERE r.immatriculation = v.immatriculation AND l.date_retour_effective IS NULL)";
    $p = [':debut' => $debut, ':fin' => $fin];
    if ($cat) { $sql .= ' AND v.code_categorie = :cat'; $p[':cat'] = $cat; }
    if ($immat) { $sql .= ' AND v.immatriculation = :immat'; $p[':immat'] = $immat; }
    if (!empty($f['type'])) { $sql .= ' AND v.type = :type'; $p[':type'] = $f['type']; }
    if (!empty($f['energie'])) { $sql .= ' AND v.energie = :energie'; $p[':energie'] = $f['energie']; }
    if (!empty($f['boite'])) { $sql .= ' AND v.boite = :boite'; $p[':boite'] = $f['boite']; }
    if (!empty($f['places'])) { $sql .= ' AND v.nb_places >= :places'; $p[':places'] = (int) $f['places']; }
    if (!empty($f['prixmax'])) {
        $sql .= " AND (SELECT prix FROM tarif t2 WHERE t2.code_categorie = v.code_categorie AND t2.code_formule = 'JOUR') <= :prixmax";
        $p[':prixmax'] = (float) $f['prixmax'];
    }
    $st = $pdo->prepare($sql . ' ORDER BY prix_jour, v.code_categorie, v.marque');
    $st->execute($p);
    return $st->fetchAll();
}

// ---------- Client.aLocationEnCours() (RG6) ----------
function location_en_cours(PDO $pdo, int $numClient): ?array
{
    $st = $pdo->prepare('SELECT l.*, r.immatriculation FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation
                         WHERE r.num_client = ? AND l.date_retour_effective IS NULL');
    $st->execute([$numClient]);
    return $st->fetch() ?: null;
}

// ---------- CarteBancaire.estValide() et banque simulée (UC-11) ----------
function chiffres(string $numero): string
{
    return preg_replace('/\D/', '', $numero);
}
function luhn_valide(string $numero): bool
{
    $n = chiffres($numero);
    if (strlen($n) < 13 || strlen($n) > 19) {
        return false;
    }
    $somme = 0;
    $double = false;
    for ($i = strlen($n) - 1; $i >= 0; $i--) {
        $d = (int) $n[$i];
        if ($double) { $d *= 2; if ($d > 9) { $d -= 9; } }
        $somme += $d;
        $double = !$double;
    }
    return $somme % 10 === 0;
}
/** Date d'expiration au format AAAA-MM : la carte est valable jusqu'au dernier jour du mois. */
function fin_validite(string $mois): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $mois, $m)) {
        return null;
    }
    return (new DateTime("$m[1]-$m[2]-01"))->modify('last day of this month')->format('Y-m-d');
}
function carte_valide(string $numero, string $mois, string $jour): bool
{
    $fin = fin_validite($mois);
    return $fin !== null && $fin >= $jour && luhn_valide($numero);
}
/** Même comportement que ServiceBancaireSimule : fin 0000 = refusée, fin 9999 = banque indisponible. */
function banque_autoriser(string $numero, float $montant): string
{
    $n = chiffres($numero);
    if (str_ends_with($n, '9999')) {
        return 'INDISPONIBLE';
    }
    return (!str_ends_with($n, '0000') && $montant > 0) ? 'AUTORISE' : 'REFUSE';
}
function masquer(string $numero): string
{
    return '**** **** **** ' . substr(chiffres($numero), -4);
}

// ---------- Emplacement (UC-13) ----------
function emplacements_libres(PDO $pdo): array
{
    return $pdo->query('SELECT e.* FROM emplacement e LEFT JOIN vehicule v ON v.num_emplacement = e.numero WHERE v.immatriculation IS NULL ORDER BY e.numero')->fetchAll();
}

// ---------- Location.estEnRetard() (UC-14) ----------
function locations_en_retard(PDO $pdo, string $jour): array
{
    $st = $pdo->prepare("SELECT l.*, r.immatriculation, v.marque, v.modele, p.nom, p.prenom, p.adresse, p.telephone, cl.num_client,
                                DATEDIFF(:j, l.date_retour_prevue) AS jours_retard
                         FROM location l JOIN reservation r ON r.num_reservation = l.num_reservation
                         JOIN vehicule v ON v.immatriculation = r.immatriculation
                         JOIN client cl ON cl.num_client = r.num_client JOIN personne p ON p.id_personne = cl.id_personne
                         WHERE l.date_retour_effective IS NULL AND l.date_retour_prevue < :j2 ORDER BY l.date_retour_prevue");
    $st->execute([':j' => $jour, ':j2' => $jour]);
    return $st->fetchAll();
}

const PRIX_LITRE_CARBURANT = 2.20;

/** État d'un véhicule qui redevient libre : RESERVE s'il reste une réservation confirmée à venir, sinon DISPONIBLE. */
function etat_apres_liberation(PDO $pdo, string $immat): string
{
    $st = $pdo->prepare("SELECT 1 FROM reservation WHERE immatriculation = ? AND statut = 'CONFIRMEE' AND date_fin >= CURDATE()");
    $st->execute([$immat]);
    return $st->fetch() ? 'RESERVE' : 'DISPONIBLE';
}
function liberer_vehicule(PDO $pdo, string $immat): void
{
    $pdo->prepare("UPDATE vehicule SET etat = ? WHERE immatriculation = ? AND etat = 'RESERVE'")->execute([etat_apres_liberation($pdo, $immat), $immat]);
}
