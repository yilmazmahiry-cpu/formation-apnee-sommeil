/* Bandeau de consentement aux cookies — Somnéa (Nova Learning)
 *
 * - Aucune balise publicitaire ou de mesure ne doit s'exécuter avant le choix du visiteur : le contexte de
 *   consentement (Google Consent Mode v2) est positionné sur « refusé » par défaut, avant le chargement de
 *   Google Tag Manager (voir le petit script placé dans <head> de chaque page).
 * - Le choix est mémorisé 6 mois dans le navigateur (localStorage), puis redemandé.
 * - « Tout refuser » est aussi simple et visible que « Tout accepter ».
 * - Le lien « Gérer mes cookies » (attribut data-cookie-settings) permet de modifier ou retirer son choix à tout moment.
 *
 * Dans Google Tag Manager : déclencher les balises Meta (pixel) et Google Ads sur l'évènement « consent_update »
 * avec la variable de couche de données consent_ads = true (mesure d'audience : consent_analytics = true).
 */
(function () {
  'use strict';

  var KEY = 'somnea_consent_v1';
  var MAX_AGE_MS = 180 * 24 * 60 * 60 * 1000; // 6 mois
  var dl = (window.dataLayer = window.dataLayer || []);
  function gtag() { dl.push(arguments); }

  function read() {
    try {
      var c = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (c && typeof c.ts === 'number' && Date.now() - c.ts < MAX_AGE_MS) return c;
    } catch (e) { /* stockage indisponible */ }
    return null;
  }
  function write(c) {
    try { localStorage.setItem(KEY, JSON.stringify(c)); } catch (e) { /* on continue sans mémorisation */ }
  }
  function apply(c) {
    var ads = c.ads ? 'granted' : 'denied';
    gtag('consent', 'update', {
      analytics_storage: c.analytics ? 'granted' : 'denied',
      ad_storage: ads,
      ad_user_data: ads,
      ad_personalization: ads
    });
    dl.push({ event: 'consent_update', consent_analytics: !!c.analytics, consent_ads: !!c.ads });
  }

  var root = null;
  var lastFocus = null;

  function el(html) {
    var d = document.createElement('div');
    d.innerHTML = html;
    return d.firstElementChild;
  }

  function build() {
    root = el(
      '<div class="sc" id="sc-root" hidden>' +
      '<div class="sc-card" role="dialog" aria-modal="false" aria-labelledby="sc-title" aria-describedby="sc-text" tabindex="-1">' +
      '<div data-view="intro">' +
      '<p class="sc-title" id="sc-title">Cookies et confidentialité</p>' +
      '<p class="sc-text" id="sc-text">Avec votre accord, nous utilisons des cookies pour mesurer l\'audience de ce site et l\'efficacité de nos publicités (Google, Meta). Aucun cookie de ce type n\'est déposé sans votre choix, et vous pouvez changer d\'avis à tout moment grâce au lien « Gérer mes cookies » en bas de page. <a href="confidentialite">En savoir plus</a></p>' +
      '<div class="sc-actions">' +
      '<button type="button" class="sc-btn sc-btn-main" data-sc="refuse">Tout refuser</button>' +
      '<button type="button" class="sc-btn sc-btn-main" data-sc="accept">Tout accepter</button>' +
      '<button type="button" class="sc-btn sc-btn-ghost" data-sc="custom">Personnaliser</button>' +
      '</div></div>' +
      '<div data-view="detail" class="sc-detail" hidden>' +
      '<p class="sc-title">Vos choix</p>' +
      '<p class="sc-note">Votre choix est mémorisé 6 mois sur cet appareil. Aucun cookie strictement nécessaire soumis à votre accord n\'est utilisé sur ce site.</p>' +
      '<div class="sc-cat"><div><h3>Mesure d\'audience</h3><p>Nous aide à comprendre comment le site est utilisé (pages vues, parcours) afin de l\'améliorer. Fournisseur : Google.</p></div>' +
      '<label class="sc-switch"><input type="checkbox" data-cat="analytics" aria-label="Mesure d\'audience"><span></span></label></div>' +
      '<div class="sc-cat"><div><h3>Publicité et suivi des conversions</h3><p>Mesure l\'efficacité de nos annonces et permet de vous présenter nos formations sur d\'autres sites et réseaux sociaux. Fournisseurs : Google (Google Ads), Meta (Facebook, Instagram).</p></div>' +
      '<label class="sc-switch"><input type="checkbox" data-cat="ads" aria-label="Publicité et suivi des conversions"><span></span></label></div>' +
      '<div class="sc-actions">' +
      '<button type="button" class="sc-btn sc-btn-main" data-sc="save">Enregistrer mes choix</button>' +
      '<button type="button" class="sc-btn sc-btn-ghost" data-sc="refuse">Tout refuser</button>' +
      '<button type="button" class="sc-btn sc-btn-ghost" data-sc="accept">Tout accepter</button>' +
      '</div></div>' +
      '</div></div>'
    );
    document.body.appendChild(root);
    root.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-sc]') : null;
      if (!b) return;
      var a = b.getAttribute('data-sc');
      if (a === 'accept') choose({ analytics: true, ads: true });
      else if (a === 'refuse') choose({ analytics: false, ads: false });
      else if (a === 'custom') showView('detail');
      else if (a === 'save') {
        choose({
          analytics: root.querySelector('[data-cat="analytics"]').checked,
          ads: root.querySelector('[data-cat="ads"]').checked
        });
      }
    });
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && read()) close(); // fermeture possible seulement si un choix existe déjà
    });
  }

  function showView(name) {
    var views = root.querySelectorAll('[data-view]');
    for (var i = 0; i < views.length; i++) views[i].hidden = views[i].getAttribute('data-view') !== name;
    var card = root.querySelector('.sc-card');
    card.scrollTop = 0;
    var first = root.querySelector('[data-view="' + name + '"] button');
    if (first) first.focus();
  }

  function open(view) {
    if (!root) build();
    var c = read() || { analytics: false, ads: false };
    root.querySelector('[data-cat="analytics"]').checked = !!c.analytics;
    root.querySelector('[data-cat="ads"]').checked = !!c.ads;
    lastFocus = document.activeElement;
    root.hidden = false;
    showView(view || 'intro');
  }

  function close() {
    if (root) root.hidden = true;
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* ignore */ } }
  }

  function choose(c) {
    var rec = { v: 1, ts: Date.now(), analytics: !!c.analytics, ads: !!c.ads };
    write(rec);
    apply(rec);
    close();
  }

  function init() {
    var links = document.querySelectorAll('[data-cookie-settings]');
    for (var i = 0; i < links.length; i++) {
      links[i].addEventListener('click', function (e) { e.preventDefault(); open('detail'); });
    }
    var existing = read();
    if (existing) apply(existing);   // restitue le choix mémorisé à Google Tag Manager
    else open('intro');
  }

  window.somneaConsent = { open: function () { open('detail'); } };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
