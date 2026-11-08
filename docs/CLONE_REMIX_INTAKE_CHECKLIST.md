# WedWorks intake and clone/remix alignment checklist

Status: Task 1 questionnaire approved and implemented locally; production rollout pending. Remaining pipeline adjustments are proposed. Updated November 9, 2026 (Asia/Manila).

Purpose: agree on the intake brief and agent handoff before changing Bot 1, Bot 2, required settings, or production behavior. The current chat plugin collects information; it does not automatically build or remix a website.

Working document: add future questionnaire, content, asset and agent-alignment adjustments here. An unchecked item is proposed work, not an implemented feature. Record the decision and its acceptance check before implementation.

Task 1 questionnaire wording: [BOT2_QUESTIONNAIRE_FINAL_DRAFT.md](BOT2_QUESTIONNAIRE_FINAL_DRAFT.md). It contains the approved twenty-step client flow, required/optional rules, choices, conditional follow-ups and the two questions for each of the twelve supplier types. Local implementation and data upgrade are complete. Remix-agent alignment and production rollout remain pending. Domain/platform background is optional while deployment is deferred. See [local implementation and rollout notes](website-intake-v2-release.md).

## Adjustment tracker

| ID | Adjustment | Current status | Done when |
|---|---|---|---|
| IN-01 | Confirm public business contacts separately from Bot 1 private contacts. | Proposed | The client approves which fields may appear on the site; private addresses and personal contacts remain excluded unless approved. |
| IN-02 | Require a differentiator decision, with a guided-help option. | Proposed | A factual differentiator is confirmed, or a named follow-up remains visible; the agent does not invent experience or qualifications. |
| IN-03 | Require a pricing publication choice. | Proposed | Exact, starting-price or inquiry-only mode is recorded and the preview follows it. |
| IN-04 | Add dedicated social-proof status and permission. | Proposed | Genuine supplied reviews are approved for publication, or the section is pending/omitted. A supplier's own claim is not presented as a customer quote. |
| IN-05 | Require an asset/fallback decision without requiring a logo. | Proposed | Upload now, provide later or approved stock is recorded; placeholders and unlicensed images cannot silently reach publication. |
| IN-06 | Add brand voice, fonts/colors and brand-guideline handling. | Proposed | The website's voice and brand choices are approved independently of the intake assistant persona. |
| IN-07 | Agree on pages, working features and existing-content treatment. | Proposed | Must-have pages, keep/rewrite/remove instructions and supported integrations are explicit. |
| IN-08 | Select from the existing cloned-site library. | Library confirmed; catalog/handoff alignment pending | Planner selects an existing library entry/version and maps supplier answers into its supported sections. Admin approves before remix. No fresh website cloning per order. |
| IN-09 | Collect policy/legal-content status. | Proposed | Supplied text and publication approval are recorded, or follow-up/omission is explicit; the agent does not create business commitments. |
| IN-10 | Record optional domain/platform background; defer hosting and deployment responsibilities. | Deployment deferred | Domain information does not block preview planning. Agree access owner and deployment scope later; credentials use a separate secure channel. |
| IN-11 | Implement paid-order-to-build-agent dispatch and feedback. | Not implemented; pre-build admin approval required | Verified payment allows planning only. An admin must approve the exact brief/template/section-plan version before any copy/build/remix agent runs; retries cannot duplicate builds. Publication has a separate approval. |
| IN-12 | Align readiness rules with the five-day delivery promise. | Awaiting business decision | Client-facing wording, asset follow-ups and build expectations agree; delays do not silently reset existing commitments. |
| IN-13 | Roll out expanded intake consistently. | Not implemented | All supplier types, Hermes, fallback forms, snapshots, review/admin displays and handoff payload pass the approved acceptance checks. |

## Current baseline to preserve

- Bot 1 has seven contact/business questions. Bot 2 currently has 35 definitions: eleven common questions plus two applicable service-specific questions, giving thirteen per supplier type.
- Company Description, Target Audience, Main Website Goal and Website Style are required common questions. The proposed changes below are not yet applied.
- Current source defaults in `config/uploads.php`: one logo, up to ten photos, up to 5 MB per file; JPG/JPEG, PNG and WebP. Environment overrides may differ, so verify deployed limits before showing them to clients. SVG/AI/PDF/font/document uploads are not currently supported by this uploader.
- Bot 1's personal contact information is not automatically authorization to publish it as business contact information.
- Payment creates an order and locks confirmed answers. The current Hermes questionnaire plugin does not create, remix or deploy a site automatically.
- “Up to 20 per business type” is the existing top-level question cap, not a target for twenty long, multi-part chat messages. Review burden and progressive follow-ups before finalizing the sequence.

