// ---------- Header scroll state ----------
const header = document.querySelector(".site-header");
const onScroll = () => {
  if (!header) return;
  header.classList.toggle("is-scrolled", window.scrollY > 40);
};
onScroll();
window.addEventListener("scroll", onScroll, { passive: true });

// ---------- Mobile nav ----------
const navToggle = document.querySelector(".nav-toggle");
const mobileMenu = document.querySelector(".mobile-menu");

if (navToggle && mobileMenu) {
  // Die Hoehe regelt das CSS selbst (height:auto + max-height-Uebergang).
  // Kein Messen per JS noetig — dadurch bleibt unten nie Leerraum stehen,
  // auch nicht wenn die Web-Schrift nachlaedt oder eine Gruppe aufklappt.
  const setOpen = (isOpen) => {
    mobileMenu.classList.toggle("is-open", isOpen);
    document.body.classList.toggle("nav-open", isOpen);
    navToggle.classList.toggle("is-active", isOpen);
    navToggle.setAttribute("aria-expanded", String(isOpen));
  };

  navToggle.setAttribute("aria-expanded", "false");
  navToggle.addEventListener("click", () => {
    setOpen(!mobileMenu.classList.contains("is-open"));
  });

  mobileMenu.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => setOpen(false));
  });

  document.addEventListener("click", (e) => {
    if (!mobileMenu.classList.contains("is-open")) return;
    if (mobileMenu.contains(e.target) || navToggle.contains(e.target)) return;
    setOpen(false);
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && mobileMenu.classList.contains("is-open")) setOpen(false);
  });
}

// ---------- Mobile nav collapsible group (Über Uns) ----------
document.querySelectorAll(".mobile-nav-group").forEach((group) => {
  const toggle = group.querySelector(".mobile-nav-toggle");
  const panel = group.querySelector(".mobile-nav-panel");
  if (!toggle || !panel) return;

  toggle.addEventListener("click", () => {
    const isOpen = group.classList.toggle("open");
    toggle.setAttribute("aria-expanded", String(isOpen));
    panel.style.maxHeight = isOpen ? panel.scrollHeight + "px" : null;
  });
});

// ---------- Scroll reveal ----------
const revealEls = document.querySelectorAll("[data-reveal]");
if ("IntersectionObserver" in window && revealEls.length) {
  const io = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("in-view");
          io.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.14, rootMargin: "0px 0px -60px 0px" }
  );
  revealEls.forEach((el) => io.observe(el));
} else {
  revealEls.forEach((el) => el.classList.add("in-view"));
}

// ---------- Accordion ----------
document.querySelectorAll(".accordion-item").forEach((item) => {
  const head = item.querySelector(".accordion-head");
  const panel = item.querySelector(".accordion-panel");
  if (!head || !panel) return;

  head.addEventListener("click", () => {
    const isOpen = item.classList.contains("open");

    item.parentElement.querySelectorAll(".accordion-item.open").forEach((openItem) => {
      if (openItem !== item) {
        openItem.classList.remove("open");
        openItem.querySelector(".accordion-panel").style.maxHeight = null;
      }
    });

    item.classList.toggle("open", !isOpen);
    panel.style.maxHeight = !isOpen ? panel.scrollHeight + "px" : null;
  });
});

// ---------- Listing filters ----------
const filterButtons = document.querySelectorAll(".listing-filters button");
const listingCards = document.querySelectorAll("[data-listing-type]");

filterButtons.forEach((btn) => {
  btn.addEventListener("click", () => {
    filterButtons.forEach((b) => b.classList.remove("active"));
    btn.classList.add("active");
    const filter = btn.dataset.filter;

    listingCards.forEach((card) => {
      const show = filter === "all" || card.dataset.listingType === filter;
      card.style.display = show ? "" : "none";
    });
  });
});


