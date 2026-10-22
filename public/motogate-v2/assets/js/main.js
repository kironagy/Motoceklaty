"use strict";

const bikes = {
  keeway: {
    brand: "Keeway",
    name: "RKZ200",

    price: 89000,
  },
  sym: {
    brand: "SYM",
    name: "NH T200",
  },
  haojue: {
    brand: "Haojue",
    name: "DR160",
  },
  benelli: {
    brand: "Benelli",
    name: "TNT",
  },
};
const articleData = [
  {
    title: "إزاي أقدر أختار أنسب موتوسيكل؟",
  },
  {
    title: "أي نوع زيت أفضل لموتوسيكلك؟",
  },
  {
    title: "إيه أنظمة التقسيط الأنسب لي؟",
  },
];
const detailsElement = document.getElementById("detailsModal");
const detailsModal = new bootstrap.Modal(detailsElement);
function openContent(title, templateId) {
  const template = document.getElementById(templateId);
  if (!template) return;
  document.getElementById("detailsTitle").textContent = title;
  document
    .getElementById("detailsBody")
    .replaceChildren(template.content.cloneNode(true));
  detailsModal.show();
}
function showBike(key) {
  const bike = bikes[key];
  if (bike) openContent(`${bike.brand} ${bike.name}`, `bike-content-${key}`);
}
function showArticle(index) {
  const article = articleData[index];
  if (article) location.href = 'blog-details.html?article=' + ['choose','care','finance'][index];
}
document.addEventListener("click", (event) => {
  const model = event.target.closest("[data-model]");
  if (model) {
    if (!document.body.classList.contains("gallery-page")) showBike(model.dataset.model);
    return;
  }
  const article = event.target.closest("[data-article]");
  if (article) {
    event.preventDefault();
    showArticle(Number(article.dataset.article));
    return;
  }
  if (event.target.closest("[data-all-articles]"))
    openContent("دليلك لاختيار الأفضل", "article-list-content");
  if (event.target.closest('a[href="#team"]')) {
    event.preventDefault();
    openContent("فريق يعمل من أجلك", "team-content");
  }
});
// Close the mobile drawer after following an in-page link.
document
  .querySelectorAll("#mobileMenu a")
  .forEach((link) =>
    link.addEventListener("click", () =>
      bootstrap.Offcanvas.getInstance(
        document.getElementById("mobileMenu"),
      )?.hide(),
    ),
  );

const grid = document.querySelector(".models-grid");
function filterBrand(brand) {
  if (!grid) return;
  let count = 0;
  grid.querySelectorAll(".model-card").forEach((card) => {
    const match =
      (!card.hasAttribute("data-extra-model") || extraModelsVisible) &&
      (brand === "all" ||
        card.dataset.make.toLowerCase() === brand.toLowerCase());
    card.hidden = !match;
    if (match) count++;
  });
  document.querySelector(".empty-models").hidden = count > 0;
  document
    .querySelectorAll("[data-brand]")
    .forEach((button) =>
      button.setAttribute(
        "aria-pressed",
        String(button.dataset.brand.toLowerCase() === brand.toLowerCase()),
      ),
    );
}
let extraModelsVisible = false;
function addFeaturedCard() {
  extraModelsVisible = true;
  grid?.querySelector("[data-extra-model]")?.removeAttribute("hidden");
}
document.querySelectorAll("[data-brand]").forEach((button) =>
  button.addEventListener("click", () => {
    if (button.dataset.brand.toLowerCase() === "keeway") addFeaturedCard();
    filterBrand(button.dataset.brand);
  }),
);
document
  .querySelector("[data-reset-brands]")
  ?.addEventListener("click", () => filterBrand("all"));
document.querySelector("[data-show-all]")?.addEventListener("click", () => {
  addFeaturedCard();
  filterBrand("all");
});
document.querySelector(".brand-next")?.addEventListener("click", () => {
  const list = document.querySelector(".brand-list");
  if (list.scrollLeft + list.clientWidth >= list.scrollWidth - 4)
    list.scrollTo({ left: 0, behavior: "smooth" });
  else list.scrollBy({ left: 250, behavior: "smooth" });
});
if (grid) {
  const category = new URLSearchParams(location.search).get("category");
  if (category && category !== "sport") {
    grid
      .querySelectorAll(".model-card")
      .forEach((card) => (card.hidden = true));
    document.querySelector(".empty-models").hidden = false;
  }
  if (category) document.getElementById("models").scrollIntoView();
}

const search = document.getElementById("bikeSearch");
function renderSearch() {
  const query = search.value.trim().toLocaleLowerCase();
  const results = Object.entries(bikes).filter(([, bike]) =>
    `${bike.brand} ${bike.name}`.toLowerCase().includes(query),
  );
  const container = document.getElementById("searchResults");
  container.replaceChildren();
  for (const [key, bike] of results) {
    const button = document.createElement("button");
    button.type = "button";
    button.dataset.searchModel = key;
    button.textContent = `${bike.brand} ${bike.name}`;
    container.append(button);
  }
  if (!results.length)
    container.textContent =
      "لا توجد نتائج مطابقة. جرّب اسم ماركة أو موديل آخر.";
}
search.addEventListener("input", renderSearch);
renderSearch();
document
  .getElementById("searchModal")
  .addEventListener("shown.bs.modal", () => search.focus());
document.getElementById("searchResults").addEventListener("click", (event) => {
  const button = event.target.closest("[data-search-model]");
  if (!button) return;
  const searchModal = document.getElementById("searchModal");
  searchModal.addEventListener(
    "hidden.bs.modal",
    () => showBike(button.dataset.searchModel),
    { once: true },
  );
  bootstrap.Modal.getInstance(searchModal).hide();
});

