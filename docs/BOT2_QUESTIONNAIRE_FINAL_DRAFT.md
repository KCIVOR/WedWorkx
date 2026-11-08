# Bot 2 — final questionnaire draft

Date: November 9, 2026 (Asia/Manila).

Approved by the user and implemented locally on November 9, 2026. This specification defines the wording, order, choices and completion rules. The local database now contains 42 definitions, with 20 main steps per supplier type. Production deployment and updating the installed Hermes plugin remain pending. Clone-agent alignment and approval come next; website building and deployment are outside this change.

## Scope and rules

- Keep Bot 1's seven registration questions. Do not automatically publish its personal contact information.
- Bot 2 has eighteen common main steps and two business-specific steps: twenty per supplier type. Across all twelve types, this would mean forty-two question definitions, not forty-two questions per client.
- Ask one short question at a time. Conditional follow-ups inside a main step are separate conversational turns; twenty main steps is not a promise of twenty messages.
- Required answer: a real answer is needed to complete the brief.
- Required decision: the client must choose a truthful status, such as provide now, provide later, no preference or help me decide. This does not mean they must possess a logo, reviews or formal policies.
- Optional: the client can skip. Do not keep asking after an explicit skip.
- Record useful volunteered information once. Confirm unclear details rather than re-asking every question mechanically.
- Never invent testimonials, years of experience, awards, policies, package inclusions, prices, image rights or unavailable features.
- A completed intake and successful payment authorize planning only. Our team approves the exact brief, library entry and section plan before copy/build/remix starts.
- Use the existing cloned-site library. Client references are inspiration, not instructions to clone another site. Deployment and live delivery remain deferred.

## Questions in the client-facing sequence

### 1. Company description — required answer

**Ask:** “Tell us about {business_name}. What do you offer, and what would you like visitors to know about your business?”

**Helpful follow-up, only if relevant information is missing:** “How long have you been operating? If you're just starting, that's completely fine.”

Accept a clear business summary. Do not require an established business history or infer experience from the owner's age.

### 2. Target audience — required answer

**Ask:** “Who would you most like to reach through your website?”

**Help text:** “For example: couples planning a wedding in Tagaytay, destination-wedding clients, other suppliers, or corporate clients.”

Clarify their main audience and relevant location/preferences if needed. Detailed demographics and private customer information are not mandatory.

### 3. Main website goal — required decision

**Ask:** “What is the main action you want visitors to take on your website?”

**Choices:** Send an inquiry; request a quote; request an appointment; ask about a booking; purchase/order; subscribe; other; help me choose.

Ask the intended destination when needed, such as an inquiry form, approved email, phone, Messenger or existing booking link. Collect contact details in step 10 and reuse them. A requested action is not confirmation that checkout, live reservations or another integration is included; unsupported requests go to plan review.

### 4. Business differentiator — required decision

**Ask:** “What makes your business a good choice compared with similar suppliers?”

**Allow:** Describe it now; I need help identifying this.

If they need help, ask: “What do you put the most care into when serving clients?” Suggest a short factual description based on their answers and ask for confirmation. Do not force a claim of being unique, cheapest or best. Unresolved positioning is flagged for the planner; no invented claim is approved by default.

### 5. Services and packages — required decision

**Ask:** “Which services or packages should we feature on your website?”

**Allow:** Add services/packages now; describe my main service; I'll provide the full package details later.

Capture at least the main service from this answer or confirmed earlier answers. Package names, inclusions and prices can be supplied when available. “Provide later” creates a follow-up item; it does not authorize invented packages. Pricing publication is decided next.

### 6. Pricing display — required decision, new

**Ask:** “How would you like pricing to appear on your website?”

**Choices:** Show exact prices; show starting prices; say “Contact us for pricing”; decide with the team.

For exact/starting prices, ask for the approved amounts and currency for the relevant services. If amounts are not ready, record them as pending rather than displaying placeholder prices. Do not publish a price merely because it appeared in the conversation.

### 7–8. Business-specific questions

