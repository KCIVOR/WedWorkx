# Expanded intake chat capacity and recovery

November 9, 2026. Confirmed production symptom: a resumed conversation's message POST returned `422 {error:"chat_full"}` at the old 80-message combined supplier/Hermes limit. Refreshing resumed the same full conversation, and the browser silently ignored the response.

The limit is now 500 combined messages for both chat types. Existing open conversations below that count can continue after deploying and clearing Laravel config caches; no message deletion or database migration is needed.

At the limit, the server rejects the unsent message, retains existing messages/answers, marks the chat as fallback and clears its waiting state. The browser recovers server answers (or the last accepted answers for older response bodies), moves to the fixed questions and explains that the last message was not sent. Other validation errors do not trigger this recovery. No new order, payment or submitted answer is created by recovery.

Deployment: pull matching backend/config and compiled frontend assets, then run `artisan optimize:clear`. No schema/data migration or Hermes protocol refresh is required specifically for this fix. Existing browser pages must reload to receive the updated frontend. Chats already closed for another reason retain their normal lifecycle.

Regression coverage: expanded default budget, full-session answer preservation, stopped polling wait state, rejected-message omission, frontend full-response recognition and unrelated validation-error exclusion. Existing message length/access/idempotency tests remain in place.
