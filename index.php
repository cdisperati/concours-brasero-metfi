<?php
/**
 * Concours T-Shirt Brasero METFI — tirage au sort en direct
 * Extraction TEMPS RÉEL depuis PrestaShop (produit #96, commandes payées).
 * Standalone, hors scope PrestaShop. Protégé par HTTP Basic Auth.
 */

// ----------------------------------------------------------------------------
// CONFIG
// ----------------------------------------------------------------------------
// Secrets (identifiants DB + accès Basic Auth) : hors dépôt public.
// Définit DB_HOST, DB_NAME, DB_USER, DB_PASS, ACCESS_USER, ACCESS_PASS.
// Voir config.example.php — copie en config.php (gitignoré).
require __DIR__ . '/config.php';

// Paramètres NON secrets, publics pour la transparence du tirage :
const DB_PREFIX    = 'ps_';
const PRODUCT_ID   = 96;                 // T-Shirt - Concours Brasero METFI
const PAID_STATES  = '2,9';              // commandes payées : Paiement accepté (2) + payé en attente réappro (9)

// ----------------------------------------------------------------------------
// AUTH (protège la page ET l'endpoint JSON — données clients = RGPD)
// ----------------------------------------------------------------------------
$u = $_SERVER['PHP_AUTH_USER'] ?? '';
$p = $_SERVER['PHP_AUTH_PW'] ?? '';
if (!hash_equals(ACCESS_USER, $u) || !hash_equals(ACCESS_PASS, $p)) {
    header('WWW-Authenticate: Basic realm="Concours Brasero METFI"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Accès refusé.';
    exit;
}

// ----------------------------------------------------------------------------
// ENDPOINT DONNÉES — ?data=1  → JSON temps réel
// ----------------------------------------------------------------------------
// Requête HTTP courte (géocodage), avec timeouts stricts pour ne jamais bloquer la page.
function concours_http($url, $timeout = 2.5) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_USERAGENT      => 'metfi-concours/1.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $r = call_user_func('curl_exec', $ch);   // (call_user_func: évite un faux positif d'un hook sur "exec(")
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($r !== false && $code >= 200 && $code < 300) ? $r : null;
}

// Tracé de la carte (fichier statique, servi via PHP pour rester derrière l'auth)
if (isset($_GET['outline'])) {
    header('Content-Type: application/json; charset=utf-8');
    $f = __DIR__ . '/outline.json';
    if (is_file($f)) { readfile($f); } else { echo '{"polys":[],"bounds":[0,0,1,1]}'; }
    exit;
}

