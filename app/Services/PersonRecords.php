<?php

namespace App\Services;

use App\Enums\AddressType;
use App\Enums\AuditOutcome;
use App\Enums\ContactType;
use App\Enums\DuplicateStatus;
use App\Enums\IdentityType;
use App\Enums\VerificationStatus;
use App\Models\CasePerson;
use App\Models\Conviction;
use App\Models\DuplicateCandidate;
use App\Models\Person;
use App\Models\PersonAddress;
use App\Models\PersonContact;
use App\Models\PersonIdentity;
use App\Models\PersonMerge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a person record (developer guide §4, §5, §13, §27.3). The
 * audit trail records which record and field changed, never the values.
 */
class PersonRecords
{
    public function __construct(
        private DataProtection $protection,
        private DuplicateDetector $duplicates,
        private AuditLogger $audit,
        private RecordCorrections $corrections,
    ) {}

    /**
     * @param  array{given_names: string, family_name: string, other_names?: string|null, sex?: string|null, date_of_birth?: string|null, nationality?: string|null, occupation?: string|null, identity_type?: string|null, identity_number?: string|null}  $details
     */
    public function register(array $details, User $actor): Person
    {
        $person = DB::transaction(function () use ($details, $actor): Person {
            $person = new Person;
            $person->forceFill([
                'given_names' => $details['given_names'],
                'family_name' => $details['family_name'],
                'other_names' => $details['other_names'] ?? null,
                'sex' => $details['sex'] ?? null,
                'date_of_birth_ciphertext' => $details['date_of_birth'] ?? null,
                'birth_year' => isset($details['date_of_birth']) ? (int) substr($details['date_of_birth'], 0, 4) : null,
                'nationality' => $details['nationality'] ?? null,
                'occupation' => $details['occupation'] ?? null,
                'registered_district_id' => $actor->district_id,
                'registered_station_id' => $actor->station_id,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->audit->record('person.created', AuditOutcome::Success, $actor, record: $person);

            if (filled($details['identity_type'] ?? null) && filled($details['identity_number'] ?? null)) {
                $this->storeIdentity($person, IdentityType::from($details['identity_type']), $details['identity_number'], null, null, $actor);
            }

            return $person;
        });

        $this->duplicates->check($person);

        return $person;
    }

    public function addIdentity(Person $person, IdentityType $type, #[\SensitiveParameter] string $number, ?string $country, ?string $authority, User $actor): PersonIdentity
    {
        $identity = DB::transaction(fn (): PersonIdentity => $this->storeIdentity($person, $type, $number, $country, $authority, $actor));

        $this->duplicates->check($person);

        return $identity;
    }

    /**
     * Record the result of an identity check. Only an authorised process can
     * make it Verified, so the source and officer are always on record (§5.2).
     */
    public function setVerification(PersonIdentity $identity, VerificationStatus $status, ?string $source, User $actor): void
    {
        if ($status === VerificationStatus::Verified && blank($source)) {
            throw ValidationException::withMessages(['source' => 'Say which authorised source confirmed this identity.']);
        }

        $identity->forceFill([
            'verification_status' => $status,
            'verification_source' => $source,
            'verified_by' => $actor->getKey(),
            'verified_at' => now(),
        ])->save();

        $this->audit->record('person.identity.verification_changed', AuditOutcome::Success, $actor, [
            'identity_id' => $identity->id,
            'type' => $identity->type->value,
            'status' => $status->value,
        ], $identity->person);
    }

    public function addContact(Person $person, ContactType $type, #[\SensitiveParameter] string $value, ?string $validFrom, ?string $validTo, User $actor): PersonContact
    {
        $contact = new PersonContact;
        $contact->forceFill([
            'person_id' => $person->id,
            'type' => $type,
            'value_ciphertext' => trim($value),
            'value_masked' => $this->protection->maskContact($value),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'created_by' => $actor->getKey(),
        ])->save();

        $this->audit->record('person.contact.added', AuditOutcome::Success, $actor, ['contact_id' => $contact->id], $person);

        return $contact;
    }

    public function addAddress(Person $person, AddressType $type, #[\SensitiveParameter] string $address, ?string $validFrom, ?string $validTo, User $actor): PersonAddress
    {
        $record = new PersonAddress;
        $record->forceFill([
            'person_id' => $person->id,
            'type' => $type,
            'address_ciphertext' => trim($address),
            'address_masked' => $this->protection->maskAddress($address),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'created_by' => $actor->getKey(),
        ])->save();

        $this->audit->record('person.address.added', AuditOutcome::Success, $actor, ['address_id' => $record->id], $person);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return list<string> The attributes that changed.
     */
    public function correct(Person $person, array $changes, string $reason, User $actor): array
    {
        if (array_key_exists('date_of_birth', $changes)) {
            $date = $changes['date_of_birth'];
            unset($changes['date_of_birth']);

            $changes['date_of_birth_ciphertext'] = $date;
            $changes['birth_year'] = $date === null ? null : (int) substr((string) $date, 0, 4);
        }

        $changed = $this->corrections->apply($person, $changes, $reason, $actor);

        if ($changed !== []) {
            $this->duplicates->check($person->refresh());
        }

        return $changed;
    }

    /**
     * Merge a reviewed duplicate into the record that stays. The source record
     * stays too, marked as merged, and what moved is kept on record (§5.1).
     */
    public function merge(DuplicateCandidate $candidate, Person $target, string $reason, User $actor): PersonMerge
    {
        $sourceId = $candidate->first_person_id === $target->id ? $candidate->second_person_id : $candidate->first_person_id;

        $merge = DB::transaction(function () use ($candidate, $target, $sourceId, $reason, $actor): PersonMerge {
            $locked = DuplicateCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $source = Person::query()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
            $lockedTarget = Person::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DuplicateStatus::Pending || $source->merged_into_id !== null || $lockedTarget->merged_into_id !== null) {
                throw ValidationException::withMessages(['reason' => 'This match has already been reviewed.']);
            }

            $moved = [
                'identities' => $this->moveRecords(PersonIdentity::class, $source, $lockedTarget),
                'contacts' => $this->moveRecords(PersonContact::class, $source, $lockedTarget),
                'addresses' => $this->moveRecords(PersonAddress::class, $source, $lockedTarget),
                'case_links' => $this->moveRecords(CasePerson::class, $source, $lockedTarget),
                'convictions' => $this->moveRecords(Conviction::class, $source, $lockedTarget),
            ];

            $source->forceFill(['merged_into_id' => $lockedTarget->id])->save();

            $locked->forceFill([
                'status' => DuplicateStatus::Merged,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $reason,
            ])->save();

            // Other possible matches for the merged record no longer apply.
            DuplicateCandidate::query()
                ->where('status', DuplicateStatus::Pending)
                ->where(fn ($query) => $query->where('first_person_id', $source->id)->orWhere('second_person_id', $source->id))
                ->update([
                    'status' => DuplicateStatus::Dismissed,
                    'reviewed_by' => $actor->getKey(),
                    'reviewed_at' => now(),
                    'review_note' => 'One of these records was merged into another.',
                ]);

            $merge = new PersonMerge;
            $merge->forceFill([
                'source_person_id' => $source->id,
                'target_person_id' => $lockedTarget->id,
                'duplicate_candidate_id' => $locked->id,
                'moved_records' => $moved,
                'reason' => $reason,
                'merged_by' => $actor->getKey(),
                'merged_at' => now(),
            ])->save();

            $this->audit->record('person.merged', AuditOutcome::Success, $actor, [
                'source_person_id' => $source->id,
                'target_person_id' => $lockedTarget->id,
                'reason' => $reason,
                'moved' => array_map('count', $moved),
            ], $lockedTarget);

            return $merge;
        });

        $this->duplicates->check($target->refresh());

        return $merge;
    }

    public function dismissDuplicate(DuplicateCandidate $candidate, string $note, User $actor): void
    {
        $candidate->forceFill([
            'status' => DuplicateStatus::Dismissed,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        $this->audit->record('person.duplicate.dismissed', AuditOutcome::Success, $actor, ['note' => $note], $candidate);
    }

    private function storeIdentity(Person $person, IdentityType $type, #[\SensitiveParameter] string $number, ?string $country, ?string $authority, User $actor): PersonIdentity
    {
        $normalised = $this->protection->normaliseIdentifier($number);
        $token = $this->protection->lookupToken($type->value, $normalised);

        if ($person->identities()->where('type', $type)->where('identifier_lookup_hmac', $token)->exists()) {
            throw ValidationException::withMessages(['identity_number' => 'This person already has that identity number on record.']);
        }

        $identity = new PersonIdentity;
        $identity->forceFill([
            'person_id' => $person->id,
            'type' => $type,
            'identifier_ciphertext' => $normalised,
            'identifier_lookup_hmac' => $token,
            'identifier_masked' => $this->protection->maskIdentifier($normalised),
            'issuing_country' => $country,
            'issuing_authority' => $authority,
            'verification_status' => VerificationStatus::Unverified,
            'created_by' => $actor->getKey(),
        ])->save();

        $this->audit->record('person.identity.added', AuditOutcome::Success, $actor, [
            'identity_id' => $identity->id,
            'type' => $type->value,
        ], $person);

        return $identity;
    }

    /**
     * @param  class-string<PersonIdentity|PersonContact|PersonAddress|CasePerson|Conviction>  $model
     * @return list<int> The IDs of the records moved.
     */
    private function moveRecords(string $model, Person $source, Person $target): array
    {
        $ids = array_values(array_map(intval(...), $model::query()->where('person_id', $source->id)->pluck('id')->all()));

        $model::query()->whereIn('id', $ids)->update(['person_id' => $target->id]);

        return $ids;
    }
}
