<?php
require_once __DIR__ . '/config.php';

/** Lit db.properties (format Java clé=valeur) et renvoie un tableau associatif. */
function lire_proprietes(string $fichier): array
{
    $props = [];
    if (!is_readable($fichier)) {
        return $props;
    }
    foreach (file($fichier, FILE_IGNORE_NEW_LINES) as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '' || $ligne[0] === '#' || !str_contains($ligne, '=')) {
            continue;
        }
        [$cle, $valeur] = explode('=', $ligne, 2);
        $props[trim($cle)] = trim($valeur);
    }
    return $props;
}

/** Connexion PDO en lecture à la base rentcar, ou null si MySQL ne répond pas. */
function connexion_rentcar(?string &$erreur = null): ?PDO
{
    $p = lire_proprietes(RENTCAR_DB_PROPERTIES);
    // jdbc:mysql://localhost:3306/rentcar?serverTimezone=... -> hôte, port, base
    if (!preg_match('#^jdbc:mysql://([^:/]+)(?::(\d+))?/([^?]+)#', $p['db.url'] ?? '', $m)) {
        $erreur = 'URL de connexion introuvable dans db.properties.';
        return null;
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $m[1], $m[2] ?: '3306', $m[3]);
    try {
        return new PDO($dsn, $p['db.user'] ?? '', $p['db.password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
        ]);
    } catch (PDOException $e) {
        $erreur = $e->getMessage();
        return null;
    }
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
