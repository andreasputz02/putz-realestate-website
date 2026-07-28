// ---------- Listings rendering (grid cards + detail page) ----------
(function () {
  const cameraIcon =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3.5"/><path d="M8 5l1.5-2h5L16 5"/></svg>';

  function renderCard(listing) {
    return `
      <a class="listing-card" data-reveal data-listing-type="${listing.type}" href="immobilie.html?id=${listing.id}">
        <div class="listing-media">
          <div class="scene" style="background:${listing.gradient}"></div>
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

  function buildGallery(gradient) {
    let html = `<div class="gallery-tile is-main" style="background:${gradient}"><span class="placeholder-icon">${cameraIcon}</span></div>`;
    for (let i = 0; i < 4; i++) {
      const isLast = i === 3;
      const opacity = (0.9 - i * 0.08).toFixed(2);
      const inner = isLast ? `<span class="gallery-more">+</span>` : `<span class="placeholder-icon">${cameraIcon}</span>`;
      html += `<div class="gallery-tile${isLast ? " is-more" : ""}" style="background:${gradient}; opacity:${opacity}">${inner}</div>`;
    }
    return html;
  }

  function setupLightbox(galleryEl, gradient) {
    const lightbox = document.querySelector("[data-lightbox]");
    if (!lightbox) return;
    const tiles = [...galleryEl.querySelectorAll(".gallery-tile")];
    const slides = tiles.map((t) => ({ background: t.style.background, opacity: t.style.opacity || "1" }));
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

      const galleryEl = detailRoot.querySelector('[data-field="gallery"]');
      if (galleryEl) {
        galleryEl.innerHTML = buildGallery(listing.gradient);
        setupLightbox(galleryEl, listing.gradient);
      }

      const descEl = detailRoot.querySelector('[data-field="description"]');
      if (descEl) descEl.innerHTML = listing.description.map((p) => `<p>${p}</p>`).join("");

      const videoWrap = detailRoot.querySelector('[data-field="video-wrap"]');
      const topEl = detailRoot.querySelector(".property-top");
      if (videoWrap && listing.video) {
        videoWrap.hidden = false;
        const player = videoWrap.querySelector(".video-feature-player");
        const videoEl = videoWrap.querySelector("video");
        const sourceEl = videoWrap.querySelector("source");
        if (listing.video.orientation === "portrait") player.classList.add("is-portrait");
        videoEl.setAttribute("poster", listing.video.poster);
        sourceEl.setAttribute("src", listing.video.src);
        videoEl.load();
      } else if (topEl) {
        topEl.classList.add("no-video");
      }

      const subjectField = detailRoot.querySelector('[data-field="form-subject"]');
      if (subjectField) subjectField.value = listing.title;
    }
  }
})();
