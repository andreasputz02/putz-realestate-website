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
}

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

// ---------- Forms (visual-only placeholder until email delivery is connected) ----------
document.querySelectorAll("form[data-contact-form]").forEach((form) => {
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    const status = form.querySelector(".form-status");
    if (status) {
      status.textContent =
        "Danke! Das Formular ist bereit — der E-Mail-Versand wird in der nächsten Revision aktiviert.";
      status.classList.remove("error");
      status.classList.add("show", "success");
    }
  });
});
