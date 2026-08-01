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
  navToggle.addEventListener("click", () => {
    const isOpen = mobileMenu.classList.toggle("is-open");
    document.body.classList.toggle("nav-open", isOpen);
    navToggle.classList.toggle("is-active", isOpen);
  });

  mobileMenu.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => {
      mobileMenu.classList.remove("is-open");
      document.body.classList.remove("nav-open");
      navToggle.classList.remove("is-active");
    });
  });

  document.addEventListener("click", (e) => {
    if (!mobileMenu.classList.contains("is-open")) return;
    if (mobileMenu.contains(e.target) || navToggle.contains(e.target)) return;
    mobileMenu.classList.remove("is-open");
    document.body.classList.remove("nav-open");
    navToggle.classList.remove("is-active");
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

// ---------- Animated counters (smooth premium ease-out) ----------
document.querySelectorAll(".counter").forEach((el) => {
  const target = parseInt(el.dataset.target, 10) || 0;
  const suffix = el.dataset.suffix || "";
  const duration = 2600;
  const start = performance.now();

  const easeOutExpo = (t) => (t >= 1 ? 1 : 1 - Math.pow(2, -10 * t));

  const tick = (now) => {
    const progress = Math.min((now - start) / duration, 1);
    const eased = easeOutExpo(progress);
    const current = Math.round(target * eased);
    el.textContent = current.toLocaleString("de-DE") + suffix;
    if (progress < 1) requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
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

document.querySelectorAll(".video-slider-track, .testimonial-slider-track, .team-slider-track").forEach((track) => setupTrackSlider(track, 28));

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
          status.textContent = "Danke! Ihre Nachricht wurde erfolgreich gesendet — wir melden uns bei Ihnen.";
          status.classList.add("show", "success");
          form.reset();
        } else {
          status.textContent = "Es gab ein Problem beim Senden. Bitte versuchen Sie es erneut oder kontaktieren Sie uns telefonisch.";
          status.classList.add("show", "error");
        }
      })
      .catch(() => {
        if (!status) return;
        status.classList.remove("success");
        status.textContent = "Es gab ein Problem beim Senden. Bitte versuchen Sie es erneut oder kontaktieren Sie uns telefonisch.";
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
