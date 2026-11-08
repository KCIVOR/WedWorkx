# Duplicate chat send protection

The Hermes composer previously allowed repeated submits while a POST was pending. A second race could display the same saved message twice when polling received it before the original POST returned.

Both registration and website chats now share a synchronous send gate and a pending UI state. The composer disables during the pending request; Enter and rapid submissions are guarded too. Failed sends preserve the input and reuse the same request UUID when retrying the same text in the same session. New intentional sends get new UUIDs, so legitimate repeated answers such as Yes remain allowed.

The browser merges both polling and POST results by database message ID and keeps server order. Only polling advances its transcript cursor, preventing a late POST response from skipping an earlier unread bot message.

The server serializes sends under the chat row lock. A UUID acknowledgment cache, scoped by chat capability, returns an already-saved message without inserting it again or changing waiting state. Reusing a UUID for different text returns 409. Replays are checked before the message limit. Cache retention is 24 hours; clearing/losing the cache removes that retry guarantee. Legacy clients without a UUID retain existing behavior; frontend send locking and duplicate-display fixes require the new bundle. Existing duplicate messages are not deleted.

No migration, dependency or Hermes restart is required. Deploy matching app/controller, React bundle and manifest, clear caches and reload the page. Validation: 28 PHP chat/resume/timestamp tests (378 assertions), 18 frontend tests and Vite build passed; archive/controller/asset integrity checks are recorded with the release.
