<?php
/**
 * Configuration de l'admin Somnéa.
 * Copier ce fichier en « config.php » puis remplir les valeurs.
 * Ne jamais versionner ni envoyer config.php : il contient des secrets.
 */
return [
    // --- Accès à l'admin -------------------------------------------------------------------------
    // Un compte par personne : 'identifiant' => hash du mot de passe.
    // Générer un hash :  php -r "echo password_hash('VotreMotDePasse', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_users' => [
        // 'mahir' => '$2y$10$…',
    ],

    // --- Stripe ----------------------------------------------------------------------------------
    // Stripe → Développeurs → Webhooks → Ajouter un endpoint :
    //   URL : https://formationsahos.fr/admin/webhook-stripe.php
    //   Évènement : checkout.session.completed
    // puis copier ici le « Secret de signature » (whsec_…)
    'stripe_webhook_secret' => '',
    // Facultatif : clé restreinte (rk_live_…) en LECTURE sur « Checkout Sessions », pour importer
    // les ventes passées avec le bouton « Importer depuis Stripe ».
    'stripe_secret_key' => '',

    // --- Meta ------------------------------------------------------------------------------------
    'meta_pixel_id'      => '1113341564765241',
    // Compte publicitaire au format act_XXXXXXXXXXXX (Gestionnaire de publicités → identifiant du compte)
    'meta_ad_account_id' => '',
    // Jeton d'accès « utilisateur système » du portefeuille business, droits ads_read (+ ads_management
    // si l'API Conversions est activée). Paramètres de l'entreprise → Utilisateurs système → Générer un jeton.
    'meta_access_token'  => '',
    'meta_api_version'   => 'v23.0',
    // API Conversions : renvoie Lead et Purchase côté serveur, UNIQUEMENT pour les visiteurs ayant accepté
    // les cookies publicitaires (exigence CNIL). Dédoublonné avec le pixel grâce à l'event_id.
    'meta_capi_enabled'  => false,
    // Code de test du Gestionnaire d'évènements (onglet « Tester les évènements »), à vider en production.
    'meta_capi_test_code' => '',

    // --- Divers ----------------------------------------------------------------------------------
    'site_origin' => 'https://formationsahos.fr',   // seule origine autorisée à envoyer des leads
    'timezone'    => 'Europe/Paris',
    // Durée de conservation des leads non convertis (RGPD), en mois. Purge automatique.
    'lead_retention_months' => 24,
];
