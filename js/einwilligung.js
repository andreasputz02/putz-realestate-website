// ============================================================
//  Klick-Sperre fuer eingebettete Inhalte von Dritten
//
//  YouTube-Videos und Google-Karten bauen beim Laden eine Verbindung
//  zu Google auf und uebertragen dabei die IP-Adresse des Besuchers.
//  Ohne Einwilligung ist das nach DSGVO nicht zulaessig.
//
//  Deshalb wird an ihrer Stelle zunaechst eine Vorschau angezeigt.
//  Erst ein Klick laedt den Inhalt wirklich nach. Die Entscheidung
//  gilt fuer die laufende Sitzung, damit nicht jede Unterseite
//  erneut fragt — sie wird bewusst NICHT dauerhaft gespeichert.
//
//  Verwendung:
//      einwilligungsRahmen({
//        dienst: "youtube",          // oder "maps"
//        src:    "https://…",        // Adresse des Rahmens
//        titel:  "Video-Rundgang",
//        vorschauBild: "…jpg"        // optional, muss von uns stammen
//      })  ->  liefert ein Element zum Einhaengen
// ============================================================

(function () {
  "use strict";

  const TEXTE = {
    youtube: {
      name: "YouTube",
      anbieter: "Google Ireland Limited",
      hinweis: "Zum Abspielen wird das Video von YouTube geladen. Dabei erfährt Google Ihre IP-Adresse.",
      knopf: "Video laden",
    },
    maps: {
      name: "Google Maps",
      anbieter: "Google Ireland Limited",
      hinweis: "Zum Anzeigen wird die Karte von Google Maps geladen. Dabei erfährt Google Ihre IP-Adresse.",
      knopf: "Karte laden",
    },
  };

  const merker = {};   // gilt nur fuer diesen Seitenbesuch

  function rahmenBauen(einstellung) {
    const el = document.createElement("iframe");
    el.src = einstellung.src;
    el.title = einstellung.titel || "";
    el.loading = "lazy";
    el.allow = "accelerometer; encrypted-media; picture-in-picture; fullscreen";
    el.allowFullscreen = true;
    // Kein "no-referrer": YouTube prueft die einbettende Domain und
    // verweigert sonst die Wiedergabe (Fehler 153).
    el.referrerPolicy = "strict-origin-when-cross-origin";
    return el;
  }

  window.einwilligungsRahmen = function (einstellung) {
    const t = TEXTE[einstellung.dienst] || TEXTE.youtube;
    const huelle = document.createElement("div");
    huelle.className = "einwilligung";

    // Schon in dieser Sitzung zugestimmt: direkt laden.
    if (merker[einstellung.dienst]) {
      huelle.appendChild(rahmenBauen(einstellung));
      return huelle;
    }

    if (einstellung.vorschauBild) {
      huelle.style.backgroundImage = `url("${einstellung.vorschauBild}")`;
      huelle.classList.add("hat-vorschau");
    }

    const karte = document.createElement("div");
    karte.className = "einwilligung-karte";
    karte.innerHTML =
      `<p class="einwilligung-dienst">${t.name}</p>` +
      `<p class="einwilligung-text">${t.hinweis}</p>` +
      `<button type="button" class="btn btn-gold einwilligung-knopf">${t.knopf}</button>` +
      `<p class="einwilligung-fuss">Anbieter: ${t.anbieter} · ` +
      `<a href="datenschutz.html">Datenschutzerklärung</a></p>`;

    karte.querySelector(".einwilligung-knopf").addEventListener("click", () => {
      merker[einstellung.dienst] = true;
      huelle.classList.remove("hat-vorschau");
      huelle.style.backgroundImage = "";
      huelle.replaceChildren(rahmenBauen(einstellung));
    });

    huelle.appendChild(karte);
    return huelle;
  };

  // Fest im HTML stehende Einbettungen: Platzhalter mit
  //   data-einwilligung="maps" data-src="…" data-titel="…"
  // werden hier automatisch durch die Sperre ersetzt.
  function platzhalterErsetzen() {
    document.querySelectorAll("[data-einwilligung]").forEach((el) => {
      const sperre = window.einwilligungsRahmen({
        dienst: el.dataset.einwilligung,
        src: el.dataset.src,
        titel: el.dataset.titel || "",
        vorschauBild: el.dataset.vorschau || "",
      });
      sperre.className = el.className + " einwilligung";
      el.replaceWith(sperre);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", platzhalterErsetzen);
  } else {
    platzhalterErsetzen();
  }
})();
