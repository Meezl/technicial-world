<?php

namespace App\Rules;

use App\Models\Technician;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The person on the other end of this id must be a tradesman.
 *
 * Gang members live in the same table as technicians — see the add_gang_members
 * migration for why — so `exists:technicians,id` no longer means what it used
 * to at the dozen endpoints that hand out work, money or a task. This says the
 * part that matters, and says it in words the office can act on rather than
 * "the selected technician is invalid".
 *
 * Deliberately not the only guard. The models refuse the same thing in their
 * booted() invariants, because a rule that lives only in a form is a rule that
 * holds only where somebody remembered to write it.
 */
class IsTechnician implements ValidationRule
{
    public function __construct(private string $because = 'this')
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $technician = Technician::find($value);

        if ($technician && $technician->isGangMember()) {
            $fail(sprintf(
                '%s is a gang member, not a technician, so they cannot be given %s. Add them to the crew instead, with a description of what they will be doing on site.',
                $technician->user->name ?? 'That person',
                $this->because
            ));
        }
    }
}