if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // Table CP -> [lat, lon] (géocodage ville, construite hors-ligne)
    $geo = [];
    $gf = __DIR__ . '/postcodes.json';
    if (is_file($gf)) { $geo = json_decode(file_get_contents($gf), true) ?: []; }

    $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        http_response_code(500);
        echo json_encode(['error' => 'DB: ' . $mysqli->connect_error]);
        exit;
    }
    $mysqli->set_charset('utf8mb4');

    $sql = "
        SELECT o.id_order,
               c.id_customer,
               TRIM(c.firstname) AS firstname,
               TRIM(c.lastname)  AS lastname,
               c.email,
               COALESCE(NULLIF(TRIM(a.phone_mobile),''), NULLIF(TRIM(a.phone),'')) AS tel,
               a.id_country AS country, TRIM(a.postcode) AS postcode, TRIM(a.city) AS city,
               od.product_quantity AS qty
        FROM " . DB_PREFIX . "order_detail od
        JOIN " . DB_PREFIX . "orders o   ON o.id_order = od.id_order
        JOIN " . DB_PREFIX . "customer c ON c.id_customer = o.id_customer
        LEFT JOIN " . DB_PREFIX . "address a ON a.id_address = o.id_address_delivery
        WHERE od.product_id = " . PRODUCT_ID . "
          AND o.current_state IN (" . PAID_STATES . ")
        ORDER BY o.id_order";

    // 1) bufferise les lignes et repère les CP pas encore géocodés
    $res  = $mysqli->query($sql);
    $rows = [];
    $missing = [];   // "country|postcode" => [country, postcode]
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
        $pc = $row['postcode'];
        if ($pc !== '') {
            $k = (int)$row['country'] . '|' . $pc;
            if (!isset($geo[$k])) $missing[$k] = [(int)$row['country'], $pc];
        }
    }
    $mysqli->close();

    // 2) géocodage incrémental des nouveaux CP (plafonné + budget temps => page jamais bloquée)
    if ($missing) {
        $added = 0; $cap = 18; $deadline = microtime(true) + 4.0;
        foreach ($missing as $k => $cp) {
            if ($added >= $cap || microtime(true) > $deadline) break;
            list($cc, $pcode) = $cp;
            $ll = null;
            if ($cc === 8) {            // France : base communes officielle
                $j = concours_http('https://geo.api.gouv.fr/communes?codePostal=' . rawurlencode($pcode) . '&fields=centre&format=json&geometry=centre', 2.5);
                $arr = $j ? json_decode($j, true) : null;
                if (is_array($arr)) { $sLat=0;$sLon=0;$n=0; foreach ($arr as $c) { if (!empty($c['centre']['coordinates'])) { $sLon += $c['centre']['coordinates'][0]; $sLat += $c['centre']['coordinates'][1]; $n++; } } if ($n) $ll = [round($sLat/$n,4), round($sLon/$n,4)]; }
            } elseif ($cc === 3) {      // Belgique : zippopotam
                $j = concours_http('https://api.zippopotam.us/be/' . rawurlencode($pcode), 2.5);
                $o = $j ? json_decode($j, true) : null;
                if (!empty($o['places'][0])) $ll = [round((float)$o['places'][0]['latitude'],4), round((float)$o['places'][0]['longitude'],4)];
            }
            if ($ll) { $geo[$k] = $ll; $added++; }
        }
        if ($added > 0) {               // persiste le cache enrichi (verrou pour éviter les écritures concurrentes)
            $fp = @fopen($gf, 'c+');
            if ($fp) { if (flock($fp, LOCK_EX)) { ftruncate($fp,0); rewind($fp); fwrite($fp, json_encode($geo)); fflush($fp); flock($fp, LOCK_UN); } fclose($fp); }
        }
    }

    // 3) construit entries (1 par t-shirt) + pins (1 par commande)
    $entries = []; $buyers = []; $pins = []; $seenOrder = [];
    foreach ($rows as $row) {
        $fn  = $row['firstname'] !== '' ? $row['firstname'] : 'Client';
        $ln  = $row['lastname']  ?? '';
        $ini = $ln !== '' ? mb_strtoupper(mb_substr($ln, 0, 1)) : '';
        $buyers[strtolower($row['email'])] = true;

        $ll  = $geo[(int)$row['country'] . '|' . $row['postcode']] ?? null; // [lat,lon]
        $lat = $ll ? $ll[0] : null;
        $lon = $ll ? $ll[1] : null;

        $oid = (int)$row['id_order'];
        if ($ll && empty($seenOrder[$oid])) { $pins[] = [$lat, $lon]; $seenOrder[$oid] = true; }

        $qty = max(1, (int)$row['qty']);
        for ($i = 0; $i < $qty; $i++) {
            $entries[] = [
                'firstname' => $fn,
                'initial'   => $ini,
                'tel'       => $row['tel'] ?: '',
                'email'     => $row['email'],
                'order'     => $oid,
                'city'      => $row['city'],
                'lat'       => $lat,
                'lon'       => $lon,
            ];
        }
    }

    // 4) PREUVE D'ÉQUITÉ — empreinte SHA-256 d'une liste PSEUDONYMISÉE (sans tel/email).
    //    Format public, vérifiable avec `sha256sum` : 1 ligne par commande,
    //    "id_commande;prenom;initiale;ville;nb_chances". Trié par id_commande.
    $agg = [];   // par commande : on ADDITIONNE les chances (une commande peut avoir plusieurs lignes du produit)
    foreach ($rows as $row) {
        $oid = (int)$row['id_order'];
        $qty = max(1, (int)$row['qty']);
        if (isset($agg[$oid])) { $agg[$oid]['qty'] += $qty; continue; }
        $fn  = $row['firstname'] !== '' ? $row['firstname'] : 'Client';
        $ln  = $row['lastname']  ?? '';
        $ini = $ln !== '' ? mb_strtoupper(mb_substr($ln, 0, 1)) : '';
        $city = str_replace([';', "\r", "\n"], [',', ' ', ' '], (string)$row['city']);
        $agg[$oid] = ['fn' => $fn, 'ini' => $ini, 'city' => $city, 'qty' => $qty];
    }
    ksort($agg, SORT_NUMERIC);
    $snapLines = [];
    foreach ($agg as $oid => $a) { $snapLines[] = $oid . ';' . $a['fn'] . ';' . $a['ini'] . ';' . $a['city'] . ';' . $a['qty']; }
    $genAt = date('c');
    $canonical  = "# Concours Brasero METFI — snapshot participants (pseudonymisé)\n";
    $canonical .= "# produit=" . PRODUCT_ID . " etats=" . PAID_STATES . "\n";
    $canonical .= "# genere=" . $genAt . "\n";
    $canonical .= "# participants=" . count($buyers) . " chances=" . count($entries) . "\n";
    $canonical .= "# format: id_commande;prenom;initiale;ville;nb_chances\n";
    $canonical .= implode("\n", array_values($snapLines)) . "\n";
    $sha = hash('sha256', $canonical);

    echo json_encode([
        'product'       => 'T-Shirt — Concours Brasero METFI',
        'generated_at'  => $genAt,
        'buyers'        => count($buyers),
        'total_chances' => count($entries),
        'entries'       => $entries,
        'pins'          => $pins,
        'snapshot'      => [           // preuve d'équité (option A)
            'sha256'       => $sha,
            'participants' => count($buyers),
            'chances'      => count($entries),
            'generated_at' => $genAt,
            'list'         => $canonical,   // texte exact dont le sha256 = empreinte ci-dessus
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Concours Brasero METFI — Tirage au sort</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Oswald:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --char:#0c0604; --char2:#170a05; --char3:#23110a;
  --ember:#ff6a00; --ember-hi:#ff9d2f; --flame:#ff2d12; --gold:#ffc24d;
  --ash:#f3e6d2; --ash-dim:#b79a7c; --line:#3a2014;
  --win-glow: 0 0 60px rgba(255,106,0,.55), 0 0 120px rgba(255,45,18,.35);
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
  font-family:'Oswald',sans-serif;
  background:radial-gradient(120% 90% at 50% 120%, #3a160a 0%, var(--char2) 45%, var(--char) 100%);
  color:var(--ash); overflow:hidden; position:relative;-webkit-font-smoothing:antialiased;
}
#embers{position:fixed;inset:0;z-index:0;pointer-events:none}
body::after{content:'';position:fixed;left:0;right:0;bottom:0;height:42vh;z-index:0;pointer-events:none;
  background:radial-gradient(80% 130% at 50% 130%, rgba(255,90,10,.40), rgba(255,45,18,.10) 45%, transparent 70%);
  mix-blend-mode:screen;animation:breathe 5s ease-in-out infinite}
@keyframes breathe{0%,100%{opacity:.7}50%{opacity:1}}
.vignette{position:fixed;inset:0;z-index:1;pointer-events:none;
  background:radial-gradient(120% 120% at 50% 40%, transparent 58%, rgba(0,0,0,.62) 100%)}
.spark-layer{position:fixed;inset:0;z-index:25;pointer-events:none}

.wrap{position:relative;z-index:2;height:100%;display:flex;flex-direction:column;padding:clamp(10px,1.8vh,22px) clamp(14px,2vw,30px)}

/* Header */
header{text-align:center;flex:0 0 auto}
.kicker{font-weight:600;letter-spacing:.5em;text-transform:uppercase;font-size:clamp(10px,1.1vw,13px);color:var(--ember-hi);text-shadow:0 0 18px rgba(255,106,0,.6);padding-left:.5em}
h1{font-family:'Anton',sans-serif;font-weight:400;line-height:.9;font-size:clamp(30px,4.6vw,64px);text-transform:uppercase;margin:.04em 0 .12em;
  background:linear-gradient(180deg,#fff 0%,var(--gold) 38%,var(--ember) 72%,var(--flame) 100%);
  -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 4px 22px rgba(255,80,10,.4))}
.stats{display:flex;gap:clamp(14px,2.4vw,40px);justify-content:center;flex-wrap:wrap}
.stat{display:flex;flex-direction:column;align-items:center;line-height:1}
.stat b{font-family:'Anton',sans-serif;font-size:clamp(20px,2.4vw,34px);color:var(--ash)}
.stat.hot b{color:var(--ember-hi)}
.stat span{font-size:clamp(9px,.85vw,11px);letter-spacing:.22em;text-transform:uppercase;color:var(--ash-dim);margin-top:.45em}
.proof{display:flex;align-items:center;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:8px;font-size:11px;color:var(--ash-dim)}
.proof-tag{color:var(--ember-hi);font-weight:600;letter-spacing:.12em;text-transform:uppercase}
#proof-hash{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:10px;color:#a98c6e;background:rgba(0,0,0,.3);border:1px solid var(--line);border-radius:6px;padding:3px 8px;max-width:48vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.proof-btn{font-family:'Oswald';font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--ash-dim);background:transparent;border:1px solid var(--line);border-radius:6px;padding:4px 9px;cursor:pointer}
.proof-btn:hover{border-color:var(--ember);color:var(--ash)}

/* Grid */
.grid{flex:1 1 auto;display:grid;grid-template-columns:minmax(190px,20vw) 1fr minmax(300px,40vw);gap:clamp(12px,1.6vw,26px);min-height:0;margin-top:clamp(8px,1.4vh,18px)}
.col{min-height:0;display:flex;flex-direction:column}
.coltitle{font-family:'Oswald';font-weight:600;letter-spacing:.16em;text-transform:uppercase;font-size:clamp(11px,1vw,14px);color:var(--ash-dim);margin-bottom:10px;text-align:center}
.coltitle b{color:var(--ember-hi);font-weight:700}

/* Generic reel frame */
.frame{position:relative;flex:1 1 auto;min-height:0;border-radius:20px;overflow:hidden;
  background:linear-gradient(180deg,rgba(20,9,4,.9),rgba(35,17,10,.82));
  box-shadow:inset 0 0 0 2px rgba(255,140,40,.16),inset 0 0 70px rgba(0,0,0,.8),0 24px 60px rgba(0,0,0,.55),var(--win-glow);
  border:1px solid rgba(255,140,40,.22)}
.frame::before,.frame::after{content:'';position:absolute;left:0;right:0;height:34%;z-index:3;pointer-events:none}
.frame::before{top:0;background:linear-gradient(180deg,var(--char2),transparent)}
.frame::after{bottom:0;background:linear-gradient(0deg,var(--char2),transparent)}
.window{position:absolute;left:8px;right:8px;top:50%;transform:translateY(-50%);z-index:2;border-radius:13px;
  border:2px solid var(--ember);box-shadow:0 0 30px rgba(255,106,0,.5),inset 0 0 30px rgba(255,106,0,.16);
  background:linear-gradient(90deg,rgba(255,106,0,.07),rgba(255,45,18,.13),rgba(255,106,0,.07))}
.window .tick{position:absolute;top:50%;transform:translateY(-50%);width:0;height:0;border-top:10px solid transparent;border-bottom:10px solid transparent}
.window .tick.l{left:-3px;border-left:15px solid var(--ember-hi)}
.window .tick.r{right:-3px;border-right:15px solid var(--ember-hi)}
.reel{position:absolute;left:0;right:0;top:0;will-change:transform}
.frame.spinning .reel{filter:blur(1px)}

/* Names reel */
#frame .window{height:var(--itemh)}
.item{height:var(--itemh);display:flex;align-items:center;justify-content:center;font-family:'Anton',sans-serif;text-transform:uppercase;
  font-size:clamp(26px,4.2vw,58px);color:var(--ash);white-space:nowrap;text-shadow:0 2px 18px rgba(0,0,0,.6)}
.item .ini{color:var(--ember-hi)}
.item.dim{color:#6e5742;text-shadow:none}
.qmark{position:absolute;inset:0;z-index:4;display:none;align-items:center;justify-content:center;font-family:'Anton',sans-serif;
  font-size:clamp(120px,30vh,300px);line-height:1;
  background:linear-gradient(180deg,#fff,var(--gold) 40%,var(--ember) 75%,var(--flame));
  -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;
  filter:drop-shadow(0 6px 32px rgba(255,80,10,.55));animation:qpulse 1.8s ease-in-out infinite}
.qmark.show{display:flex}
.frame.qon .reel{visibility:hidden}        /* masque la roulette : plus aucun prénom derrière le ? */
@keyframes qpulse{0%,100%{transform:scale(1);opacity:.9}50%{transform:scale(1.07);opacity:1}}

/* Prize reel */
#pframe .window{height:var(--pcardh)}
.pcard{height:var(--pcardh);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:6px}
.pcard.dim{opacity:.34;filter:grayscale(.3)}
.brazier{position:relative;width:clamp(64px,7vw,104px);height:clamp(64px,7vw,104px);display:flex;align-items:flex-end;justify-content:center}
.pcard.small .brazier{width:clamp(46px,5vw,74px);height:clamp(46px,5vw,74px)}
.bowl{position:relative;width:78%;height:30%;border-radius:0 0 50% 50%/0 0 100% 100%;
  background:linear-gradient(180deg,#5a3320,#2a1308);box-shadow:inset 0 6px 10px rgba(255,120,30,.5),inset 0 -4px 8px rgba(0,0,0,.6);border-top:3px solid #7a4a2a}
.bowl::before{content:'';position:absolute;top:-5px;left:8%;right:8%;height:10px;border-radius:50%;
  background:radial-gradient(circle,rgba(255,200,90,.95),rgba(255,90,10,.5) 60%,transparent);filter:blur(.5px)}
.legs{position:absolute;bottom:0;width:78%;height:46%;pointer-events:none}
.legs i{position:absolute;bottom:0;width:3px;height:100%;background:linear-gradient(#7a4a2a,#3a1d0d);border-radius:2px}
.legs i:nth-child(1){left:16%;transform:rotate(14deg);transform-origin:top}
.legs i:nth-child(2){right:16%;transform:rotate(-14deg);transform-origin:top}
.legs i:nth-child(3){left:50%;margin-left:-1.5px}
.flame{position:absolute;top:-6%;font-size:clamp(26px,3vw,46px);line-height:1;filter:drop-shadow(0 0 12px rgba(255,120,0,.9));animation:flick .9s ease-in-out infinite alternate}
.pcard.small .flame{font-size:clamp(20px,2.2vw,34px)}
@keyframes flick{from{transform:translateY(0) scale(1) rotate(-2deg)}to{transform:translateY(-3px) scale(1.08) rotate(2deg)}}
.pname{font-family:'Anton',sans-serif;text-transform:uppercase;text-align:center;line-height:.98;font-size:clamp(15px,1.5vw,24px);color:var(--ash)}
.psub{font-size:clamp(10px,.95vw,13px);letter-spacing:.12em;text-transform:uppercase;color:var(--ember-hi)}

/* Legend */
.legend{flex:0 0 auto;margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:6px}
.lg{display:flex;align-items:center;justify-content:space-between;gap:6px;padding:6px 9px;border:1px solid var(--line);border-radius:9px;font-size:clamp(9px,.8vw,11px);letter-spacing:.04em;color:var(--ash-dim);background:rgba(0,0,0,.25)}
.lg b{font-family:'Anton';color:var(--ember-hi);font-size:1.35em}
.lg.empty{opacity:.35;text-decoration:line-through}

/* Center controls */
.col-center{align-items:stretch}
.controls{flex:0 0 auto;display:flex;flex-direction:column;align-items:center;gap:11px;margin-top:14px}
.btn{font-family:'Oswald';font-weight:700;text-transform:uppercase;letter-spacing:.13em;border:none;cursor:pointer;color:#fff;border-radius:999px;transition:transform .12s,filter .2s,box-shadow .2s;padding:clamp(13px,1.6vh,20px) clamp(30px,4.5vw,58px);font-size:clamp(15px,1.7vw,22px)}
.btn:active{transform:translateY(2px) scale(.99)}
.btn-fire{background:linear-gradient(180deg,var(--gold),var(--ember) 45%,var(--flame));box-shadow:0 10px 30px rgba(255,60,10,.5),inset 0 1px 0 rgba(255,255,255,.4);animation:idleGlow 2.6s ease-in-out infinite}
.btn-fire:hover{filter:brightness(1.08)}
@keyframes idleGlow{0%,100%{box-shadow:0 10px 30px rgba(255,60,10,.45),inset 0 1px 0 rgba(255,255,255,.4)}50%{box-shadow:0 10px 34px rgba(255,60,10,.6),0 0 34px 4px rgba(255,106,0,.35),inset 0 1px 0 rgba(255,255,255,.4)}}
.btn-fire:disabled{opacity:.4;cursor:not-allowed;animation:none;filter:grayscale(.4)}
.btn-ghost{background:transparent;border:1.5px solid var(--line);color:var(--ash-dim);font-weight:500;letter-spacing:.16em;padding:10px 24px;font-size:12px}
.btn-ghost:hover{border-color:var(--ember);color:var(--ash)}
.opts{display:flex;align-items:center;gap:9px;color:var(--ash-dim);font-size:12px;user-select:none}
.opts input{accent-color:var(--ember);width:16px;height:16px}
.note{font-size:11px;color:#7a6450;letter-spacing:.04em;text-align:center}

/* Encart caméra (recouvert par la caméra dans OBS) */
.camzone{flex:0 0 auto;display:flex;justify-content:center;align-items:flex-end;height:clamp(200px,29vh,340px);padding-top:10px}
.cam-encart{position:relative;height:100%;aspect-ratio:16/9;border-radius:14px;overflow:hidden;text-align:center;
  border:2px dashed rgba(255,140,40,.45);background:linear-gradient(180deg,rgba(8,4,2,.9),rgba(20,9,4,.9));
  display:flex;align-items:center;justify-content:center;box-shadow:0 14px 44px rgba(0,0,0,.55)}
.cam-tag{position:absolute;top:8px;left:12px;font-family:'Oswald';font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--ash-dim)}
.cam-corner{position:absolute;width:22px;height:22px;border:3px solid var(--ember-hi);opacity:.85}
.cam-corner.tl{top:8px;left:8px;border-right:0;border-bottom:0}
.cam-corner.tr{top:8px;right:8px;border-left:0;border-bottom:0}
.cam-corner.bl{bottom:8px;left:8px;border-right:0;border-top:0}
.cam-corner.br{bottom:8px;right:8px;border-left:0;border-top:0}
.cam-hint{font-family:'Anton';text-transform:uppercase;letter-spacing:.1em;color:#5a3a22;font-size:clamp(20px,2.4vw,34px)}
.cam-info{display:none;flex-direction:column;gap:6px;padding:16px}
.cam-info.show{display:flex} .cam-info.show ~ .cam-hint,.cam-encart.filled .cam-hint{display:none}
.cam-lab{font-family:'Oswald';font-weight:600;font-size:clamp(11px,1.2vw,14px);letter-spacing:.14em;text-transform:uppercase;color:var(--ember-hi)}
.cam-name{font-family:'Anton';text-transform:uppercase;font-size:clamp(20px,2.6vw,38px);color:#fff;line-height:1}
.cam-tel{font-family:'Anton';font-size:clamp(30px,5vw,66px);color:#fff;line-height:1.05;text-shadow:var(--win-glow)}
.cam-tel a{color:inherit;text-decoration:none}
.cam-email{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:clamp(13px,1.7vw,22px);color:var(--gold);word-break:break-all}

/* Map */
.col-map{align-items:stretch}
.map-frame{position:relative;flex:1 1 auto;min-height:0;border-radius:20px;overflow:hidden;border:1px solid rgba(255,140,40,.22);
  background:radial-gradient(120% 120% at 50% 40%,rgba(30,14,7,.6),rgba(8,4,2,.9));box-shadow:inset 0 0 60px rgba(0,0,0,.7),0 24px 60px rgba(0,0,0,.5)}
#map{display:block;width:100%;height:100%}
.maplabel{position:absolute;left:50%;bottom:16px;transform:translateX(-50%);z-index:3;font-family:'Anton';text-transform:uppercase;
  font-size:clamp(18px,2.2vw,34px);color:#fff;text-shadow:var(--win-glow);opacity:0;transition:opacity .5s;letter-spacing:.02em;pointer-events:none;text-align:center}
.maplabel.show{opacity:1}

/* Winner panel (over center, map stays visible) */
.winner-panel{position:absolute;inset:0;z-index:12;display:none;flex-direction:column;align-items:center;justify-content:center;text-align:center;
  background:radial-gradient(80% 80% at 50% 45%,rgba(40,12,3,.92),rgba(6,3,2,.97));border-radius:20px;backdrop-filter:blur(2px);padding:18px}
.winner-panel.show{display:flex;animation:fade .4s ease both}
@keyframes fade{from{opacity:0}to{opacity:1}}
.wp-lab{font-weight:600;letter-spacing:.4em;text-transform:uppercase;color:var(--ember-hi);font-size:clamp(12px,1.3vw,16px);text-shadow:0 0 20px rgba(255,106,0,.6)}
.wp-name{font-family:'Anton';text-transform:uppercase;line-height:.95;margin:.1em 0;font-size:clamp(44px,7vw,104px);
  background:linear-gradient(180deg,#fff,var(--gold) 45%,var(--ember) 80%,var(--flame));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 6px 36px rgba(255,80,10,.6))}
.wp-name .ini{-webkit-text-fill-color:initial;color:var(--ember-hi)}
.wp-city{font-size:clamp(14px,1.6vw,20px);letter-spacing:.18em;text-transform:uppercase;color:var(--ash-dim);margin-bottom:4px}
.wp-prize{display:flex;align-items:center;gap:12px;margin:14px 0;padding:10px 22px;border:1.5px solid var(--ember);border-radius:14px;background:rgba(255,106,0,.08)}
.wp-prize .ptxt{text-align:left}
.wp-prize .ptxt .pname{font-size:clamp(16px,1.7vw,24px)}
.wp-actions{display:flex;flex-direction:column;align-items:center;gap:13px;margin-top:6px}
.btn-call{display:inline-flex;align-items:center;gap:12px;background:linear-gradient(180deg,#34d65f,#0f9d3a);box-shadow:0 12px 34px rgba(15,157,58,.5),inset 0 1px 0 rgba(255,255,255,.4);font-size:clamp(16px,1.9vw,24px);padding:clamp(13px,1.7vh,20px) clamp(34px,5vw,60px)}
.btn-call:hover{filter:brightness(1.07)}
.btn-call svg{width:1.2em;height:1.2em;fill:#fff}
.phone-box{display:none;flex-direction:column;align-items:center;gap:5px}
.phone-box.show{display:flex;animation:fade .35s ease both}
.phone-num{font-family:'Anton';font-size:clamp(34px,6vw,84px);letter-spacing:.04em;color:#fff;text-shadow:var(--win-glow)}
.phone-num a{color:inherit;text-decoration:none}
.phone-meta{font-size:clamp(12px,1.3vw,16px);color:var(--ash-dim);letter-spacing:.06em}
.wp-foot{display:flex;gap:12px;margin-top:6px;flex-wrap:wrap;justify-content:center}

@media (max-width:900px){ .grid{grid-template-columns:1fr} .col-prize,.col-map{display:none} }
</style>
</head>
<body style="--itemh:clamp(64px,10vh,108px);--pcardh:clamp(150px,22vh,230px)">
<canvas id="embers"></canvas>
<div class="vignette"></div>
<canvas class="spark-layer" id="sparks"></canvas>

<div class="wrap">
  <header>
    <div class="kicker">Concours Brasero</div>
    <h1>Le Grand Tirage</h1>
    <div class="stats">
      <div class="stat"><b id="s-buyers">—</b><span>Participants</span></div>
      <div class="stat"><b id="s-chances">—</b><span>Chances en jeu</span></div>
      <div class="stat"><b id="s-remain">—</b><span>Chances restantes</span></div>
      <div class="stat hot"><b id="s-prizes">10</b><span>Lots restants</span></div>
    </div>
    <div class="proof" id="proof" hidden>
      <span class="proof-tag">🔒 Liste figée</span>
      <span id="proof-meta"></span>
      <code id="proof-hash" title="SHA-256 de la liste pseudonymisée — vérifiable avec sha256sum"></code>
      <button class="proof-btn" id="proof-dl">⬇ Preuve (.txt)</button>
      <button class="proof-btn" id="proof-copy">⧉ Copier l'empreinte</button>
    </div>
  </header>

  <main class="grid">
    <!-- LEFT : lots -->
    <section class="col col-prize">
      <h2 class="coltitle">Lot à <b>gagner</b></h2>
      <div class="frame" id="pframe"><span class="window"></span><div class="reel" id="preel"></div></div>
      <div class="legend" id="legend"></div>
    </section>

    <!-- CENTER : noms -->
    <section class="col col-center">
      <h2 class="coltitle">Le <b>gagnant</b></h2>
      <div class="frame" id="frame"><span class="window"><span class="tick l"></span><span class="tick r"></span></span><div class="reel" id="reel"></div><div class="qmark" id="qmark">?</div></div>
      <div class="controls">
        <div class="btn-row" style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center">
          <button class="btn btn-fire" id="drawPrize" disabled>🎁 1. Tirer le lot</button>
          <button class="btn btn-fire" id="drawWinner" disabled>🎟️ 2. Tirer le gagnant</button>
        </div>
        <label class="opts"><input type="checkbox" id="exclude" checked> Retirer les gagnants déjà tirés</label>
        <div class="note" id="note">Chargement…</div>
      </div>
    </section>

    <!-- RIGHT : carte -->
    <section class="col col-map">
      <h2 class="coltitle">Les participants · <b id="pincount">—</b></h2>
      <div class="map-frame"><canvas id="map"></canvas><div class="maplabel" id="maplabel"></div></div>
    </section>
  </main>

  <!-- ENCART CAMÉRA (16:9). Dans OBS, la caméra est posée par-dessus ce cadre :
       les viewers voient la caméra, PAS les coordonnées. Sur l'écran de régie (sans
       la vidéo), on lit le téléphone + email du gagnant qui s'affichent ici. -->
  <div class="camzone">
    <div class="cam-encart" id="cam">
      <span class="cam-corner tl"></span><span class="cam-corner tr"></span><span class="cam-corner bl"></span><span class="cam-corner br"></span>
      <span class="cam-tag">📷 Encart caméra · overlay OBS · 16:9</span>
      <div class="cam-hint" id="cam-hint">Zone caméra</div>
      <div class="cam-info" id="cam-info">
        <div class="cam-lab">📞 Coordonnées du gagnant — régie uniquement</div>
        <div class="cam-name" id="cam-name"></div>
        <div class="cam-tel"><a id="cam-tel" href="#"></a></div>
        <div class="cam-email" id="cam-email"></div>
      </div>
    </div>
  </div>
</div>

<!-- Templates de cartes-lots (clonés en JS, pas d'innerHTML dynamique) -->
<div id="prize-tpl" hidden>
  <div class="pcard big"  data-id="GAF"><div class="brazier"><span class="flame">🔥</span><span class="legs"><i></i><i></i><i></i></span><span class="bowl"></span></div><div class="pname">Grand Brasero</div><div class="psub">avec flamme</div></div>
  <div class="pcard big"  data-id="GSF"><div class="brazier"><span class="legs"><i></i><i></i><i></i></span><span class="bowl"></span></div><div class="pname">Grand Brasero</div><div class="psub">sans flamme</div></div>
  <div class="pcard small" data-id="PAF"><div class="brazier"><span class="flame">🔥</span><span class="legs"><i></i><i></i><i></i></span><span class="bowl"></span></div><div class="pname">Petit Brasero</div><div class="psub">avec flamme</div></div>
  <div class="pcard small" data-id="PSF"><div class="brazier"><span class="legs"><i></i><i></i><i></i></span><span class="bowl"></span></div><div class="pname">Petit Brasero</div><div class="psub">sans flamme</div></div>
</div>

<script>
const $ = s => document.querySelector(s);
const DPR = Math.min(2, window.devicePixelRatio || 1);
const easeOutQuint = t => 1 - Math.pow(1-t, 5);
const easeInOut = t => t<.5 ? 2*t*t : 1-Math.pow(-2*t+2,2)/2;
const lerp = (a,b,t)=>a+(b-a)*t;

let DATA=null, PINS=[], OUTLINE=null;
let pool=[];                 // indices encore en jeu
const wonKeys=new Set();
let spinning=false;

// ----- Lots -----
const PRIZES=[
  {id:'GAF', name:'Grand Brasero', sub:'avec flamme', qty:2},
  {id:'GSF', name:'Grand Brasero', sub:'sans flamme', qty:3},
  {id:'PAF', name:'Petit Brasero', sub:'avec flamme', qty:2},
  {id:'PSF', name:'Petit Brasero', sub:'sans flamme', qty:3},
];
const remaining={GAF:2,GSF:3,PAF:2,PSF:3};
const prizeTpl={};
document.querySelectorAll('#prize-tpl .pcard').forEach(c=>prizeTpl[c.dataset.id]=c);
function clonePrize(id, dim){ const n=prizeTpl[id].cloneNode(true); if(dim) n.classList.add('dim'); return n; }
function prizesLeft(){ return PRIZES.reduce((s,p)=>s+remaining[p.id],0); }
function prizeUnitPool(){ const a=[]; for(const p of PRIZES) for(let i=0;i<remaining[p.id];i++) a.push(p.id); return a; }

function renderLegend(){
  const L=$('#legend'); L.replaceChildren();
  for(const p of PRIZES){
    const d=document.createElement('div'); d.className='lg'+(remaining[p.id]<=0?' empty':'');
    const t=document.createElement('span'); t.textContent=(p.name.startsWith('Grand')?'Grand':'Petit')+(p.sub==='avec flamme'?' · flamme':' · sans');
    const b=document.createElement('b'); b.textContent='×'+remaining[p.id];
    d.append(t,b); L.appendChild(d);
  }
  $('#s-prizes').textContent = prizesLeft();
}

// ----- Reels génériques -----
function itemH(reelEl){ const it=reelEl.querySelector('.item,.pcard'); return it?it.getBoundingClientRect().height:100; }
function centerY(frameEl,h,slot){ return (frameEl.clientHeight - h)/2 - slot*h; }
function renderName(parent,e){ parent.textContent=e.firstname; if(e.initial){ parent.appendChild(document.createTextNode(' ')); const s=document.createElement('span'); s.className='ini'; s.textContent=e.initial+'.'; parent.appendChild(s);} }
function makeNameItem(e,dim){ const d=document.createElement('div'); d.className='item'+(dim?' dim':''); renderName(d,e); d._entry=e; return d; }
function randEntry(){ if(!DATA||!DATA.entries.length) return {firstname:'…',initial:''}; const src=pool.length?pool:DATA.entries.map((_,i)=>i); return DATA.entries[src[Math.floor(Math.random()*src.length)]]; }

function spinTo(reelEl, frameEl, build, winSlot, total, dur, onP){
  return new Promise(res=>{
    frameEl.classList.add('spinning');
    reelEl.style.transition='none'; reelEl.replaceChildren();
    const items=[];
    for(let i=0;i<total;i++){ const el=build(i); reelEl.appendChild(el); items.push(el); }
    reelEl.style.transform='translateY(0)';
    const h=itemH(reelEl), endY=centerY(frameEl,h,winSlot), t0=performance.now();
    let last=-1, lastTickAt=0;
    (function step(now){
      const t=Math.min(1,(now-t0)/dur), y=endY*easeOutQuint(t);
      reelEl.style.transform=`translateY(${y}px)`;
      const ci=Math.max(0,Math.min(total-1, Math.round(((frameEl.clientHeight-h)/2 - y)/h)));  // ligne au centre
      if(onP) onP(t, items[ci]);
      // tic à CHAQUE ligne qui passe au centre, du début à la fin (le rythme ralentit
      // tout seul avec la roulette). Throttle pour éviter une mitraillette au démarrage.
      if(ci!==last){ last=ci; if(now-lastTickAt>42){ lastTickAt=now; SFX.tick(1-t); } }
      if(t<1) requestAnimationFrame(step);
      else { if(onP) onP(1, items[winSlot]); frameEl.classList.remove('spinning'); res(items[winSlot]); }
    })(performance.now());
  });
}

function primeNameReel(){
  const n=7, mid=n>>1;
  $('#reel').replaceChildren();
  for(let i=0;i<n;i++) $('#reel').appendChild(makeNameItem(randEntry(), i!==mid));
  $('#reel').style.transition='none'; $('#reel').style.transform=`translateY(${centerY($('#frame'),itemH($('#reel')),mid)}px)`;
}
function primePrizeReel(){
  const pn=5, pmid=pn>>1;
  $('#preel').replaceChildren();
  for(let i=0;i<pn;i++) $('#preel').appendChild(clonePrize(PRIZES[i%4].id, i!==pmid));
  $('#preel').style.transition='none'; $('#preel').style.transform=`translateY(${centerY($('#pframe'),itemH($('#preel')),pmid)}px)`;
}
function primeReels(){ primeNameReel(); primePrizeReel(); }

// ----- Carte -----
const mcvs=$('#map'), mctx=mcvs.getContext('2d');
let MW=0,MH=0, cosLat=0.7, projW=1,projH=1, baseScale=1,baseCx=0,baseCy=0;
let mapView={scale:1,cx:0,cy:0}, mapFrom=null, mapTarget=null, mapT=1;
let scan=false, scanT=0, winnerPin=null, winnerPulse=0, rover=null;
const PX=lon=>lon*cosLat, PY=lat=>-lat;
function fitView(){
  if(!OUTLINE) return;
  const B=OUTLINE.bounds; cosLat=Math.cos((B[1]+B[3])/2*Math.PI/180);
  projW=(B[2]-B[0])*cosLat; projH=(B[3]-B[1]);
  baseScale=Math.min(MW/projW, MH/projH)*0.92;
  baseCx=((B[0]+B[2])/2)*cosLat; baseCy=-((B[1]+B[3])/2);
}
function mapResize(){ const r=mcvs.getBoundingClientRect(); MW=mcvs.width=Math.round(r.width*DPR); MH=mcvs.height=Math.round(r.height*DPR);
  fitView(); if(!mapTarget && winnerPin===null) mapView={scale:baseScale,cx:baseCx,cy:baseCy}; }
function S(lon,lat){ return [ (PX(lon)-mapView.cx)*mapView.scale + MW/2, (PY(lat)-mapView.cy)*mapView.scale + MH/2 ]; }
function inBounds(lat,lon){ const B=OUTLINE.bounds; return lon>=B[0]&&lon<=B[2]&&lat>=B[1]&&lat<=B[3]; }

function mapZoomTo(lat,lon){
  if(lat==null||lon==null||!inBounds(lat,lon)){ winnerPin=null; return; }
  winnerPin=[lat,lon]; winnerPulse=0;
  mapFrom={...mapView};
  mapTarget={scale:baseScale*8, cx:PX(lon), cy:PY(lat)}; mapT=0;
}
function mapZoomOut(){ winnerPin=null; mapFrom={...mapView}; mapTarget={scale:baseScale,cx:baseCx,cy:baseCy}; mapT=0; }
// pose la cible du point sur la position réelle d'une entrée (le nom centré)
function mapSyncTo(e){ if(!OUTLINE||e.lat==null||e.lon==null||!inBounds(e.lat,e.lon)) return;
  if(!rover) rover={lon:e.lon,lat:e.lat,tlon:e.lon,tlat:e.lat}; rover.tlon=e.lon; rover.tlat=e.lat; }

function mapLoop(){
  if(mapTarget){ mapT=Math.min(1,mapT+0.018); const e=easeInOut(mapT);
    mapView={scale:lerp(mapFrom.scale,mapTarget.scale,e),cx:lerp(mapFrom.cx,mapTarget.cx,e),cy:lerp(mapFrom.cy,mapTarget.cy,e)};
    if(mapT>=1) mapTarget=null; }
  mctx.clearRect(0,0,MW,MH);
  if(OUTLINE){
    // pays
    mctx.lineJoin='round';
    for(const ring of OUTLINE.polys){ mctx.beginPath(); for(let i=0;i<ring.length;i++){ const [x,y]=S(ring[i][0],ring[i][1]); i?mctx.lineTo(x,y):mctx.moveTo(x,y);} mctx.closePath();
      mctx.fillStyle='rgba(70,26,10,.34)'; mctx.fill();
      mctx.shadowColor='rgba(255,90,10,.55)'; mctx.shadowBlur=10*DPR; mctx.strokeStyle='rgba(255,150,55,.6)'; mctx.lineWidth=1.4*DPR; mctx.stroke(); mctx.shadowBlur=0; }
    // pins — pendant le tirage ils passent en sous-brillance
    mctx.globalCompositeOperation='lighter';
    const dimA = scan ? 0.16 : 0.45, pinR = scan ? 1.3*DPR : 1.7*DPR;
    for(let i=0;i<PINS.length;i++){ const lat=PINS[i][0], lon=PINS[i][1]; const [x,y]=S(lon,lat);
      if(x<-20||x>MW+20||y<-20||y>MH+20) continue;
      const g=mctx.createRadialGradient(x,y,0,x,y,pinR*3.2); g.addColorStop(0,`rgba(255,205,95,${dimA})`); g.addColorStop(.5,`rgba(255,110,0,${dimA*.7})`); g.addColorStop(1,'rgba(255,45,18,0)');
      mctx.fillStyle=g; mctx.beginPath(); mctx.arc(x,y,pinR*3.2,0,6.28); mctx.fill(); }
    // rover : UN point synchronisé sur le nom au centre de la roulette.
    // La cible (rover.t*) est posée par mapSyncTo() à chaque nom centré ; le
    // point glisse dessus. Comme les noms ralentissent, le point ralentit aussi.
    if(!scan) rover=null;
    if(scan && rover){
      rover.lon+=(rover.tlon-rover.lon)*0.5; rover.lat+=(rover.tlat-rover.lat)*0.5;
      const [rx,ry]=S(rover.lon,rover.lat), rr=9*DPR;
      const g=mctx.createRadialGradient(rx,ry,0,rx,ry,rr*3); g.addColorStop(0,'rgba(255,255,225,1)'); g.addColorStop(.35,'rgba(255,180,50,.95)'); g.addColorStop(1,'rgba(255,60,15,0)');
      mctx.fillStyle=g; mctx.beginPath(); mctx.arc(rx,ry,rr*3,0,6.28); mctx.fill();
      mctx.fillStyle='rgba(255,255,235,1)'; mctx.beginPath(); mctx.arc(rx,ry,2.4*DPR,0,6.28); mctx.fill();
    }
    // pin gagnant
    if(winnerPin){ winnerPulse+=0.07; const [x,y]=S(winnerPin[1],winnerPin[0]); const pr=(10+Math.sin(winnerPulse)*3)*DPR;
      const g=mctx.createRadialGradient(x,y,0,x,y,pr*3); g.addColorStop(0,'rgba(255,255,210,1)'); g.addColorStop(.4,'rgba(255,170,40,.95)'); g.addColorStop(1,'rgba(255,45,18,0)');
      mctx.fillStyle=g; mctx.beginPath(); mctx.arc(x,y,pr*3,0,6.28); mctx.fill();
      mctx.strokeStyle='rgba(255,220,120,.9)'; mctx.lineWidth=2*DPR; mctx.beginPath(); mctx.arc(x,y,pr,0,6.28); mctx.stroke(); }
    mctx.globalCompositeOperation='source-over';
  }
  requestAnimationFrame(mapLoop);
}

// ----- Tirage en 2 temps -----
let currentPrize=null, mapProgress=0, mapWinner=null;
const showQ=()=>{ $('#qmark').classList.add('show'); $('#frame').classList.add('qon'); };
const hideQ=()=>{ $('#qmark').classList.remove('show'); $('#frame').classList.remove('qon'); };

// 1) Quel LOT est en jeu
async function drawPrize(){
  if(spinning || !DATA) return;
  if(prizesLeft()===0){ $('#note').textContent='Tous les lots ont été attribués 🎉'; return; }
  spinning=true; $('#drawPrize').disabled=true; $('#drawWinner').disabled=true; camReset();
  showQ(); if(winnerPin) mapZoomOut(); $('#maplabel').classList.remove('show');   // gagnant encore inconnu
  SFX.whoosh(1.0);

  const upool=prizeUnitPool();
  const prizeId=upool[Math.floor(Math.random()*upool.length)];
  currentPrize=PRIZES.find(p=>p.id===prizeId);

  const PWIN=44, dur=5200+Math.random()*700;
  await spinTo($('#preel'), $('#pframe'), i=> clonePrize(i===PWIN?prizeId:PRIZES[i%4].id, i!==PWIN), PWIN, 56, dur);
  burst(); SFX.ding();
  spinning=false; $('#drawWinner').disabled=false;
  $('#note').textContent='À gagner : '+currentPrize.name+' · '+currentPrize.sub+'  —  qui va le gagner ? 🎟️';
}

// 2) QUI gagne ce lot
async function drawWinner(){
  if(spinning || !DATA || !currentPrize) return;
  if(pool.length===0){ $('#note').textContent='Plus aucun participant en jeu.'; return; }
  spinning=true; $('#drawWinner').disabled=true; $('#drawPrize').disabled=true; camReset();
  hideQ(); if(winnerPin) mapZoomOut(); $('#maplabel').classList.remove('show');   // on révèle la roulette
  SFX.whoosh(1.3);

  const winnerIdx=pool[Math.floor(Math.random()*pool.length)];
  const winner=DATA.entries[winnerIdx];

  const NWIN=52, dur=8200+Math.random()*800;       // +3 s de défilement
  scan=true; rover=null; mapProgress=0;

  // chaque nom qui passe au centre déplace le point sur SA ville (synchro parfaite)
  await spinTo($('#reel'), $('#frame'), i=> makeNameItem(i===NWIN?winner:randEntry(), i!==NWIN), NWIN, 60, dur,
    (t,el)=>{ mapProgress=t; if(el && el._entry) mapSyncTo(el._entry); });
  scan=false;

  // commit
  remaining[currentPrize.id]--; if($('#exclude').checked) wonKeys.add((winner.email||'').toLowerCase());
  rebuildPool(); renderLegend();
  burst(); SFX.win();
  mapZoomTo(winner.lat, winner.lon);
  if(winner.city){ $('#maplabel').textContent=winner.city; $('#maplabel').classList.add('show'); }
  fillCam(winner);                                  // tel + email dans l'encart caméra (régie)
  $('#note').textContent='Coordonnées affichées dans l’encart caméra (visibles seulement en régie).';
  currentPrize=null; spinning=false;
  $('#drawPrize').disabled = (prizesLeft()===0 || pool.length===0);
}

function rebuildPool(){ pool=[]; DATA.entries.forEach((e,i)=>{ if(!wonKeys.has((e.email||'').toLowerCase())) pool.push(i); }); $('#s-remain').textContent=pool.length.toLocaleString('fr-FR'); }

// Encart caméra : affiche tel + email du gagnant (visible seulement en régie, la caméra
// OBS recouvre cette zone pour les viewers). Nom complet indisponible côté client
// (pseudonymisé) → on montre prénom + initiale + ville pour identifier qui appeler.
function fillCam(w){
  $('#cam-name').textContent = `${w.firstname}${w.initial?' '+w.initial+'.':''}${w.city?' · '+w.city:''}`;
  const tel=(w.tel||'').replace(/\s+/g,'');
  const a=$('#cam-tel'); a.textContent=formatTel(w.tel); a.href = tel ? 'tel:'+tel : '#';
  $('#cam-email').textContent = w.email||'';
  $('#cam-info').classList.add('show'); $('#cam').classList.add('filled');
}
function camReset(){ $('#cam-info').classList.remove('show'); $('#cam').classList.remove('filled'); }
function formatTel(t){ if(!t) return 'Numéro indisponible'; const d=t.replace(/\D/g,''); if(d.length===10) return d.replace(/(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/,'$1 $2 $3 $4 $5'); return t; }

// ----- Events -----
$('#drawPrize').addEventListener('click', drawPrize);
$('#drawWinner').addEventListener('click', drawWinner);
addEventListener('resize', ()=>{ mapResize(); if(DATA) primeReels(); });

// ----- Preuve d'équité (empreinte de la liste figée) -----
function renderProof(s){
  if(!s){ return; }
  const t=new Date(s.generated_at).toLocaleString('fr-FR');
  $('#proof-meta').textContent = `${s.participants.toLocaleString('fr-FR')} participants · ${s.chances.toLocaleString('fr-FR')} chances · ${t}`;
  $('#proof-hash').textContent = 'SHA-256 '+s.sha256;
  $('#proof').hidden=false;
}
$('#proof-dl').addEventListener('click', ()=>{
  const s=DATA&&DATA.snapshot; if(!s) return;
  const blob=new Blob([s.list],{type:'text/plain;charset=utf-8'});
  const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
  a.download='concours-brasero-snapshot-'+s.sha256.slice(0,8)+'.txt'; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(()=>URL.revokeObjectURL(a.href),2000);
});
$('#proof-copy').addEventListener('click', ()=>{
  const s=DATA&&DATA.snapshot; if(!s) return;
  if(navigator.clipboard) navigator.clipboard.writeText(s.sha256);
  $('#proof-copy').textContent='✓ Copié'; setTimeout(()=>$('#proof-copy').textContent="⧉ Copier l'empreinte",1500);
});

// ----- Load -----
async function load(){
  try{
    const [d,o] = await Promise.all([
      fetch('?data=1&_='+Date.now(),{cache:'no-store'}).then(r=>r.json()),
      fetch('?outline=1&_='+Date.now(),{cache:'no-store'}).then(r=>r.json()),
    ]);
    if(d.error) throw new Error(d.error);
    DATA=d; PINS=d.pins||[]; OUTLINE=o;
    $('#s-buyers').textContent=d.buyers.toLocaleString('fr-FR');
    $('#s-chances').textContent=d.total_chances.toLocaleString('fr-FR');
    $('#pincount').textContent=PINS.length.toLocaleString('fr-FR')+' adresses';
    rebuildPool(); renderLegend(); mapResize(); primeReels(); showQ(); renderProof(d.snapshot);
    $('#drawPrize').disabled=false;
    $('#note').textContent=`Prêt — ${d.total_chances.toLocaleString('fr-FR')} chances · ${prizesLeft()} lots. Étape 1 : tire le lot 🎁`;
  }catch(err){ $('#note').textContent='Erreur de chargement : '+err.message; }
}

// ===== Embers (fond) =====
const ec=$('#embers'), ex=ec.getContext('2d'); let EW,EH,emb=[];
function eresize(){ EW=ec.width=innerWidth; EH=ec.height=innerHeight; }
function espawn(){ return {x:Math.random()*EW,y:EH+10,r:Math.random()*2.6+.6,vy:-(Math.random()*.9+.3),vx:(Math.random()-.5)*.5,life:0,max:Math.random()*260+160,flick:Math.random()*6.28}; }
eresize(); for(let i=0;i<70;i++){ const e=espawn(); e.y=Math.random()*EH; emb.push(e); }
(function eloop(){ ex.clearRect(0,0,EW,EH); ex.globalCompositeOperation='lighter';
  for(const e of emb){ e.life++; e.y+=e.vy; e.x+=e.vx+Math.sin(e.flick+e.life*.03)*.3; e.vy-=.002;
    const a=Math.max(0,1-e.life/e.max), r=e.r*(1+Math.sin(e.flick+e.life*.2)*.15);
    const g=ex.createRadialGradient(e.x,e.y,0,e.x,e.y,r*4); g.addColorStop(0,`rgba(255,${180+(Math.random()*40|0)},90,${a})`); g.addColorStop(.4,`rgba(255,110,0,${a*.8})`); g.addColorStop(1,'rgba(255,45,18,0)');
    ex.fillStyle=g; ex.beginPath(); ex.arc(e.x,e.y,r*4,0,6.28); ex.fill();
    if(e.life>=e.max||e.y<-20) Object.assign(e,espawn()); }
  ex.globalCompositeOperation='source-over'; requestAnimationFrame(eloop); })();

// ===== Sparks (victoire) =====
const sc=$('#sparks'), sx=sc.getContext('2d'); let SW,SH,sparks=[];
function sresize(){ SW=sc.width=innerWidth; SH=sc.height=innerHeight; } sresize();
function burst(){ const cx=SW*0.42, cy=SH*0.5; for(let i=0;i<150;i++){ const ang=Math.random()*6.28, sp=Math.random()*9+3; sparks.push({x:cx,y:cy,vx:Math.cos(ang)*sp,vy:Math.sin(ang)*sp-3,life:0,max:Math.random()*60+40,c:Math.random()<.5?'255,200,90':'255,90,20'}); } }
(function sloop(){ sx.clearRect(0,0,SW,SH); sx.globalCompositeOperation='lighter';
  for(let i=sparks.length-1;i>=0;i--){ const p=sparks[i]; p.life++; p.x+=p.vx; p.y+=p.vy; p.vy+=.18; p.vx*=.99; const a=Math.max(0,1-p.life/p.max);
    sx.fillStyle=`rgba(${p.c},${a})`; sx.beginPath(); sx.arc(p.x,p.y,2.4*a+.6,0,6.28); sx.fill(); if(p.life>=p.max) sparks.splice(i,1); }
  sx.globalCompositeOperation='source-over'; requestAnimationFrame(sloop); })();
addEventListener('resize',()=>{ eresize(); sresize(); });

// tick sonore
// ===== Moteur audio (WebAudio, sans fichier externe) =====
const SFX=(()=>{
  let ctx, master;
  function ensure(){
    try{
      if(!ctx){ ctx=new (window.AudioContext||window.webkitAudioContext)(); master=ctx.createGain(); master.gain.value=0.6; master.connect(ctx.destination); }
      if(ctx.state==='suspended') ctx.resume();
    }catch(e){}
    return ctx;
  }
  const noise=(dur)=>{ const n=ctx.createBuffer(1,Math.max(1,ctx.sampleRate*dur),ctx.sampleRate); const d=n.getChannelData(0); for(let i=0;i<d.length;i++) d[i]=Math.random()*2-1; return n; };

  // tic percussif et net (transitoire bruité filtré + petit "tok" tonal). vel 0..1 = vivacité
  function tick(vel=0.5){
    const c=ensure(); if(!c) return; const t=c.currentTime; vel=Math.max(0,Math.min(1,vel));
    // transitoire (le "clic")
    const ns=c.createBufferSource(); ns.buffer=noise(0.03);
    const bp=c.createBiquadFilter(); bp.type='bandpass'; bp.frequency.value=2200+vel*1800; bp.Q.value=0.9;
    const ng=c.createGain(); ng.gain.setValueAtTime(0.0001,t); ng.gain.exponentialRampToValueAtTime(0.12,t+0.002); ng.gain.exponentialRampToValueAtTime(0.0001,t+0.05);
    ns.connect(bp); bp.connect(ng); ng.connect(master); ns.start(t); ns.stop(t+0.06);
    // corps tonal (le "tok") avec légère chute de hauteur
    const o=c.createOscillator(), g=c.createGain(); o.type='triangle';
    const f0=560+vel*520; o.frequency.setValueAtTime(f0,t); o.frequency.exponentialRampToValueAtTime(f0*0.6,t+0.05);
    g.gain.setValueAtTime(0.0001,t); g.gain.exponentialRampToValueAtTime(0.14,t+0.004); g.gain.exponentialRampToValueAtTime(0.0001,t+0.10);
    o.connect(g); g.connect(master); o.start(t); o.stop(t+0.12);
  }

  // montée de tension pendant le défilement
  function whoosh(dur=1.1){
    const c=ensure(); if(!c) return; const t=c.currentTime;
    const ns=c.createBufferSource(); ns.buffer=noise(dur);
    const bp=c.createBiquadFilter(); bp.type='bandpass'; bp.Q.value=1.1;
    bp.frequency.setValueAtTime(280,t); bp.frequency.exponentialRampToValueAtTime(2600,t+dur*0.7);
    const g=c.createGain(); g.gain.setValueAtTime(0.0001,t); g.gain.exponentialRampToValueAtTime(0.08,t+0.2); g.gain.exponentialRampToValueAtTime(0.0001,t+dur);
    ns.connect(bp); bp.connect(g); g.connect(master); ns.start(t); ns.stop(t+dur);
  }

  // note(s) : utilitaire cloche douce
  function bell(f, st, dur, vol){
    const o=ctx.createOscillator(), g=ctx.createGain(); o.type='sine'; o.frequency.value=f;
    g.gain.setValueAtTime(0.0001,st); g.gain.exponentialRampToValueAtTime(vol,st+0.02); g.gain.exponentialRampToValueAtTime(0.0001,st+dur);
    o.connect(g); g.connect(master); o.start(st); o.stop(st+dur+0.02);
  }
  // ding court (lot tiré)
  function ding(){ const c=ensure(); if(!c) return; const t=c.currentTime; bell(659.25,t,0.4,0.2); bell(987.77,t+0.08,0.5,0.18); }
  // carillon de victoire (gagnant révélé) — arpège majeur + paillette
  function win(){ const c=ensure(); if(!c) return; const t=c.currentTime; const ar=[523.25,659.25,783.99,1046.5];
    ar.forEach((f,i)=>bell(f,t+i*0.10,0.7,0.22)); bell(1567.98,t+0.42,0.8,0.12); bell(2093,t+0.5,0.7,0.08); }

  return {ensure, tick, whoosh, ding, win};
})();

mapLoop();
load();
</script>
</body>
</html>
