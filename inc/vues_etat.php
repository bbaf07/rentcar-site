<?php
declare(strict_types=1);
// Formulaire et affichage d'un état des lieux, partagés par le départ (UC-06) et le retour (UC-07).

/** Champs du formulaire d'état des lieux (sans la balise form). $v = valeurs déjà saisies. */
function champs_etat(array $v, int $kmDefaut, float $carburantDefaut, ?array $depart = null): string
{
    ob_start(); ?>
    <div class="grille-champs">
      <div class="champ"><label for="km">Kilométrage</label><input id="km" name="km" inputmode="numeric" value="<?= h((string) ($v['km'] ?? $kmDefaut)) ?>" required></div>
      <div class="champ"><label for="carburant">Carburant (litres)</label><input id="carburant" name="carburant" inputmode="decimal" value="<?= h((string) ($v['carburant'] ?? $carburantDefaut)) ?>" required>
        <?php if ($depart): ?><small>Au départ : <?= number_format((float) $depart['carburant'], 1, ',', ' ') ?> L</small><?php endif; ?></div>
      <div class="champ"><label for="proprete">Propreté</label><select id="proprete" name="proprete">
        <?php foreach (PROPRETES as $k => $lib): ?><option value="<?= $k ?>"<?= ($v['proprete'] ?? 'PROPRE') === $k ? ' selected' : '' ?>><?= h($lib) ?></option><?php endforeach; ?>
      </select></div>
    </div>
    <div class="defile" style="margin-top:16px">
      <table class="zones">
        <thead><tr><th>Zone</th><?php if ($depart): ?><th>Au départ</th><?php endif; ?><th>Constat</th><th>Précision</th></tr></thead>
        <tbody>
        <?php foreach (ZONES_VEHICULE as $cle => $lib):
            $choisi = $v['zone'][$cle] ?? ($depart['zones'][$cle]['etat'] ?? 'OK'); ?>
          <tr>
            <td><b><?= h($lib) ?></b></td>
            <?php if ($depart): $av = $depart['zones'][$cle] ?? ['etat' => 'OK', 'note' => null]; ?>
              <td><span class="etat-<?= h($av['etat']) ?>"><?= h(ETATS_ZONE[$av['etat']]) ?></span><?= $av['note'] ? '<br><small class="gris">' . h($av['note']) . '</small>' : '' ?></td>
            <?php endif; ?>
            <td><div class="choix-etat" role="radiogroup" aria-label="<?= h($lib) ?>">
              <?php foreach (ETATS_ZONE as $k => $libE): ?>
                <label><input type="radio" name="zone[<?= $cle ?>]" value="<?= $k ?>"<?= $choisi === $k ? ' checked' : '' ?>> <?= h($libE) ?></label>
              <?php endforeach; ?>
            </div></td>
            <td><input type="text" name="note[<?= $cle ?>]" maxlength="200" value="<?= h((string) ($v['note'][$cle] ?? '')) ?>" placeholder="ex. rayure 5 cm porte avant" aria-label="Précision, <?= h($lib) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="grille-champs" style="margin-top:16px">
      <div class="champ"><label for="photos">Photos (8 au plus)</label><input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple><small>Une photo par côté du véhicule, et une de chaque dommage.</small></div>
      <div class="champ"><label for="remarques">Remarques</label><input id="remarques" name="remarques" maxlength="500" value="<?= h((string) ($v['remarques'] ?? '')) ?>"></div>
    </div>
    <?php return (string) ob_get_clean();
}

/** Résumé lisible d'un état des lieux enregistré, avec ses photos. */
function vue_etat(array $e, ?array $depart = null): string
{
    $nouveaux = $depart ? nouveaux_dommages($depart, array_map(fn($z) => $z['etat'], $e['zones'])) : [];
    ob_start(); ?>
    <p class="gris">Réalisé le <?= (new DateTime($e['date_etat']))->format('d/m/Y à H:i') ?> par <?= h($e['emp_prenom'] . ' ' . $e['emp_nom']) ?>.
      Kilométrage <?= number_format((int) $e['kilometrage'], 0, ',', ' ') ?> km, carburant <?= number_format((float) $e['carburant'], 1, ',', ' ') ?> L, véhicule <?= h(mb_strtolower(PROPRETES[$e['proprete']])) ?>.</p>
    <div class="defile">
      <table class="zones">
        <thead><tr><th>Zone</th><?php if ($depart): ?><th>Au départ</th><?php endif; ?><th>Constat</th><th>Précision</th></tr></thead>
        <tbody>
        <?php foreach (ZONES_VEHICULE as $cle => $lib): $z = $e['zones'][$cle] ?? ['etat' => 'OK', 'note' => null]; ?>
          <tr class="<?= isset($nouveaux[$cle]) ? 'nouveau-dommage' : '' ?>">
            <td><?= h($lib) ?></td>
            <?php if ($depart): $av = $depart['zones'][$cle]['etat'] ?? 'OK'; ?><td><span class="etat-<?= h($av) ?>"><?= h(ETATS_ZONE[$av]) ?></span></td><?php endif; ?>
            <td><span class="etat-<?= h($z['etat']) ?>"><?= h(ETATS_ZONE[$z['etat']]) ?></span><?= isset($nouveaux[$cle]) ? ' <b>(nouveau)</b>' : '' ?></td>
            <td><?= h((string) $z['note']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($e['remarques']): ?><p style="margin-top:10px"><b>Remarques :</b> <?= h($e['remarques']) ?></p><?php endif; ?>
    <?php if ($e['photos']): ?>
      <div class="galerie"><?php foreach ($e['photos'] as $id): ?><a href="photo.php?id=<?= (int) $id ?>" target="_blank"><img src="photo.php?id=<?= (int) $id ?>" alt="Photo de l'état des lieux" loading="lazy"></a><?php endforeach; ?></div>
    <?php else: ?>
      <p class="gris">Aucune photo jointe.</p>
    <?php endif; ?>
    <?php return (string) ob_get_clean();
}