## 1. Intake decisions to approve

Require a clear decision where needed, rather than requiring clients to possess reviews, a logo, published pricing, or formal policies. Preserve the distinction between information supplied by the client, approved suggestions, and missing information.

| Area | Proposed client question or improvement | Completion rule | What the clone/remix agent receives |
|---|---|---|---|
| Company description | Keep the existing description; clarify services and experience when vague. | Required factual description. | Approved business summary; no invented history or credentials. |
| Differentiator | What makes your business different? Allow “I need help identifying this.” | Require an answer or help request. Suggestions need client confirmation. | Confirmed differentiator, or an explicit unresolved flag. |
| Audience | Who is the site for, where are they, and what matters to them? Use relevant examples rather than a demographic interrogation. | Required useful audience summary. | Audience, location and needs that were actually supplied. |
| Main goal | Choose the main action: inquiry, quote, appointment, purchase, subscription or another supported action. | Required primary action. | One primary CTA and its actual destination; secondary CTAs only if approved. |
| Services/packages | Capture actual offerings, package inclusions and any supplied prices. | Require a services summary or an explicit follow-up decision. Prices need not exist. | Actual services and package data, with missing details flagged. |
| Pricing publication | Should the website show exact prices, starting prices, or “Contact us for pricing”? | Required publication choice. | Display mode; approved currency/amounts. Never publish private draft prices automatically. |
| Public contact details | Confirm the business email, phone, public address or service area, and social links approved for the site. Offer to reuse Bot 1 details only after explicit confirmation. | Required publication decision and a working approved contact/booking route. A private address need not be published. | Public contact fields separately from private intake contact details. |
| Brand voice | What should your website sound like: warm, elegant, modern, playful, formal, or another voice? | Choose a voice or authorize a suggested default. | Client website voice, distinct from the questionnaire assistant's SOUL.md persona. |
| Social proof | Supply genuine testimonials/reviews, source links and permission to publish, or choose “None yet” / “Provide later.” | Required status decision, not mandatory testimonials. | Verified supplied quotes/attribution, approved publication, or an omit/pending instruction. Never fabricate reviews. |
| Photos/assets | Upload approved images now, supply later, or authorize a suitable stock-image approach. A logo remains optional; offer an approved text-based brand treatment. | Require an asset plan and usage permission. Missing assets remain visible as pending. | Files/URLs, categories, descriptions, usage approval, source/license and required credits. |
| Asset guidance | Explain useful categories per supplier type, supported file formats and actual upload limits. Ask for exact colors/fonts or guidelines when available. | Confirm technical limits and agree on a minimum usable asset set before specifying numbers to clients. | Asset manifest; approved brand colors/fonts or explicitly authorized suggestions. |
| Website references/remix source | Separate inspirational links from the exact approved template/source to remix. Confirm ownership or permission when using a client's existing site. | Require a selected permitted source, or an explicit request for template selection. | Source identifier/URL, authorization scope, inspiration links and what to emulate. Inspiration is not permission to copy assets/content. |
| Pages/features/content | Which pages and functions are essential? What existing copy should be retained, rewritten or removed? | Required scope decision or approval of a proposed starter structure. | Page inventory, must-keep/remove items, copy source and feature requirements. Distinguish visual mockups from working forms/bookings/payments. |
| Policies/legal content | Ask for actual booking, deposit, cancellation/refund policies and existing privacy/terms text. Allow “No policy supplied” / “Provide later.” | Required status decision. Obtain client or qualified review where publication requires it. | Approved policy text or an explicit omission/review-needed flag. Never invent legal commitments. |
| Domain/hosting/CMS | Ask optionally about an existing public domain/site and known platform. Hosting/deployment scope is deferred. | Optional background in the current preview-only phase; it must not block planning. Revisit ownership/deployment decisions when publication returns to scope. | Public domain/platform preference only for now. Future credentials use a separate secure channel. |
| Additional information | Keep the catch-all for awards, promotions, special instructions or other relevant facts. | Optional. | Client-provided details with factual/permission checks where relevant. |

