#! /bin/bash
rm -f build/debughawk.zip
composer install --no-dev
npm install
npm run build
zip -r build/debughawk.zip . -x "*.git*" ".claude/*" ".parcel-cache/*" "build/*" "node_modules/*" "tests/*" "phpunit.xml.dist" ".phpunit.result.cache" "*.DS_Store" "build.sh" "CLAUDE.md" "tsconfig.json" "vite.config.js"
