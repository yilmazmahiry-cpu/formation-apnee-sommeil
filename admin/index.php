<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/sync.php';
require_login();

$pdo = db();

/* ---------- Actions (POST) ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'sync_meta') {
            $n = sync_meta(90);
            $_SESSION['flash'] = ['ok', "Meta synchronisé ($n lignes sur 90 jours)."];
        } elseif ($action === 'import_stripe') {
            $n = import_stripe(180);
            $_SESSION['flash'] = ['ok', "Import Stripe terminé : $n nouvelle(s) vente(s)."];
        } elseif ($action === 'lead_update') {
            $statut = (string)($_POST['statut'] ?? '');
            if (!array_key_exists($statut, LEAD_STATUTS)) throw new RuntimeException('Statut inconnu.');
            $pdo->prepare('UPDATE leads SET statut = ?, note = ?, updated_at = ? WHERE id = ?')
                ->execute([$statut, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000), now_iso(), (int)($_POST['id'] ?? 0)]);
            $_SESSION['flash'] = ['ok', 'Lead mis à jour.'];
        } elseif ($action === 'lead_delete') {
            $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            $_SESSION['flash'] = ['ok', 'Lead supprimé.'];
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['error', $e->getMessage()];
    }
    $back = strtok((string)($_SERVER['REQUEST_URI'] ?? './'), '#');
    header('Location: ' . $back . ($action === 'lead_update' || $action === 'lead_delete' ? '#leads' : ''));
    exit;
}

/* ---------- Période ----------------------------------------------------------------------- */
$p = (string)($_GET['p'] ?? '30');
$today = date('Y-m-d');
switch ($p) {
    case '7':    $from = date('Y-m-d', strtotime('-6 days')); $to = $today; break;
    case '90':   $from = date('Y-m-d', strtotime('-89 days')); $to = $today; break;
    case 'mois': $from = date('Y-m-01'); $to = $today; break;
    case 'prec': $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('last day of last month')); break;
    case 'perso':
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-29 days'));
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : $today;
        if ($from > $to) [$from, $to] = [$to, $from];
        break;
    default: $p = '30'; $from = date('Y-m-d', strtotime('-29 days')); $to = $today;
}
$fromTs = $from . ' 00:00:00';
$toTs = $to . ' 23:59:59';

function one(string $sql, array $args = [])
{
    $s = db()->prepare($sql);
    $s->execute($args);
    return $s->fetch() ?: [];
}
function all(string $sql, array $args = []): array
{
    $s = db()->prepare($sql);
    $s->execute($args);
    return $s->fetchAll();
}

/* ---------- Chiffres clés ----------------------------------------------------------------- */
$meta = one('SELECT COALESCE(SUM(spend),0) spend, COALESCE(SUM(impressions),0) impr, COALESCE(SUM(link_clicks),0) clicks,
                    COALESCE(SUM(landing_views),0) views, COALESCE(SUM(meta_leads),0) mleads,
                    COALESCE(SUM(meta_purchases),0) mbuy, COALESCE(SUM(meta_purchase_value),0) mval
             FROM meta_insights WHERE date BETWEEN ? AND ?', [$from, $to]);
$leads = one('SELECT COUNT(*) n, SUM(statut = \'inscrit\') inscrits, SUM(statut = \'a_rappeler\') a_rappeler FROM leads WHERE created_at BETWEEN ? AND ?', [$fromTs, $toTs]);
$salesMeta = one('SELECT COUNT(*) n, COALESCE(SUM(amount),0)/100.0 ca FROM sales WHERE source = \'meta_lp\' AND created_at BETWEEN ? AND ?', [$fromTs, $toTs]);
$salesAll = one('SELECT COUNT(*) n, COALESCE(SUM(amount),0)/100.0 ca FROM sales WHERE created_at BETWEEN ? AND ?', [$fromTs, $toTs]);
$salesFromLeads = (int)(one('SELECT COUNT(DISTINCT s.lead_id) n FROM sales s JOIN leads l ON l.id = s.lead_id WHERE l.created_at BETWEEN ? AND ?', [$fromTs, $toTs])['n'] ?? 0);

$spend = (float)$meta['spend'];
$nLeads = (int)$leads['n'];
$nSalesMeta = (int)$salesMeta['n'];
$caMeta = (float)$salesMeta['ca'];

$cpl = $nLeads ? $spend / $nLeads : null;
$cpa = $nSalesMeta ? $spend / $nSalesMeta : null;
$roas = $spend > 0 ? $caMeta / $spend : null;
$convPage = (int)$meta['views'] ? $nLeads / (int)$meta['views'] : null;
$convLead = $nLeads ? $salesFromLeads / $nLeads : null;

/* ---------- Série quotidienne (graphique) -------------------------------------------------- */
$days = [];
for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) $days[date('Y-m-d', $d)] = ['spend' => 0, 'sales' => 0, 'leads' => 0];
foreach (all('SELECT date, SUM(spend) s FROM meta_insights WHERE date BETWEEN ? AND ? GROUP BY date', [$from, $to]) as $r) $days[$r['date']]['spend'] = round((float)$r['s'], 2);
foreach (all('SELECT substr(created_at,1,10) d, COUNT(*) n FROM sales WHERE source = \'meta_lp\' AND created_at BETWEEN ? AND ? GROUP BY d', [$fromTs, $toTs]) as $r) if (isset($days[$r['d']])) $days[$r['d']]['sales'] = (int)$r['n'];
foreach (all('SELECT substr(created_at,1,10) d, COUNT(*) n FROM leads WHERE created_at BETWEEN ? AND ? GROUP BY d', [$fromTs, $toTs]) as $r) if (isset($days[$r['d']])) $days[$r['d']]['leads'] = (int)$r['n'];