## 2. Proposed Bot 2 sequence: at most 20 top-level questions per supplier type

The current implementation caps questions at 20 for each type. It currently shows 13: eleven common plus two service-specific. This proposal adds seven top-level questions, reaching 20. Grouped subfields below need implementation/schema validation; this is not a claim that the existing admin editor supports them as structured widgets.

1. Company description — existing.
2. Target audience — enrich existing.
3. Main website goal and CTA — enrich existing.
4. Differentiator — strengthen existing with a help/clarification path.
5. Services/packages — strengthen existing with an explicit completion decision.
6. Pricing publication preference — **new**.
7. First business-type-specific question — preserve existing.
8. Second business-type-specific question — preserve existing; for planners, Why Couples Choose You is a supplier statement, not a testimonial.
9. Service areas and booking method — existing.
10. Public contact details and publication permission — **new**.
11. Website voice/tone — **new**.
12. Website visual style — existing.
13. Website references, approved remix source and source authorization — extend existing reference step.
14. Brand assets, photos, image permissions, colors/fonts and fallback plan — extend existing media step.
15. Testimonials/reviews and permission — **new**.
16. Pages, features and existing-content instructions — **new**.
17. Booking policies and legal-content status — **new**.
18. Domain and existing platform — **new**, optional background while deployment is deferred; no credentials or hosting-access collection.
19. Search phrases — existing.
20. Additional information — existing and optional.

- [ ] Review this sequence with the clone AI agent; remove questions it does not use and identify any missing required inputs.
- [ ] Decide which grouped items need separate screens/subquestions rather than overwhelming one message.
- [ ] Verify the seven additions, multi-field validation and every business type remain inside the configured cap; approve a cap change separately if needed.
- [ ] Keep Bot 1 personal contacts private by default; ask separately before publishing them.
- [ ] Adapt policy examples and photo guidance for all twelve supplier types, not just planners.

## 3. Asset plan and production readiness

- [ ] Agree on image categories per business: planners use past events/team/styling; venues use spaces/capacities; caterers use dishes/setups; cakes use finished designs; photographers use portfolio work.
- [ ] Agree on actual upload limits/formats and minimum image count by template; do not promise SVG/AI or additional formats until supported and reviewed.
- [ ] Define image approval, privacy/consent and rights checks, including permission to publish testimonials and attribution.
- [ ] Agree on stock sourcing, allowed licenses and CREDITS output. A credit does not replace permission/license compliance.
- [ ] Choose how “provide later” works: submission remains possible, and the team sees an awaiting-assets/follow-up state before final production approval.
- [ ] Set the policy for temporary placeholders in a review draft; placeholders must not silently reach the published site.
- [ ] Align asset delays with the current five-day delivery message before introducing a build gate. Do not silently reset a paid client's delivery clock.

## 4. Clone/remix agent contract

Agree on a versioned brief before implementing the bridge. Do not send a raw chat transcript and expect the agent to guess permissions or missing requirements.

- [ ] Define the handoff format and version, order reference and supplier business type.
- [ ] Include the client's confirmed answer snapshot and stable field keys; preserve old snapshots when definitions change.
- [ ] Separate public site content from private intake contacts, credentials and internal notes.
- [ ] Include approved source/template ID, inspiration URLs, authorization scope and what must remain/change.
- [ ] Include business summary, audience, differentiator, services, pricing display mode, visual style and website voice.
- [ ] Include page/feature scope, working CTA targets and content-retention/rewrite/removal instructions.
- [ ] Include approved assets and social proof with provenance/permissions, license/credit requirements and missing-item flags.
- [ ] Include policy/legal-content status, domain/CMS constraints and the approved deployment scope.
- [ ] Represent unknown, not applicable, declined, supply later and approved-default decisions explicitly. Preserve who approved each suggestion.
- [ ] Ask the clone agent which fields it must have to generate a draft, which are optional, and which block publication.
- [ ] Define a structured response: accepted brief, needs clarification, awaiting assets, failed, review-ready or delivered.
- [ ] Include preview URL, asset credits, unresolved items and a review checklist in the agent's output. Do not claim these states already exist in the app.

