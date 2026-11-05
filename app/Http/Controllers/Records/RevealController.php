<?php

namespace App\Http\Controllers\Records;

use App\Enums\AccessPurpose;
use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Models\AccessJustification;
use App\Models\Person;
use App\Models\User;
use App\Services\AccessRequests;
use App\Services\AuditLogger;
use App\Services\PasswordCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shows one full sensitive value, to someone allowed to, after they re-enter
 * their password and state a purpose (developer guide §11). Each reveal is
 * recorded before the value is returned, without the value.
 */
class RevealController extends Controller
{
    public function __invoke(Request $request, Person $person, PasswordCheck $passwords, AccessRequests $access, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('reveal', $person);

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['identity', 'contact', 'address', 'date_of_birth'])],
            'record_id' => ['nullable', 'integer'],
            'purpose' => ['required', Rule::enum(AccessPurpose::class)],
            'explanation' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $error = $passwords->check($user, $validated['password'], 'reveal', 'The password is incorrect.');

        if ($error !== null) {
            throw ValidationException::withMessages(['password' => $error]);
        }

        $value = $this->valueFor($person, $validated['kind'], $validated['record_id'] ?? null);

        $purpose = AccessPurpose::from($validated['purpose']);
        $justification = $access->recordPurpose($user, AccessJustification::ACTION_REVEAL, $purpose, explanation: $validated['explanation'] ?? null);

        $audit->record('person.identifier.revealed', AuditOutcome::Success, $user, [
            'kind' => $validated['kind'],
            'record_id' => $validated['record_id'] ?? null,
            'purpose' => $purpose->value,
            'justification_id' => $justification->id,
        ], $person);

        return response()->json(['value' => $value]);
    }

    /**
     * The value must belong to this person; otherwise the address would let
     * someone read another person's details.
     */
    private function valueFor(Person $person, string $kind, ?int $recordId): string
    {
        $value = match ($kind) {
            'identity' => $person->identities()->whereKey($recordId)->first()?->identifier_ciphertext,
            'contact' => $person->contacts()->whereKey($recordId)->first()?->value_ciphertext,
            'address' => $person->addresses()->whereKey($recordId)->first()?->address_ciphertext,
            default => $person->date_of_birth_ciphertext,
        };

        abort_if($value === null, 404);

        return $value;
    }
}
