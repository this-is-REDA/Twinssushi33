<?php
/* Page de secours : affichée seulement si la base est injoignable.
   Les coordonnées y sont écrites en dur, puisque c'est justement la base qui
   manque — un client qui veut commander doit quand même pouvoir appeler. */
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Twins Sushi — site momentanément indisponible</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #11100e; color: #f4efe6;
         font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; text-align: center; padding: 24px; }
  main { max-width: 32rem; }
  h1 { font-size: 1.6rem; margin: 0 0 .6rem; }
  p { color: #c8c0b2; margin: 0 0 1.4rem; }
  a.b { display: inline-block; margin: .3rem; padding: .8rem 1.4rem; border-radius: 999px;
        background: #c8102e; color: #fff; text-decoration: none; font-weight: 600; }
  a.b.alt { background: #26241f; color: #f4efe6; }
  address { font-style: normal; color: #9b948a; font-size: .95rem; margin-top: 1.6rem; }
</style>
</head>
<body>
<main>
  <h1>Twins Sushi</h1>
  <p>Notre site rencontre une difficulté technique passagère. Vous pouvez commander par téléphone ou sur WhatsApp, comme d’habitude.</p>
  <a class="b" href="tel:+212619162094">Appeler le 06 19 16 20 94</a>
  <a class="b alt" href="https://wa.me/212619162094">Commander sur WhatsApp</a>
  <address>167 rue Ibnou Faris, Maârif — Casablanca<br>Lundi au vendredi 11h00 – minuit · Samedi et dimanche 13h00 – minuit</address>
</main>
<?php /* Indique si les deux variables d'environnement sont lisibles, sans jamais
         révéler leur contenu : permet de distinguer « mal configuré » de
         « base injoignable » sans accès aux journaux de l'hébergeur. */ ?>
<!-- cfg:<?= sb_configured() ? '1' : '0' ?> -->
</body>
</html>