## 5. Proposed processing flow

Current scope: confirmed client answers + verified applicable payment -> Website Planner prepares structured brief, selection from the existing cloned-site library and section/content mapping -> awaiting admin plan approval -> admin approves the exact plan/library version -> one remix job -> existing copy/remix agents (CMS agents only for an explicitly approved supported requirement) -> draft verification -> team reviews the preview and requests corrections or accepts the draft -> STOP at reviewed draft/preview ready.

### Deferred: production publishing and live delivery (confirmed November 9, 2026)

- [x] User deferred former process steps 12 and 13: cPanel deployment, live-site verification and delivery completion are outside the current implementation phase.
- [ ] Preserve these as future work: publication approval -> cPanel deployment -> verify final live URL -> mark delivered -> website-live email/client Visit my website link.
- [ ] In the current phase, do not invoke cpanel-ftps-deploy-bot or cpanel-ssh-deploy-bot, change production domains or mark the client order delivered merely because a remix preview exists.
- [ ] Store the generated draft/artifact and a team-accessible preview for review. Preview access is not public launch or client delivery.
- [ ] Keep preview acceptance distinct from later permission to publish. Resume deployment/delivery planning only when explicitly brought back into scope.
- [ ] Existing application delivery functions remain unchanged; deferral concerns the proposed automated agent pipeline, not removal of existing records or functionality.

### Existing cloned-site library (confirmed November 9, 2026)

- [x] User confirmed that websites have already been cloned and a reusable library exists. Normal paid-order fulfillment is remix-only; do not invoke clone-site-bot or clone another public website for each supplier.
- [ ] Identify the library location and provide a small catalog: stable entry ID/version, preview, suitable supplier types/styles, existing pages/sections and supported features.
- [ ] Planner selects the closest suitable entry and maps confirmed supplier content into it. Inspirational links guide selection/style; they are not new clone targets.
- [ ] Admin approves the brief, library entry/version, content mapping, asset plan and any missing-section decisions before remix begins.
- [ ] Give each order an isolated working copy. Never overwrite the reusable library source or another supplier's website.
- [ ] Pass the approved plan to remix-site-bot, directly or through the existing orchestrator configured to skip cloning. Use web-copy-bot for approved website copy where needed.
- [ ] Flag missing sections/features during planning: choose another library entry, request a scope adjustment or use an approved CMS assembly step. Never silently omit an agreed feature.
- [ ] Treat future library expansion as separate work; no new cloning agent is needed for standard orders.

### Required pre-build approval gate (confirmed November 9, 2026)

- [ ] Add an admin plan-review view showing the structured brief, template preview/source, proposed pages/sections, features, asset strategy and unresolved items.
- [ ] Offer Approve for build and Request changes. Requesting changes returns to planning; it must not launch build agents.
- [ ] Payment completion may schedule planning, but cannot authorize copy generation, cloning, remixing, CMS assembly or deployment by itself.
- [ ] Approval must be checked by the backend before build dispatch, not inferred from an agent's message or an enabled UI button.
- [ ] Record approved plan version, approving admin and approval time. Dispatch only that version once.
- [ ] Changes to the brief, chosen template, pages/features or asset strategy after approval require approval of the revised plan before a new build starts.
- [ ] Keep pre-build approval separate from final preview/publication approval. Approval to build is not permission to publish.
- [ ] Test that unapproved/rejected/superseded plans cannot start a build, even through direct API calls, worker retries or repeated payment events.

- [ ] Decide what agent/service owns each step and how it receives work; the current chat plugin is not the build worker.
- [ ] Keep payment verification server-side; a chat claim or browser return must not authorize a build.
- [ ] Decide whether paid orders with missing assets enter a follow-up queue; do not discard the order or rerun payment.
- [ ] Add job idempotency, retry limits and failure handling before automating dispatch, preventing duplicate sites on repeated webhook/poll events.
- [ ] Agree on draft review permissions and the environment used for team-accessible previews. Production publication permissions are deferred.
- [ ] Map actual build events onto internal stage tracking. Clients continue seeing the agreed delivery message, not internal progress.
- [ ] Deferred: mark delivered and email the client only after the approved site is actually available at its verified live URL. A reviewed draft must not trigger this.

