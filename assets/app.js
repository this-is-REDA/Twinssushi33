/* Twins Sushi — interactions (carte, filtres, commande à emporter via WhatsApp) */
(function () {
  "use strict";
  var CFG = window.TWINS || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* Thème clair / sombre : suit le téléphone, sauf choix manuel mémorisé */
  var root = document.documentElement, mq = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;
  function isDark() { var t = root.getAttribute("data-theme"); return t ? t === "dark" : !!(mq && mq.matches); }
  function syncMeta() {
    $$('meta[name="theme-color"]').forEach(function (m) { m.setAttribute("content", isDark() ? "#1A1312" : "#FBF6EF"); m.removeAttribute("media"); });
  }
  $$(".theme").forEach(function (b) {
    b.addEventListener("click", function () {
      var next = isDark() ? "light" : "dark";
      root.setAttribute("data-theme", next);
      try { localStorage.setItem("twins-theme", next); } catch (e) {}
      syncMeta();
    });
  });
  if (root.getAttribute("data-theme")) syncMeta();

  /* Plan Google Maps chargé à la demande (plus rapide, pas de cookies avant clic) */
  $$(".map-load").forEach(function (b) {
    b.addEventListener("click", function () {
      var box = b.closest(".map"), f = document.createElement("iframe");
      f.src = box.getAttribute("data-src"); f.title = "Plan d’accès Twins Sushi"; f.loading = "lazy";
      f.referrerPolicy = "no-referrer-when-downgrade"; box.innerHTML = ""; box.appendChild(f);
    });
  });

  /* Horaires : surligner le jour courant */
  var d = new Date().getDay(); // 0 = dimanche
  $$(".hours tr").forEach(function (tr) {
    var days = (tr.getAttribute("data-days") || "").split(",");
    if (days.indexOf(String(d)) > -1) tr.classList.add("today");
  });

  /* ---------- Carte : navigation par catégories ---------- */
  var chips = $$(".cats a");
  var cats = $$(".cat");
  var io = null;
  function initNav() {
    chips = $$(".cats a"); cats = $$(".cat");
    if (io) io.disconnect();
    if ("IntersectionObserver" in window && cats.length) {
      io = new IntersectionObserver(function (es) {
        es.forEach(function (e) { if (e.isIntersecting) setOn(e.target.id); });
      }, { rootMargin: "-45% 0px -50% 0px" });
      cats.forEach(function (c) { io.observe(c); });
    }
  }
  function setOn(id) {
    chips.forEach(function (c) {
      var on = c.getAttribute("href") === "#" + id;
      c.classList.toggle("on", on);
      if (on && c.scrollIntoView) {
        var bar = c.parentNode, l = c.offsetLeft - bar.clientWidth / 2 + c.clientWidth / 2;
        bar.scrollTo({ left: l, behavior: "smooth" });
      }
    });
  }
  initNav();

  /* ---------- Recherche et filtres ---------- */
  var q = $("#q"), empty = $(".empty");
  var active = {};
  function norm(s) { return (s || "").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, ""); }
  function applyFilter() {
    var term = norm(q ? q.value.trim() : "");
    var keys = Object.keys(active).filter(function (k) { return active[k]; });
    var shown = 0;
    cats.forEach(function (cat) {
      var n = 0;
      $$(".p", cat).forEach(function (p) {
        var ok = (!term || norm(p.getAttribute("data-search")).indexOf(term) > -1) &&
                 (!keys.length || keys.indexOf(p.getAttribute("data-badge")) > -1);
        p.hidden = !ok; if (ok) n++;
      });
      cat.hidden = n === 0; shown += n;
    });
    if (empty) empty.hidden = shown > 0;
  }
  if (q) q.addEventListener("input", applyFilter);
  $$(".fchip").forEach(function (b) {
    b.addEventListener("click", function () {
      var k = b.getAttribute("data-f"), on = b.getAttribute("aria-pressed") !== "true";
      b.setAttribute("aria-pressed", on ? "true" : "false"); active[k] = on; applyFilter();
    });
  });

  /* ---------- Commande à emporter ---------- */
  var KEY = "twins-panier-v1";
  var items = {};
  function readItems() {
    items = {};
    $$(".p[data-code]").forEach(function (p) {
      items[p.getAttribute("data-code")] = {
        name: p.getAttribute("data-name"),
        price: parseFloat(p.getAttribute("data-price")) || 0,
        img: (p.querySelector(".ph img") || {}).getAttribute ? p.querySelector(".ph img").getAttribute("src") : ""
      };
    });
  }
  readItems();
  /* La carte peut être re-générée (aperçu relié à la base) : on relit tout */
  window.TWINS_REFRESH_ITEMS = function () {
    readItems(); Object.keys(cart).forEach(function (k) { if (!items[k] || !items[k].price) delete cart[k]; });
    initNav(); applyFilter(); render();
  };
  var cart = {};
  try { cart = JSON.parse(localStorage.getItem(KEY) || "{}") || {}; } catch (e) { cart = {}; }
  Object.keys(cart).forEach(function (k) { if (!items[k] || !items[k].price) delete cart[k]; });
  function save() { try { localStorage.setItem(KEY, JSON.stringify(cart)); } catch (e) {} }
  function count() { return Object.keys(cart).reduce(function (a, k) { return a + cart[k]; }, 0); }
  function total() { return Object.keys(cart).reduce(function (a, k) { return a + cart[k] * items[k].price; }, 0); }
  function fmt(n) { return n.toLocaleString("fr-FR") + " DH"; }

  var drawer = $("#panier"), toastEl = $(".toast"), toastT;
  function toast(msg) {
    if (!toastEl) return;
    toastEl.textContent = msg; toastEl.classList.add("on");
    clearTimeout(toastT); toastT = setTimeout(function () { toastEl.classList.remove("on"); }, 1800);
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }

  function render() {
    var n = count();
    $$(".cart-count").forEach(function (el) { el.textContent = n; });
    var fab = $(".fab"); if (fab) fab.classList.toggle("show", n > 0); document.body.classList.toggle("has-fab", n > 0);
    $$(".p[data-code] .add").forEach(function (b) {
      var c = b.closest(".p").getAttribute("data-code");
      b.classList.toggle("in", !!cart[c]);
    });
    var list = $("#lines"); if (!list) return;
    var keys = Object.keys(cart);
    if (!keys.length) {
      list.innerHTML = '<div class="cart-empty"><p>Votre commande est vide.</p><a class="btn btn-ligne" href="#carte" data-close>Voir la carte</a></div>';
    } else {
      list.innerHTML = keys.map(function (k) {
        var it = items[k];
        return '<div class="line"><img src="' + esc(it.img) + '" alt="" loading="lazy"><div><div class="nm">' + esc(it.name) +
          '</div><div class="pr">' + fmt(it.price * cart[k]) + '</div></div><div class="qty">' +
          '<button type="button" data-dec="' + k + '" aria-label="Retirer un ' + esc(it.name) + '">−</button><span>' + cart[k] +
          '</span><button type="button" data-inc="' + k + '" aria-label="Ajouter un ' + esc(it.name) + '">+</button></div></div>';
      }).join("");
    }
    var t = $("#total"); if (t) t.textContent = fmt(total());
    $$("#panier form, #panier footer").forEach(function (el) { el.hidden = !keys.length; });
    updateLink();
  }

  function message() {
    var lines = ["Bonjour Twins Sushi, je souhaite passer une commande à emporter :", ""];
    Object.keys(cart).forEach(function (k) {
      lines.push("• " + cart[k] + " × " + items[k].name + " — " + fmt(items[k].price * cart[k]));
    });
    lines.push("", "Total : " + fmt(total()));
    var nm = $("#c-nom"), hr = $("#c-heure"), nt = $("#c-note");
    if (nm && nm.value.trim()) lines.push("Nom : " + nm.value.trim());
    if (hr) lines.push("Retrait : " + hr.value);
    if (nt && nt.value.trim()) lines.push("Remarque : " + nt.value.trim());
    return lines.join("\n");
  }
  function updateLink() {
    var a = $("#send"); if (!a) return;
    var nm = $("#c-nom");
    var ok = count() > 0 && nm && nm.value.trim().length > 1;
    a.setAttribute("aria-disabled", ok ? "false" : "true");
    a.href = "https://wa.me/" + CFG.wa + "?text=" + encodeURIComponent(message());
  }

  function open() { if (drawer) { drawer.hidden = false; document.body.style.overflow = "hidden"; var x = $(".x", drawer); if (x) x.focus(); } }
  function close() { if (drawer) { drawer.hidden = true; document.body.style.overflow = ""; } }

  document.addEventListener("click", function (e) {
    var t = e.target;
    var add = t.closest && t.closest(".add");
    if (add && !add.disabled) {
      var c = add.closest(".p").getAttribute("data-code");
      cart[c] = (cart[c] || 0) + 1; save(); render(); toast("Ajouté : " + items[c].name);
      return;
    }
    var inc = t.closest && t.closest("[data-inc]"), dec = t.closest && t.closest("[data-dec]");
    if (inc) { var k = inc.getAttribute("data-inc"); cart[k]++; save(); render(); return; }
    if (dec) { var k2 = dec.getAttribute("data-dec"); cart[k2]--; if (cart[k2] <= 0) delete cart[k2]; save(); render(); return; }
    if (t.closest && t.closest("[data-open-cart]")) { e.preventDefault(); open(); return; }
    if (t.closest && t.closest("[data-close]")) { close(); return; }
    if (t === drawer) close();
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });
  ["#c-nom", "#c-heure", "#c-note"].forEach(function (s) {
    var el = $(s); if (el) { el.addEventListener("input", updateLink); el.addEventListener("change", updateLink); }
  });
  var send = $("#send");
  if (send) send.addEventListener("click", function (e) {
    if (send.getAttribute("aria-disabled") === "true") { e.preventDefault(); var nm = $("#c-nom"); if (nm) nm.focus(); }
  });

  /* Créneaux de retrait : toutes les 15 min pour les 3 prochaines heures */
  var sel = $("#c-heure");
  if (sel) {
    var now = new Date(), start = new Date(now.getTime() + 30 * 60000);
    start.setMinutes(Math.ceil(start.getMinutes() / 15) * 15, 0, 0);
    var opts = ['<option>Dès que possible</option>'];
    for (var i = 0; i < 12; i++) {
      var s = new Date(start.getTime() + i * 15 * 60000);
      opts.push("<option>Vers " + String(s.getHours()).padStart(2, "0") + "h" + String(s.getMinutes()).padStart(2, "0") + "</option>");
    }
    sel.innerHTML = opts.join("");
  }

  render();
})();
