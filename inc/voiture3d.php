<?php
declare(strict_types=1);
// Affichage des véhicules en 3D, en rotation (composant <model-viewer>, hébergé dans assets/js/vendor).
// Les modèles sont génériques par silhouette (citadine, compacte, SUV, fourgon) et recolorés avec la couleur réelle
// du véhicule ; la photo réelle sert d'image d'attente et de solution de repli si la 3D ne peut pas s'afficher.

/** Modèles 3D : fichier, matériau de carrosserie à recolorer, auteur, licence, page source. */
const MODELES_3D = [
    'citadine' => ['citadine.glb', 'Blue', 'Car, Quaternius', 'CC0 1.0', 'https://poly.pizza/m/Cz6yDaUcM9'],
    'compacte' => ['compacte.glb', 'LightBlue', 'Car, Quaternius', 'CC0 1.0', 'https://poly.pizza/m/unqqkULtRU'],
    'suv' => ['suv.glb', 'White', 'SUV, Quaternius', 'CC0 1.0', 'https://poly.pizza/m/xsMtZhBkxL'],
    'fourgon' => ['fourgon.glb', 'bodywork', 'Generic Van, PuKkBuMXDD', 'CC BY 3.0', 'https://poly.pizza/m/BbRojf2v3H'],
];
// Modèles de type crossover : affichés avec la silhouette SUV
const MODELES_SUV = ['c3 aircross', 'yaris cross', '3008', '2008', 'captur', 'juke', 'tiguan'];

const COULEURS_3D = [
    'bleu' => '#2F7FD3', 'blanc' => '#F1F2F0', 'gris' => '#A4A9AE', 'argent' => '#B9BDC1', 'noir' => '#23262B',
    'rouge' => '#C23A2B', 'vert' => '#2F7D4F', 'jaune' => '#E8B923', 'orange' => '#E2701F', 'marron' => '#6B4A34',
];

function silhouette_3d(array $v): string
{
    if (($v['type'] ?? '') === 'UTILITAIRE') { return 'fourgon'; }
    if (in_array(mb_strtolower((string) ($v['modele'] ?? '')), MODELES_SUV, true)) { return 'suv'; }
    return ($v['code_categorie'] ?? 'A') === 'A' ? 'citadine' : 'compacte';
}

/** Code couleur (hexadécimal) déduit de la couleur saisie pour le véhicule. */
function couleur_3d(?string $couleur): string
{
    $c = mb_strtolower(trim((string) $couleur));
    foreach (COULEURS_3D as $nom => $hex) {
        if (str_starts_with($c, $nom)) { return $hex; }
    }
    return '#A4A9AE';
}

/** Balise <model-viewer> d'un véhicule, en rotation automatique ; $grand = présentation plus large (accueil). */
function voiture_3d(array $v, string $classe = 'vue-3d', bool $grand = false): string
{
    [$fichier, $materiau] = MODELES_3D[silhouette_3d($v)];
    [$photo] = photo_vehicule($v);
    $nom = trim(($v['marque'] ?? '') . ' ' . ($v['modele'] ?? ''));
    return '<model-viewer class="' . h($classe) . '" src="assets/3d/' . h($fichier) . '" poster="' . h($photo) . '"'
        . ' alt="' . h($nom . ', vue 3D en rotation') . '" auto-rotate rotation-per-second="' . ($grand ? '18deg' : '24deg') . '"'
        . ' auto-rotate-delay="0" camera-orbit="35deg 72deg auto" camera-controls disable-zoom disable-pan interaction-prompt="none"'
        . ' shadow-intensity="1" shadow-softness="0.8" exposure="1.05" environment-image="neutral" loading="lazy" reveal="auto"'
        . ' data-couleur="' . h(couleur_3d($v['couleur'] ?? null)) . '" data-carrosserie="' . h($materiau) . '"></model-viewer>';
}
