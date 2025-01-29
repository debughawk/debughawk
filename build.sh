#! /bin/bash
rm -f debughawk.zip
composer install --no-dev
npm install
npm run build
zip -r debughawk.zip . -x "*.git*" ".parcel-cache/*" "node_modules/*" "*.DS_Store" "build.sh"