Use only the pair matching the business type chosen in Bot 1. Exact wording and completion rules are in the supplier table below. Do not show other suppliers' questions.

For a **wedding planner/coordinator**:

**7. Planning service — required decision:** “Which planning services do you offer?”

Choices: Full wedding planning, start to finish; day-of coordination only; both, depending on the couple's needs.

**8. Planning approach — optional:** “What do couples appreciate most about working with you?”

Help text: “For example: clear communication, attention to detail, or staying calm on the wedding day. If you're just starting, you can skip this.”

If step 4 already supplies the answer, reuse it without repeating the question. This is the supplier's own description, not a customer testimonial or evidence of a quotation. Preserve the existing Why Couples Choose You question's key/type when implementing; a label change does not justify replacing saved answers.

### 9. Service areas and booking method — required decision

**Ask first:** “Which towns, cities or areas do you serve?”

**Then:** “How should interested clients contact you to ask about a booking?”

Allow nationwide/online service if accurate. Booking choices: website inquiry form; Messenger/social message; phone; email; existing booking link; help me choose. Reuse approved contact information from step 10. Do not invent a service area or treat an inquiry as a confirmed reservation.

### 10. Public business contacts — required decision, new

**Ask:** “Which business contact details may we publish on your website?”

Offer: Use selected registration details; enter different business details; provide them later.

For reuse, show the actual fields and obtain explicit confirmation. Ask separately as needed for public email, phone, social links and optional address. Ask: “Should visitors see your full address, just your service area, or no address?”

The approved plan needs at least one usable contact/CTA route. A client may submit with details pending, but the planner must flag them for resolution before build approval. Never publish a private home address or Bot 1 contacts by assumption.

### 11. Website tone of voice — required decision, new

**Ask:** “How would you like your website to sound?”

**Choices:** Warm and friendly; elegant and refined; clear and professional; cheerful and creative; suggest a suitable tone; other.

If suggested, propose a concise tone and confirm it. This controls the client's website copy, not the chatbot's SOUL.md personality.

### 12. Website visual style — required decision

**Ask:** “What kind of look would you like for your website?”

Keep the existing choices: Elegant & Luxurious; Modern & Simple; Romantic & Soft; Classic & Timeless; Colorful & Creative; Surprise Me!

“Surprise Me!” authorizes a proposed style for team review; it is not permission to skip plan approval or invent brand claims.

### 13. Style references and existing website — optional

**Ask:** “Are there websites or designs you like? Share a link and tell us what you like about it.”

If they mention their own site, ask whether they want any content retained, rewritten or removed; record the details in step 16. Confirm ownership/permission before reusing its content or assets.

No references is a valid skip. The planner selects a suitable existing library entry and your team approves it. Inspiration links are not new cloning targets or permission to copy another business's work.

### 14. Photos, logo and brand choices — required decision

**Ask first:** “What photos or brand materials would you like us to use?”

**Choices:** Upload now; provide later; I don't have photos—please propose suitable stock images for approval.

For uploads, guide by business type: “A small selection of your strongest real work is helpful.” Current source defaults support up to ten photos and one optional logo, JPG/JPEG/PNG/WebP, up to 5 MB per file. Verify actual deployed limits before displaying them. Do not require ten images or promise SVG/AI/PDF support. Minimum usable images depend on the selected template, not an invented universal number.

Follow up separately: “Do you have brand colors, fonts or guidelines you want us to follow, or would you like us to suggest them?” Accept available color codes, font names or descriptions. File types not supported by the uploader need a separately agreed collection method.

For supplied assets, confirm: “Do you own these materials or have permission for us to use them on your website?” Record permission or unresolved rights; do not assume permission from a file upload alone. Stock choices must be sourced, licensed and credited as required, then reviewed. A credit alone does not establish usage rights. No logo may use a text-based brand treatment proposed in the plan.

Provide-later keeps intake possible and creates an awaiting-assets item. The team explicitly approves an asset plan before build; real images cannot be silently replaced with stock or placeholders.