/* ---------- Par spécialité ---------------------------------------------------------------- */
$bySpe = [];
foreach (array_keys(SPECIALITES) as $slug) $bySpe[$slug] = ['spend' => 0.0, 'leads' => 0, 'sales' => 0, 'ca' => 0.0];
$bySpe['_'] = ['spend' => 0.0, 'leads' => 0, 'sales' => 0, 'ca' => 0.0];
foreach (all('SELECT COALESCE(spe, \'_\') k, SUM(spend) s FROM meta_insights WHERE date BETWEEN ? AND ? GROUP BY k', [$from, $to]) as $r) $bySpe[$r['k']]['spend'] = (float)$r['s'];
foreach (all('SELECT COALESCE(spe, \'_\') k, COUNT(*) n FROM leads WHERE created_at BETWEEN ? AND ? GROUP BY k', [$fromTs, $toTs]) as $r) $bySpe[$r['k']]['leads'] = (int)$r['n'];
foreach (all('SELECT COALESCE(spe, \'_\') k, COUNT(*) n, SUM(amount)/100.0 ca FROM sales WHERE source = \'meta_lp\' AND created_at BETWEEN ? AND ? GROUP BY k', [$fromTs, $toTs]) as $r) {
    $bySpe[$r['k']]['sales'] = (int)$r['n'];
    $bySpe[$r['k']]['ca'] = (float)$r['ca'];
}
$bySpe = array_filter($bySpe, fn($v) => $v['spend'] > 0 || $v['leads'] > 0 || $v['sales'] > 0);
uasort($bySpe, fn($a, $b) => [$b['sales'], $b['leads'], $b['spend']] <=> [$a['sales'], $a['leads'], $a['spend']]);