const form = document.getElementById("installmentForm");
if (form) {
  const brand = document.getElementById("brand"),
    model = document.getElementById("model"),
    deposit = document.getElementById("deposit"),
    result = document.getElementById("installmentResult");
  brand.addEventListener("change", () => {
    model.replaceChildren(new Option("النوع", ""));
    Object.entries(bikes)
      .filter(([, bike]) => bike.brand === brand.value)
      .forEach(([key, bike]) => model.add(new Option(bike.name, key)));
    result.textContent = "";
    deposit.setCustomValidity("");
  });
  model.addEventListener("change", () => {
    deposit.setCustomValidity("");
    result.textContent = "";
  });
  deposit.addEventListener("input", () => deposit.setCustomValidity(""));
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    const bike = bikes[model.value];
    if (!bike) return;
    if (!bike.price) {
      result.textContent =
        "سعر الموديل حسب التوفر. تواصل معنا على 0100 123 4567 لمعرفة السعر وحساب القسط.";
      window.showPaymentResult?.();
      return;
    }
    const down = Number(deposit.value),
      months = Number(document.getElementById("months").value),
      rate = Number(document.getElementById("finance").value);
    if (down >= bike.price) {
      deposit.setCustomValidity(
        "المقدم لازم يكون أقل من سعر الدراجة: " + bike.price + " جنيه",
      );
      deposit.reportValidity();
      return;
    }
    const principal = bike.price - down,
      total = principal * (1 + (rate * months) / 12),
      monthly = total / months;
    result.innerHTML = `القسط الشهري التقديري: <strong>${monthly.toLocaleString("ar-EG", { maximumFractionDigits: 0 })} جنيه</strong> لمدة ${months} شهر.<br><small>إجمالي الأقساط ${total.toLocaleString("ar-EG", { maximumFractionDigits: 0 })} جنيه. حساب توضيحي بعائد ثابت حسب النظام المختار، ولا يشمل رسوم أو تأمين. العرض النهائي حسب شروط التعاقد.</small>`;
    window.showPaymentResult?.();
  });
}

// Shared, dependency-free carousel: arrows, dots, keyboard, swipe and pause.
// Each slide and its images are authored in HTML; only active state changes.
document.querySelectorAll("[data-carousel]").forEach((root) => {
  const slides = [...root.querySelectorAll("[data-slide]")];
  const dots = [...root.querySelectorAll("[data-dot]")];
  if (slides.length < 2) return;
  let index = 0,
    timer = null,
    paused = false,
    hovered = false,
    focused = false,
    touchStart = null;
  const reducedMotion = matchMedia("(prefers-reduced-motion: reduce)");
  const delay = root.dataset.carousel === "hero" ? 5500 : 7500;
  function render(next, announce = false) {
    index = (next + slides.length) % slides.length;
    slides.forEach((slide, i) => {
      const active = i === index;
      slide.classList.toggle("is-active", active);
      slide.setAttribute("aria-hidden", String(!active));
      slide.inert = !active;
    });
    dots.forEach((dot, i) =>
      dot.setAttribute("aria-pressed", String(i === index)),
    );
    if (announce)
      root.querySelector("[data-carousel-status]").textContent =
        `الشريحة ${index + 1} من ${slides.length}`;
  }
  function schedule() {
    clearTimeout(timer);
    if (
      !paused &&
      !hovered &&
      !focused &&
      !document.hidden &&
      !reducedMotion.matches
    )
      timer = setTimeout(() => {
        render(index + 1);
        schedule();
      }, delay);
  }
  function step(delta) {
    render(index + delta, true);
    schedule();
  }
  root.querySelector("[data-prev]").addEventListener("click", () => step(-1));
  root.querySelector("[data-next]").addEventListener("click", () => step(1));
  dots.forEach((dot) =>
    dot.addEventListener("click", () => {
      render(Number(dot.dataset.dot), true);
      schedule();
    }),
  );
  root.querySelector("[data-pause]").addEventListener("click", (event) => {
    paused = !paused;
    const button = event.currentTarget;
    button.setAttribute("aria-pressed", String(paused));
    button.setAttribute(
      "aria-label",
      paused ? "تشغيل التبديل التلقائي" : "إيقاف التبديل التلقائي",
    );
    schedule();
  });
  root.addEventListener("mouseenter", () => {
    hovered = true;
    schedule();
  });
  root.addEventListener("mouseleave", () => {
    hovered = false;
    schedule();
  });
  root.addEventListener("focusin", () => {
    focused = true;
    schedule();
  });
  root.addEventListener("focusout", () => {
    requestAnimationFrame(() => {
      focused = root.contains(document.activeElement);
      schedule();
    });
  });
  root.addEventListener("keydown", (event) => {
    if (event.key === "ArrowRight" || event.key === "ArrowLeft") {
      event.preventDefault();
      step(event.key === "ArrowRight" ? 1 : -1);
    }
  });
  root.addEventListener(
    "touchstart",
    (event) => {
      touchStart = { x: event.touches[0].clientX, y: event.touches[0].clientY };
      clearTimeout(timer);
    },
    { passive: true },
  );
  root.addEventListener(
    "touchend",
    (event) => {
      if (!touchStart) return;
      const dx = event.changedTouches[0].clientX - touchStart.x,
        dy = event.changedTouches[0].clientY - touchStart.y;
      if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy))
        step(dx < 0 ? 1 : -1);
      touchStart = null;
      schedule();
    },
    { passive: true },
  );
  root.addEventListener(
    "touchcancel",
    () => {
      touchStart = null;
      schedule();
    },
    { passive: true },
  );
  document.addEventListener("visibilitychange", schedule);
  reducedMotion.addEventListener("change", schedule);
  render(0);
  schedule();
});
