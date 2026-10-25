# Work Factory / مصنع الشغل

Dashboard page: `/admin/work-factory`. Owners can open it immediately. Roles can grant `WorkFactory` → view; the existing permissions matrix discovers the page automatically.

Each authenticated staff member gets a separate `staff_ID` session. Session IDs are derived on the PHP server, never accepted from browser input. A standalone Baileys worker listens on loopback port 3081 with the existing BOT_TOKEN header. It does not use or restart the AI bot, send replies, mark messages read, or retain message bodies. Credentials and sender identities belong outside public_html with private filesystem permissions.

Start connection and scan the QR using WhatsApp Linked Devices. History is asynchronous and limited to what WhatsApp provides. Full-history sync is requested; the UI does not claim all historic chats are guaranteed. Incoming one-to-one message senders are collected, with groups/status/outgoing-only contacts excluded. LID/phone mappings merge identities; hidden LIDs are never exported as phone numbers. Names and usernames are retained where WhatsApp supplies them. A disconnected account's extracted list remains available; disconnect removes only its linking credentials.

Downloads are actual XLSX workbooks, with phone numbers as explicit strings and no formula interpretation. Splitting creates one ZIP containing balanced XLSX files, with any remainder assigned to the earliest files. Each extracted row is assigned once. Up to 100 nonempty files per export. Downloads are generated privately and temporary files are removed after streaming.

Runtime environment: `BOT_TOKEN`, `FACTORY_DATA_DIR` (required, outside web root), `FACTORY_PORT` (default 3081). PHP can override the worker URL via `WHATSAPP_FACTORY_URL`. Node uses the already installed whatsapp-bot dependencies; no new package is required. On production the worker is managed by `motogate-work-factory.service` as elhawy with a private environment file, private data directory, and no public listening socket.

Tests: `node --test whatsapp-bot/test/factory-contacts.test.js`; `php vendor/phpunit/phpunit/phpunit tests/Unit/WorkFactoryExportTest.php tests/Feature/WorkFactoryTest.php`. Coverage includes sender filtering, LID aliases across restart, usernames, balanced 300/2 and 301/2 splits, valid XML, string phone cells, formula safety, own-session isolation and ZIP members. Live WhatsApp history import requires the owner to scan their QR.

History API reference: https://github.com/WhiskeySockets/docs/blob/main/advanced/history-sync.mdx

Production verification: the service is active and bound to 127.0.0.1:3081; unauthenticated requests return 401. The dashboard was inspected after login and a real WhatsApp QR was generated. Desktop registration was rejected with code 428 before QR, so the collector uses the compatible Chrome profile while still requesting history. See https://github.com/WhiskeySockets/Baileys/issues/2677. PHP tests passed (6 tests, 22 assertions); Node sender tests passed (3 tests), also on production. Actual account history cannot be validated until the owner scans their QR.
