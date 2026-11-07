<?php

namespace App\Support;

/**
 * The 12 business types, copied from docs/chatbot.html (BUSINESS_TYPES).
 * The key is what we store; the label is what the supplier sees.
 */
final class BusinessTypes
{
    public const ALL = [
        'venue' => 'Wedding Venue',
        'coordinator' => 'Wedding Coordinator / Planner',
        'photographer' => 'Photographer / Videographer',
        'caterer' => 'Catering Supplier',
        'florist' => 'Florist / Wedding Stylist',
        'makeup' => 'Makeup Artist',
        'attire' => 'Bridal Gown / Suit Supplier',
        'entertainment' => 'Wedding Entertainment Supplier',
        'cake' => 'Wedding Cake Supplier',
        'invitations' => 'Invitations / Souvenirs Supplier',
        'transportation' => 'Transportation / Accommodation Supplier',
        'other' => 'Other Wedding-Related Business',
    ];

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }
}
