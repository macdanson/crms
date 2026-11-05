<?php

namespace App\Http\Controllers\Records;

use App\Enums\AddressType;
use App\Enums\ContactType;
use App\Enums\IdentityType;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\User;
use App\Services\PersonRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Identity documents, contact details and address history for a person.
 */
class PersonDetailController extends Controller
{
    public function storeIdentity(Request $request, Person $person, PersonRecords $people): RedirectResponse
    {
        Gate::authorize('addDetails', $person);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(IdentityType::class)],
            'identity_number' => ['required', 'string', 'min:4', 'max:40', 'regex:/^[A-Za-z0-9\s\-]+$/'],
            'issuing_country' => ['nullable', 'string', 'max:80'],
            'issuing_authority' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $people->addIdentity(
            $person,
            IdentityType::from($validated['type']),
            $validated['identity_number'],
            $validated['issuing_country'] ?? null,
            $validated['issuing_authority'] ?? null,
            $user,
        );

        return back()->with('status', 'Identity document added as unverified.');
    }

    public function updateVerification(Request $request, Person $person, PersonIdentity $identity, PersonRecords $people): RedirectResponse
    {
        Gate::authorize('verifyIdentity', $person);

        abort_unless($identity->person_id === $person->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(VerificationStatus::class)],
            'source' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $people->setVerification($identity, VerificationStatus::from($validated['status']), $validated['source'] ?? null, $user);

        return back()->with('status', 'Verification status updated.');
    }

    public function storeContact(Request $request, Person $person, PersonRecords $people): RedirectResponse
    {
        Gate::authorize('addDetails', $person);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ContactType::class)],
            'value' => ['required', 'string', 'max:120', Rule::when($request->input('type') === ContactType::Email->value, ['email'])],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $people->addContact($person, ContactType::from($validated['type']), $validated['value'], $validated['valid_from'] ?? null, $validated['valid_to'] ?? null, $user);

        return back()->with('status', 'Contact detail added.');
    }

    public function storeAddress(Request $request, Person $person, PersonRecords $people): RedirectResponse
    {
        Gate::authorize('addDetails', $person);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(AddressType::class)],
            'address' => ['required', 'string', 'max:500'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $people->addAddress($person, AddressType::from($validated['type']), $validated['address'], $validated['valid_from'] ?? null, $validated['valid_to'] ?? null, $user);

        return back()->with('status', 'Address added.');
    }
}