// ---------- Track sliders (video + testimonials) ----------
function setupTrackSlider(track, gap) {
  const wrap = track.parentElement;
  if (!wrap) return;
  const prevBtns = [...wrap.querySelectorAll('[data-dir="-1"]')];
  const nextBtns = [...wrap.querySelectorAll('[data-dir="1"]')];
  const dots = [...wrap.querySelectorAll(".slider-dot")];

  const findSlide = () => {
    for (const el of track.querySelectorAll("*")) {
      if (getComputedStyle(el).scrollSnapAlign !== "none") return el;
    }
    return track.firstElementChild;
  };

  const slideAmount = () => {
    const slide = findSlide();
    return (slide ? slide.getBoundingClientRect().width : track.clientWidth) + gap;
  };

  const scrollByDir = (dir) => track.scrollBy({ left: dir * slideAmount(), behavior: "smooth" });
  const scrollToIndex = (index) => track.scrollTo({ left: index * slideAmount(), behavior: "smooth" });

  prevBtns.forEach((btn) => btn.addEventListener("click", () => scrollByDir(-1)));
  nextBtns.forEach((btn) => btn.addEventListener("click", () => scrollByDir(1)));

  dots.forEach((dot) => {
    dot.addEventListener("click", () => scrollToIndex(Number(dot.dataset.index)));
  });

  const updateArrows = () => {
    const maxScroll = track.scrollWidth - track.clientWidth - 4;
    const atStart = track.scrollLeft <= 4;
    const atEnd = track.scrollLeft >= maxScroll;
    prevBtns.forEach((btn) => { btn.disabled = atStart; });
    nextBtns.forEach((btn) => { btn.disabled = atEnd; });

    if (dots.length) {
      const activeIndex = Math.round(track.scrollLeft / slideAmount());
      dots.forEach((dot) => dot.classList.toggle("is-active", Number(dot.dataset.index) === activeIndex));
    }
  };
  track.addEventListener("scroll", updateArrows, { passive: true });
  window.addEventListener("resize", updateArrows);
  updateArrows();
}

document.querySelectorAll(".video-slider-track, .testimonial-slider-track, .team-slider-track, .reel-track").forEach((track) => setupTrackSlider(track, 28));


// ---------- Reels: automatisch abspielen, sobald die Sektion sichtbar wird ----------
(function () {
  const reels = document.querySelectorAll(".reel-card video");
  if (!reels.length) return;

  // Browser erlauben automatisches Abspielen nur ohne Ton.
  reels.forEach((v) => {
    v.muted = true;
    v.loop = true;
    v.playsInline = true;
  });

  // Bei reduzierter Bewegung nichts von selbst starten.
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    reels.forEach((v) => v.setAttribute("controls", ""));
    return;
  }

  if (!("IntersectionObserver" in window)) {
    reels.forEach((v) => v.play().catch(() => {}));
    return;
  }

  const io = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        const v = entry.target;
        if (entry.isIntersecting) {
          v.play().catch(() => {}); // Scheitert z.B. im Energiesparmodus — kein Grund für einen Fehler.
        } else {
          v.pause();
        }
      });
    },
    { threshold: 0.4 }
  );
  reels.forEach((v) => io.observe(v));

  // Ton-Schalter: immer nur ein Reel darf hörbar sein.
  document.querySelectorAll(".reel-sound").forEach((btn) => {
    btn.addEventListener("click", () => {
      const card = btn.closest(".reel-card");
      const video = card && card.querySelector("video");
      if (!video) return;
      const turnOn = video.muted;
      reels.forEach((v) => {
        v.muted = true;
        const b = v.closest(".reel-card").querySelector(".reel-sound");
        if (b) {
          b.classList.remove("is-on");
          b.setAttribute("aria-label", "Ton einschalten");
        }
      });
      if (turnOn) {
        video.muted = false;
        btn.classList.add("is-on");
        btn.setAttribute("aria-label", "Ton ausschalten");
        video.play().catch(() => {});
      }
    });
  });
})();