## 6. Compatibility and acceptance checks before implementation

- [ ] Confirm the current production question definitions and identify migrations needed for the approved changes.
- [ ] Preserve existing IDs/keys/types/options where possible; introduce explicit mappings for new structured fields rather than repurposing old answers silently.
- [ ] Preserve submitted snapshots and old paid orders; agree how new requirements affect unfinished drafts.
- [ ] Ensure Hermes, fixed questionnaire, review screen, admin view and handoff payload enforce the same rules.
- [ ] Keep clarification friendly and concise; never fill gaps with invented testimonials, experience, prices or policies.
- [ ] Test every supplier type, missing logo, no testimonials, pending photos, stock authorization, private contact details, unknown domain/CMS and unsupported features.
- [ ] Test browser handoff, reconnect, repeated submits and pipeline retries without lost answers or duplicate builds.
- [ ] Validate the approved brief with the clone agent using representative synthetic examples before a production rollout.

## 7. Open alignment decisions

- [ ] Which clone AI agent/platform and template repository will process orders, and what API/job interface does it provide?
- [ ] Is the deliverable a static website or an editable CMS site? Which booking/form integrations are actually supported?
- [ ] Who selects templates, approves generated copy/design, and authorizes publication?
- [x] Require our team's admin approval of the planner's brief, template and section plan before AI build begins. Implementation remains pending.
- [ ] What stock-image licenses/credit rules and minimum asset set apply to each template?
- [ ] What do domain/hosting costs and management include, and how is access transferred securely?
- [ ] When does the five-day timeline begin, and how are missing client materials handled under the stated offer?
- [ ] Who approves policy/legal text, and what content must block publication until supplied?

## 8. Supplier-specific asset and content guidance

These are suggested categories, not mandatory image quantities. The clone agent and chosen template must confirm what is actually needed. Ask clients to provide only material they own or have permission to publish.

| Supplier type | Useful photos/assets | Content and feature checks |
|---|---|---|
| Wedding venue | Actual event spaces, ceremony/reception setups, exterior/access and amenities. | Confirm capacities, inclusions, service area/address publication and inquiry/visit route. |
| Planner/coordinator | Past wedding setups, coordination work and team photos where authorized. | Planning-service scope, confirmed differentiator, packages, booking steps and genuine reviews. |
| Photographer/videographer | Approved portfolio selections and permissioned video links. | Coverage, photo/video offerings, portfolio rights and supported video embedding/hosting. |
| Caterer | Actual dishes, menu presentations, buffet/table setups and permitted team photos. | Menus, portions/packages and verified dietary/special-request claims; never invent certifications. |
| Florist/stylist | Actual arrangements, event styling, installations and detail shots. | Styling scope, floral/decor inclusions and approved descriptions of available services. |
| Makeup artist | Permissioned portfolio, studio/work setup and approved team images. | Travel/studio location, trial availability and consent for identifiable before/after photos. |
| Bridal gown/suit supplier | Actual garments, fitting area and permissioned model/customer photos. | Rental/sale/custom offerings, fittings, alterations and actual booking/ordering arrangements. |
| Entertainment supplier | Permissioned performance photos and sample audio/video links. | Available acts/equipment, duration and media-use rights; no implied streaming integration without support. |
| Cake supplier | Actual finished cakes, detail shots and authorized tasting/setup images. | Flavors/designs, tasting, portions and supplied order/deposit/cancellation rules. |
| Invitations/souvenirs supplier | Actual finished products, design examples and packaging. | Product categories, customization scope and client permission for names/details in samples. |
| Transportation/accommodation supplier | Actual vehicles, rooms, amenities and location images. | Capacity, availability/inquiry model, service area and accurate amenities; distinguish inquiries from live reservations. |
| Other wedding-related business | Representative owned work, products, location and team assets. | Clarify actual offerings and select a suitable template rather than forcing another supplier's features. |

## 9. Completion states and client clarification

