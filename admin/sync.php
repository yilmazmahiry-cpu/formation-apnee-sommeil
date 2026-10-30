<?php
declare(strict_types=1);
/**
 * Synchronisations :
 *  - Meta : dépense, clics, leads et achats vus par Meta, par pub et par jour (API Insights).
 *  - Stripe (facultatif) : import des ventes passées.
 *
 * En ligne de commande (tâche cron quotidienne recommandée, ex. 6 h) :
 *   php /chemin/vers/admin/sync.php            → 30 derniers jours Meta
 *   php /chemin/vers/admin/sync.php 90 stripe  → 90 jours Meta + import Stripe
 * Depuis l'admin : boutons « Synchroniser Meta » et « Importer depuis Stripe ».
 */
require_once __DIR__ . '/lib.php';

function meta_action(array $list, array $types): float
{
    // Meta renvoie souvent le même évènement sous plusieurs types : on prend le premier trouvé, dans l'ordre donné.
    foreach ($types as $t) {
        foreach ($list as $a) {
            if (($a['action_type'] ?? '') === $t) return (float)$a['value'];
        }
    }
    return 0.0;
}

function sync_meta(int $days = 30): int
{
    $acct = (string)cfg('meta_ad_account_id');
    $token = (string)cfg('meta_access_token');
    if ($acct === '' || $token === '') throw new RuntimeException('Compte publicitaire ou jeton Meta non configuré.');
    if (!str_starts_with($acct, 'act_')) $acct = 'act_' . $acct;

    $since = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' days'));
    $until = date('Y-m-d');
    $url = sprintf('https://graph.facebook.com/%s/%s/insights?%s', cfg('meta_api_version'), $acct, http_build_query([
        'level'          => 'ad',
        'time_increment' => 1,
        'time_range'     => json_encode(['since' => $since, 'until' => $until]),
        'fields'         => 'date_start,ad_id,ad_name,adset_name,campaign_name,spend,impressions,inline_link_clicks,actions,action_values',
        'limit'          => 500,
        'access_token'   => $token,
    ]));

    $pdo = db();
    $up = $pdo->prepare('INSERT INTO meta_insights(date, ad_id, campaign_name, adset_name, ad_name, spe, spend, impressions, link_clicks,
                                                   landing_views, meta_leads, meta_purchases, meta_purchase_value)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
                         ON CONFLICT(date, ad_id) DO UPDATE SET campaign_name=excluded.campaign_name, adset_name=excluded.adset_name,
                           ad_name=excluded.ad_name, spe=excluded.spe, spend=excluded.spend, impressions=excluded.impressions,
                           link_clicks=excluded.link_clicks, landing_views=excluded.landing_views, meta_leads=excluded.meta_leads,
                           meta_purchases=excluded.meta_purchases, meta_purchase_value=excluded.meta_purchase_value');
    $rows = 0;
    $pages = 0;
    while ($url && $pages++ < 50) {
        $res = http_json('GET', $url);
        foreach ($res['data'] ?? [] as $r) {
            $actions = $r['actions'] ?? [];
            $values = $r['action_values'] ?? [];
            $up->execute([
                $r['date_start'], (string)$r['ad_id'], $r['campaign_name'] ?? null, $r['adset_name'] ?? null, $r['ad_name'] ?? null,
                spe_from_ad_name($r['ad_name'] ?? null),
                (float)($r['spend'] ?? 0), (int)($r['impressions'] ?? 0), (int)($r['inline_link_clicks'] ?? 0),
                (int)meta_action($actions, ['landing_page_view', 'omni_landing_page_view']),
                (int)meta_action($actions, ['lead', 'offsite_conversion.fb_pixel_lead', 'onsite_conversion.lead_grouped']),
                (int)meta_action($actions, ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase']),
                meta_action($values, ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase']),
            ]);
            $rows++;
        }
        $url = $res['paging']['next'] ?? null;
    }
    kv_set('meta_last_sync', now_iso());
    return $rows;
}

function import_stripe(int $days = 90): int
{
    $key = (string)cfg('stripe_secret_key');
    if ($key === '') throw new RuntimeException('Clé Stripe non configurée (facultative, voir config.php).');
    $since = time() - $days * 86400;
    $count = 0;
    $after = null;
    for ($i = 0; $i < 30; $i++) {
        $q = ['limit' => 100, 'status' => 'complete', 'created[gte]' => $since];
        if ($after) $q['starting_after'] = $after;
        $res = http_json('GET', 'https://api.stripe.com/v1/checkout/sessions?' . http_build_query($q), null, [], $key);
        foreach ($res['data'] ?? [] as $s) {
            if (record_sale($s)) $count++;
            $after = $s['id'];
        }
        if (empty($res['has_more'])) break;
    }
    kv_set('stripe_last_import', now_iso());
    return $count;
}

/* Exécution en ligne de commande */
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $days = (int)($argv[1] ?? 30) ?: 30;
    try {
        echo 'Meta : ', sync_meta($days), " lignes\n";
        if (in_array('stripe', $argv, true)) echo 'Stripe : ', import_stripe($days), " nouvelles ventes\n";
        purge_old_leads();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
        exit(1);
    }
}
