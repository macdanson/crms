<?php

namespace App\Http\Controllers\Records;

use App\Enums\AccessPurpose;
use App\Enums\AuditOutcome;
use App\Enums\IdentityType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AccessJustification;
use App\Models\CriminalCase;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\Station;
use App\Models\User;
use App\Services\AccessRequests;
use App\Services\AuditLogger;
use App\Services\DataProtection;
use App\Services\KeyedHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Search and record verification (developer guide §10, §27.6).
 *
 * The search itself is a POST: identifiers and names must not end up in URLs,
 * browser history or server logs. A match the user may not see looks exactly
 * like no match, so searching cannot confirm that a record exists. Every search
 * is audited, without the search term.
 */
class SearchController extends Controller
{
    /**
     * The most results one search returns: this is for finding a record, not
     * for browsing the database.
     */
    private const RESULT_LIMIT = 50;

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('search/Index', [
            'identityTypes' => IdentityType::options(),
            'purposes' => AccessPurpose::options(),
            'stations' => Station::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'search_identifiers' => $user->hasPermission(Permission::SearchIdentifiers),
                'search_people' => $user->hasPermission(Permission::PersonsView),
                'request_access' => $user->hasPermission(Permission::AccessRequest),
            ],
        ]);
    }

    public function caseReference(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate(['reference' => ['required', 'string', 'min:3', 'max:60']]);

        /** @var User $user */
        $user = $request->user();

        $cases = CriminalCase::query()
            ->visibleTo($user)
            ->with(['station:id,name', 'offenceCategory:id,name'])
            ->where('reference', 'like', '%'.addcslashes(trim($validated['reference']), '%_\\').'%')
            ->orderBy('reference')
            ->limit(self::RESULT_LIMIT)
            ->get();

        $audit->record('search.case', AuditOutcome::Success, $user, ['result_count' => $cases->count()]);

        return response()->json([
            'cases' => $cases->map(fn (CriminalCase $case): array => [
                'id' => $case->id,
                'reference' => $case->reference,
                'station' => $case->station->name,
                'category' => $case->offenceCategory->name,
                'status' => $case->status->label(),
            ]),
        ]);
    }

    public function identity(Request $request, DataProtection $protection, AccessRequests $access, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(IdentityType::class)],
            'identity_number' => ['required', 'string', 'min:4', 'max:40'],
            'purpose' => ['required', Rule::enum(AccessPurpose::class)],
            'case_reference' => ['nullable', 'string', 'max:60'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $purpose = AccessPurpose::from($validated['purpose']);
        $justification = $access->recordPurpose($user, AccessJustification::ACTION_SEARCH_IDENTITY, $purpose, $validated['case_reference'] ?? null);

        $matches = PersonIdentity::query()
            ->where('type', $validated['type'])
            ->where('identifier_lookup_hmac', $protection->lookupToken($validated['type'], $validated['identity_number']))
            ->whereHas('person', fn ($query) => $query->visibleTo($user))
            ->with('person.registeredStation:id,name')
            ->limit(self::RESULT_LIMIT)
            ->get();

        $audit->record('search.person.identity', AuditOutcome::Success, $user, [
            'type' => $validated['type'],
            'purpose' => $purpose->value,
            'justification_id' => $justification->id,
            'result_count' => $matches->count(),
        ]);

        return response()->json([
            'people' => $matches->map(fn (PersonIdentity $identity): array => [
                'id' => $identity->person->id,
                'full_name' => $identity->person->full_name,
                'birth_year' => $identity->person->birth_year,
                'station' => $identity->person->registeredStation->name,
                'match_reason' => "Exact {$identity->type->label()} match ({$identity->identifier_masked})",
                'verification' => $identity->verification_status->label(),
            ]),
        ]);
    }

    public function name(Request $request, AccessRequests $access, AuditLogger $audit, KeyedHash $hash): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'birth_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'station_id' => ['nullable', 'integer'],
            'purpose' => ['required', Rule::enum(AccessPurpose::class)],
            'case_reference' => ['nullable', 'string', 'max:60'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $term = Person::normaliseName($validated['name']);

        if (mb_strlen($term) < 2) {
            return response()->json(['people' => []]);
        }

        $purpose = AccessPurpose::from($validated['purpose']);
        $justification = $access->recordPurpose($user, AccessJustification::ACTION_SEARCH_NAME, $purpose, $validated['case_reference'] ?? null);

        $people = Person::query()
            ->visibleTo($user)
            ->notMerged()
            ->with('registeredStation:id,name')
            // normaliseName leaves no LIKE wildcards in the term.
            ->where('search_name', 'like', "%{$term}%")
            ->when($validated['birth_year'] ?? null, fn ($query, int $year) => $query->where('birth_year', $year))
            ->when($validated['station_id'] ?? null, fn ($query, int $stationId) => $query->where('registered_station_id', $stationId))
            ->orderBy('search_name')
            ->orderBy('id')
            ->limit(self::RESULT_LIMIT)
            ->get();

        $audit->record('search.person.name', AuditOutcome::Success, $user, [
            'term_hash' => $hash->of($term),
            'purpose' => $purpose->value,
            'justification_id' => $justification->id,
            'result_count' => $people->count(),
        ]);

        return response()->json([
            'people' => $people->map(fn (Person $person): array => [
                'id' => $person->id,
                'full_name' => $person->full_name,
                'birth_year' => $person->birth_year,
                'station' => $person->registeredStation->name,
                'match_reason' => 'Name match only: this does not prove it is the same person',
                'verification' => null,
            ]),
        ]);
    }
}
