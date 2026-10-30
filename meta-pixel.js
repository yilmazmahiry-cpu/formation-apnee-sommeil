/* Pixel Meta — Somnéa (formationsahos.fr)
 *
 * Le pixel ne se charge QUE si le visiteur a accepté la catégorie « Publicité et suivi des conversions »
 * dans le bandeau cookies (consent.js, clé localStorage « somnea_consent_v1 »).
 * - Choix déjà enregistré : chargement immédiat.
 * - Choix fait pendant la visite : chargement juste après le clic dans le bandeau ; les évènements de la page
 *   (ViewContent…) déclenchés avant le choix sont alors envoyés à ce moment-là.
 * - Refus : rien n'est chargé ni envoyé.
 *
 * Utilisation dans les pages :  somneaTrack('Lead', {content_name: '…'}, 'identifiant-unique-optionnel');
 * Le 3e argument (eventID) sert à dédoublonner avec l'API Conversions côté serveur.
 *
 * ⚠️ Si une balise Meta Pixel existe aussi dans Google Tag Manager (GTM-WKT38CDW), en garder une seule :
 * sinon chaque évènement est compté deux fois.
 */
(function () {
  'use strict';

  var PIXEL_ID = '1113341564765241';
  var KEY = 'somnea_consent_v1';
  var MAX_AGE_MS = 180 * 24 * 60 * 60 * 1000; // 6 mois, comme consent.js
  var loaded = false;
  var pending = [];

  function adsConsent() {
    try {
      var c = JSON.parse(localStorage.getItem(KEY) || 'null');
      return !!(c && c.ads && typeof c.ts === 'number' && Date.now() - c.ts < MAX_AGE_MS);
    } catch (e) {
      return false;
    }
  }

  function load() {
    if (loaded) return;
    loaded = true;
    /* Code officiel du pixel Meta */
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    window.fbq('init', PIXEL_ID);
    window.fbq('track', 'PageView');
  }

  function send(ev) {
    if (ev.eventId) window.fbq('track', ev.name, ev.params, { eventID: ev.eventId });
    else window.fbq('track', ev.name, ev.params);
  }

  function flush() {
    while (pending.length) send(pending.shift());
  }

  /* API publique pour les pages */
  window.somneaHasAdsConsent = adsConsent;

  window.somneaTrack = function (name, params, eventId) {
    var ev = { name: name, params: params || {}, eventId: eventId || null };
    if (adsConsent()) {
      load();
      send(ev);
    } else {
      pending.push(ev); // envoyé seulement si le visiteur accepte pendant sa visite
    }
  };

  /* Choix fait dans le bandeau pendant la visite */
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-sc]') : null;
    if (!b) return;
    setTimeout(function () {
      if (adsConsent()) {
        load();
        flush();
      } else {
        pending.length = 0;
      }
    }, 0);
  });

  if (adsConsent()) load();
})();
