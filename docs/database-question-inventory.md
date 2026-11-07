# Database question inventory

Extracted: 2026-11-08 02:16 Asia/Manila

Source: the MySQL database configured for this workspace; registration_questions and website_questions. This is a read-only inventory of question definitions, including archived rows if present. It does not include supplier answers or historical question snapshots.

Total: 37 questions (Bot 1: 7; Bot 2: 30). Archived: 0

General means asked across all service types. Service-specific means restricted by applies_to. These are organizational categories derived from the application rules. Questions are ordered by sort_order, then database ID. Bot 2 asks its general questions first, followed by the questions matching the supplier's service type. {name} and {business_name} are replaced with the supplier's details at runtime.

## Bot 1 — Registration: general questions

Used during initial supplier registration, before the website-details chat. All service types receive these questions. The business-type question determines which service-specific questions Bot 2 will show. Core input widgets and business-type choices are defined by the application rather than the database options column.

### 1. Name

ID: 1 | Key: `name` | Required: Yes | Input type: core | Sort order: 10 | Status: Active

> Hello! Welcome to our 10‑10‑10 Campaign! We're celebrating Kasalang Tagaytay's 10th anniversary with a special website offer for wedding suppliers. I'd love to know more about your business. Let's get started! 🎉
> 
> May I know your name?

Placeholder: Your full name

### 2. Email

ID: 2 | Key: `email` | Required: Yes | Input type: core | Sort order: 20 | Status: Active

> Nice to meet you, {name}! What's your email address? We'll use this to send you updates about the website offer.

Placeholder: you@example.com

### 3. Mobile

ID: 3 | Key: `mobile` | Required: Yes | Input type: core | Sort order: 30 | Status: Active

> What's the best mobile number to reach you?

Placeholder: 09xx xxx xxxx

### 4. Business Name

ID: 4 | Key: `business_name` | Required: Yes | Input type: core | Sort order: 40 | Status: Active

> What's the name of your wedding business?

Placeholder: Business name

### 5. Business Type

ID: 5 | Key: `business_type` | Required: Yes | Input type: core | Sort order: 50 | Status: Active

> What type of wedding service do you offer?

### 6. Location

ID: 6 | Key: `location` | Required: Yes | Input type: core | Sort order: 60 | Status: Active

> Which city or province is your business based in?

Placeholder: e.g. Tagaytay City, Cavite

### 7. Social Media

ID: 7 | Key: `social_link` | Required: No | Input type: core | Sort order: 70 | Status: Active

> Last one for now — do you have a Facebook page, Instagram account, or existing website you'd like to share? This is optional.

Placeholder: Facebook / Instagram / website link

## Bot 2 — Website details: general questions

Used when collecting information to build the supplier's website. All service types receive these questions.

### 1. Business Description

ID: 1 | Key: `description` | Required: Yes | Input type: textarea | Sort order: 10 | Status: Active

> Let's start with your business. In a sentence or two, what makes {business_name} special to couples getting married?

Placeholder: e.g. We've been in the wedding industry for 8 years...

### 2. Services / Packages

ID: 2 | Key: `services` | Required: No | Input type: services | Sort order: 20 | Status: Active

> What services or packages would you like to list on your website? Add each one, and the price if you'd like to show it.

### 3. Service Areas & Booking

ID: 3 | Key: `areas` | Required: No | Input type: areas | Sort order: 30 | Status: Active

> Which towns or cities do you serve? And how do couples usually get in touch to book you — Messenger, phone call, or something else?

### 4. Photos, Logo & Colors

ID: 4 | Key: `media` | Required: No | Input type: media | Sort order: 40 | Status: Active

> Do you have a logo, photos of your work, or favorite colors you'd like us to use? This is optional — skip it if you don't have anything ready yet.

Placeholder: Favorite colors, e.g. Blush pink and gold

### 5. Website Style

ID: 5 | Key: `style` | Required: Yes | Input type: choice | Sort order: 50 | Status: Active

> What kind of look would you like for your website? This is just a preference — our design team creates the final look for you.

Answer choices:

