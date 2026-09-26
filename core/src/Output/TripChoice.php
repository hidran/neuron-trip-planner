<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Output;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

/**
 * The offer scout's pick: two IDs and a reason. No prices.
 *
 * That omission is the security design of the whole example. The model
 * chooses; the application prices. Whatever total the traveller authorises is
 * read back from the inventory by ID, never from text the model wrote.
 */
class TripChoice
{
    #[SchemaProperty(description: 'The id of the chosen flight offer, exactly as returned by search_flights.', required: true)]
    #[NotBlank]
    public string $flight_offer_id;

    #[SchemaProperty(description: 'The id of the chosen hotel offer, exactly as returned by search_hotels.', required: true)]
    #[NotBlank]
    public string $hotel_offer_id;

    #[SchemaProperty(
        description: 'Why this combination is the best value for this traveller, in two or three sentences.',
        required: true,
    )]
    #[NotBlank]
    public string $reasoning;
}
