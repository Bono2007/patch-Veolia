# Patch veolia_eau — support nouveau site SUEZ (toutsurmoneau.fr)

Correctif pour le plugin Jeedom **veolia_eau v3.1.0** (NextDom) cassé depuis la refonte du site `toutsurmoneau.fr` (SUEZ, build front 2026-03-10, version 10.7.0).

## Symptômes corrigés

Dans `/var/www/html/log/veolia_eau` :
```
[ERROR] Maximum 31 characters allowed in sheet title.
[ERROR] Call to a member function find() on bool
[ERROR] Aucune donnée, merci de vérifier que vos identifiants sont corrects…
```

## Cause

Le plugin scrappait l'ancienne URL `/mon-compte-en-ligne/exporter-consommation/day/{token}/{year}/{month}` qui n'existe plus. Le serveur renvoie maintenant la home HTML, que PHPExcel essaie de parser → exception "31 characters".

Le nouveau site est une SPA React qui utilise des **APIs JSON internes** (`/public-api/cel-consumption/*`). Le bouton de téléchargement CSV est généré côté client, plus aucun endpoint serveur ne renvoie le CSV.

## Ce que fait le patch

Pour `website == 4` (toutsurmoneau.fr), bypass complet du legacy : login Symfony → API JSON `meters-list` + `telemetry`. Le code legacy reste intact pour les autres fournisseurs (Veolia classique, Lyon, etc.).

Voir [`REVERSE_ENGINEERING.md`](REVERSE_ENGINEERING.md) pour le détail du flow d'API et la méthode de découverte.

## Contenu du dossier

| Fichier | Usage |
|---|---|
| `README.md` | Ce fichier |
| `REVERSE_ENGINEERING.md` | Détail du flow d'API SUEZ et méthode de découverte |
| `apply_patch.py` | Applique le patch sur un fichier original (avec backup auto) |
| `restore.sh` | Restaure depuis le backup |
| `snippet_new_method.php` | La nouvelle méthode `getConsoToutsurmoneauTR()` seule (pour copier-coller manuellement) |
| `veolia_eau_process.class.php` | Fichier complet **patché**, prêt à remplacer |
| `veolia_eau_process.class.php.original` | Fichier complet **original** v3.1.0, pour référence |
| `veolia_eau_TR_v1.patch` | Diff unifié (compatible `git apply` et `patch -p0`) |

## 3 façons d'installer

### A. Script Python (recommandé)

Sur le serveur Jeedom (LXC ou bare-metal) :

```bash
# Copier le dossier patch sur le serveur, puis :
cd patch/
sudo python3 apply_patch.py
```

Cible par défaut : `/var/www/html/plugins/veolia_eau/core/class/veolia_eau_process.class.php`.
Backup auto en `<fichier>.before_TR_PATCH`.

Pour un autre chemin :
```bash
sudo python3 apply_patch.py /chemin/vers/veolia_eau_process.class.php
```

### B. Remplacement direct du fichier

```bash
sudo cp /var/www/html/plugins/veolia_eau/core/class/veolia_eau_process.class.php{,.before_TR_PATCH}
sudo cp veolia_eau_process.class.php /var/www/html/plugins/veolia_eau/core/class/
sudo php -l /var/www/html/plugins/veolia_eau/core/class/veolia_eau_process.class.php
```

### C. `patch` Unix

```bash
cd /var/www/html/plugins/veolia_eau/core/class/
sudo cp veolia_eau_process.class.php{,.before_TR_PATCH}
sudo patch < /chemin/vers/veolia_eau_TR_v1.patch
```

## Test après installation

```bash
sudo -u www-data php -r '
  require_once "/var/www/html/core/php/core.inc.php";
  foreach (eqLogic::byType("veolia_eau", true) as $e) {
    echo $e->getName().": ".var_export($e->getConso(0), true)."\n";
    echo "  compteur=".$e->getConfiguration("compteur")."\n";
    echo "  last=".$e->getConfiguration("last")."\n";
  }
'
```

Attendu :
```
Compteur Eau: 0
  compteur=<index actuel en m³>
  last=<dernière date>
```

Vérifier ensuite l'historique de la commande **Index** dans Jeedom — elle doit contenir les 30 derniers jours.

## Désinstallation

```bash
sudo bash restore.sh
```

Ou manuellement :
```bash
sudo cp /var/www/html/plugins/veolia_eau/core/class/veolia_eau_process.class.php.before_TR_PATCH \
        /var/www/html/plugins/veolia_eau/core/class/veolia_eau_process.class.php
```

## Précautions

- **Le patch sera écrasé** si le plugin est mis à jour depuis le market Jeedom/NextDom. Il faut le réappliquer.
- **Hardcodé pour `website == 4`** (toutsurmoneau.fr générique). Les autres sites SUEZ (vendo, sénart, codes 6 à 14) utilisent probablement la même API mais ce n'est **pas testé**. Pour les ajouter, étendre le `if` dans `getConso()` :
  ```php
  if (in_array(intval($this->getConfiguration('website')), [4, 6, 7, 8, 9, 10, 11, 12, 13, 14])) {
      return $this->getConsoToutsurmoneauTR($mock_test);
  }
  ```
  et adapter `$url_site` dans `getConsoToutsurmoneauTR()` (actuellement codé en dur à `www.toutsurmoneau.fr`).
- **Robustesse de la regex CSRF** : si SUEZ change le format de la page de login, le pattern `csrfToken\\u0022\\u003A\\u0022(...)` peut casser. Le diagnostic se fait dans le log Jeedom : message `TR: csrfToken introuvable dans la page de login`.

## Pour proposer en upstream (NextDom)

Le repo est `https://github.com/NextDom/plugin-veolia_eau`.

Suggestion de Pull Request :
1. Fork du repo
2. Branche `fix/toutsurmoneau-tr-api`
3. Appliquer le patch (`apply_patch.py` ou copier `veolia_eau_process.class.php`)
4. Tests : trigger manuel + vérifier le log + vérifier l'historique d'une cmd
5. Description PR : pointer vers `README.md` et `REVERSE_ENGINEERING.md`
6. Mentionner que la lib `3rparty/PHPExcel/` peut être supprimée à terme (plus utilisée pour website=4)

## Versionning

- `v1` (mai 2026) : support initial pour website=4 (toutsurmoneau.fr générique)