// ---------- Generic modal ----------
function openModal(name) {
  const modal = document.querySelector(`[data-modal="${name}"]`);
  if (!modal) return;
  modal.hidden = false;
  document.body.classList.add("modal-open");
}
function closeModal(modal) {
  modal.hidden = true;
  document.body.classList.remove("modal-open");
}
document.querySelectorAll("[data-modal-open]").forEach((btn) => {
  btn.addEventListener("click", () => openModal(btn.dataset.modalOpen));
});
document.querySelectorAll("[data-modal]").forEach((modal) => {
  modal.addEventListener("click", (e) => {
    if (e.target === modal) closeModal(modal);
  });
  modal.querySelectorAll("[data-modal-close]").forEach((btn) => {
    btn.addEventListener("click", () => closeModal(modal));
  });
});
document.addEventListener("keydown", (e) => {
  if (e.key !== "Escape") return;
  document.querySelectorAll("[data-modal]:not([hidden])").forEach(closeModal);
});

// ---------- Forms (send via send-mail.php) ----------
document.querySelectorAll("form[data-contact-form]").forEach((form) => {
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    const status = form.querySelector(".form-status");
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    fetch("send-mail.php", { method: "POST", body: new FormData(form) })
      .then((res) => res.json().catch(() => ({ ok: false })))
      .then((data) => {
        if (!status) return;
        status.classList.remove("error", "success");
        if (data.ok) {
          status.textContent = "Danke! Deine Nachricht wurde erfolgreich gesendet — wir melden uns bei dir.";
          status.classList.add("show", "success");
          form.reset();
        } else {
          status.textContent = "Es gab ein Problem beim Senden. Bitte versuch es erneut oder ruf uns kurz an.";
          status.classList.add("show", "error");
        }
      })
      .catch(() => {
        if (!status) return;
        status.classList.remove("success");
        status.textContent = "Es gab ein Problem beim Senden. Bitte versuch es erneut oder ruf uns kurz an.";
        status.classList.add("show", "error");
      })
      .finally(() => {
        if (submitBtn) submitBtn.disabled = false;
      });
  });
});

// ---------- Link-Vorschau beim Hover (Partner) ----------
// Zeigt beim Überfahren eines Links eine Vorschaukarte, die der Maus leicht folgt.
(function () {
  const triggers = document.querySelectorAll("[data-peek-src]");
  if (!triggers.length) return;

  // Auf Touch-Geräten und bei reduzierter Bewegung: gar nicht erst aufbauen.
  const finePointer = window.matchMedia("(hover: hover) and (pointer: fine)");
  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
  if (!finePointer.matches || reducedMotion.matches) return;

  const peek = document.createElement("div");
  peek.className = "link-peek";
  peek.innerHTML =
    '<div class="link-peek-frame">' +
    '<img alt="" aria-hidden="true">' +
    "</div>" +
    '<span class="link-peek-label"></span>';
  document.body.appendChild(peek);

  const baseImg = peek.querySelector(".link-peek-frame > img");
  const label = peek.querySelector(".link-peek-label");

  let hideTimer = null;
  let targetX = 0, targetY = 0, currentX = 0, currentY = 0;
  let rafId = null;

  // Sanftes Nachziehen der Karte (Feder-Effekt ohne Bibliothek).
  function animate() {
    currentX += (targetX - currentX) * 0.16;
    currentY += (targetY - currentY) * 0.16;
    peek.style.left = currentX + "px";
    peek.style.top = currentY + "px";
    if (Math.abs(targetX - currentX) > 0.4 || Math.abs(targetY - currentY) > 0.4) {
      rafId = requestAnimationFrame(animate);
    } else {
      rafId = null;
    }
  }

  function position(e, instant) {
    const w = peek.offsetWidth || 232;
    const h = peek.offsetHeight || 175;
    // Über dem Cursor platzieren, am Viewport-Rand einklemmen.
    let x = e.clientX - w / 2;
    let y = e.clientY - h - 18;
    x = Math.max(10, Math.min(x, window.innerWidth - w - 10));
    if (y < 10) y = e.clientY + 24;
    targetX = x;
    targetY = y;
    if (instant) {
      currentX = x;
      currentY = y;
      peek.style.left = x + "px";
      peek.style.top = y + "px";
    } else if (!rafId) {
      rafId = requestAnimationFrame(animate);
    }
  }

  triggers.forEach((trigger) => {
    trigger.addEventListener("mouseenter", (e) => {
      clearTimeout(hideTimer);
      const src = trigger.dataset.peekSrc;
      const text = trigger.dataset.peekLabel || "";
      if (baseImg.getAttribute("src") !== src) {
        baseImg.src = src;
      }
      label.textContent = text;
      position(e, true);
      // Erst im nächsten Frame öffnen, damit die Startposition nicht mitanimiert wird.
      requestAnimationFrame(() => peek.classList.add("is-open"));
    });

    trigger.addEventListener("mousemove", (e) => {
      position(e, false);
    });

    trigger.addEventListener("mouseleave", () => {
      hideTimer = setTimeout(() => peek.classList.remove("is-open"), 90);
    });
  });
})();

