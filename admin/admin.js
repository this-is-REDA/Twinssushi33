/* Twins Sushi — espace admin (sans dépendance) */
(function () {
  "use strict";
  var BASE = window.TWINS_BASE != null ? window.TWINS_BASE : "../"; // racine du site vue depuis /admin/
  var DAYS = [["1", "L"], ["2", "M"], ["3", "M"], ["4", "J"], ["5", "V"], ["6", "S"], ["0", "D"]];
  var DAY_NAMES = { "1": "lundi", "2": "mardi", "3": "mercredi", "4": "jeudi", "5": "vendredi", "6": "samedi", "0": "dimanche" };
  var S = { user: "", csrf: "", menu: null, settings: null, badges: {}, tab: "carte", cat: null, q: "" };
  var app = document.getElementById("app");

  /* Thème : même choix que le site public */
  try { var th = localStorage.getItem("twins-theme"); if (th === "dark" || th === "light") document.documentElement.setAttribute("data-theme", th); } catch (e) {}

  /* ---------- Outils ---------- */
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function asset(p) { var m = window.TWINS_IMG_MAP; return m && m[p] ? m[p] : BASE + p; }
  function img(p) { return !p ? "" : /^(data:|blob:|https?:)/.test(p) ? p : asset(p); }
  var SITE = window.TWINS_SITE_URL || BASE;
  var toastT;
  function toast(msg, bad) {
    var t = $(".toast"); if (!t) { t = document.createElement("div"); t.className = "toast"; t.setAttribute("role", "status"); document.body.appendChild(t); }
    t.textContent = msg; t.classList.toggle("bad", !!bad); t.classList.add("on");
    clearTimeout(toastT); toastT = setTimeout(function () { t.classList.remove("on"); }, 2600);
  }
  var I = {
    up: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>',
    down: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/></svg>',
    x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    ext: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>'
  };

  /* ---------- API ---------- */
  function api(action, payload) {
    var hook = window.TWINS_API || window.TWINS_DEMO_API; if (hook) return hook(action, payload || {});
    var body = JSON.stringify(Object.assign({ action: action }, payload || {}));
    return fetch("api.php", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF": S.csrf }, body: body })
      .then(parse);
  }
  function upload(code, file) {
    var hook2 = window.TWINS_API || window.TWINS_DEMO_API; if (hook2) return hook2("upload", { code: code, file: file });
    var fd = new FormData(); fd.append("action", "upload"); fd.append("code", code); fd.append("csrf", S.csrf); fd.append("photo", file);
    return fetch("api.php", { method: "POST", credentials: "same-origin", headers: { "X-CSRF": S.csrf }, body: fd }).then(parse);
  }
  function parse(r) {
    return r.json().catch(function () { return { ok: false, error: "Réponse du serveur illisible (" + r.status + ")." }; }).then(function (d) {
      if (r.status === 401 && S.user) { S.user = ""; renderLogin("Session expirée, reconnectez-vous."); }
      if (!d.ok) throw new Error(d.error || "Erreur");
      return d;
    });
  }
  function apply(d) { if (d.menu) S.menu = d.menu; if (d.settings) S.settings = d.settings; if (d.badges) S.badges = d.badges; }
  function run(action, payload, okMsg) {
    return api(action, payload).then(function (d) { apply(d); render(); if (okMsg) toast(okMsg); return d; })
      .catch(function (e) { toast(e.message, true); throw e; });
  }

  /* ---------- Connexion ---------- */
  function renderLogin(msg) {
    if (window.TWINS_API) {
      app.innerHTML = '<div class="login"><form onsubmit="return false"><img class="logo lg-l" src="' + asset('assets/img/logo-96.webp') + '" alt="" width="84" height="77"><h1 class="t-titre">Espace admin</h1><p class="muted">' + esc(msg || "Cet aperçu est en lecture seule pour vous. Seul le propriétaire de l’aperçu (ou une personne invitée en « Éditeur ») peut modifier la carte.") + '</p><a class="b" href="#">Retour au site</a></form></div>';
      return;
    }
    app.innerHTML = '<div class="login"><form id="lf" novalidate>' +
      '<img class="logo lg-l" src="' + asset('assets/img/logo-96.webp') + '" alt="Twins Sushi" width="84" height="77"><img class="logo lg-d" src="' + asset('assets/img/logo-creme-96.webp') + '" alt="Twins Sushi" width="84" height="77">' +
      '<h1 class="t-titre">Espace admin</h1><p class="muted">Gestion de la carte Twins Sushi</p>' +
      (window.TWINS_DEMO_API ? '<p class="muted demo-hint">Version de démonstration · identifiant <b>demo</b>, mot de passe <b>demo</b></p>' : "") +
      '<div class="err" id="le">' + esc(msg || "") + '</div>' +
      '<label class="f"><span>Identifiant</span><input id="lu" autocomplete="username" required autocapitalize="none" spellcheck="false"></label>' +
      '<label class="f"><span>Mot de passe</span><input id="lp" type="password" autocomplete="current-password" required></label>' +
      '<button class="b p" type="submit">Se connecter</button></form></div>';
    $("#lu").focus();
    $("#lf").addEventListener("submit", function (e) {
      e.preventDefault();
      var btn = $("#lf button"); btn.disabled = true;
      api("login", { user: $("#lu").value, pass: $("#lp").value }).then(function (d) {
        S.user = d.user; S.csrf = d.csrf;
        return load();
      }).catch(function (err) { $("#le").textContent = err.message; btn.disabled = false; $("#lp").select(); });
    });
  }

  function load() {
    return api("data").then(function (d) {
      apply(d);
      if (!S.cat && S.menu.categories.length) S.cat = S.menu.categories[0].id;
      render();
    }).catch(function (e) { toast(e.message, true); });
  }

  /* ---------- Structure ---------- */
  function render() {
    if (!S.menu) return;
    var tabs = [["carte", "La carte"], ["reglages", "Infos & liens"], ["securite", "Sécurité & sauvegardes"]];
    var demo = window.TWINS_DEMO_API ? '<div class="demo">Démo : les modifications restent dans ce navigateur et ne changent pas le site en ligne.</div>' : "";
    app.innerHTML = demo + '<header class="top"><div class="in">' +
      '<a class="brand" href="' + SITE + '" target="_blank" rel="noopener"><img class="lg-l" src="' + asset('assets/img/logo-96.webp') + '" alt="Twins Sushi" width="42" height="38"><img class="lg-d" src="' + asset('assets/img/logo-creme-96.webp') + '" alt="Twins Sushi" width="42" height="38"><b>ADMIN</b></a>' +
      '<a class="b sm" href="' + SITE + '" target="_blank" rel="noopener">' + I.ext + '<span class="hide-sm">Voir le site</span></a>' +
      '<button class="b sm" id="theme" type="button" aria-label="Changer de thème">◐</button>' +
      '<button class="b sm" id="logout" type="button">Déconnexion</button></div>' +
      '<nav class="tabs" role="tablist">' + tabs.map(function (t) {
        return '<button role="tab" type="button" data-tab="' + t[0] + '" aria-selected="' + (S.tab === t[0]) + '">' + t[1] + '</button>';
      }).join("") + '</nav></header><main class="main" id="main"></main>';
    $$("[data-tab]").forEach(function (b) { b.addEventListener("click", function () { S.tab = b.getAttribute("data-tab"); render(); }); });
    $("#logout").addEventListener("click", function () { api("logout").finally(function () { S.user = ""; S.menu = null; renderLogin(); }); });
    $("#theme").addEventListener("click", function () {
      var r = document.documentElement, cur = r.getAttribute("data-theme") || (matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
      var nx = cur === "dark" ? "light" : "dark"; r.setAttribute("data-theme", nx); try { localStorage.setItem("twins-theme", nx); } catch (e) {}
    });
    if (S.tab === "carte") viewMenu(); else if (S.tab === "reglages") viewSettings(); else viewSecurity();
  }

  /* ---------- Carte ---------- */
  function price(it) { return it.price === null || it.price === "" ? '<span class="pr tbd">Prix à venir</span>' : '<span class="pr">' + it.price + ' DH</span>'; }
  function itemRow(it, cat, idx, len) {
    var dim = it.visible === false ? " dim" : "";
    var tags = (it.badge ? '<span class="pill ' + esc(it.badge) + '">' + esc(S.badges[it.badge] || it.badge) + '</span>' : "") +
      (it.available === false ? '<span class="pill off">Indisponible</span>' : "") + (it.visible === false ? '<span class="pill">Masqué</span>' : "");
    return '<div class="it' + dim + '" data-code="' + esc(it.code) + '">' +
      (it.img ? '<img src="' + esc(img(it.img)) + '" alt="" loading="lazy" width="56" height="56">' : '<span class="noimg">Pas de photo</span>') +
      '<div class="mw0"><div class="nm">' + esc(it.name) + tags + '</div><div class="ds">' + esc(it.desc) + '</div></div>' +
      price(it) +
      '<div class="tg"><label class="sw"><input type="checkbox" data-toggle="available"' + (it.available === false ? "" : " checked") + '>Disponible</label>' +
      '<label class="sw"><input type="checkbox" data-toggle="visible"' + (it.visible === false ? "" : " checked") + '>En ligne</label>' +
      '<div class="ac">' + (cat ? '<button class="b ic" type="button" data-move="-1" aria-label="Monter"' + (idx === 0 ? " disabled" : "") + '>' + I.up + '</button>' +
      '<button class="b ic" type="button" data-move="1" aria-label="Descendre"' + (idx === len - 1 ? " disabled" : "") + '>' + I.down + '</button>' : "") +
      '<button class="b sm" type="button" data-edit>' + I.edit + 'Modifier</button></div></div></div>';
  }
  function catOf(code) { var r = null; S.menu.categories.forEach(function (c) { c.items.forEach(function (i) { if (i.code === code) r = c; }); }); return r; }
  function itemOf(code) { var r = null; S.menu.categories.forEach(function (c) { c.items.forEach(function (i) { if (i.code === code) r = i; }); }); return r; }

  function viewMenu() {
    var cats = S.menu.categories;
    if (!cats.some(function (c) { return c.id === S.cat; })) S.cat = cats[0] ? cats[0].id : null;
    var total = 0, off = 0; cats.forEach(function (c) { total += c.items.length; c.items.forEach(function (i) { if (i.available === false) off++; }); });
    var side = cats.map(function (c) {
      return '<button type="button" data-cat="' + esc(c.id) + '" class="' + (c.id === S.cat ? "on" : "") + (c.visible === false ? " hidden-cat" : "") + '"><span>' + esc(c.title) + '</span><span class="n">' + c.items.length + '</span></button>';
    }).join("");
    var pick = '<label class="f cat-pick"><span>Catégorie</span><select id="catpick">' + cats.map(function (c) {
      return '<option value="' + esc(c.id) + '"' + (c.id === S.cat ? " selected" : "") + '>' + esc(c.title) + ' (' + c.items.length + ')</option>'; }).join("") + '</select></label>';
    var body;
    var q = S.q.trim().toLowerCase();
    if (q) {
      var hits = [];
      cats.forEach(function (c) { c.items.forEach(function (i) { if ((i.name + " " + i.desc + " " + i.code).toLowerCase().indexOf(q) > -1) hits.push(i); }); });
      body = '<section class="cat-card"><div class="cat-top"><h2 class="t-titre">Résultats</h2><span class="muted">' + hits.length + ' plat(s)</span></div><div class="items">' +
        (hits.length ? hits.map(function (i) { return itemRow(i, null); }).join("") : '<p class="empty-cat">Aucun plat trouvé.</p>') + '</div></section>';
    } else {
      var c = cats.filter(function (x) { return x.id === S.cat; })[0];
      if (!c) body = '<p class="muted">Aucune catégorie. Créez-en une.</p>';
      else {
        var ci = cats.indexOf(c);
        body = '<section class="cat-card"><div class="cat-top"><h2 class="t-titre">' + esc(c.title) + '<span class="jp">' + esc(c.jp || "") + '</span></h2>' +
          (c.visible === false ? '<span class="pill off">Catégorie masquée</span>' : "") +
          '<button class="b ic" type="button" data-cmove="-1" aria-label="Monter la catégorie"' + (ci === 0 ? " disabled" : "") + '>' + I.up + '</button>' +
          '<button class="b ic" type="button" data-cmove="1" aria-label="Descendre la catégorie"' + (ci === cats.length - 1 ? " disabled" : "") + '>' + I.down + '</button>' +
          '<button class="b sm" type="button" id="catedit">' + I.edit + 'Catégorie</button>' +
          '<button class="b sm p" type="button" id="additem">+ Ajouter un plat</button></div><div class="items">' +
          (c.items.length ? c.items.map(function (i, k) { return itemRow(i, c, k, c.items.length); }).join("") : '<p class="empty-cat">Aucun plat dans cette catégorie.</p>') + '</div></section>';
      }
    }
    $("#main").innerHTML = '<div class="toolbar"><div class="search"><label class="sr" for="q">Rechercher</label><input id="q" type="search" placeholder="Rechercher un plat (nom, ingrédient, code)…" value="' + esc(S.q) + '"></div>' +
      '<span class="muted caps">' + total + ' plats · ' + off + ' indisponible(s)</span></div>' +
      '<div class="menu-layout"><nav class="side" aria-label="Catégories">' + side + '<button class="b sm add-cat" type="button" id="addcat">+ Nouvelle catégorie</button></nav><div>' + pick + body + '</div></div>';

    var qi = $("#q");
    qi.addEventListener("input", function () { S.q = qi.value; var pos = qi.selectionStart; viewMenu(); var n = $("#q"); n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) {} });
    $$("[data-cat]").forEach(function (b) { b.addEventListener("click", function () { S.cat = b.getAttribute("data-cat"); S.q = ""; viewMenu(); window.scrollTo({ top: 0 }); }); });
    var cp = $("#catpick"); if (cp) cp.addEventListener("change", function () { S.cat = cp.value; S.q = ""; viewMenu(); });
    var ai = $("#additem"); if (ai) ai.addEventListener("click", function () { editItem(null, S.cat); });
    $("#addcat").addEventListener("click", function () { editCat(null); });
    var ce = $("#catedit"); if (ce) ce.addEventListener("click", function () { editCat(S.cat); });
    $$("[data-cmove]").forEach(function (b) { b.addEventListener("click", function () { run("cat_move", { id: S.cat, dir: +b.getAttribute("data-cmove") }); }); });
    $$(".it").forEach(function (row) {
      var code = row.getAttribute("data-code");
      $$("[data-toggle]", row).forEach(function (t) {
        t.addEventListener("change", function () {
          var f = t.getAttribute("data-toggle");
          run("item_toggle", { code: code, field: f }, f === "available" ? (t.checked ? "Plat disponible" : "Plat marqué indisponible") : (t.checked ? "Plat affiché sur le site" : "Plat masqué du site"));
        });
      });
      $$("[data-move]", row).forEach(function (b) { b.addEventListener("click", function () { run("item_move", { code: code, dir: +b.getAttribute("data-move") }); }); });
      var e = $("[data-edit]", row); if (e) e.addEventListener("click", function () { editItem(code); });
    });
  }

  /* ---------- Modale générique ---------- */
  function modal(title, bodyHtml, footHtml) {
    var m = document.createElement("div"); m.className = "modal"; m.setAttribute("role", "dialog"); m.setAttribute("aria-modal", "true");
    m.innerHTML = '<div class="sheet"><header><h2 class="t-titre">' + esc(title) + '</h2><button class="b ic" type="button" data-x aria-label="Fermer">' + I.x + '</button></header>' +
      '<div class="bd">' + bodyHtml + '</div><footer>' + footHtml + '</footer></div>';
    document.body.appendChild(m); document.body.style.overflow = "hidden";
    function close() { m.remove(); document.body.style.overflow = ""; document.removeEventListener("keydown", onKey); }
    function onKey(e) { if (e.key === "Escape") close(); }
    document.addEventListener("keydown", onKey);
    m.addEventListener("click", function (e) { if (e.target === m || e.target.closest("[data-x]")) close(); });
    var first = $("input,select,textarea", m); if (first) setTimeout(function () { first.focus(); }, 30);
    return { el: m, close: close };
  }
  function confirmBtn(btn, label, fn) {
    btn.addEventListener("click", function () {
      if (!btn.classList.contains("confirm")) { btn.classList.add("confirm"); btn.textContent = label; setTimeout(function () { if (btn.isConnected) { btn.classList.remove("confirm"); btn.textContent = btn.getAttribute("data-label"); } }, 4000); return; }
      fn();
    });
  }

  /* ---------- Édition d'un plat ---------- */
  function editItem(code, catId) {
    var it = code ? itemOf(code) : { code: "", name: "", desc: "", price: "", pcs: 1, showPcs: false, badge: "", available: true, visible: true, img: "" };
    var cat = code ? catOf(code).id : catId;
    var badges = Object.keys(S.badges).map(function (k) { return '<option value="' + k + '"' + (it.badge === k ? " selected" : "") + '>' + esc(S.badges[k]) + '</option>'; }).join("");
    var cats = S.menu.categories.map(function (c) { return '<option value="' + esc(c.id) + '"' + (c.id === cat ? " selected" : "") + '>' + esc(c.title) + '</option>'; }).join("");
    var body =
      (code ? '<div class="photo-edit"><div class="pv" id="pv">' + (it.img ? '<img src="' + esc(img(it.img)) + '" alt="">' : '<span class="muted caps">Pas de photo</span>') + '</div>' +
        '<div class="btns"><span class="b sm file-btn">Changer la photo<input type="file" id="ph" accept="image/jpeg,image/png,image/webp"></span>' +
        (it.img ? '<button class="b sm d" type="button" id="phrm">Retirer</button>' : "") + '<small class="muted fb100">JPG, PNG ou WebP, 8 Mo max. Fond détouré (PNG) conseillé.</small></div></div>'
        : '<p class="muted m0">Vous pourrez ajouter la photo juste après avoir créé le plat.</p>') +
      '<label class="f"><span>Nom du plat</span><input id="e-name" maxlength="80" value="' + esc(it.name) + '" placeholder="Ex. California Saumon Avocat"></label>' +
      '<label class="f"><span>Description <em class="count" id="dc"></em></span><textarea id="e-desc" maxlength="220" placeholder="Ingrédients principaux, sans grammages">' + esc(it.desc) + '</textarea></label>' +
      '<div class="row3"><label class="f"><span>Prix (DH)</span><input id="e-price" type="number" inputmode="numeric" min="0" step="1" value="' + (it.price === null ? "" : esc(it.price)) + '" placeholder="Vide = prix à venir"></label>' +
      '<label class="f"><span>Nb de pièces</span><input id="e-pcs" type="number" inputmode="numeric" min="1" step="1" value="' + esc(it.pcs || 1) + '"></label>' +
      '<label class="f"><span>Badge</span><select id="e-badge">' + badges + '</select></label></div>' +
      '<label class="f"><span>Catégorie</span><select id="e-cat">' + cats + '</select></label>' +
      '<div class="checks"><label class="sw"><input type="checkbox" id="e-showpcs"' + (it.showPcs ? " checked" : "") + '>Afficher « X PCS » à côté du prix</label>' +
      '<label class="sw"><input type="checkbox" id="e-av"' + (it.available === false ? "" : " checked") + '>Disponible</label>' +
      '<label class="sw"><input type="checkbox" id="e-vis"' + (it.visible === false ? "" : " checked") + '>En ligne</label></div>' +
      '<div class="err" id="ee"></div>';
    var foot = (code ? '<button class="b d sp" type="button" id="del" data-label="Supprimer">Supprimer</button>' : '<span class="sp"></span>') +
      '<button class="b" type="button" data-x>Annuler</button><button class="b p" type="button" id="save">' + (code ? "Enregistrer" : "Créer le plat") + '</button>';
    var M = modal(code ? "Modifier le plat" : "Nouveau plat", body, foot);
    var d = $("#e-desc", M.el), dc = $("#dc", M.el);
    function cnt() { dc.textContent = d.value.length + "/220"; } d.addEventListener("input", cnt); cnt();
    $("#save", M.el).addEventListener("click", function () {
      var btn = this; btn.disabled = true;
      var payload = { cat: $("#e-cat", M.el).value, item: {
        code: it.code, name: $("#e-name", M.el).value, desc: d.value, price: $("#e-price", M.el).value, pcs: $("#e-pcs", M.el).value || 1,
        badge: $("#e-badge", M.el).value, showPcs: $("#e-showpcs", M.el).checked, available: $("#e-av", M.el).checked, visible: $("#e-vis", M.el).checked } };
      api("item_save", payload).then(function (r) {
        apply(r); S.cat = payload.cat; M.close(); render(); toast(code ? "Plat enregistré" : "Plat créé");
        if (!code && r.code) editItem(r.code);
      }).catch(function (e) { $("#ee", M.el).textContent = e.message; btn.disabled = false; });
    });
    if (code) {
      confirmBtn($("#del", M.el), "Confirmer la suppression", function () { run("item_delete", { code: code }, "Plat supprimé").then(M.close); });
      var ph = $("#ph", M.el);
      ph.addEventListener("change", function () {
        var f = ph.files[0]; if (!f) return;
        if (f.size > 8 * 1024 * 1024) { toast("Photo trop lourde (8 Mo max).", true); return; }
        $("#pv", M.el).innerHTML = '<span class="muted caps">Envoi…</span>';
        upload(code, f).then(function (r) { apply(r); render(); M.close(); editItem(code); toast("Photo mise à jour"); })
          .catch(function (e) { toast(e.message, true); $("#pv", M.el).innerHTML = it.img ? '<img src="' + esc(img(it.img)) + '" alt="">' : ""; });
      });
      var rm = $("#phrm", M.el); if (rm) rm.addEventListener("click", function () { run("photo_remove", { code: code }, "Photo retirée").then(function () { M.close(); editItem(code); }); });
    }
  }

  /* ---------- Édition d'une catégorie ---------- */
  function editCat(id) {
    var c = id ? S.menu.categories.filter(function (x) { return x.id === id; })[0] : { title: "", jp: "", visible: true, items: [] };
    var body = '<label class="f"><span>Titre</span><input id="c-title" maxlength="50" value="' + esc(c.title) + '" placeholder="Ex. Les Spéciaux"></label>' +
      '<label class="f"><span>Sous-titre japonais (facultatif)</span><input id="c-jp" maxlength="30" value="' + esc(c.jp) + '" lang="ja" placeholder="Ex. スペシャル"></label>' +
      (id ? '<label class="sw"><input type="checkbox" id="c-vis"' + (c.visible === false ? "" : " checked") + '>Catégorie affichée sur le site</label>' : "") +
      '<div class="err" id="ce"></div>';
    var foot = (id ? '<button class="b d sp" type="button" id="cdel" data-label="Supprimer">Supprimer</button>' : '<span class="sp"></span>') +
      '<button class="b" type="button" data-x>Annuler</button><button class="b p" type="button" id="csave">' + (id ? "Enregistrer" : "Créer") + '</button>';
    var M = modal(id ? "Catégorie" : "Nouvelle catégorie", body, foot);
    $("#csave", M.el).addEventListener("click", function () {
      api("cat_save", { id: id || "", title: $("#c-title", M.el).value, jp: $("#c-jp", M.el).value, visible: id ? $("#c-vis", M.el).checked : true })
        .then(function (r) { apply(r); S.cat = r.id; M.close(); render(); toast("Catégorie enregistrée"); })
        .catch(function (e) { $("#ce", M.el).textContent = e.message; });
    });
    if (id) confirmBtn($("#cdel", M.el), "Confirmer", function () {
      api("cat_delete", { id: id }).then(function (r) { apply(r); S.cat = null; M.close(); render(); toast("Catégorie supprimée"); })
        .catch(function (e) { $("#ce", M.el).textContent = e.message; });
    });
  }

  /* ---------- Réglages ---------- */
  function fld(k, label, opt) {
    opt = opt || {};
    return '<label class="f"><span>' + label + '</span><input data-k="' + k + '" value="' + esc(S.settings[k] || "") + '"' + (opt.type ? ' type="' + opt.type + '"' : "") +
      (opt.ph ? ' placeholder="' + esc(opt.ph) + '"' : "") + (opt.max ? ' maxlength="' + opt.max + '"' : "") + '>' + (opt.help ? '<small>' + opt.help + '</small>' : "") + '</label>';
  }
  function hoursRow(h, k) {
    var ds = (h.days || "").split(",");
    return '<div class="hrow" data-h="' + k + '"><label class="f"><span>Libellé</span><input data-hk="label" value="' + esc(h.label) + '" placeholder="Lundi – Vendredi"></label>' +
      '<div class="f days-f"><span>Jours</span><div class="days">' + DAYS.map(function (d) {
        return '<label title="' + DAY_NAMES[d[0]] + '"><input type="checkbox" value="' + d[0] + '"' + (ds.indexOf(d[0]) > -1 ? " checked" : "") + ' aria-label="' + DAY_NAMES[d[0]] + '">' + d[1] + '</label>'; }).join("") + '</div></div>' +
      '<label class="f"><span>Ouverture</span><input data-hk="open" type="time" value="' + esc(h.open) + '"></label>' +
      '<label class="f"><span>Fermeture</span><input data-hk="close" type="time" value="' + esc(h.close) + '"></label>' +
      '<label class="f txt"><span>Texte affiché</span><input data-hk="text" value="' + esc(h.text) + '" placeholder="11h00 – minuit"></label>' +
      '<button class="b ic d" type="button" data-hdel aria-label="Supprimer cette ligne">' + I.x + '</button></div>';
  }
  function viewSettings() {
    var s = S.settings;
    $("#main").innerHTML =
      '<section class="panel"><h2 class="t-titre">Bandeau et annonce</h2><p class="hint">Le bandeau rouge s’affiche en haut du site jusqu’à la date de fin (incluse). L’annonce (bandeau brun) reste tant qu’elle n’est pas vidée.</p>' +
      '<div class="row2">' + fld("bandeau_texte", "Texte du bandeau rouge", { max: 140, ph: "Ouverture le lundi 28 septembre" }) + fld("bandeau_fin", "Afficher jusqu’au", { type: "date" }) + '</div>' +
      fld("annonce", "Annonce (facultatif)", { max: 160, ph: "Ex. Fermeture exceptionnelle le 1er janvier", help: "Laissez vide pour ne rien afficher." }) + '</section>' +
      '<section class="panel"><h2 class="t-titre">Horaires</h2><div id="hours">' + (s.horaires || []).map(hoursRow).join("") + '</div>' +
      '<div><button class="b sm" type="button" id="addh">+ Ajouter une ligne</button></div></section>' +
      '<section class="panel"><h2 class="t-titre">Contact et adresse</h2><div class="row2">' +
      fld("tel_affiche", "Téléphone (affiché)", { ph: "05 21 23 19 26" }) + fld("tel", "Téléphone (format international)", { ph: "+212521231926", help: "Utilisé pour le bouton « Appeler »." }) +
      fld("wa_affiche", "WhatsApp (affiché)", { ph: "07 19 16 20 94" }) + fld("wa", "WhatsApp (format international)", { ph: "212719162094", help: "Reçoit les commandes des clients. Sans le +." }) +
      fld("email", "E-mail", { type: "email" }) + fld("rue", "Rue") + fld("quartier", "Quartier") + fld("cp", "Code postal") + fld("ville", "Ville") + '</div></section>' +
      '<section class="panel"><h2 class="t-titre">Plateformes et réseaux</h2><p class="hint">Collez l’adresse complète de votre page (https://…). Vide = « bientôt » sur le site.</p><div class="row2">' +
      fld("glovo", "Glovo", { type: "url", ph: "https://glovoapp.com/…" }) + fld("yassir", "Yassir", { type: "url" }) + fld("kool", "Kool", { type: "url" }) +
      fld("instagram", "Instagram", { type: "url" }) + fld("tiktok", "TikTok", { type: "url" }) + fld("facebook", "Facebook", { type: "url" }) + '</div></section>' +
      '<div class="savebar"><button class="b p" type="button" id="ssave">Enregistrer les réglages</button></div>';
    function bindH() { $$("[data-hdel]").forEach(function (b) { b.onclick = function () { b.closest(".hrow").remove(); }; }); }
    bindH();
    $("#addh").addEventListener("click", function () { $("#hours").insertAdjacentHTML("beforeend", hoursRow({ label: "", days: "", open: "11:00", close: "23:59", text: "" }, Date.now())); bindH(); });
    $("#ssave").addEventListener("click", function () {
      var d = {}; $$("[data-k]").forEach(function (i) { d[i.getAttribute("data-k")] = i.value; });
      d.horaires = $$(".hrow").map(function (r) {
        var o = {}; $$("[data-hk]", r).forEach(function (i) { o[i.getAttribute("data-hk")] = i.value; });
        o.days = $$(".days input:checked", r).map(function (i) { return i.value; }).join(","); return o;
      });
      run("settings_save", { settings: d }, "Réglages enregistrés").catch(function () {});
    });
  }

  /* ---------- Sécurité ---------- */
  function viewSecurity() {
    $("#main").innerHTML =
      '<section class="panel"><h2 class="t-titre">Identifiants</h2>' +
      '<label class="f"><span>Identifiant</span><input id="s-user" value="' + esc(S.user) + '" autocomplete="username" autocapitalize="none"></label>' +
      '<div class="row2"><label class="f"><span>Mot de passe actuel</span><input id="s-cur" type="password" autocomplete="current-password"></label>' +
      '<label class="f"><span>Nouveau mot de passe</span><input id="s-new" type="password" autocomplete="new-password"><small>10 caractères minimum, lettres et chiffres.</small></label></div>' +
      '<div class="err" id="se"></div><div><button class="b p" type="button" id="spw">Mettre à jour</button></div></section>' +
      '<section class="panel"><h2 class="t-titre">Sauvegardes automatiques</h2><p class="hint">Chaque modification crée une copie de la version précédente, conservée 60 jours (40 plus récentes affichées). Restaurer remet la carte ou les réglages dans l’état de cette date.</p><div id="bk" class="muted">Chargement…</div></section>';
    $("#spw").addEventListener("click", function () {
      api("password", { user: $("#s-user").value, current: $("#s-cur").value, new: $("#s-new").value })
        .then(function (d) { S.user = d.user; S.csrf = d.csrf || S.csrf; $("#s-cur").value = $("#s-new").value = ""; $("#se").textContent = ""; toast("Identifiants mis à jour"); })
        .catch(function (e) { $("#se").textContent = e.message; });
    });
    api("backups").then(function (d) {
      var list = d.backups || [];
      $("#bk").innerHTML = list.length ? list.map(function (b) {
        var d = new Date(b.at);
        var p = function (n) { return (n < 10 ? "0" : "") + n; };
        var lbl = (b.kind === "menu" ? "Carte" : "Réglages") + " · " +
          p(d.getDate()) + "/" + p(d.getMonth() + 1) + "/" + d.getFullYear() + " à " +
          p(d.getHours()) + ":" + p(d.getMinutes()) + ":" + p(d.getSeconds());
        return '<div class="bk"><span>' + esc(lbl) + '</span><button class="b sm" type="button" data-rs="' + b.id + '" data-label="Restaurer">Restaurer</button></div>';
      }).join("") : "Aucune sauvegarde pour l’instant.";
      $$("[data-rs]").forEach(function (b) { confirmBtn(b, "Confirmer", function () { run("restore", { file: b.getAttribute("data-rs") }, "Version restaurée"); }); });
    }).catch(function (e) { $("#bk").textContent = e.message; });
  }

  /* ---------- Démarrage ---------- */
  api("me").then(function (d) {
    if (!d.ok) return renderLogin();
    S.user = d.user; S.csrf = d.csrf;
    load();
  }).catch(function () { renderLogin(); });
})();
