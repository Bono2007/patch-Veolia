#!/usr/bin/env python3
"""
Applique le patch TR (toutsurmoneau / SUEZ) au plugin Jeedom veolia_eau v3.1.0.

Usage :
    python3 apply_patch.py [chemin_du_fichier_a_patcher]

Par défaut : /var/www/html/plugins/veolia_eau/core/class/veolia_eau_process.class.php

Le script :
  1. Vérifie que le patch n'est pas déjà appliqué (marqueur)
  2. Backup l'original en <fichier>.before_TR_PATCH
  3. Insère la nouvelle méthode getConsoToutsurmoneauTR() avant getConso()
  4. Insère un branchement dans getConso() pour rediriger website==4
  5. Vérifie le PHP avec `php -l`
"""

import shutil
import subprocess
import sys
from pathlib import Path

DEFAULT_TARGET = (
    "/var/www/html/plugins/veolia_eau/core/class/veolia_eau_process.class.php"
)
MARKER = "/* @VEOLIA_TR_PATCH_V1@ */"
ORIGINAL_GETCONSO_HEADER = "\tpublic function getConso($mock_test) {\n"

NEW_METHOD_FILE = Path(__file__).parent / "snippet_new_method.php"

BRANCH_INJECT = """\tpublic function getConso($mock_test) {
        // [TR_PATCH] Pour toutsurmoneau (website=4), bypass complet du legacy
        // (ancien scraping HTML/PHPExcel cassé depuis la refonte SUEZ 2026-03).
        if (intval($this->getConfiguration('website')) == 4) {
            return $this->getConsoToutsurmoneauTR($mock_test);
        }
"""


def main(argv):
    target = Path(argv[1] if len(argv) > 1 else DEFAULT_TARGET)
    if not target.is_file():
        print(f"ERREUR : fichier introuvable : {target}", file=sys.stderr)
        return 1
    if not NEW_METHOD_FILE.is_file():
        print(f"ERREUR : snippet introuvable : {NEW_METHOD_FILE}", file=sys.stderr)
        return 1

    src = target.read_text()
    if MARKER in src:
        print(f"PATCH DÉJÀ APPLIQUÉ — rien à faire ({target})")
        return 0
    if ORIGINAL_GETCONSO_HEADER not in src:
        print(
            "ERREUR : signature 'public function getConso($mock_test) {' introuvable.",
            file=sys.stderr,
        )
        print(
            "Le fichier ne semble pas être le veolia_eau_process.class.php attendu.",
            file=sys.stderr,
        )
        return 1

    backup = target.with_suffix(target.suffix + ".before_TR_PATCH")
    if not backup.exists():
        shutil.copy2(target, backup)
        print(f"Backup créé : {backup}")
    else:
        print(f"Backup déjà présent : {backup} (conservé)")

    new_method = NEW_METHOD_FILE.read_text()
    if not new_method.endswith("\n"):
        new_method += "\n"

    src = src.replace(ORIGINAL_GETCONSO_HEADER, BRANCH_INJECT, 1)
    src = src.replace(BRANCH_INJECT, new_method + "\n" + BRANCH_INJECT, 1)
    target.write_text(src)
    print(f"Patch appliqué ({target}, {len(src)} octets)")

    # Vérification PHP
    php = shutil.which("php")
    if php:
        r = subprocess.run([php, "-l", str(target)], capture_output=True, text=True)
        if r.returncode != 0:
            print("ERREUR : php -l a échoué :", file=sys.stderr)
            print(r.stdout + r.stderr, file=sys.stderr)
            print(f"Restauration depuis {backup}…", file=sys.stderr)
            shutil.copy2(backup, target)
            return 2
        print(r.stdout.strip())
    else:
        print("(php non trouvé — vérification syntaxique sautée)")

    print("\nProchaine étape : exécuter le cron du plugin ou attendre 23h.")
    print("Test manuel :")
    print(
        '  sudo -u www-data php -r \'require "/var/www/html/core/php/core.inc.php"; foreach (eqLogic::byType("veolia_eau", true) as $e) $e->getConso(0);\''
    )
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