/* ============================================================
   ZAHLEN — Scroll-Übergang zur Über-Uns-Section

   Der Abschnitt ist mehrere Bildschirmhöhen hoch; der Innenbereich
   klebt oben fest. Diese zusätzliche Höhe ist die Strecke, über die
   die Animation abläuft — je weiter man scrollt, desto weiter ist
   der Ablauf fortgeschritten (0 bis 1).

   Der Ablauf in zwei Phasen:
     0,00 – 0,22   Überschrift zoomt heran und blendet aus
     0,30 – 1,00   Hintergrund ist Gold, die Zahlen fliegen
                   nacheinander aus der Mitte heraus

   Gerechnet wird nur in requestAnimationFrame, damit das Scrollen
   flüssig bleibt.
   ============================================================ */
(function () {
  const abschnitt = document.querySelector("[data-zahlen]");
  if (!abschnitt) return;

  // Wer Bewegung reduziert haben möchte, bekommt die ruhige Fassung aus dem CSS.
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

  const klebend = abschnitt.querySelector(".zahlen-sticky");
  const titel = abschnitt.querySelector(".zahlen-titel");
  const zahlen = [...abschnitt.querySelectorAll(".zahl")];

  // Die Zahlenphase belegt die Strecke von START bis zum Ende.
  // FENSTER ist, wie lange eine einzelne Zahl unterwegs ist; TAKT der
  // Abstand zwischen zwei Starts. Der Takt ergibt sich aus der Anzahl,
  // damit die letzte Zahl immer genau am Ende ausgeflogen ist —
  // egal, wie viele Zahlen im HTML stehen.
  const START = 0.3;
  const FENSTER = 0.24;
  const TAKT = zahlen.length > 1 ? (1 - START - FENSTER) / (zahlen.length - 1) : 0;

  // Blendet einen Wert innerhalb eines Fensters von 0 auf 1 auf.
  const anteil = (wert, von, bis) => {
    if (bis === von) return 0;
    return Math.min(1, Math.max(0, (wert - von) / (bis - von)));
  };

  // Weich beginnen und enden — wirkt weniger mechanisch als linear.
  const weich = (t) => t * t * (3 - 2 * t);

  let angefordert = false;

  function zeichnen() {
    angefordert = false;

    const oben = abschnitt.offsetTop;
    const strecke = abschnitt.offsetHeight - window.innerHeight;
    if (strecke <= 0) return;

    const p = Math.min(1, Math.max(0, (window.scrollY - oben) / strecke));

    // --- Phase 1: Überschrift zoomt heran und blendet aus ---
    const tp = weich(anteil(p, 0, 0.22));
    titel.style.setProperty("--titel-zoom", (1 + tp * 2.6).toFixed(3));
    titel.style.setProperty("--titel-deckkraft", (1 - anteil(p, 0.08, 0.2)).toFixed(3));

    // --- Phase 2: Hintergrund kippt auf Gold, Zahlen fliegen heraus ---
    // Der Wechsel muss VOR der ersten Zahl liegen — die Zahlen sind
    // schwarz und waeren auf schwarzem Grund unsichtbar.
    klebend.classList.toggle("ist-gold", p >= START - 0.04);

    zahlen.forEach((el, i) => {
      // Jede Zahl startet später als die vorige. Takt und Fensterbreite
      // sind so gewählt, dass die letzte Zahl genau am Ende der Strecke
      // ausgeflogen ist — unabhängig davon, wie viele Zahlen es sind.
      const start = START + i * TAKT;
      const q = weich(anteil(p, start, start + FENSTER));

      el.style.setProperty("--weg", q.toFixed(3));
      el.style.setProperty("--zoom", (0.35 + q * 1.55).toFixed(3));
      // Auf- und wieder abblenden, damit die Zahlen nicht am Rand kleben.
      const sichtbar = Math.min(anteil(q, 0, 0.22), 1 - anteil(q, 0.74, 1));
      el.style.setProperty("--deckkraft", sichtbar.toFixed(3));
    });
  }

  function anfordern() {
    if (angefordert) return;
    angefordert = true;
    requestAnimationFrame(zeichnen);
  }

  window.addEventListener("scroll", anfordern, { passive: true });
  window.addEventListener("resize", anfordern);
  zeichnen();
})();