### 15. Testimonials and reviews — required decision, new

**Ask:** “Do you have genuine customer reviews or testimonials you'd like included?”

**Choices:** Provide the exact review now; share a source link for review; provide later; none yet—omit the section.

If supplied, ask for the exact wording, approved attribution and permission to publish. A public review link is a source for checking, not blanket permission to republish. Do not invent a customer, quotation, star rating or review count. No testimonials is a valid completed decision and must not prevent a truthful website.

### 16. Pages, features and existing content — required decision, new

**Ask:** “Which pages or features are most important for your website?”

**Allow:** Tell us what you need; suggest a suitable structure for our team's approval.

Examples: About, services/packages, portfolio/gallery, contact/inquiry form, FAQ or existing booking link. Ask about keep/rewrite/remove instructions only when existing content is supplied. Ask for a priority if the list is long.

The client describes their needs; the planner maps them to supported sections/features in the library. Any missing section, CMS request, booking system, store or other integration is flagged for approval—not automatically promised. Suggested sections must be approved by your team before build.

### 17. Booking policies and legal-content status — required decision, new

**Ask:** “Do you have booking, deposit, cancellation or refund policies you want visitors to see?”

**Choices:** Provide existing text now; provide later; no policies to publish at this time; ask the team to clarify what is needed.

If appropriate, follow up: “Do you also have existing privacy or terms text for the website?” Keep business booking policies separate from website privacy/terms. Do not invent deposits, refund conditions, guarantees or legally approved text. Record supplied content, approval and pending review. No-policy decisions must remain visible for planning; they do not assert that no legal obligations apply.

### 18. Domain and existing platform — optional background for now, new

**Ask:** “Do you already have a domain name or an existing website platform?”

**Choices:** Existing domain/site; no domain yet; not sure; discuss later.

For an existing site, collect only the public URL and platform name if known, such as WordPress. Do not ask for passwords, hosting logins or payment details in chat. Do not promise domain purchase, hosting inclusion, transfer or publication. Deployment and live delivery are deferred; this answer must not block planning or a remix preview.

### 19. Search phrases — optional

**Ask:** “What might someone type into Google to find a business like yours?”

Help text for planners: “For example: wedding coordinator in Tagaytay.” Accept no preference or a skip. Suggestions remain draft copy/SEO inputs, not verified search-volume research or ranking guarantees.

### 20. Additional information — optional

**Ask:** “Is there anything else you'd like us to consider for your website?”

Examples: Genuine awards, promotions, FAQs, accessibility preferences or important instructions. Accept a skip. Do not use this catch-all to hide missing required decisions from earlier steps.

## Supplier-specific wording for steps 7 and 8

Only these two steps vary by business type. Preserve existing answer keys/types and existing choice values where possible. Revised presentation and conditional follow-ups need explicit implementation mapping; the table does not authorize silently changing saved data.