- Elegant & Luxurious (`elegant`)
- Modern & Simple (`modern`)
- Romantic & Soft (`romantic`)
- Classic & Timeless (`classic`)
- Colorful & Creative (`colorful`)
- Surprise Me! (`surprise`)

### 6. Additional Info

ID: 6 | Key: `additional` | Required: No | Input type: textarea | Sort order: 60 | Status: Active

> Anything else couples should know? For example reviews, ongoing promos, or how far in advance they should book. This is optional.

Placeholder: Optional — leave blank to skip

## Bot 2 — Service-specific: Venue

Shown only for service type `venue`, after the general questions.

### 1. Guest Capacity

ID: 7 | Key: `extra1` | Required: No | Input type: number | Sort order: 100 | Status: Active

> About how many guests can your venue fit comfortably?

Placeholder: e.g. 150 guests

### 2. What's Included

ID: 8 | Key: `extra2` | Required: Yes | Input type: choice | Sort order: 101 | Status: Active

> Besides the venue space, what else is included — like catering, tables and chairs, or decorations?

Answer choices:

- Venue space only
- Venue + basic setup (tables & chairs)
- Venue + full package (catering, decor, etc.)
- It depends on the package

## Bot 2 — Service-specific: Coordinator

Shown only for service type `coordinator`, after the general questions.

### 1. Planning Service

ID: 9 | Key: `extra1` | Required: Yes | Input type: choice | Sort order: 110 | Status: Active

> Which of these do you offer?

Answer choices:

- Full wedding planning, start to finish
- Day-of coordination only
- Both — depending on what the couple needs

### 2. Why Couples Choose You

ID: 10 | Key: `extra2` | Required: No | Input type: textarea | Sort order: 111 | Status: Active

> What's something couples often say they loved about working with you?

Placeholder: Optional — e.g. attention to detail, staying calm under pressure...

## Bot 2 — Service-specific: Photographer

Shown only for service type `photographer`, after the general questions.

### 1. Coverage Type

ID: 11 | Key: `extra1` | Required: Yes | Input type: choice | Sort order: 120 | Status: Active

> What kind of coverage do you usually offer?

Answer choices:

- Full-day coverage
- Half-day coverage
- Ceremony only
- Pre-wedding + wedding day
- Depends on the package

### 2. Photo or Video

ID: 12 | Key: `extra2` | Required: Yes | Input type: choice | Sort order: 121 | Status: Active

> Do you do photos, videos, or both?

Answer choices:

- Photos only
- Videos only
- Both photos and videos

## Bot 2 — Service-specific: Caterer

Shown only for service type `caterer`, after the general questions.

### 1. Menu Options

ID: 13 | Key: `extra1` | Required: No | Input type: textarea | Sort order: 130 | Status: Active

> What food packages or menu styles do you offer?

Placeholder: e.g. Buffet, plated meals, food stations...

### 2. Special Requests

ID: 14 | Key: `extra2` | Required: Yes | Input type: choice | Sort order: 131 | Status: Active

> Can you prepare special meals, like vegetarian or halal, if couples ask?

Answer choices:

- Yes, we can accommodate special requests
- We can try — just ask us first
- We only offer our standard menu

## Bot 2 — Service-specific: Florist

Shown only for service type `florist`, after the general questions.

### 1. Style

ID: 15 | Key: `extra1` | Required: No | Input type: textarea | Sort order: 140 | Status: Active

> What flower or decoration styles do you usually do?

Placeholder: e.g. Garden-style, minimalist, tropical...

### 2. Other Décor

ID: 16 | Key: `extra2` | Required: Yes | Input type: choice | Sort order: 141 | Status: Active

> Do you only do flowers, or do you also handle other décor like backdrops or lights?

Answer choices:

- Flowers only
- Flowers plus some décor
- Full styling and décor

## Bot 2 — Service-specific: Makeup

Shown only for service type `makeup`, after the general questions.

### 1. Service Location

ID: 17 | Key: `extra1` | Required: Yes | Input type: choice | Sort order: 150 | Status: Active

> Where do you usually do the makeup — at your own studio, or do you travel to the venue?

