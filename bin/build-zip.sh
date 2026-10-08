#!/usr/bin/env bash
# Construit dist/novi_storelocator.zip, installable via Extensions > Ajouter > Téléverser.
# Le dossier racine du zip s'appelle « novi_storelocator » : WordPress reconnaît ainsi
# le plugin déjà installé et le remplace au lieu d'en créer un second.
set -euo pipefail

cd "$(dirname "$0")/.."
VERSION=$(sed -n 's/^ \* Version: *//p' novi_storelocator.php | tr -d '[:space:]')
mkdir -p dist
rm -f dist/novi_storelocator.zip

git archive --format=zip --prefix=novi_storelocator/ -o dist/novi_storelocator.zip HEAD -- \
	novi_storelocator.php includes assets README.md CHANGELOG.md

echo "dist/novi_storelocator.zip (version ${VERSION}) créé à partir du commit $(git rev-parse --short HEAD)."