- [ ] Distinguish question completion from production readiness: “None yet,” “Provide later,” “Not applicable,” “Do not publish” and “Help me choose” are explicit decisions, not missing strings to silently fill.
- [ ] Define which decisions allow brief submission/payment, which allow a review draft and which block publication. Agree this with the clone agent before changing validation.
- [ ] Make an unanswered required decision trigger a friendly clarification. Do not repeatedly demand a testimonial, logo or policy the client does not have.
- [ ] Confirm suggested differentiators, copy, colors, fonts and stock-image plans before treating them as approved inputs.
- [ ] Show a concise review separating public content, private contacts, pending materials and agent-proposed defaults.
- [ ] Store permission/status changes with their source and approval time; include them in the confirmed brief version.
- [ ] Keep an explicit follow-up owner for missing material so a paid order cannot disappear between intake and production.
- [ ] Review the length of each question and split grouped items into conversational substeps where appropriate. Do not make the client answer a whole intake form in one chat message.

## 10. Draft handoff structure for clone-agent review

Field names below are discussion placeholders, not a finalized schema or an existing API. The receiving agent should mark each as required for draft, required for publication, optional or unsupported.

```text
brief_version / confirmed_at / approval_source
order_reference / supplier_type / verified_payment_reference
business: name, description, services, differentiator, audience
goals: primary_cta, destination, secondary_actions
public_contacts: approved email, phone, address/service_area, social_links
pricing: display_mode, approved_amounts, currency
brand: website_voice, visual_style, colors, fonts, guideline_references
source: library_entry_id, library_version, preview_url, isolated_working_copy, permitted_reuse_scope, inspiration_links
content: pages, supplied_copy, retain/rewrite/remove, approved_features
assets[]: identifier, category, source, usage_permission, license, credits
social_proof: status, supplied_reviews, attribution, publication_permission
policies: supplied_text, status, publication_approval, review_needed
deployment: domain_choice, platform/CMS, scope, responsible_owner
pending_items[]: item, reason, next_action, owner, blocks_draft/publish
approved_defaults[]: proposal, approved_by, approved_at
```

- [ ] Share this checklist with the clone AI agent and record its input requirements and unsupported features here.
- [ ] Create one synthetic planner/coordinator brief and one asset-poor/no-review brief to test alignment.
- [ ] Agree on success/failure responses, clarification requests, preview artifacts and publication evidence.
- [ ] Keep credentials and raw provider secrets out of this Markdown file and the ordinary build payload.

## 11. Decision log

| Date (Asia/Manila) | Topic | Decision / next action |
|---|---|---|
| 2026-11-09 | Planning scope | Track intake improvements and clone-agent alignment here first; do not change live questions or agent behavior yet. |
| 2026-11-09 | Required inputs | Prefer required decisions with truthful “none / later / help” paths over forcing clients to possess reviews, a logo or formal policies. Final validation awaits alignment. |
| 2026-11-09 | Next review | Confirm clone-agent contract, supported features, asset rules, delivery expectations and proposed twenty-step intake before implementation. |
| 2026-11-09 | Pre-build approval | User confirmed: Website Planner outputs must be approved by our team before copy/build/remix agents proceed. Enforce this as a backend approval gate for the exact plan version; final publication approval remains separate. Planning only; not implemented. |

| 2026-11-09 | Existing cloned-site library | User confirmed sites are already cloned and available in a library. Standard fulfillment selects and remixes an existing entry after admin approval; fresh cloning is excluded. Confirm library catalog/location and remix invocation interface before implementation. |
| 2026-11-09 | Deployment/delivery deferred | User deferred steps 12 and 13. Current automation ends at a verified, team-reviewed remix draft/preview. No deployment bot, live delivery status or website-live email is triggered by draft completion. Existing delivery functionality is not removed. |
| 2026-11-09 | Task 1 questionnaire draft | Prepared exact twenty-step Bot 2 wording and all twelve supplier pairs in BOT2_QUESTIONNAIRE_FINAL_DRAFT.md. Required decisions accept truthful none/later/help paths; public contact use needs confirmation. References/domain background/search/additional remain optional. No live questions or agent settings changed; review/alignment precede implementation. |

Use future entries to record approved wording, owner, acceptance criteria and any effect on existing clients. Move a tracker row to Implemented only after its code/data change and acceptance checks are complete.

No application, agent runtime or production configuration changes are authorized by this checklist alone. Implement only after the revised intake and clone-agent contract are agreed.
