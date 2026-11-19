/* Statistiques de visites Somnéa — sans cookie, sans stockage dans le navigateur.
 * Envoie à l'admin de suivi (admin.formationsahos.fr) :
 *   - une page vue à l'arrivée sur la page ;
 *   - le temps réellement passé sur la page (onglet visible) quand le visiteur la quitte.
 * Aucune donnée personnelle : l'admin ne conserve ni l'adresse IP ni d'identifiant durable (voir hit.php).
 * À inclure sur chaque page :  <script src="stats.js" defer></script>
 */
(function () {
  'use strict';
  var URL_ADMIN = 'https://admin.formationsahos.fr/hit.php';
  try {
    if (navigator.webdriver) return;                       // navigateurs automatisés

    function send(data) {
      var body = JSON.stringify(data);
      try {
        if (navigator.sendBeacon && navigator.sendBeacon(URL_ADMIN, new Blob([body], { type: 'text/plain;charset=UTF-8' }))) return;
      } catch (e) { /* on tente fetch */ }
      try {
        fetch(URL_ADMIN, { method: 'POST', body: body, keepalive: true, mode: 'cors',
          headers: { 'Content-Type': 'text/plain;charset=UTF-8' } }).catch(function () {});
      } catch (e) { /* jamais bloquant */ }
    }

    // Identifiant de cette page vue, gardé en mémoire uniquement (perdu à la fermeture de la page)
    var id = (Date.now().toString(36) + Math.random().toString(36).slice(2, 10)).slice(0, 20);
    var qs = new URLSearchParams(location.search), q = {};
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'spe', 'zone'].forEach(function (k) {
      var v = qs.get(k); if (v) q[k] = v.slice(0, 120);
    });
    send({ t: 'pv', id: id, p: location.pathname, r: document.referrer || '', q: q });

    // Temps actif : on ne compte que lorsque la page est visible
    var active = 0, since = document.visibilityState === 'visible' ? Date.now() : null, lastSent = -1;
    function flush() {
      if (since !== null) { active += Date.now() - since; since = null; }
      var s = Math.round(active / 1000);
      if (s !== lastSent && s > 0) { lastSent = s; send({ t: 'end', id: id, d: s }); }
    }
    function tick() {                                    // point d'étape : rien n'est perdu si la page se ferme brutalement
      if (since === null) return;
      var now = Date.now(); active += now - since; since = now;
      var s = Math.round(active / 1000);
      if (s >= 5 && s !== lastSent) { lastSent = s; send({ t: 'end', id: id, d: s }); }
    }
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') flush();
      else since = Date.now();
    });
    window.addEventListener('pagehide', flush);
    var timer = setInterval(tick, 15000);
    setTimeout(function () { clearInterval(timer); }, 30 * 60 * 1000); // au-delà de 30 min, on s'arrête
  } catch (e) { /* jamais bloquant */ }
})();
