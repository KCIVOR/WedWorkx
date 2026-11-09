<?php

use App\Models\WebsiteQuestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->applyCopy(true);
    }

    public function down(): void
    {
        $this->applyCopy(false);
    }

    private function applyCopy(bool $forward): void
    {
        $original = json_decode(file_get_contents(database_path('data/website-intake-v2.json')), true, 512, JSON_THROW_ON_ERROR);

        $introductions = [
            'description' => 'Tell us a little about {business_name}. What do you offer, and what would you love visitors to know about you?',
            'q_7be6711e' => 'Who would you love to find and connect with through your website?',
            'q_c074a772' => 'When someone visits your website, what would you like them to do next?',
            'q_77963408' => 'What makes working with you special for the couples or clients you serve?',
            'services' => 'Which services or packages would you like to showcase on your website?',
            'pricing_display' => 'How would you like to share your prices with visitors?',
            'areas' => 'Where do you work, and how would you like clients to get in touch about a booking?',
            'public_contacts' => 'Which contact details would you be comfortable sharing on your website?',
            'website_voice' => 'When someone reads your website, how would you like it to sound?',
            'style' => 'What kind of look would feel right for your website?',
            'q_19a5f097' => 'Have you seen a website or design you love? Feel free to share a link and tell us what caught your eye.',
            'media' => 'What photos, logo, or favorite colors would you like us to use?',
            'social_proof' => 'Have any past clients left a review you’d love to share on your website?',
            'website_scope' => 'What would you like visitors to find or be able to do on your website?',
            'policies' => 'What should clients know about booking with you, including any deposits, cancellations, or refunds?',
            'domain_platform' => 'Do you already have a website address or a service you use to manage your website?',
            'q_702f0901' => 'What might a couple type into Google when looking for a business like yours?',
            'additional' => 'Is there anything else you’d like to share about your website?',
        ];

        $prompts = [
            'description' => 'Tell us a little about your business and what you offer.',
            'business_experience' => 'How long have you been in business? If you’re just getting started, that’s perfectly fine.',
            'q_7be6711e' => 'Who would you most like to reach? Think of couples planning a wedding, fellow suppliers, or businesses planning an event.',
            'q_c074a772' => 'What would you like visitors to do next?',
            'website_goal_other' => 'What would you like visitors to do?',
            'cta_destination' => 'Is there a form, booking link, or favorite way for clients to get in touch? You can skip this for now.',
            'differentiator_status' => 'Would you like to tell us what makes your service special, or would you like a little help finding the words?',
            'q_77963408' => 'What makes working with you special?',
            'differentiator_help' => 'What do you take the most care with when working with clients?',
            'services_status' => 'Would you like to share your packages now, tell us about your main service, or send the full details later?',
            'services' => 'Tell us which services or packages to feature. You can include what comes with each one and add prices if you’d like.',
            'services_followup' => 'What is your main service? You can share the full package details later.',
            'pricing_display' => 'Would you prefer to show exact prices, starting prices, or invite visitors to ask for a quote?',
            'approved_prices' => 'Which prices may we show, and which service or package is each price for? You can also say you’ll share them later.',
            'pricing_currency' => 'Should we show those prices in Philippine pesos, US dollars, or another currency?',
            'pricing_currency_other' => 'Which currency would you like us to use?',
            'service_area_status' => 'Would you like to share the areas you serve now or confirm them with us later?',
            'service_areas' => 'Which towns or cities do you serve? Let us know if you work nationwide or online.',
            'booking' => 'How should clients reach you to ask about a booking?',
            'booking_link' => 'What link should clients use to ask about or make a booking?',
            'public_contact_mode' => 'Would you like to review the details you shared when you registered, give us different business contacts, or do this later?',
            'public_contact_permission' => 'Would you like to choose which details to show now and check them at the end, or decide later?',
            'public_email' => 'Which email address may visitors use to reach you? You can skip this if you prefer another way to get in touch.',
            'public_phone' => 'Which phone number may visitors use to reach you? You can skip this if you prefer another way to get in touch.',
            'public_socials' => 'Which social media pages would you like to share with visitors?',
            'public_address_mode' => 'Would you like to show your full business address, only the area you serve, or no address?',
            'public_address' => 'What business address may we show on your website?',
            'website_voice' => 'How would you like the words on your website to feel when someone reads them?',
            'website_voice_other' => 'How would you describe the tone you have in mind?',
            'style' => 'What kind of look feels most like your business?',
            'reference_use' => 'Would you like to share a design you admire or a website you already use?',
            'q_19a5f097' => 'Share a link and tell us what you like about it, such as the colors, photo gallery, or layout.',
            'reference_permission' => 'Do you own the photos or words you’d like us to reuse, or do you have permission to use them?',
            'asset_plan' => 'Would you like to add your own photos now, share them later, or see suggestions from a photo library?',
            'photo_ids' => 'Add a few favorite photos of your work. You’re welcome to include a logo if you have one.',
            'asset_permission' => 'Do you own the photos or logo you shared, or have permission to use them on your website?',
            'colors' => 'Are there any colors you’d love us to use? Color names are fine.',
            'brand_fonts' => 'Are there any fonts you already use or would like us to use?',
            'brand_guidelines' => 'Is there anything else we should know about your brand’s look? You can describe it or share a link.',
            'social_proof' => 'Would you like to share a review, send us a link to it, or leave reviews off for now?',
            'testimonial_text' => 'Please share the review as it was written and the name we may show with it.',
            'testimonial_source' => 'Where can we find the review? Please share a link.',
            'testimonial_permission' => 'Do you have permission to show this review and the reviewer’s name on your website?',
            'scope_mode' => 'Would you like to tell us what to include on your website, or would you like a few suggestions?',
            'scope_details' => 'What matters most to you? For example, a photo gallery, a page for your packages, or a form for inquiries.',
            'existing_content_mode' => 'Do you have photos, text, or pages you’d like us to keep, rewrite, or leave out?',
            'existing_content_details' => 'Which photos, text, or pages should we keep, rewrite, or leave out? Please share a link if you have one.',
            'policies' => 'Do you have booking, deposit, cancellation, or refund policies you’d like clients to read?',
            'booking_policy_text' => 'Please share the wording you’d like clients to read before booking.',
            'privacy_status' => 'Do you already have a privacy notice or terms you’d like to include on the website?',
            'privacy_text' => 'Please share the wording or a link where we can read it.',
            'domain_platform' => 'Do you already have a website address, or would you prefer to talk about it later?',
            'existing_website_url' => 'What is your current website address?',
            'existing_cms' => 'If you know, what service do you use to manage your website, such as WordPress?',
            'q_702f0901' => 'What might someone search for to find you? For example, “wedding photographer in Laguna.” You can skip this.',
            'additional' => 'Anything else you’d like to share about your website?',
        ];

        $optionLabels = [
            'q_c074a772' => ['inquiry' => 'Send me a message', 'quote' => 'Ask for a quote'],
            'differentiator_status' => ['help' => 'Help me find the words'],
            'pricing_currency' => ['PHP' => 'Philippine pesos', 'USD' => 'US dollars', 'pending' => 'Decide later'],
            'pricing_display' => ['team' => 'Decide later'],
            'service_area_status' => ['later' => 'Share my service areas later'],
            'booking' => ['form' => 'Send a message through a form on my website', 'link' => 'Use my booking link'],
            'public_contact_mode' => ['reuse' => 'Review the contact details I shared when I signed up', 'later' => 'Share contact details later'],
            'public_contact_permission' => ['approved' => 'Choose details now and review them at the end', 'review' => 'Decide later'],
            'reference_use' => ['owned' => 'My website or another source I have permission to use'],
            'reference_permission' => ['approved' => 'Yes, we have permission to use it', 'review' => 'Let’s check permission first'],
            'asset_plan' => ['stock' => 'Suggest photos from a library for me to review'],
            'asset_permission' => ['approved' => 'Yes, we have permission to use them', 'review' => 'Let’s check permission first'],
            'social_proof' => ['now' => 'Share a written review now', 'link' => 'Share a link to a review', 'none' => 'Leave reviews off for now'],
            'testimonial_permission' => ['approved' => 'Yes, we have permission to show it', 'review' => 'Let’s check permission first'],
            'scope_mode' => ['custom' => 'Tell you what I’d like on my website', 'suggest' => 'I’d like suggestions'],
            'existing_content_mode' => ['none' => 'I have nothing to carry over', 'mixed' => 'I’d like to keep some things and change others'],
            'policies' => ['help' => 'I’d like help with this'],
            'privacy_status' => ['none' => 'I don’t have wording to share', 'help' => 'I’d like help with this'],
            'domain_platform' => ['existing' => 'I have a website address', 'none' => 'I don’t have a website address yet'],
        ];

        DB::transaction(function () use ($forward, $original, $introductions, $prompts, $optionLabels) {
            foreach ($original as $definition) {
                $key = $definition['key'];
                $question = WebsiteQuestion::query()->whereNull('applies_to')->whereNotNull('intake_schema')
                    ->where(fn ($query) => $query->where('key', $key)->orWhere('label', $definition['label']))
                    ->lockForUpdate()->get()->sole();

                $schema = $question->intake_schema;
                $originalFields = array_column($definition['intake_schema']['fields'], null, 'key');
                foreach ($schema['fields'] as &$field) {
                    $fieldKey = $field['key'] === $question->key ? $key : $field['key'];
                    $originalField = $originalFields[$fieldKey] ?? null;
                    if (!$originalField) continue;
                    if (isset($prompts[$fieldKey])) {
                        $field['prompt'] = $this->replaceCopy($field['prompt'], $originalField['prompt'], $prompts[$fieldKey], $forward);
                    }
                    if (!isset($optionLabels[$fieldKey], $field['options'])) continue;
                    $oldLabels = array_column($originalField['options'], 'label', 'value');
                    foreach ($field['options'] as &$option) {
                        $value = $option['value'];
                        if (isset($oldLabels[$value], $optionLabels[$fieldKey][$value])) {
                            $option['label'] = $this->replaceCopy($option['label'], $oldLabels[$value], $optionLabels[$fieldKey][$value], $forward);
                        }
                    }
                    unset($option);
                }
                unset($field);

                $question->text = $this->replaceCopy($question->text, $definition['text'], $introductions[$key], $forward);
                $question->intake_schema = $schema;
                if ($question->isDirty()) $question->save();
            }
        });
    }

    private function replaceCopy(string $current, string $original, string $revised, bool $forward): string
    {
        // Keep wording that an admin has already customized.
        return $current === ($forward ? $original : $revised) ? ($forward ? $revised : $original) : $current;
    }
};