/* ---------- Par publicité ----------------------------------------------------------------- */
$ads = all('SELECT ad_id, MAX(ad_name) ad_name, MAX(campaign_name) campaign_name, SUM(spend) spend, SUM(impressions) impr,
                   SUM(link_clicks) clicks, SUM(landing_views) views, SUM(meta_leads) mleads, SUM(meta_purchases) mbuy
            FROM meta_insights WHERE date BETWEEN ? AND ? GROUP BY ad_id HAVING SUM(spend) > 0 ORDER BY spend DESC', [$from, $to]);

/* ---------- Listes ------------------------------------------------------------------------ */
$fStatut = (string)($_GET['statut'] ?? '');
$leadSql = 'SELECT l.*, (SELECT COUNT(*) FROM sales s WHERE s.lead_id = l.id) has_sale FROM leads l WHERE l.created_at BETWEEN ? AND ?';
$leadArgs = [$fromTs, $toTs];
if (array_key_exists($fStatut, LEAD_STATUTS)) {
    $leadSql .= ' AND l.statut = ?';
    $leadArgs[] = $fStatut;
}
$leadRows = all($leadSql . ' ORDER BY l.created_at DESC LIMIT 300', $leadArgs);
$saleRows = all('SELECT * FROM sales WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 300', [$fromTs, $toTs]);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$metaSync = kv_get('meta_last_sync');
$configured = cfg('meta_ad_account_id') && cfg('meta_access_token');

function qs(array $over): string
{
    return '?' . http_build_query(array_merge(['p' => $_GET['p'] ?? '30', 'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null, 'statut' => $_GET['statut'] ?? null], $over));
}
function fdate(string $d, bool $time = true): string
{
    $t = strtotime($d);
    return $time ? date('d/m/Y H:i', $t) : date('d/m/Y', $t);
}
$csrf = csrf_token();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Suivi Meta Ads · Somnéa</title>
<link rel="stylesheet" href="assets/admin.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
</head>
<body>
<header class="top">
  <div class="wrap top-in">
    <div class="brand"><span class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2-5 4 10 2-5h6"/></svg></span> Somnéa · Suivi Meta Ads</div>
    <div class="top-actions">
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="sync_meta">
        <button class="btn btn-ghost" <?= $configured ? '' : 'disabled title="Renseignez le compte publicitaire et le jeton dans config.php"' ?>>↻ Synchroniser Meta</button></form>
      <?php if (cfg('stripe_secret_key')): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="import_stripe">
        <button class="btn btn-ghost">⇣ Importer depuis Stripe</button></form>
      <?php endif; ?>
      <span class="who"><?= h(ucfirst(current_user())) ?></span>
      <a href="logout.php" class="btn btn-link">Déconnexion</a>
    </div>
  </div>
</header>

<main class="wrap">
  <?php if ($flash): ?><p class="alert alert-<?= h($flash[0]) ?>"><?= h($flash[1]) ?></p><?php endif; ?>

  <nav class="periods">
    <?php foreach (['7' => '7 jours', '30' => '30 jours', '90' => '90 jours', 'mois' => 'Ce mois-ci', 'prec' => 'Mois dernier'] as $k => $lbl): ?>
      <a href="<?= h(qs(['p' => $k, 'from' => null, 'to' => null])) ?>" class="<?= $p === (string)$k ? 'on' : '' ?>"><?= h($lbl) ?></a>
    <?php endforeach; ?>
    <form method="get" class="custom">
      <input type="hidden" name="p" value="perso">
      <input type="date" name="from" value="<?= h($from) ?>" aria-label="Du"> → <input type="date" name="to" value="<?= h($to) ?>" aria-label="Au">
      <button class="btn btn-ghost btn-sm">OK</button>
    </form>
    <span class="sync-info"><?= $metaSync ? 'Meta synchronisé le ' . h(fdate($metaSync)) : ($configured ? 'Meta jamais synchronisé' : 'Meta non configuré') ?></span>
  </nav>

  <!-- Chiffres clés -->
  <section class="kpis">
    <div class="kpi"><span class="lbl">Dépense Meta</span><span class="val"><?= eur($spend) ?></span><span class="sub"><?= number_format((int)$meta['clicks'], 0, ',', ' ') ?> clics · <?= number_format((int)$meta['views'], 0, ',', ' ') ?> vues de page</span></div>
    <div class="kpi"><span class="lbl">Leads (demandes de rappel)</span><span class="val"><?= $nLeads ?></span><span class="sub">Coût par lead : <?= $cpl === null ? '—' : eur($cpl) ?> · page : <?= pct($convPage) ?></span></div>
    <div class="kpi accent"><span class="lbl">Ventes via les pubs</span><span class="val"><?= $nSalesMeta ?></span><span class="sub"><?= eur($caMeta) ?> · <?= (int)$salesAll['n'] ?> vente(s) au total sur le site</span></div>
    <div class="kpi <?= $cpa !== null && $cpa <= 300 ? 'good' : ($cpa !== null ? 'warn' : '') ?>"><span class="lbl">Coût par inscription</span><span class="val"><?= $cpa === null ? '—' : eur($cpa) ?></span><span class="sub">Objectif : moins de 300 €</span></div>
    <div class="kpi"><span class="lbl">ROAS réel</span><span class="val"><?= $roas === null ? '—' : number_format($roas, 1, ',', '') . ' ×' ?></span><span class="sub">CA des pubs ÷ dépense</span></div>
    <div class="kpi"><span class="lbl">Leads → inscriptions</span><span class="val"><?= pct($convLead) ?></span><span class="sub"><?= (int)$leads['a_rappeler'] ?> lead(s) encore à rappeler</span></div>
  </section>
  <p class="note">Meta a compté <strong><?= (int)$meta['mbuy'] ?></strong> achat(s) et <strong><?= (int)$meta['mleads'] ?></strong> lead(s) sur la période. Les chiffres ci-dessus viennent de Stripe et du formulaire : ils sont plus complets, car Meta ne voit que les visiteurs ayant accepté les cookies.</p>

  <!-- Graphique -->
  <section class="card">
    <h2>Dépense et résultats par jour</h2>
    <div class="chart-box"><canvas id="chart" aria-label="Dépense Meta, leads et ventes par jour"></canvas></div>
  </section>

  <div class="grid-2">
    <!-- Par spécialité -->
    <section class="card">
      <h2>Par spécialité</h2>
      <?php if (!$bySpe): ?><p class="empty">Aucune donnée sur la période.</p><?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Spécialité</th><th class="num">Dépense</th><th class="num">Leads</th><th class="num">Ventes</th><th class="num">Coût / inscription</th></tr></thead>
        <tbody>
        <?php foreach ($bySpe as $slug => $r): ?>
          <tr><td><?= h($slug === '_' ? 'Non attribuée' : spe_label($slug)) ?></td>
              <td class="num"><?= eur($r['spend']) ?></td><td class="num"><?= $r['leads'] ?></td><td class="num"><?= $r['sales'] ?></td>
              <td class="num"><?= $r['sales'] && $r['spend'] ? eur($r['spend'] / $r['sales']) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="hint">La dépense est attribuée d'après le nom des pubs (y inclure « cardio », « orl », « dentiste »…). Les leads et ventes d'après le paramètre <code>?spe=</code> de la landing.</p>
      <?php endif; ?>
    </section>

    <!-- Par pub -->
    <section class="card">
      <h2>Par publicité</h2>
      <?php if (!$ads): ?><p class="empty"><?= $configured ? 'Aucune dépense sur la période. Lancez une synchronisation si besoin.' : 'Renseignez le compte publicitaire et le jeton Meta dans config.php.' ?></p><?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Publicité</th><th class="num">Dépense</th><th class="num">CTR</th><th class="num">Leads Meta</th><th class="num">Achats Meta</th><th class="num">Coût / lead</th></tr></thead>
        <tbody>
        <?php foreach ($ads as $a): $ctr = (int)$a['impr'] ? (int)$a['clicks'] / (int)$a['impr'] : null; ?>
          <tr><td><strong><?= h($a['ad_name']) ?></strong><span class="muted"><?= h($a['campaign_name']) ?></span></td>
              <td class="num"><?= eur((float)$a['spend']) ?></td><td class="num"><?= pct($ctr) ?></td>
              <td class="num"><?= (int)$a['mleads'] ?></td><td class="num"><?= (int)$a['mbuy'] ?></td>
              <td class="num"><?= (int)$a['mleads'] ? eur((float)$a['spend'] / (int)$a['mleads']) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
  </div>

  <!-- Leads -->
  <section class="card" id="leads">
    <div class="card-head">
      <h2>Demandes de rappel <span class="count"><?= count($leadRows) ?></span></h2>
      <nav class="chips">
        <a href="<?= h(qs(['statut' => null])) ?>#leads" class="<?= $fStatut === '' ? 'on' : '' ?>">Toutes</a>
        <?php foreach (LEAD_STATUTS as $k => $lbl): ?><a href="<?= h(qs(['statut' => $k])) ?>#leads" class="<?= $fStatut === $k ? 'on' : '' ?>"><?= h($lbl) ?></a><?php endforeach; ?>
      </nav>
    </div>
    <?php if (!$leadRows): ?><p class="empty">Aucune demande sur la période.</p><?php else: ?>
    <div class="table-wrap"><table class="leads">
      <thead><tr><th>Reçu le</th><th>Contact</th><th>Profil</th><th>Message</th><th>Suivi</th></tr></thead>
      <tbody>
      <?php foreach ($leadRows as $l): ?>
        <tr class="st-<?= h($l['statut']) ?>">
          <td class="nowrap"><?= h(fdate($l['created_at'])) ?></td>
          <td><strong><?= h($l['prenom'] . ' ' . $l['nom']) ?></strong>
              <a href="tel:<?= h(preg_replace('/[^\d+]/', '', (string)$l['telephone'])) ?>" class="muted"><?= h($l['telephone']) ?></a>
              <a href="mailto:<?= h($l['email']) ?>" class="muted"><?= h($l['email']) ?></a></td>
          <td><?= h($l['profession']) ?><span class="muted"><?= h($l['mode_exercice']) ?> · <?= h(spe_label($l['spe'])) ?></span><?php if ($l['has_sale']): ?><span class="badge">Payé</span><?php endif; ?></td>
          <td class="msg"><?= nl2br(h($l['message'])) ?></td>
          <td>
            <form method="post" class="lead-form">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="lead_update"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <select name="statut" aria-label="Statut"><?php foreach (LEAD_STATUTS as $k => $lbl): ?><option value="<?= h($k) ?>" <?= $l['statut'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select>
              <input type="text" name="note" value="<?= h($l['note']) ?>" placeholder="Note (ex. rappeler jeudi)" aria-label="Note">
              <button class="btn btn-sm btn-primary">OK</button>
            </form>
            <form method="post" onsubmit="return confirm('Supprimer définitivement ce lead ?')" class="del">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="lead_delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <button class="btn-link danger">Supprimer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <!-- Ventes -->
  <section class="card" id="ventes">
    <h2>Ventes <span class="count"><?= count($saleRows) ?></span></h2>
    <?php if (!$saleRows): ?><p class="empty">Aucune vente sur la période.</p><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Client</th><th class="num">Montant</th><th>Provenance</th><th>Lead</th></tr></thead>
      <tbody>
      <?php foreach ($saleRows as $s): ?>
        <tr><td class="nowrap"><?= h(fdate($s['created_at'])) ?></td>
            <td><strong><?= h($s['name'] ?: '—') ?></strong><span class="muted"><?= h($s['email']) ?></span></td>
            <td class="num"><?= eur($s['amount'] / 100) ?></td>
            <td><?= $s['source'] === 'meta_lp' ? '<span class="badge meta">Pub Meta</span> ' . h(spe_label($s['spe'])) : '<span class="badge">Site</span>' ?></td>
            <td><?= $s['lead_id'] ? 'Oui, après rappel' : '—' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <p class="foot">Données hébergées sur ce serveur · leads non convertis supprimés automatiquement après <?= (int)cfg('lead_retention_months') ?> mois.</p>
</main>

<script>
window.addEventListener('DOMContentLoaded', function () {
  if (!window.Chart) return;
  var days = <?= json_encode(array_keys($days)) ?>;
  var data = <?= json_encode(array_values($days)) ?>;
  var css = getComputedStyle(document.documentElement);
  new Chart(document.getElementById('chart'), {
    data: {
      labels: days.map(function (d) { var p = d.split('-'); return p[2] + '/' + p[1]; }),
      datasets: [
        { type: 'bar', label: 'Dépense Meta (€)', data: data.map(function (x) { return x.spend; }), backgroundColor: css.getPropertyValue('--c-spend').trim(), borderRadius: 4, yAxisID: 'y', order: 3 },
        { type: 'line', label: 'Leads', data: data.map(function (x) { return x.leads; }), borderColor: css.getPropertyValue('--c-leads').trim(), backgroundColor: css.getPropertyValue('--c-leads').trim(), tension: .3, pointRadius: 3, yAxisID: 'y1', order: 2 },
        { type: 'line', label: 'Ventes (pubs)', data: data.map(function (x) { return x.sales; }), borderColor: css.getPropertyValue('--c-sales').trim(), backgroundColor: css.getPropertyValue('--c-sales').trim(), borderWidth: 3, tension: .3, pointRadius: 4, yAxisID: 'y1', order: 1 }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
        y: { position: 'left', beginAtZero: true, title: { display: true, text: '€' }, grid: { color: 'rgba(10,35,66,.06)' } },
        y1: { position: 'right', beginAtZero: true, grid: { display: false }, ticks: { precision: 0 }, title: { display: true, text: 'Nombre' } }
      }
    }
  });
});
</script>
</body>
</html>