// ---------- Bewertungen: endlos laufendes Band ----------
// Im Quelltext steht jede Bewertung nur einmal. Fuer den nahtlosen
// Uebergang braucht das Band aber zwei identische Durchgaenge — die
// Kopie entsteht hier, damit die Seite nicht doppelten Text enthaelt.
(function () {
  const band = document.querySelector("[data-laufband]");
  if (!band) return;

  const spur = band.querySelector(".testimonial-track");
  const karten = [...spur.children];
  if (!karten.length) return;

  // Bei reduzierter Bewegung laeuft nichts — dann waere die Kopie
  // nur doppelter Inhalt ohne Zweck.
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

  karten.forEach((k) => {
    const kopie = k.cloneNode(true);
    // Fuer Screenreader unsichtbar: derselbe Text zweimal waere verwirrend.
    kopie.setAttribute("aria-hidden", "true");
    kopie.classList.add("testimonial-kopie");
    spur.appendChild(kopie);
  });
})();

// ---------- Zahlenband: einmaliges Hochzaehlen ----------
// Die Endwerte stehen im HTML. Nur wenn das Band ins Bild kommt, wird
// kurz von 0 hochgezaehlt — ohne JS oder bei reduzierter Bewegung
// bleibt schlicht der Endwert stehen.
(function () {
  const band = document.querySelector("[data-zahlenband]");
  if (!band) return;

  const felder = [...band.querySelectorAll("strong[data-ziel]")];
  if (!felder.length) return;
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

  const schreiben = (el, wert) => {
    const nk = Number(el.dataset.nachkomma || 0);
    const zahl = wert.toLocaleString("de-AT", {
      minimumFractionDigits: nk,
      maximumFractionDigits: nk,
    });
    el.textContent = `${el.dataset.praefix || ""}${zahl}${el.dataset.suffix || ""}`;
  };

  function hochzaehlen() {
    const dauer = 1400;
    const start = performance.now();
    function schritt(jetzt) {
      const t = Math.min(1, (jetzt - start) / dauer);
      const e = 1 - Math.pow(1 - t, 3);   // weich auslaufend
      felder.forEach((el) => schreiben(el, Number(el.dataset.ziel) * e));
      if (t < 1) requestAnimationFrame(schritt);
    }
    requestAnimationFrame(schritt);
  }

  const beobachter = new IntersectionObserver((eintraege) => {
    if (!eintraege.some((e) => e.isIntersecting)) return;
    beobachter.disconnect();
    hochzaehlen();
  }, { threshold: 0.4 });
  beobachter.observe(band);
})();

// ---------- Slogan: Linie und Wort ----------
// Laeuft einmal pro Seitenaufruf, sobald das Wort im Bild ist.
// Ohne JavaScript bleibt das Wort sichtbar — die Klasse, die es
// zunaechst ausblendet, wird erst hier gesetzt.
(function () {
  const wort = document.querySelector(".hero-title em[data-marker]");
  if (!wort) return;

  const ruhig = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (!ruhig) wort.classList.add("ist-bereit");

  const beobachter = new IntersectionObserver((eintraege) => {
    if (!eintraege.some((e) => e.isIntersecting)) return;
    beobachter.disconnect();
    wort.classList.add("ist-markiert");
  }, { threshold: 0.6 });

  beobachter.observe(wort);
})();
