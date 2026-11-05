<?php

namespace App\Http\Controllers\Records;

use App\Enums\AccessPurpose;
use App\Enums\AddressType;
use App\Enums\AuditOutcome;
use App\Enums\ContactType;
use App\Enums\IdentityType;
use App\Enums\PersonSex;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\CorrectPersonRequest;
use App\Http\Requests\Records\StorePersonRequest;
use App\Models\CasePerson;
use App\Models\Person;
use App\Models\PersonAddress;
use App\Models\PersonContact;
use App\Models\PersonIdentity;
use App\Models\RecordCorrection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PersonRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Person records. Sensitive values reach the browser only masked (§11); full
 * values are available solely through RevealController.
 */
class PersonController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Person::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('persons/Index', [
            'people' => Person::query()
                ->visibleTo($user)
                ->notMerged()
                ->with('registeredStation:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->through(fn (Person $person): array => [
                    'id' => $person->id,
                    'full_name' => $person->full_name,
                    'birth_year' => $person->birth_year,
                    'station' => $person->registeredStation->name,
                    'created_at' => $person->created_at?->toIso8601String(),
                ]),
            'canCreate' => $user->can('create', Person::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Person::class);

        return Inertia::render('persons/Create', [
            'options' => [
                'sexes' => PersonSex::options(),
                'identity_types' => IdentityType::options(),
            ],
        ]);
    }

    public function store(StorePersonRequest $request, PersonRecords $people): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{given_names: string, family_name: string, other_names?: string|null, sex?: string|null, date_of_birth?: string|null, nationality?: string|null, occupation?: string|null, identity_type?: string|null, identity_number?: string|null} $details */
        $details = $request->validated();

        $person = $people->register($details, $user);

        return to_route('persons.show', $person)->with('status', 'Person registered.');
    }

    public function show(Request $request, Person $person, AuditLogger $audit): Response|RedirectResponse
    {
        Gate::authorize('view', $person);

        /** @var User $user */
        $user = $request->user();

        $audit->record('person.viewed', AuditOutcome::Success, $user, record: $person);

        return Inertia::render('persons/Show', [
            'person' => [
                'id' => $person->id,
                'full_name' => $person->full_name,
                'given_names' => $person->given_names,
                'family_name' => $person->family_name,
                'other_names' => $person->other_names,
                'sex' => $person->sex?->value,
                'sex_label' => $person->sex?->label(),
                'birth_year' => $person->birth_year,
                'has_date_of_birth' => $person->getRawOriginal('date_of_birth_ciphertext') !== null,
                'nationality' => $person->nationality,
                'occupation' => $person->occupation,
                'station' => $person->registeredStation->name,
                'district' => $person->registeredDistrict->name,
                'merged_into_id' => $person->merged_into_id,
            ],
            'identities' => $person->identities()->with('verifiedBy:id,name')->orderBy('id')->get()->map(fn (PersonIdentity $identity): array => [
                'id' => $identity->id,
                'type' => $identity->type->label(),
                'masked' => $identity->identifier_masked,
                'issuing_country' => $identity->issuing_country,
                'issuing_authority' => $identity->issuing_authority,
                'verification_status' => $identity->verification_status->value,
                'verification_label' => $identity->verification_status->label(),
                'verification_source' => $identity->verification_source,
                'verified_by' => $identity->verifiedBy?->name,
                'verified_at' => $identity->verified_at?->toIso8601String(),
            ]),
            'contacts' => $person->contacts()->orderBy('id')->get()->map(fn (PersonContact $contact): array => [
                'id' => $contact->id,
                'type' => $contact->type->label(),
                'masked' => $contact->value_masked,
                'valid_from' => $contact->valid_from?->toDateString(),
                'valid_to' => $contact->valid_to?->toDateString(),
            ]),
            'addresses' => $person->addresses()->orderBy('id')->get()->map(fn (PersonAddress $address): array => [
                'id' => $address->id,
                'type' => $address->type->label(),
                'masked' => $address->address_masked,
                'valid_from' => $address->valid_from?->toDateString(),
                'valid_to' => $address->valid_to?->toDateString(),
            ]),
            'caseLinks' => $person->caseLinks()
                ->whereHas('criminalCase', fn (Builder $cases) => $cases->visibleTo($user))
                ->with('criminalCase:id,reference,status')
                ->orderByDesc('linked_at')
                ->get()
                ->map(fn (CasePerson $link): array => [
                    'id' => $link->id,
                    'case_id' => $link->case_id,
                    'reference' => $link->criminalCase->reference,
                    'case_status' => $link->criminalCase->status->label(),
                    'role' => $link->role->label(),
                    'role_status' => $link->role_status,
                    'linked_at' => $link->linked_at->toIso8601String(),
                    'unlinked_at' => $link->unlinked_at?->toIso8601String(),
                ]),
            'corrections' => RecordCorrection::query()
                ->where('record_type', $person->getMorphClass())
                ->where('record_id', $person->id)
                ->with('correctedBy:id,name')
                ->orderByDesc('corrected_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (RecordCorrection $correction): array => [
                    'id' => $correction->id,
                    'field' => str_replace('_ciphertext', '', $correction->field),
                    'reason' => $correction->reason,
                    'corrected_by' => $correction->correctedBy->name,
                    'corrected_at' => $correction->corrected_at->toIso8601String(),
                ]),
            'options' => [
                'sexes' => PersonSex::options(),
                'identity_types' => IdentityType::options(),
                'contact_types' => ContactType::options(),
                'address_types' => AddressType::options(),
                'verification_statuses' => VerificationStatus::options(),
                'purposes' => AccessPurpose::options(),
            ],
            'can' => [
                'correct' => $user->can('correct', $person),
                'add_details' => $user->can('addDetails', $person),
                'verify_identity' => $user->can('verifyIdentity', $person),
                'reveal' => $user->can('reveal', $person),
            ],
        ]);
    }

    public function update(CorrectPersonRequest $request, Person $person, PersonRecords $people): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $changed = $people->correct(
            $person,
            $request->safe()->except('reason'),
            $request->string('reason')->toString(),
            $user,
        );

        return back()->with('status', $changed === [] ? 'Nothing was changed.' : 'Correction recorded.');
    }
}
