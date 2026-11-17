<?php
// Catalogue des cas d'utilisation, repris du diagramme des cas d'utilisation (P-01).
// Chaque page de l'application réalise un cas et affiche ses étapes ; 'page' = fichier qui le réalise.
return [
    'UC-01' => [
        'titre' => "Rechercher la disponibilité d'un véhicule", 'acteurs' => ['Visiteur', 'Client', 'Employé'], 'page' => 'recherche.php', 'regles' => ['RG1'],
        'etapes' => [
            "Le visiteur choisit une catégorie (facultatif) et une période.",
            "Le système recherche les véhicules libres sur toute la période.",
            "Le système affiche les véhicules disponibles et leur tarif.",
            "Le visiteur choisit un véhicule pour le réserver (il doit alors être client).",
        ],
        'extensions' => ['2a' => "Période incohérente (fin avant début, ou date passée) : message, retour à l'étape 1.", '3a' => "Aucun véhicule libre : le système propose d'élargir la recherche."],
    ],
    'UC-02' => [
        'titre' => 'Consulter les tarifs et les formules', 'acteurs' => ['Visiteur', 'Client', 'Employé'], 'page' => 'tarifs.php', 'regles' => ['RG1', 'RG7'],
        'etapes' => [
            "Le visiteur ouvre la grille des tarifs.",
            "Le système affiche le prix de chaque couple catégorie × formule (classe-association Tarif).",
            "Le visiteur peut simuler le prix d'une durée : Tarif.calculerPrix(duree).",
        ],
        'extensions' => [],
    ],
    'UC-03' => [
        'titre' => "S'inscrire", 'acteurs' => ['Visiteur'], 'page' => 'inscription.php', 'regles' => ['RG1', 'RG2'],
        'etapes' => [
            "Le visiteur saisit son identité, son permis, son login et son mot de passe.",
            "Le système vérifie les champs et l'unicité du login.",
            "Le système crée la personne, son compte et le client.",
            "Le visiteur devient client et est connecté.",
        ],
        'extensions' => ['2a' => "Champ manquant ou login déjà pris : message, retour à l'étape 1."],
    ],
    'UC-04' => [
        'titre' => "S'authentifier", 'acteurs' => ['Client', 'Employé'], 'page' => 'connexion.php', 'regles' => ['RG2'],
        'etapes' => [
            "L'utilisateur saisit son login et son mot de passe.",
            "Le système vérifie le mot de passe (empreinte PBKDF2).",
            "Le système ouvre l'espace correspondant : client ou employé.",
        ],
        'extensions' => ['2a' => "Login ou mot de passe incorrect : message, retour à l'étape 1."],
    ],
    'UC-05' => [
        'titre' => 'Réserver un véhicule', 'acteurs' => ['Client', 'Employé'], 'page' => 'reserver.php', 'regles' => ['RG4', 'RG7'],
        'etapes' => [
            "Le client (ou l'employé pour un client) choisit le véhicule, la formule et la période.",
            "Le système vérifie que le véhicule est libre sur la période.",
            "Le système calcule le montant prévisionnel (tarif × nombre de formules).",
            "L'utilisateur confirme la réservation.",
            "Le système enregistre la réservation : en attente si elle vient du client, confirmée si elle vient d'un employé.",
        ],
        'extensions' => ['2a' => "Véhicule déjà réservé ou loué sur la période : retour à l'étape 1.", '4a' => "L'utilisateur modifie son choix : retour à l'étape 1."],
    ],
    'UC-06' => [
        'titre' => 'Louer un véhicule', 'acteurs' => ['Employé', 'Système bancaire'], 'page' => 'louer.php', 'regles' => ['RG5', 'RG6', 'RG7'],
        'include' => ['UC-11'],
        'etapes' => [
            "L'employé saisit le numéro de réservation du client.",
            "Le système vérifie que la réservation existe, est confirmée et couvre le jour du retrait.",
            "Le système vérifie que le client n'a pas de location en cours (RG6).",
            "Le système calcule le montant : tarif catégorie × formule, selon la durée (RG7).",
            "Le système affiche le récapitulatif : client, véhicule, formule, dates, montant.",
            "L'employé saisit la carte bancaire du client.",
            "Le système demande une autorisation de paiement au système bancaire.",
            "Le système bancaire accepte la transaction.",
            "Le système crée la location, enregistre le paiement, passe le véhicule à LOUE et clôture la réservation.",
            "Le système affiche le contrat avec l'emplacement du véhicule ; l'employé réalise l'état des lieux de départ (zones, carburant, photos).",
        ],
        'extensions' => [
            '1a' => "Numéro inconnu : recherche par nom du client, choix d'une réservation. Retour à l'étape 2.",
            '2a' => "Réservation inexistante, non confirmée, expirée ou pas encore commencée : fin (échec).",
            '3a' => "Le client a déjà une location en cours : fin (échec).",
            '5a' => "Le client change de formule : montant recalculé. Retour à l'étape 5.",
            '7a' => "Système bancaire indisponible : aucune location créée, fin (échec).",
            '8a' => "Carte refusée, moins de 3 tentatives : autre carte demandée. Retour à l'étape 6.",
            '8b' => "Troisième refus : location abandonnée, réservation conservée. Fin (échec).",
        ],
    ],
    'UC-07' => [
        'titre' => 'Rendre un véhicule', 'acteurs' => ['Employé', 'Système bancaire'], 'page' => 'rendre.php', 'regles' => ['RG8'],
        'extend' => ['UC-12'],
        'etapes' => [
            "L'employé choisit la location en cours du véhicule rendu.",
            "L'employé réalise l'état des lieux du retour : kilométrage, carburant, constat par zone, photos.",
            "Le système compare avec la date prévue et l'état des lieux de départ (carburant, dommages nouveaux).",
            "Point d'extension « retard, carburant manquant, dommage » : si besoin, Facturer un supplément.",
            "Le système enregistre le retour, libère la caution et remet le véhicule DISPONIBLE à sa place.",
        ],
        'extensions' => ['2a' => "Kilométrage inférieur à celui du départ : message, retour à l'étape 2."],
    ],
    'UC-08' => [
        'titre' => 'Enregistrer un nouveau véhicule', 'acteurs' => ['Employé'], 'page' => 'vehicule-nouveau.php', 'regles' => ['RG3'],
        'include' => ['UC-13'],
        'etapes' => [
            "L'employé saisit l'immatriculation, le type, la marque, le modèle, la catégorie et les compteurs.",
            "Le système vérifie que l'immatriculation est nouvelle et bien formée.",
            "Le système affecte un emplacement libre (cas inclus « Affecter un emplacement »).",
            "Le système enregistre le véhicule, DISPONIBLE.",
        ],
        'extensions' => ['2a' => "Immatriculation invalide ou déjà connue : message, retour à l'étape 1.", '3a' => "Aucun emplacement libre : enregistrement impossible, fin (échec)."],
    ],
    'UC-09' => [
        'titre' => 'Relancer les clients en retard', 'acteurs' => ['Employé'], 'page' => 'retards.php', 'regles' => ['RG9'],
        'etapes' => [
            "L'employé ouvre le dernier rapport des retards, généré la veille à 22h00.",
            "Le système liste les clients en retard, avec le véhicule et le nombre de jours.",
            "L'employé imprime une lettre de relance par client, envoyée par courrier postal.",
        ],
        'extensions' => ['1a' => "Aucun rapport disponible : l'employé peut le générer (simulation de l'horloge)."],
    ],
    'UC-10' => [
        'titre' => 'Imprimer une fiche récapitulative', 'acteurs' => ['Employé'], 'page' => 'fiches.php', 'regles' => ['RG10'],
        'specialisations' => ['Fiche client', 'Fiche véhicule', 'Fiche location'],
        'etapes' => [
            "L'employé choisit le type de fiche : client, véhicule ou location.",
            "L'employé choisit l'élément concerné.",
            "Le système compose la fiche récapitulative.",
            "L'employé l'imprime.",
        ],
        'extensions' => [],
    ],
    'UC-11' => [
        'titre' => 'Vérifier la carte bancaire', 'acteurs' => ['Système bancaire'], 'page' => 'louer.php', 'regles' => ['RG5'], 'inclus_par' => 'UC-06',
        'etapes' => ["Contrôle local : date d'expiration et clé de Luhn.", "Demande d'autorisation au système bancaire.", "Réponse : autorisé, refusé ou banque indisponible."],
        'extensions' => [],
    ],
    'UC-12' => [
        'titre' => 'Facturer un supplément', 'acteurs' => ['Employé', 'Système bancaire'], 'page' => 'rendre.php', 'etend' => 'UC-07',
        'etapes' => ["Le système calcule le supplément : jours de retard × tarif journée, carburant manquant et réparation d'un dommage nouveau (plafonnée à la franchise).", "L'employé encaisse le supplément par carte.", "Le système enregistre un paiement supplémentaire lié à la location."],
        'extensions' => [],
    ],
    'UC-13' => [
        'titre' => 'Affecter un emplacement de parking', 'acteurs' => ['Employé'], 'page' => 'vehicule-nouveau.php', 'regles' => ['RG3'], 'inclus_par' => 'UC-08',
        'etapes' => ["Le système cherche les emplacements sans véhicule.", "Le premier emplacement libre (ou celui choisi par l'employé) est attribué au véhicule."],
        'extensions' => [],
    ],
    'UC-14' => [
        'titre' => 'Générer le rapport des retards (PDF)', 'acteurs' => ['Horloge'], 'page' => 'retards.php', 'regles' => ['RG9'],
        'etapes' => ["Chaque soir à 22h00, l'horloge déclenche la génération.", "Le système recense les locations dont la date de retour prévue est dépassée (estEnRetard).", "Le système enregistre le rapport et produit sa version imprimable (PDF)."],
        'extensions' => [],
    ],
];
