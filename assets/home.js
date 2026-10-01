/* Twins Sushi — animations de la page d'accueil (sans dépendance) */
(function () {
  "use strict";
  var root = document.documentElement;
  root.classList.remove("no-js"); root.classList.add("js");
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var mobile = function () { return window.innerWidth <= 860; };
  var clamp = function (v, a, b) { return v < a ? a : v > b ? b : v; };
  var ease = function (t) { return t < .5 ? 2 * t * t : -1 + (4 - 2 * t) * t; };

  /* Défilement doux uniquement quand on clique un lien interne (jamais à la molette) */
  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest('a[href^="#"]'); if (!a || a.getAttribute("href").length < 2) return;
    var t = document.getElementById(a.getAttribute("href").slice(1)); if (!t) return;
    e.preventDefault();
    var top = t.getBoundingClientRect().top + window.pageYOffset - (t.classList.contains("cat") ? 150 : 70);
    window.scrollTo({ top: top, behavior: reduce ? "auto" : "smooth" });
    if (history.replaceState) history.replaceState(null, "", a.getAttribute("href"));
  });

  /* Apparitions au scroll */
  if ("IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); } });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.1 });
    $$(".rv, .display, .grid .p").forEach(function (el) { io.observe(el); });
    /* Compteurs */
    var ioc = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return; ioc.unobserve(e.target);
        var el = e.target, to = parseFloat(el.getAttribute("data-to")), suf = el.getAttribute("data-suffix") || "", t0 = null;
        if (reduce) { el.firstChild.nodeValue = to; return; }
        function step(ts) { if (!t0) t0 = ts; var p = clamp((ts - t0) / 1400, 0, 1); el.firstChild.nodeValue = Math.round(to * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); }
        requestAnimationFrame(step);
      });
    }, { threshold: 0.6 });
    $$("[data-to]").forEach(function (el) { ioc.observe(el); });
  } else { $$(".rv, .display, .grid .p").forEach(function (el) { el.classList.add("in"); }); }

  /* Progression d'une scène épinglée : 0 en haut, 1 en bas */
  function progress(scene) {
    var r = scene.getBoundingClientRect(), vh = window.innerHeight;
    return clamp(-r.top / (r.height - vh), 0, 1);
  }
  /* Une seule boucle : les calculs se font au rythme de l'écran, jamais plusieurs fois par événement */
  var ticks = [], dirty = true;
  function onScroll() { dirty = true; }
  window.addEventListener("scroll", onScroll, { passive: true });
  window.addEventListener("resize", onScroll);
  (function frame() { if (dirty) { dirty = false; for (var i = 0; i < ticks.length; i++) ticks[i](); } requestAnimationFrame(frame); })();

  /* ---------- Scène 1 : la sauce qui coule ---------- */
  var drip = $(".drip"), canvas = $("#drip"), ctx = canvas && canvas.getContext("2d");
  var frames = [], loaded = 0, N = 0, cur = -1, dripReady = false;
  if (drip && ctx && !reduce) {
    var total = parseInt(canvas.getAttribute("data-frames"), 10), base = canvas.getAttribute("data-src");
    var lite = window.TWINS_LITE || mobile() || (navigator.connection && /2g|3g/.test(navigator.connection.effectiveType || "")) || (navigator.connection && navigator.connection.saveData);
    var stepN = lite ? 2 : 1; if (lite) base += "m/";
    var idx = []; for (var i = 0; i < total; i += stepN) idx.push(i);
    N = idx.length;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    function size() {
      var r = canvas.getBoundingClientRect(); if (!r.width) return;
      canvas.width = Math.round(r.width * dpr); canvas.height = Math.round(r.height * dpr);
      cur = -1; draw();
    }
    function draw() {
      var p = progress(drip), f = Math.round(ease(clamp(p * 1.15, 0, 1)) * (N - 1));
      // dernière image chargée avant f
      var k = f; while (k > 0 && !frames[k]) k--;
      if (k === cur || !frames[k]) return;
      cur = k;
      var im = frames[k], cw = canvas.width, ch = canvas.height, s = Math.min(cw / im.width, ch / im.height);
      var w = im.width * s, h = im.height * s;
      ctx.clearRect(0, 0, cw, ch); ctx.drawImage(im, (cw - w) / 2, (ch - h) / 2, w, h);
    }
    function load(i) {
      var im = new Image(); im.decoding = "async";
      im.onload = function () {
        var ready = function () { frames[i] = im; loaded++; if (i === 0) { dripReady = true; size(); } else { dirty = true; } };
        if (im.decode) im.decode().then(ready, ready); else ready();
      };
      im.src = base + String(idx[i]).padStart(3, "0") + ".webp";
    }
    load(0);
    var prio = [Math.round(N * .5), N - 1, Math.round(N * .25), Math.round(N * .75)];
    prio.forEach(load);
    var q = 1; (function next() { while (q < N && (frames[q] || prio.indexOf(q) > -1)) q++; if (q < N) { load(q); q++; setTimeout(next, 30); } })();
    window.addEventListener("resize", function () { size(); dirty = true; });
    var steps = $$(".drip .step"), sub = $(".drip .sub"), ctas = $(".drip .ctas"), hint = $(".hint");
    function tick() {
      var p = progress(drip);
      draw();
      steps.forEach(function (s, i) { var a = parseFloat(s.getAttribute("data-a")), b = parseFloat(s.getAttribute("data-b")); s.classList.toggle("on", p >= a && p < b); });
      if (hint) hint.style.opacity = p > .06 ? 0 : 1;
    }
    setTimeout(function () { $(".drip h1").classList.add("in"); sub.classList.add("in"); ctas.classList.add("in"); var st = $(".drip .steps"); if (st) st.classList.add("in"); }, 100);
    ticks.push(tick); dirty = true;
  } else if (drip) {
    $(".drip h1").classList.add("in"); $(".drip .sub").classList.add("in"); $(".drip .ctas").classList.add("in");
    if (canvas) { canvas.style.display = "none"; var po = $(".drip .poster"); if (po) po.style.display = "block"; }
  }

  /* ---------- Scène 2 : flottants en parallaxe ---------- */
  var floats = $$(".float");
  if (floats.length && !reduce) {
    var mx = 0, my = 0;
    window.addEventListener("pointermove", function (e) { mx = (e.clientX / window.innerWidth - .5); my = (e.clientY / window.innerHeight - .5); }, { passive: true });
    var manif = $(".manif"), mvis = true;
    if ("IntersectionObserver" in window) new IntersectionObserver(function (es) { mvis = es[0].isIntersecting; }, { rootMargin: "20% 0px" }).observe(manif);
    (function loop() {
      requestAnimationFrame(loop); if (!mvis) return;
      var r = manif.getBoundingClientRect(), p = clamp((window.innerHeight - r.top) / (window.innerHeight + r.height), 0, 1);
      floats.forEach(function (f, i) {
        var k = [1, .6, 1.4][i] || 1, rot = [-8, 6, 14][i] || 0;
        f.style.transform = "translate3d(" + (mx * -30 * k) + "px," + ((p - .5) * -120 * k + my * -20 * k) + "px,0) rotate(" + (rot + p * 10 * k) + "deg)";
      });
    })();
  }

  /* ---------- Scène 3 : signatures ---------- */
  var sig = $(".signatures");
  if (sig && !reduce) {
    var imgs = $$(".signatures .plate img"), items = $$(".signatures .item"), dots = $$(".signatures .dots i"), n = items.length, last = -1;
    function sigTick() {
      var p = progress(sig), seg = clamp(Math.floor(p * n * 0.999), 0, n - 1);
      dots.forEach(function (d, i) { d.style.setProperty("--p", clamp(p * n - i, 0, 1)); });
      if (seg === last) return;
      imgs.forEach(function (im, i) { im.classList.toggle("on", i === seg); im.classList.toggle("out", i < seg); });
      items.forEach(function (it, i) { it.classList.toggle("on", i === seg); });
      last = seg;
    }
    ticks.push(sigTick); dirty = true;
  } else if (sig) { $$(".signatures .item").forEach(function (it) { it.classList.add("on"); }); var f0 = $(".signatures .plate img"); if (f0) f0.classList.add("on"); }

  /* ---------- Scène 4 : galerie horizontale ---------- */
  var gal = $(".gal"), track = $(".track");
  if (gal && track && !reduce) {
    var gimgs = $$("img", track), gmax = 0;
    function galSize() { gmax = track.scrollWidth - window.innerWidth; }
    function galTick() {
      if (mobile()) { track.style.transform = ""; return; }
      var p = progress(gal);
      track.style.transform = "translate3d(" + (-p * gmax) + "px,0,0)";
      for (var i = 0; i < gimgs.length; i++) gimgs[i].style.transform = "translateX(" + ((p - .5) * (i % 2 ? 24 : -24)) + "px) scale(1.06)";
    }
    galSize(); window.addEventListener("resize", galSize); window.addEventListener("load", galSize);
    ticks.push(galTick); dirty = true;
  }

  /* ---------- Scène 5 : composez votre box ---------- */
  var box = $(".box");
  if (box) {
    var MAX = 4, sel = [], picks = $$(".pick"), slots = $$(".slot"), tot = $("#box-total"), btn = $("#box-add"), cnt = $("#box-count"), msg = $("#box-msg");
    function say(t) { if (!msg) return; msg.textContent = t; msg.classList.add("on"); clearTimeout(say.t); say.t = setTimeout(function () { msg.classList.remove("on"); }, 2200); }
    function renderBox() {
      slots.forEach(function (s, i) {
        var c = sel[i]; s.classList.toggle("full", !!c);
        s.innerHTML = c ? '<img src="' + c.img + '" alt="' + c.name + '"><b class="rm" aria-hidden="true">✕</b>' : "<em>" + (i + 1) + "</em>";
        s.setAttribute("aria-label", c ? "Retirer " + c.name : "Emplacement " + (i + 1) + " vide");
        s.setAttribute("tabindex", c ? "0" : "-1"); s.setAttribute("role", c ? "button" : "");
      });
      var sum = sel.reduce(function (a, c) { return a + c.price; }, 0);
      tot.textContent = sum.toLocaleString("fr-FR") + " DH"; cnt.textContent = sel.length + "/" + MAX;
      picks.forEach(function (p) {
        var k = sel.filter(function (c) { return c.code === p.getAttribute("data-code"); }).length;
        p.classList.toggle("on", k > 0); p.querySelector(".q").textContent = "×" + k;
        p.classList.toggle("dim", sel.length >= MAX && !k);
      });
      btn.setAttribute("aria-disabled", sel.length ? "false" : "true");
      box.classList.toggle("full", sel.length >= MAX);
    }
    function removeAt(i) { if (i > -1 && i < sel.length) { var c = sel.splice(i, 1)[0]; renderBox(); say(c.name + " retiré"); } }
    picks.forEach(function (p) {
      p.addEventListener("click", function () {
        var code = p.getAttribute("data-code");
        if (sel.length >= MAX) {
          var i = sel.findIndex(function (c) { return c.code === code; });
          if (i > -1) return removeAt(i);
          say("Box pleine : retirez un roll pour en ajouter un autre."); return;
        }
        sel.push({ code: code, name: p.getAttribute("data-name"), price: parseFloat(p.getAttribute("data-price")), img: p.querySelector("img").src });
        renderBox();
      });
      var minus = p.querySelector(".minus");
      if (minus) minus.addEventListener("click", function (e) {
        e.stopPropagation(); var code = p.getAttribute("data-code");
        removeAt(sel.findIndex(function (c) { return c.code === code; }));
      });
    });
    slots.forEach(function (s, i) {
      s.addEventListener("click", function () { if (sel[i]) removeAt(i); });
      s.addEventListener("keydown", function (e) { if ((e.key === "Enter" || e.key === " ") && sel[i]) { e.preventDefault(); removeAt(i); } });
    });
    var clr = $("#box-clear"); if (clr) clr.addEventListener("click", function () { sel = []; renderBox(); });
    btn.addEventListener("click", function (e) {
      e.preventDefault(); if (!sel.length) return;
      sel.forEach(function (c) { var b = document.querySelector('.p[data-code="' + c.code + '"] .add'); if (b) b.click(); });
      sel = []; renderBox();
      var open = $("[data-open-cart]"); if (open) setTimeout(function () { open.click(); }, 400);
    });
    renderBox();
  }
})();
