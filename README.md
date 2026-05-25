# 🔥 Concours Brasero METFI — tirage au sort

Code **public, par souci de transparence**, de l'outil utilisé en direct (live) pour tirer
au sort les gagnants du concours « T-Shirt Concours Brasero » sur [metfi.fr](https://metfi.fr).

Le but de ce dépôt est de **prouver que les résultats ne sont pas truqués** : l'algorithme
de tirage est entièrement visible ci-dessous et dans [`index.php`](index.php).

---

## ⚖️ Comment le tirage fonctionne (équité)

### 1. Qui participe
Sont éligibles **toutes les commandes payées** contenant le produit du concours
(`PRODUCT_ID = 96`), c'est-à-dire les commandes dans les états :

- `2` — *Paiement accepté*
- `9` — *En attente de réapprovisionnement (payé)*

Les commandes **non payées** (paniers, en attente de paiement) sont **exclues**.
Voir la requête SQL exacte dans `index.php` (fonction `?data=1`).

### 2. Une chance par t-shirt acheté
Pour chaque commande, on crée **autant de lignes (« chances ») que de t-shirts achetés** :

> quelqu'un qui a acheté 2 t-shirts a **2 chances**, 3 t-shirts → 3 chances, etc.

```php
$qty = max(1, (int)$row['product_quantity']);
for ($i = 0; $i < $qty; $i++) { $entries[] = [...]; }  // une entrée par t-shirt
```

### 3. Le tirage est uniformément aléatoire
Le gagnant est tiré **uniformément au hasard parmi toutes les chances restantes**
(`Math.random()` du navigateur), donc la probabilité de gagner est **proportionnelle
au nombre de t-shirts achetés** — ni plus, ni moins :

```js
const winnerIdx = pool[Math.floor(Math.random() * pool.length)];
```

Aucune pondération cachée, aucune liste pré-établie : les participants sont **relus en
direct depuis la base** à chaque rechargement de page (en-tête `Cache-Control: no-store`).

### 4. Les lots
10 lots, tirés **dans la limite du stock** (impossible d'en attribuer plus que disponible) :

| Lot | Quantité |
|-----|----------|
| Grand Brasero **avec** flamme | 2 |
| Grand Brasero **sans** flamme | 3 |
| Petit Brasero **avec** flamme | 2 |
| Petit Brasero **sans** flamme | 3 |

Le tirage se fait en 2 temps pour le live : on tire d'abord **le lot**, puis **le gagnant**.

### 5. Option « retirer les gagnants déjà tirés »
Activée par défaut : une même personne ne peut pas gagner deux lots (ses chances sont
retirées du tirage après une victoire).

---

## 🔒 Données personnelles

- **Aucune donnée client n'est versionnée dans ce dépôt.** Les noms, e-mails et numéros
  de téléphone ne sont **jamais stockés sur disque** : ils sont lus en direct depuis la
  base PrestaShop via l'endpoint `?data=1`, **protégé par authentification**.
- L'interface n'affiche publiquement que le **prénom + l'initiale du nom**. Le numéro de
  téléphone n'apparaît qu'au clic sur « Appeler », côté présentateur.
- Les identifiants (base de données, accès) sont dans `config.php`, **non versionné**
  (voir `config.example.php`).
- `postcodes.json` (table *code postal → coordonnées*, sans aucune donnée nominative) est
  généré au runtime et non versionné.

---

## 🗺️ La carte

La carte France + Belgique est dessinée localement (canvas) à partir de `outline.json`
(tracé des frontières, donnée publique générique). Pendant le tirage, un point suit la
ville du nom actuellement au centre de la roulette ; à la fin il s'arrête sur la ville du
gagnant. Le géocodage **ville** des codes postaux utilise des sources publiques :

- France : [geo.api.gouv.fr](https://geo.api.gouv.fr) (base officielle des communes)
- Belgique : [zippopotam.us](https://api.zippopotam.us)

`build.mjs` permet de (re)générer `outline.json` + `postcodes.json` hors-ligne.

---

## 🚀 Installation

```bash
cp config.example.php config.php   # puis renseigne tes identifiants
# place les fichiers derrière un serveur PHP 8+ (mysqli, curl)
```

Pile : PHP 8 (mysqli, curl), une base PrestaShop, et un navigateur moderne (WebAudio,
Canvas). Aucune dépendance JS externe (hors polices Google).

---

## ✅ Vérifier l'équité (preuve par empreinte)

À chaque chargement, la liste des participants est **figée** et la page calcule un
**SHA-256 d'une liste pseudonymisée** (sans téléphone ni e-mail). La page affiche, en
direct, sous les compteurs :

> 🔒 Liste figée · *N* participants · *M* chances · *date/heure* · `SHA-256 …`

**Déroulé pour une transparence vérifiable :**

1. **Avant de tirer**, on publie publiquement (capture, post, commit) **l'empreinte
   SHA-256 + le nombre de participants/chances** affichés.
2. On télécharge la **« Preuve (.txt) »** (bouton sur la page) : c'est la liste figée,
   une ligne par commande au format `id_commande;prenom;initiale;ville;nb_chances`.
3. **Après le tirage**, on publie ce fichier.

**N'importe qui peut alors vérifier**, avec des outils standard :

```bash
sha256sum concours-brasero-snapshot-XXXXXXXX.txt
# => doit être EXACTEMENT l'empreinte annoncée avant le tirage
```

- Si l'empreinte correspond → la liste **n'a pas été modifiée** après l'engagement.
- La somme de la dernière colonne (`nb_chances`) = nombre total de chances annoncé.
- On peut recompter les chances de chacun (1 par t-shirt) et vérifier qu'aucun nom n'a
  été ajouté/dupliqué/retiré.

> ⚠️ Règle d'or : ne pas recharger la page entre l'annonce de l'empreinte et la fin des
> tirages — un rechargement re-fige la liste (nouvelles commandes) et change l'empreinte.

Le tirage lui-même s'exécute **dans le navigateur** (`Math.random()` sur la liste figée) :
en ouvrant le code source de la page (Ctrl+U), on lit l'algorithme réellement exécuté, et
il est identique à ce dépôt.

---

*Outil interne METFI. Publié pour la transparence du concours.*
