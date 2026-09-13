<?php

namespace App\Rules;

use App\Support\IdentityProof;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Checks an identity number against the format of the type chosen alongside it.
 *
 * The rule takes the type rather than reading the request so it can be used
 * from the checkout and the profile, which name the fields differently.
 */
class IdentityNumber implements ValidationRule
{
    public function __construct(private ?string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;   // presence is the required rule's business, not this one's
        }

        if (! IdentityProof::isKnownType($this->type)) {
            $fail('Choose which identity proof this number belongs to.');

            return;
        }

        if (! IdentityProof::isValid($this->type, $value)) {
            $label = IdentityProof::label($this->type);
            $example = IdentityProof::example($this->type);

            $fail($this->type === 'aadhaar'
                // A well-formed Aadhaar that fails the checksum is a typo, and
                // saying "looks like 1234 5678 9012" would be baffling.
                ? "That does not look like a valid Aadhaar number. Check it for a typo."
                : "A {$label} looks like {$example}.");
        }
    }
}
