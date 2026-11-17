<?php
declare(strict_types=1);
// RapportRetards.generer() : partagé entre la page Retards et le batch de 22h00.
require_once __DIR__ . '/metier.php';
require_once __DIR__ . '/amelioration.php';

function generer_rapport_retards(PDO $pdo): int
{
    $retards = locations_en_retard($pdo, date('Y-m-d'));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO rapport_retards (date_generation, fichier_pdf) VALUES (NOW(), '')")->execute();
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE rapport_retards SET fichier_pdf = ? WHERE id_rapport = ?')->execute(['rapport.php?id=' . $id, $id]);
        $ins = $pdo->prepare('INSERT INTO rapport_location (id_rapport, num_location) VALUES (?, ?)');
        foreach ($retards as $l) {
            $ins->execute([$id, $l['num_location']]);
            notifier($pdo, (int) $l['num_client'], "Retard de retour, location n° {$l['num_location']}",
                "Votre {$l['marque']} {$l['modele']} devait être rendu le " . (new DateTime($l['date_retour_prevue']))->format('d/m/Y') . " ({$l['jours_retard']} j de retard). Merci de le rapporter à l'agence : chaque jour de retard est facturé au tarif journée.");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}
