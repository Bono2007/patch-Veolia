# Reverse-engineering toutsurmoneau.fr (SUEZ)

État au 2026-05-01. Site SUEZ Tout Sur Mon Eau, build front 2026-03-10, version 10.7.0.

> Ce document explique le flow d'API utilisé par le patch livré dans ce dossier.

## Pourquoi le plugin Jeedom v3.1.0 est cassé

Le plugin POST sur `/mon-compte-en-ligne/exporter-consommation/day/{token}/{year}/{month}` (ancienne API). Le serveur ne reconnaît plus cette route et **renvoie la home HTML**, que PHPExcel essaie de parser → exception "Maximum 31 characters allowed in sheet title".

Le nouveau site est une SPA React qui appelle des APIs JSON internes, et le bouton de téléchargement CSV est généré côté client (`Blob` JS) — il n'y a plus d'URL serveur qui renvoie le CSV.

## Flow de scraping cible

### 1. GET `/mon-compte-en-ligne/je-me-connecte` (sans cookies)

- Récupère un cookie de session (`PHPSESSID` ou équivalent) dans `Set-Cookie`.
- Le HTML contient une variable JS avec le CSRF token, sous forme escapée Unicode :
  ```
  "csrfToken":"<TOKEN>"
  ```
  ce qui une fois désescapé donne `"csrfToken":"<TOKEN>"`.
- **Regex PHP suggérée** :
  ```php
  preg_match('/\\\\u0022csrfToken\\\\u0022\\\\u003A\\\\u0022([^\\\\]+)\\\\u0022/', $html, $m);
  $csrf = $m[1];
  ```

### 2. POST `/mon-compte-en-ligne/je-me-connecte`

Form Symfony classique (`Content-Type: application/x-www-form-urlencoded` ou `multipart/form-data`) :

| Champ | Valeur |
|---|---|
| `tsme_user_login[_username]` | email |
| `tsme_user_login[_password]` | mot de passe |
| `_csrf_token` | token récupéré à l'étape 1 |
| `tsme_user_login[_target_path]` | (vide ou `/mon-compte-en-ligne/tableau-de-bord`) |
| `g-recaptcha-response` | (optionnel — uniquement après plusieurs échecs) |

- **Renvoyer les cookies** récupérés à l'étape 1 (curl : `CURLOPT_COOKIEJAR` + `CURLOPT_COOKIEFILE`).
- Suivre le redirect (`CURLOPT_FOLLOWLOCATION = true`). Si succès → redirige vers `/mon-compte-en-ligne/tableau-de-bord`. Si échec → retour sur `/je-me-connecte` avec message d'erreur.

### 3. GET `/public-api/cel-consumption/meters-list`

Réponse JSON (valeurs anonymisées) :
```json
{
  "content": {
    "clientCompteursPro": [{
      "compteursPro": [{
        "idPDS": "<10_chiffres>",
        "matriculeCompteur": "<n°_série>",
        "etatCompteur": "Ouvert",
        "fluide": "EAUF",
        "anneeFabrication": "<YYYY>",
        "adresseDesserte": "<adresse>",
        "...": "..."
      }],
      "roles": ["..."]
    }]
  },
  "code": "00",
  "message": "OK"
}
```

Le champ utile : `content.clientCompteursPro[*].compteursPro[*].idPDS`.

### 4. GET `/public-api/cel-consumption/telemetry`

Paramètres :
- `id_PDS` : récupéré à l'étape 3
- `mode` : `daily` | `monthly` | `yearly`
- `start_date` : `YYYY-MM-DD`
- `end_date` : `YYYY-MM-DD`

⚠️ En `daily`, garder une **plage courte** (≤ 1 mois) sinon le serveur répond `code:"03"` ("Une erreur technique s'est produite").

Réponse en `monthly` (valeurs d'exemple) :
```json
{
  "content": {
    "measures": [
      { "date": "2026-04-01 00:00:00", "index": 100.000, "volume": 2.500, "numberOfDays": 30 },
      { "date": "2026-05-01 00:00:00", "index": null,    "volume": 0,     "numberOfDays": 0 }
    ]
  },
  "code": "00",
  "message": "OK"
}
```

Réponse en `daily` (valeurs d'exemple) :
```json
{
  "content": {
    "measures": [
      { "date": "2026-04-30 00:00:00", "index": 100.000, "volume": 0.080 }
    ]
  },
  "code": "00",
  "message": "OK"
}
```

- `index` = lecture cumulative du compteur (m³)
- `volume` = consommation sur la période (m³)
- `numberOfDays` = présent uniquement en monthly/yearly
- `index: null` signale une mesure indisponible (jour en cours, futur, ou panne télérelève)

## Headers à mimer (tous les XHR)

```
User-Agent:    Mozilla/5.0 (...) Chrome/...
Accept:        application/json, text/plain, */*
Referer:       https://www.toutsurmoneau.fr/mon-compte-en-ligne/historique-de-consommation-tr
X-Requested-With: XMLHttpRequest
```

## Endpoints bonus utiles (non utilisés par le patch)

| URL | Usage |
|---|---|
| `/public-api/cel-consumption/get-price` | prix unitaire de l'eau pour conversion € |
| `/public-api/meter/alerts?meterNumber=<idPDS>` | alertes (fuite, surconso) |
| `/public-api/contract/tile/balance` | solde du contrat |
| `/public-api/contract/tile/invoice` | dernière facture |
| `/public-api/user/donnees-contrats` | infos contrat |

## Notes pour faire évoluer le portage

1. Supprimer à terme la dépendance `3rparty/PHPExcel/` (devenue inutile pour `website == 4`).
2. Stocker `idPDS` en config de l'eqLogic (à la place du `compteur` legacy basé sur scraping).
3. Mode `daily` à appeler **par tranches de 30 jours** pour rebackfill l'historique.
4. Pour le cron quotidien, un seul appel `daily` sur `[J-2, J]` suffit (la donnée d'avant-hier est figée par SUEZ).

## Note sécurité

Les identifiants Veolia/SUEZ sont stockés **en clair** dans `eqLogic.configuration` (JSON) en BDD Jeedom. C'est par design Jeedom mais : si tu réutilises le même mot de passe ailleurs, change-le.

## Méthode de découverte (pour reproduire / vérifier)

Tout a été obtenu en :
1. Ouvrant `https://www.toutsurmoneau.fr/mon-compte-en-ligne/historique-de-consommation-tr` connecté.
2. F12 → onglet **Network** → filtre **Fetch/XHR** → repérer les calls `/public-api/*`.
3. Console DevTools : `fetch('/public-api/cel-consumption/meters-list').then(r=>r.json()).then(console.log)`.
4. Lecture du bundle JS minifié (`/assets/front/build/app.<hash>.js`) pour trouver le format de POST de login (cherchait `tsme_user_login`, `csrfToken`).

Aucune donnée perso, identifiant compteur ou index réel ne figure dans ce document — il est conçu pour être partagé publiquement (forum, GitHub PR).