| Business type | Step 7 | Step 8 |
|---|---|---|
| Wedding venue | **Guest capacity, optional:** “About how many guests can your venue accommodate comfortably?” Accept the actual capacity; unknown remains pending, never an invented number. | **Inclusions, required:** “What is included with your venue?” Existing choices: space only; space plus basic setup; full package; depends on the package. Clarify actual inclusions only when needed. |
| Planner/coordinator | **Planning service, required:** “Which planning services do you offer?” Full planning; day-of coordination; both. | **Planning approach, optional:** “What do couples appreciate most about working with you?” Reuse step 4 when already answered; never turn the response into a fictional testimonial. |
| Photographer/videographer | **Coverage, required:** “What coverage do you offer?” Existing choices: full day; half day; ceremony only; pre-wedding plus wedding day; depends on package. | **Media offering, required:** “Do you offer photography, videography, or both?” Photos only; videos only; both. |
| Catering supplier | **Menus, optional:** “What menu styles or food packages would you like to highlight?” Reuse step 5 details rather than repeat them. | **Special requests, required:** “Can you accommodate dietary or special meal requests?” Existing choices: yes; can try—ask first; standard menu only. Confirm any specific claim; do not imply certification. |
| Florist/wedding stylist | **Style, optional:** “What floral or event-styling looks do you specialize in?” | **Scope, required:** “Do you provide flowers only, other decor, or full event styling?” Keep existing equivalent choices. |
| Makeup artist | **Location, required:** “Do you work at your studio, travel to the venue, or offer both?” | **Trials, required:** “Do you offer a trial session?” Included; available for an extra fee; not offered. |
| Bridal gown/suit supplier | **Offering, required:** “Do you offer rentals, custom-made outfits, ready-made outfits, or a mix?” | **Alterations, required:** “Do you offer fittings or alterations?” Included; available for a fee; not offered. |
| Entertainment supplier | **Offering, optional:** “Which entertainment services should we highlight—for example, a band, DJ, host, or sound and lights?” Reuse already supplied services. | **Duration, required:** “How long do your services usually run?” A few hours; whole event; depends on package. |
| Cake supplier | **Flavors/designs, optional:** “What cake flavors or design styles would you like to highlight?” | **Tasting, required:** “Do you offer cake tastings?” Free; available for a fee; not offered. |
| Invitations/souvenirs supplier | **Products, required:** “Do you offer invitations, souvenirs, or both?” | **Customization, required:** “Do you offer custom designs, ready-made designs, or both?” |
| Transportation/accommodation supplier | **Offering, required:** “Do you provide wedding transport, accommodation, or both?” | **Vehicles/rooms, optional:** “Which vehicles, rooms or packages should we feature?” Reuse earlier package details. |
| Other wedding-related business | **Details, optional:** “Is there a part of your service that we haven't covered yet?” Do not repeat a complete company description. | **Booking notes, optional:** “Is there anything clients should know before inquiring or booking?” Reuse existing answers where appropriate; detailed supplied policies belong in step 17. |

## End-of-chat review and next step

Show a concise review of business facts, services, public contact approvals, pricing display, website style/voice, assets and rights, genuine social proof, pages/features, policy status and pending items. Let the client correct it before confirming.

Suggested closing text: “Please review your website details and anything marked for follow-up. Once you confirm and your payment is verified, our team will review a proposed website plan before building begins.”

Do not say the site is already being built, automatically published or delivered. Do not alter the existing five-day commitment or invent a new delivery-clock start rule; that business decision is still open in the master checklist.

## Required-decision summary

- Required facts: useful company description and target audience; actual main service must be known from the answers.
- Required choices/statuses: website goal, differentiator/help, services readiness, pricing publication, service area/booking route, public contact publication, voice, style, asset plan/permission, social-proof status, page/feature scope and policy status.
- Required supplier-specific choices remain required; existing optional service-specific details remain optional.
- Optional common steps: references, domain/platform background during this deferred-deployment phase, search phrases and additional information.
- A missing material may be a completed intake decision but still a readiness flag. Admin plan approval must explicitly resolve or approve handling of essential missing contacts, assets, features or content before dispatching a build.

## Approval and implementation checklist

- [x] Draft all twenty main steps, exact wording, choices, follow-up behavior and all twelve supplier-specific pairs.
- [ ] User approves this wording, sequence and required-decision approach.
- [ ] Existing remix agent confirms which fields it consumes and what missing inputs must block draft generation.
- [ ] Confirm the library catalog, asset needs and supported feature scope without introducing fresh cloning.
- [ ] Define structured fields/statuses and safe mappings for existing keys; the current generic admin editor cannot implement every grouped follow-up in this document by wording changes alone.
- [ ] Implement consistent behavior in Hermes, fixed forms, client review, admin review and planner payload.
- [ ] Preserve old submitted snapshots and paid orders; agree how unfinished drafts receive new questions.
- [ ] Test every supplier type and truthful none/later/help paths, contact privacy, permissions, retry/handoff behavior and the twenty-step cap.
- [ ] Build the planner/admin approval handoff only after the intake is aligned. Deployment and live delivery stay outside the current scope.
