// RentCar : thème, menu mobile, impression, visionneuse de diagrammes.
(() => {
  const racine = document.documentElement;
  const theme = document.getElementById('theme');
  const sombre = () => racine.dataset.theme ? racine.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
  theme?.addEventListener('click', () => {
    racine.dataset.theme = sombre() ? 'light' : 'dark';
    try { localStorage.setItem('rentcar-theme', racine.dataset.theme); } catch (e) {}
  });

  const burger = document.querySelector('.burger');
  const menu = document.getElementById('menu');
  burger?.addEventListener('click', () => {
    const ouvert = menu.classList.toggle('ouvert');
    burger.setAttribute('aria-expanded', String(ouvert));
  });

  document.querySelectorAll('[data-imprimer]').forEach(b => b.addEventListener('click', () => print()));

  // Confirmation avant une action irréversible
  document.querySelectorAll('form[data-confirmer]').forEach(f => f.addEventListener('submit', e => {
    if (!confirm(f.dataset.confirmer)) e.preventDefault();
  }));

  // Simulateur de tarif (page Tarifs) : reprend Tarif.calculerPrix(duree)
  const sim = document.getElementById('simulateur');
  if (sim) {
    const tarifs = JSON.parse(sim.dataset.tarifs);
    const cat = sim.querySelector('#s-cat'), form = sim.querySelector('#s-for'), jours = sim.querySelector('#s-jours');
    const total = sim.querySelector('#s-total'), detail = sim.querySelector('#s-detail');
    const eur = n => n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    const calc = () => {
      const t = tarifs.find(t => t.c === cat.value && t.f === form.value);
      document.querySelectorAll('.grille td[data-c]').forEach(td => td.classList.toggle('actif', td.dataset.c === cat.value && td.dataset.f === form.value));
      if (!t) { total.textContent = '–'; detail.textContent = 'Pas de tarif pour ce couple.'; return; }
      const j = Math.max(1, Math.min(365, parseInt(jours.value, 10) || 1));
      const nb = Math.ceil(j / t.d);
      total.textContent = eur(t.p * nb);
      detail.textContent = `${j} jour${j > 1 ? 's' : ''} en formule ${t.l} (${t.d} j) : ${nb} × ${eur(t.p)}`;
    };
    [cat, form, jours].forEach(el => el.addEventListener('input', calc));
    calc();
  }

  // Visionneuse plein écran (page Modèle UML)
  const dlg = document.getElementById('visionneuse');
  if (dlg) {
    const scene = dlg.querySelector('.visionneuse__scene'), img = scene.querySelector('img'), titre = dlg.querySelector('strong');
    let e = 1, x = 0, y = 0, dep = null;
    const app = () => { img.style.transform = `translate(${x}px,${y}px) scale(${e})`; };
    const ajuster = () => { const r = scene.getBoundingClientRect(), w = img.offsetWidth, h = img.offsetHeight; e = Math.min((r.width - 40) / w, (r.height - 40) / h); x = (r.width - w * e) / 2; y = (r.height - h * e) / 2; app(); };
    const zoom = (f, cx, cy) => { const r = scene.getBoundingClientRect(); cx ??= r.width / 2; cy ??= r.height / 2; const n = Math.min(8, Math.max(.15, e * f)); x = cx - (cx - x) * n / e; y = cy - (cy - y) * n / e; e = n; app(); };
    document.querySelectorAll('.diagramme').forEach(fig => {
      const src = fig.querySelector('img');
      const ouvrir = () => { titre.textContent = src.alt; img.src = src.src; img.style.width = Math.max(1400, src.naturalWidth) + 'px'; img.onload = ajuster; dlg.showModal(); requestAnimationFrame(ajuster); };
      src.addEventListener('click', ouvrir);
      fig.querySelector('[data-ouvrir]')?.addEventListener('click', ouvrir);
    });
    dlg.querySelectorAll('[data-z]').forEach(b => b.addEventListener('click', () => { const z = +b.dataset.z; z ? zoom(z > 0 ? 1.25 : .8) : ajuster(); }));
    dlg.querySelector('[data-fermer]').addEventListener('click', () => dlg.close());
    scene.addEventListener('wheel', ev => { ev.preventDefault(); const r = scene.getBoundingClientRect(); zoom(ev.deltaY < 0 ? 1.12 : .89, ev.clientX - r.left, ev.clientY - r.top); }, { passive: false });
    scene.addEventListener('pointerdown', ev => { dep = { px: ev.clientX, py: ev.clientY, x, y }; scene.setPointerCapture(ev.pointerId); });
    scene.addEventListener('pointermove', ev => { if (dep) { x = dep.x + ev.clientX - dep.px; y = dep.y + ev.clientY - dep.py; app(); } });
    scene.addEventListener('pointerup', () => { dep = null; });
  }
})();