Answer choices:

- At my own studio
- I travel to the venue
- Both, depending on the booking

### 2. Trial Session

ID: 18 | Key: `extra2` | Required: Yes | Input type: choice | Sort order: 151 | Status: Active

> Do you offer a trial session before the wedding day?

Answer choices:

- Yes, trials are included
- Trials available for an extra fee
- No trials — just the wedding day

## Bot 2 — Service-specific: Attire

Shown only for service type `attire`, after the general questions.

### 1. Offering

ID: 19 | Key: `extra1` | Required: Yes | Input type: choice | Sort order: 160 | Status: Active

> What do you offer?

Answer choices:

- Gown or suit rentals
- Custom-made outfits
- Ready-made (off-the-rack)
- A mix of these

### 2. Fittings & Alterations

ID: 20 | Key: `extra2` | Required: Yes | Input type: choice | Sort order: 161 | Status: Active

> Do you also offer fitting or alteration services?

Answer choices:

- Yes, included
- Available for a fee
- No alteration services

## Bot 2 — Service-specific: Entertainment

Shown only for service type `entertainment`, after the general questions.

### 1. What's Included

ID: 21 | Key: `extra1` | Required: No | Input type: textarea | Sort order: 170 | Status: Active

> What do you provide? For example a live band, DJ, host, or sound system.

Placeholder: e.g. Live band, DJ, host/emcee, sound & lights...

### 2. Duration

ID: 22 | Key: `extra2` | Required: Yes | Input type: choice | Sort order: 171 | Status: Active

> How long do your services usually run on the wedding day?

Answer choices:

- A few hours
- The whole event, ceremony to reception
- Depends on the package

## Bot 2 — Service-specific: Cake

Shown only for service type `cake`, after the general questions.

### 1. Flavors & Designs

ID: 23 | Key: `extra1` | Required: No | Input type: textarea | Sort order: 180 | Status: Active

> What flavors or cake designs do you specialize in?

Placeholder: Optional — e.g. classic vanilla, custom themed designs...

### 2. Tasting

ID: 24 | Key: `extra2` | Required: Yes | Input type: choice | Sort order: 181 | Status: Active

> Do you offer a free tasting before the wedding?

Answer choices:

- Yes, free tasting
- Tasting available for a fee
- No tasting offered

## Bot 2 — Service-specific: Invitations

Shown only for service type `invitations`, after the general questions.

### 1. What You Make

ID: 25 | Key: `extra1` | Required: Yes | Input type: choice | Sort order: 190 | Status: Active

> What do you make — invitations, souvenirs, or both?

Answer choices:

- Invitations only
- Souvenirs or giveaways only
- Both invitations and souvenirs

### 2. Custom Designs

ID: 26 | Key: `extra2` | Required: Yes | Input type: choice | Sort order: 191 | Status: Active

> Can couples ask for a custom design, or do you only offer ready-made designs?

Answer choices:

- Yes, custom designs available
- Mostly ready-made designs
- Both options available

## Bot 2 — Service-specific: Transportation

Shown only for service type `transportation`, after the general questions.

### 1. Offering

ID: 27 | Key: `extra1` | Required: Yes | Input type: choice | Sort order: 200 | Status: Active

> What do you offer?

Answer choices:

- Wedding car or transportation only
- Accommodation only
- Both transportation and accommodation

### 2. Vehicles & Packages

ID: 28 | Key: `extra2` | Required: No | Input type: textarea | Sort order: 201 | Status: Active

> What vehicles or rooms/packages do you have available?

Placeholder: Optional — e.g. bridal car, van for guests, honeymoon suite...

## Bot 2 — Service-specific: Other

Shown only for service type `other`, after the general questions.

### 1. Business Details

ID: 29 | Key: `extra1` | Required: No | Input type: textarea | Sort order: 210 | Status: Active

> Tell us a bit more about what your business offers.

Placeholder: Optional

### 2. Additional Notes

ID: 30 | Key: `extra2` | Required: No | Input type: textarea | Sort order: 211 | Status: Active

> Is there anything else couples should know before booking you?

Placeholder: Optional

