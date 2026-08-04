// ---------- Listings rendering (grid cards + detail page) ----------
(function () {
  const cameraIcon =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3.5"/><path d="M8 5l1.5-2h5L16 5"/></svg>';

  // Radius des Umkreises auf der Objektseite, in Metern.
  const UMKREIS_METER = 500;

  /**
   * MapLibre kennt keinen Kreis mit Angabe in Metern — anders als Leaflet.
   * Deshalb wird der Kreis als Vieleck berechnet. 96 Punkte reichen, damit
   * die Kante auch bei starker Vergroesserung rund aussieht.
   *
   * Ein Grad Breite entspricht ueberall rund 111.320 Metern. Bei der Laenge
   * haengt es vom Breitengrad ab: Richtung Pol ruecken die Laengengrade
   * zusammen, daher der Kosinus. Ohne ihn waere der Kreis in Wien merklich
   * zu einem Ei in die Breite gezogen.
   */
  function kreisFlaeche(lat, lng, meter, punkte = 96) {
    const proGradBreite = 111320;
    const proGradLaenge = 111320 * Math.cos((lat * Math.PI) / 180);
    const ring = [];
    for (let i = 0; i <= punkte; i++) {
      const winkel = (i / punkte) * 2 * Math.PI;
      ring.push([
        lng + (meter * Math.cos(winkel)) / proGradLaenge,
        lat + (meter * Math.sin(winkel)) / proGradBreite,
      ]);
    }
    return { type: "Feature", geometry: { type: "Polygon", coordinates: [ring] } };
  }

  function kreisGrenzen(flaeche) {
    const ring = flaeche.geometry.coordinates[0];
    const x = ring.map((p) => p[0]);
    const y = ring.map((p) => p[1]);
    return [
      [Math.min(...x), Math.min(...y)],
      [Math.max(...x), Math.max(...y)],
    ];
  }

  function renderCard(listing) {
    // Liegt ein echtes Foto vor, steht es auf der Karte. Sonst der Farbverlauf.
    const fotos = Array.isArray(listing.images) ? listing.images : [];
    const deckblatt = fotos.length
      ? `<div class="scene has-photo" style="background-image:url('${fotos[0]}')"></div>`
      : `<div class="scene" style="background:${listing.gradient}"></div>`;

    return `
      <a class="listing-card" data-reveal data-listing-type="${listing.type}" href="immobilie.html?id=${listing.id}">
        <div class="listing-media">
          ${deckblatt}
          <span class="tag">${listing.type === "miete" ? "Miete" : "Kauf"}</span>
          <span class="price-tag">${listing.price}</span>
        </div>
        <div class="listing-body">
          <h3>${listing.title}</h3>
          <div class="loc">${listing.location}</div>
          <div class="listing-specs">
            <div><strong>${listing.area}</strong><span>Wohnfläche</span></div>
            <div><strong>${listing.rooms}</strong><span>Zimmer</span></div>
            <div><strong>${listing.baths}</strong><span>${listing.baths === "1" ? "Bad" : "Bäder"}</span></div>
          </div>
        </div>
      </a>`;
  }

  function galleryTile(gradient, opacity, extraClass, inner) {
    const cls = extraClass ? ` ${extraClass}` : "";
    return `<div class="gallery-tile${cls}" style="background:${gradient}; opacity:${opacity}">${inner}</div>`;
  }

  // Kachel mit echtem Foto. Die Restanzahl steht auf der letzten Kachel.
  function photoTile(src, extraClass, rest) {
    const cls = extraClass ? ` ${extraClass}` : "";
    const badge = rest > 0 ? `<span class="gallery-more">+${rest}</span>` : "";
    return `<div class="gallery-tile has-photo${cls}" style="background-image:url('${src}')">${badge}</div>`;
  }

  function buildPhotoGallery(images, hasVideo) {
    const plaetze = hasVideo ? 5 : 7;                 // so viele Kacheln zeigt das Raster
    const sichtbar = images.slice(0, plaetze);
    const rest = images.length - sichtbar.length;
    return sichtbar
      .map((src, i) => {
        let cls = "";
        if (i === 0) cls = "is-main";
        else if (!hasVideo && i <= 2) cls = "is-side";
        const istLetzte = i === sichtbar.length - 1;
        if (istLetzte && rest > 0) cls += " is-more";
        return photoTile(src, cls.trim(), istLetzte ? rest : 0);
      })
      .join("");
  }

  function buildGallery(gradient, hasVideo) {
    const icon = `<span class="placeholder-icon">${cameraIcon}</span>`;
    const more = `<span class="gallery-more">+</span>`;

    if (hasVideo) {
      let html = galleryTile(gradient, "1", "is-main", icon);
      for (let i = 0; i < 4; i++) {
        const isLast = i === 3;
        html += galleryTile(gradient, (0.9 - i * 0.08).toFixed(2), isLast ? "is-more" : "", isLast ? more : icon);
      }
      return html;
    }

    let html = galleryTile(gradient, "1", "is-main", icon);
    for (let i = 0; i < 2; i++) {
      html += galleryTile(gradient, (0.94 - i * 0.06).toFixed(2), "is-side", icon);
    }
    for (let i = 0; i < 4; i++) {
      const isLast = i === 3;
      html += galleryTile(gradient, (0.86 - i * 0.06).toFixed(2), isLast ? "is-more" : "", isLast ? more : icon);
    }
    return html;
  }

  function setupLightbox(galleryEl, gradient, images) {
    const lightbox = document.querySelector("[data-lightbox]");
    if (!lightbox) return;
    const tiles = [...galleryEl.querySelectorAll(".gallery-tile")];
    // Mit echten Fotos zeigt die Lightbox alle Bilder, nicht nur die
    // sichtbaren Kacheln — sonst waeren die uebrigen nicht erreichbar.
    const slides = images && images.length
      ? images.map((src) => ({ background: `url("${src}") center/contain no-repeat`, opacity: "1" }))
      : tiles.map((t) => ({ background: t.style.background, opacity: t.style.opacity || "1" }));
    const stage = lightbox.querySelector("[data-lightbox-stage]");
    const counter = lightbox.querySelector("[data-lightbox-counter]");
    const prevBtn = lightbox.querySelector("[data-lightbox-prev]");
    const nextBtn = lightbox.querySelector("[data-lightbox-next]");
    const closeBtn = lightbox.querySelector("[data-lightbox-close]");
    let current = 0;

    const show = (index) => {
      current = (index + slides.length) % slides.length;
      stage.style.background = slides[current].background;
      stage.style.opacity = slides[current].opacity;
      counter.textContent = `${current + 1} / ${slides.length}`;
    };

    const open = (index) => {
      show(index);
      lightbox.hidden = false;
      document.body.classList.add("lightbox-open");
    };

    const close = () => {
      lightbox.hidden = true;
      document.body.classList.remove("lightbox-open");
    };

    tiles.forEach((tile, i) => tile.addEventListener("click", () => open(i)));
    if (closeBtn) closeBtn.addEventListener("click", close);
    if (prevBtn) prevBtn.addEventListener("click", () => show(current - 1));
    if (nextBtn) nextBtn.addEventListener("click", () => show(current + 1));
    lightbox.addEventListener("click", (e) => {
      if (e.target === lightbox) close();
    });
    document.addEventListener("keydown", (e) => {
      if (lightbox.hidden) return;
      if (e.key === "Escape") close();
      if (e.key === "ArrowLeft") show(current - 1);
      if (e.key === "ArrowRight") show(current + 1);
    });
  }

  function notFoundMarkup() {
    return `
      <div class="property-not-found">
        <h2>Immobilie nicht gefunden</h2>
        <p class="lede" style="margin:16px auto 28px;">Dieses Objekt ist nicht mehr verfügbar oder der Link ist fehlerhaft.</p>
        <a href="immobilien.html" class="btn btn-dark">Zu allen Immobilien</a>
      </div>`;
  }

  document.querySelectorAll("[data-listings]").forEach((grid) => {
    const limit = grid.dataset.limit ? Number(grid.dataset.limit) : Infinity;
    grid.innerHTML = window.LISTINGS.slice(0, limit).map(renderCard).join("");
  });

  const detailRoot = document.querySelector("[data-property-detail]");
  if (detailRoot) {
    const id = new URLSearchParams(window.location.search).get("id");
    const listing = window.LISTINGS.find((l) => l.id === id);

    if (!listing) {
      const eyebrow = detailRoot.querySelector('[data-field="type"]');
      const title = detailRoot.querySelector('[data-field="title"]');
      const location = detailRoot.querySelector('[data-field="location"]');
      const crumb = detailRoot.querySelector('[data-field="title-crumb"]');
      if (eyebrow) eyebrow.style.display = "none";
      if (location) location.style.display = "none";
      if (title) title.textContent = "Immobilie nicht gefunden";
      if (crumb) crumb.textContent = "Nicht gefunden";
      const body = document.querySelector("[data-property-body]");
      if (body) body.innerHTML = notFoundMarkup();
    } else {
      document.title = `${listing.title} — PUTZ Real Estate`;

      const setText = (field, value) => {
        const el = detailRoot.querySelector(`[data-field="${field}"]`);
        if (el) el.textContent = value;
      };

      setText("type", listing.type === "miete" ? "Miete" : "Kauf");
      setText("title", listing.title);
      setText("title-crumb", listing.title);
      setText("location", listing.location);
      setText("area", listing.area);
      setText("rooms", listing.rooms);
      setText("baths", listing.baths);
      setText("baths-label", listing.baths === "1" ? "Bad" : "Bäder");
      setText("price", listing.price);

      // Eckdaten auf dem Deckblatt
      setText("hero-price", listing.price);
      setText("hero-area", listing.area);
      setText("hero-rooms", listing.rooms);

      // Deckblatt: erstes Objektfoto als Hintergrund. Fehlen Fotos,
      // bleibt der bisherige dunkle Verlauf stehen.
      const heroPhoto = detailRoot.querySelector('[data-field="hero-photo"]');
      const bilder = Array.isArray(listing.images) ? listing.images : [];
      if (heroPhoto && bilder.length) {
        heroPhoto.style.backgroundImage = `url("${bilder[0]}")`;
        heroPhoto.hidden = false;
        const heroSection = heroPhoto.closest(".property-hero");
        if (heroSection) heroSection.classList.add("has-photo");
      }

      const hasVideo = !!listing.video;

      const galleryEl = detailRoot.querySelector('[data-field="gallery"]');
      if (galleryEl) {
        galleryEl.classList.toggle("is-wide-layout", !hasVideo);
        galleryEl.innerHTML = bilder.length
          ? buildPhotoGallery(bilder, hasVideo)
          : buildGallery(listing.gradient, hasVideo);
        setupLightbox(galleryEl, listing.gradient, bilder);
      }

      const descEl = detailRoot.querySelector('[data-field="description"]');
      // Beschreibungen aus Justimmo bringen ihre eigenen Absaetze mit —
      // nur reiner Text wird hier noch in <p> gefasst.
      const alsAbsatz = (t) => (/<(p|ul|ol|h[1-6]|div)[\s>]/i.test(t) ? t : `<p>${t}</p>`);
      if (descEl) descEl.innerHTML = listing.description.map(alsAbsatz).join("");

      // Karte: liegen Koordinaten vor, zeichnen wir den Umkreis von
      // 500 Metern um die Adresse. Ohne Koordinaten bleibt es bei der
      // einfachen Google-Karte, die nur nach dem Ort suchen kann.
      const mapEl = detailRoot.querySelector('[data-field="map"]');
      if (mapEl && listing.lat && listing.lng && window.maplibregl) {
        const ziel = document.createElement("div");
        ziel.className = mapEl.className + " map-umkreis";
        mapEl.replaceWith(ziel);

        const flaeche = kreisFlaeche(listing.lat, listing.lng, UMKREIS_METER);

        const karte = new window.maplibregl.Map({
          container: ziel,
          style: "https://tiles.openfreemap.org/styles/dark",
          center: [listing.lng, listing.lat],
          zoom: 13.4,
          scrollZoom: false,   // sonst bleibt man beim Scrollen in der Karte haengen
          attributionControl: false,
        });

        // Den von OpenFreeMap geforderten Nachweis liefert die Kachelquelle
        // selbst mit — nur die kompakte Darstellung wird hier gewaehlt.
        karte.addControl(new window.maplibregl.AttributionControl({ compact: true }));
        karte.addControl(new window.maplibregl.NavigationControl({ showCompass: false }), "top-left");

        karte.on("load", () => {
          karte.addSource("umkreis", { type: "geojson", data: flaeche });
          karte.addLayer({
            id: "umkreis-flaeche",
            type: "fill",
            source: "umkreis",
            paint: { "fill-color": "#fbe48b", "fill-opacity": 0.13 },
          });
          karte.addLayer({
            id: "umkreis-rand",
            type: "line",
            source: "umkreis",
            paint: { "line-color": "#fbe48b", "line-width": 2 },
          });
          karte.fitBounds(kreisGrenzen(flaeche), { padding: 26, duration: 0 });
        });
      } else if (mapEl && listing.mapQuery) {
        mapEl.src = `https://www.google.com/maps?q=${encodeURIComponent(listing.mapQuery)}&output=embed`;
      }

      const videoWrap = detailRoot.querySelector('[data-field="video-wrap"]');
      const topEl = detailRoot.querySelector(".property-top");
      if (videoWrap && hasVideo) {
        videoWrap.hidden = false;
        const player = videoWrap.querySelector(".video-feature-player");

        if (listing.video.einbettung) {
          // Video liegt bei YouTube/Vimeo. Hochkant-Videos bekommen einen
          // hochkanten Rahmen, sonst blieben links und rechts Balken.
          if (listing.video.hochformat) player.classList.add("is-portrait");
          const rahmen = document.createElement("iframe");
          rahmen.src = listing.video.einbettung;
          rahmen.title = "Video-Rundgang";
          rahmen.loading = "lazy";
          rahmen.allow = "accelerometer; encrypted-media; picture-in-picture; fullscreen";
          rahmen.allowFullscreen = true;
          // Kein "no-referrer": YouTube prueft die einbettende Domain und
          // verweigert sonst mit "Fehler 153".
          rahmen.referrerPolicy = "strict-origin-when-cross-origin";
          player.replaceChildren(rahmen);
        } else {
          // Datei liegt bei uns oder bei Justimmo — direkt abspielen.
          const videoEl = videoWrap.querySelector("video");
          const sourceEl = videoWrap.querySelector("source");
          if (listing.video.orientation === "portrait") player.classList.add("is-portrait");
          if (listing.video.poster) videoEl.setAttribute("poster", listing.video.poster);
          // Bei Videos aus Justimmo ist das Format vorab nicht bekannt.
          // Sobald die Abmessungen da sind, richtet sich der Rahmen danach —
          // sonst wuerde ein Hochkant-Video beschnitten.
          videoEl.addEventListener("loadedmetadata", () => {
            if (videoEl.videoHeight > videoEl.videoWidth) player.classList.add("is-portrait");
          });
          sourceEl.setAttribute("src", listing.video.src);
          videoEl.load();
        }
      } else if (topEl) {
        topEl.classList.add("no-video");
      }

      const subjectField = detailRoot.querySelector('[data-field="form-subject"]');
      if (subjectField) subjectField.value = listing.title;

      // Nur Objekte aus Justimmo tragen eine Objektnummer. Bei handgepflegten
      // bleibt das Feld leer — die Anfrage kommt dann nur per E-Mail.
      const jiFeld = detailRoot.querySelector('[data-field="justimmo-id"]');
      if (jiFeld) jiFeld.value = listing.objektId || "";
    }
  }
})();
