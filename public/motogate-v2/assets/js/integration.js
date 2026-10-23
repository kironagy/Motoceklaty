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
 document.querySelector('.scroltop')?.addEventListener('click',()=>window.scrollTo({top:0,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'}));
 document.querySelectorAll('[data-stock-prev],[data-stock-next]').forEach(button=>button.addEventListener('click',()=>document.querySelector('.stock-track')?.scrollBy({left:(button.hasAttribute('data-stock-prev')?1:-1)*350,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'})));
const loadingArea = document.querySelector('.loading-area');
if (loadingArea) {
 const dismiss = () => { loadingArea.hidden = true; loadingArea.querySelector('video')?.pause(); };
 const video = loadingArea.querySelector('video');
 video?.addEventListener('ended', dismiss); video?.addEventListener('error', dismiss);
 setTimeout(dismiss, matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 2500);
}
// The approved showroom's controls, populated exclusively with live model data.
function displayLiveMachine(card) {
 const data=JSON.parse(card.dataset.liveMachine), scene=document.querySelector('.gallery-feature');
 if(!scene)return;
 const photo=scene.querySelector('.showroom-motorcycle');photo.src=data.image;photo.alt=data.name;
 const heading=scene.querySelector('h1');heading.replaceChildren();const brand=document.createElement('span'),name=document.createElement('strong');brand.textContent=data.brand||'';const parts=String(data.name).match(/^(\D+?)\s*(\d.*)$/u);if(parts){const em=document.createElement('em');em.textContent=parts[1];name.append(em,parts[2])}else name.textContent=data.name;heading.append(brand,name);
 scene.querySelector('.featured-tagline').textContent=data.category||'';
 const price=scene.querySelector('.price strong');if(price)price.textContent=Number(data.price).toLocaleString('en-US');
 const mobileSpecs=scene.querySelector('.mobile-specs'),icons=['engine','stroke','transmission','brake'],iconBase=mobileSpecs.dataset.iconBase;mobileSpecs.replaceChildren(...data.specs.slice(0,4).map((spec,i)=>{const span=document.createElement('span'),img=document.createElement('img');img.className='icon bi bi-'+icons[i];img.src=iconBase+icons[i]+'.png';img.alt='';img.width=img.height=24;img.setAttribute('aria-hidden','true');span.className='showroom-spec';const label=document.createElement('small'),value=document.createElement('b');label.textContent=spec.label||'';value.textContent=spec.value;span.append(img,label,value);return span}));
 scene.querySelectorAll('.scene-specs li').forEach((el,i)=>{el.querySelector('strong').textContent=data.specs[i]?.value||'—';el.querySelector('span').textContent=data.specs[i]?.label||'';});
 const featureCalc=scene.querySelector('[data-feature-calc]');featureCalc.href=data.calc;featureCalc.dataset.openCalculator=data.id;scene.querySelector('[data-feature-contact]').href=data.contact;
 document.querySelectorAll('[data-live-machine]').forEach(el=>el.classList.toggle('is-selected',el===card));
 scene.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
}
document.querySelectorAll('[data-live-machine]').forEach(card=>{
 card.addEventListener('click',event=>{if(event.target.closest('a,button:not([data-display-machine]),input,select'))return;displayLiveMachine(card)});
 card.addEventListener('keydown',e=>{if(e.target===card&&(e.key==='Enter'||e.key===' ')){e.preventDefault();displayLiveMachine(card)}});
});
document.querySelector('.brand-next')?.addEventListener('click',()=>document.querySelector('.brand-list')?.scrollBy({left:250,behavior:'smooth'}));
document.querySelectorAll('[data-rail-prev],[data-rail-next]').forEach(button=>button.addEventListener('click',()=>{
 const id=button.dataset.railPrev||button.dataset.railNext;document.getElementById(id)?.scrollBy({left:button.dataset.railPrev?330:-330,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
}));
document.querySelectorAll('.modal').forEach(modal=>modal.addEventListener('hidden.bs.modal',()=>modal.querySelectorAll('video').forEach(video=>video.pause())));
const article=document.getElementById('articleContent'),toc=document.getElementById('articleContents');
if(article&&toc&&!toc.children.length)article.querySelectorAll('h2,h3').forEach((heading,i)=>{heading.id='article-section-'+i;const link=document.createElement('a');link.href='#'+heading.id;link.textContent=heading.textContent;toc.appendChild(link)});
// Header search from the approved design, listing active models from the database.
const bikeSearch=document.getElementById('bikeSearch'),searchResults=document.getElementById('searchResults');
if(bikeSearch&&searchResults){
 const machines=JSON.parse(document.getElementById('searchMachines')?.textContent||'[]');
 const renderSearch=()=>{
  const query=bikeSearch.value.trim().toLocaleLowerCase();
  const results=machines.filter(machine=>machine.label.toLocaleLowerCase().includes(query));
  searchResults.replaceChildren();
  for(const machine of results){const button=document.createElement('button');button.type='button';button.dataset.searchUrl=machine.url;button.textContent=machine.label;searchResults.append(button);}
  if(!results.length)searchResults.textContent='لا توجد نتائج مطابقة. جرّب اسم ماركة أو موديل آخر.';
 };
 bikeSearch.addEventListener('input',renderSearch);renderSearch();
 document.getElementById('searchModal').addEventListener('shown.bs.modal',()=>bikeSearch.focus());
 searchResults.addEventListener('click',event=>{const button=event.target.closest('[data-search-url]');if(button)location.href=button.dataset.searchUrl;});
}
// Showroom "احسب القسط": the approved design opens #paymentCalculator with the model chosen.
document.addEventListener('click',event=>{
 const trigger=event.target.closest('[data-open-calculator]'),modal=document.getElementById('paymentCalculator');
 const form=modal?.querySelector('form[data-installment-calculator]');
 if(!trigger||!form?.selectMachine||!form.selectMachine(trigger.dataset.openCalculator))return;
 event.preventDefault();bootstrap.Modal.getOrCreateInstance(modal).show();
});

const modelsSlider = document.getElementById('modelsSlider');
if (modelsSlider) {
 const slides = [...modelsSlider.querySelectorAll('.models-slide')];
 const previous = document.querySelector('[data-models-prev]'), next = document.querySelector('[data-models-next]'), status = document.querySelector('[data-models-status]');
 let current = 0;
 const update = () => {
  current = slides.reduce((best, slide, index) => Math.abs(slide.getBoundingClientRect().right - modelsSlider.getBoundingClientRect().right) < Math.abs(slides[best].getBoundingClientRect().right - modelsSlider.getBoundingClientRect().right) ? index : best, 0);
  previous.disabled = current === 0; next.disabled = current === slides.length - 1;
  status.textContent = `${current + 1} / ${slides.length}`;
 };
 const move = delta => slides[Math.max(0, Math.min(slides.length - 1, current + delta))].scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'nearest',inline:'start'});
 previous.addEventListener('click',()=>move(-1));next.addEventListener('click',()=>move(1));
 modelsSlider.addEventListener('scroll',update,{passive:true});window.addEventListener('resize',update);
 modelsSlider.addEventListener('keydown',event=>{if(event.target===modelsSlider&&['ArrowLeft','ArrowRight'].includes(event.key)){event.preventDefault();move(event.key==='ArrowLeft'?1:-1)}});
 update();
}
