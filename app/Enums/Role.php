<?php

namespace App\Enums;

/**
 * The candidate roles of developer guide §7.1, with a PROVISIONAL permission
 * matrix: the institution must approve the final one (§2.2). Run
 * `php artisan access:matrix` to print it for review.
 *
 * Technical roles hold no operational permission (§7.2), so administering the
 * system never grants access to person or case records.
 */
enum Role: string
{
    case RecordsOfficer = 'records_officer';
    case InvestigatingOfficer = 'investigating_officer';
    case StationCommander = 'station_commander';
    case DistrictSupervisor = 'district_supervisor';
    case NationalOfficer = 'national_officer';
    case RecordsQualityReviewer = 'records_quality_reviewer';
    case Auditor = 'auditor';
    case ApplicationAdministrator = 'application_administrator';
    case SecurityAdministrator = 'security_administrator';
    case InfrastructureAdministrator = 'infrastructure_administrator';

    public function label(): string
    {
        return match ($this) {
            self::RecordsOfficer => 'Records Officer',
            self::InvestigatingOfficer => 'Investigating Officer',
            self::StationCommander => 'Station Commander',
            self::DistrictSupervisor => 'District Supervisor',
            self::NationalOfficer => 'Authorised National/CID Officer',
            self::RecordsQualityReviewer => 'Records Quality Reviewer',
            self::Auditor => 'Auditor',
            self::ApplicationAdministrator => 'Application Administrator',
            self::SecurityAdministrator => 'Security Administrator',
            self::InfrastructureAdministrator => 'Database/Infrastructure Administrator',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::RecordsOfficer => [
                Permission::PersonsView, Permission::PersonsCreate, Permission::PersonsCorrect,
                Permission::CasesViewStation, Permission::CasesCreate, Permission::CasesCorrect, Permission::CasesLinkPersons,
                Permission::DocumentsUpload, Permission::DocumentsView,
                Permission::SearchRecords, Permission::AccessRequest,
            ],
            self::InvestigatingOfficer => [
                Permission::PersonsView, Permission::PersonsCreate, Permission::IdentifiersReveal,
                Permission::CasesViewAssigned, Permission::CasesProgress, Permission::CasesLinkPersons, Permission::CasesPrint,
                Permission::DocumentsUpload, Permission::DocumentsView, Permission::CourtRecord,
                Permission::SearchRecords, Permission::SearchIdentifiers, Permission::AccessRequest,
            ],
            self::StationCommander => [
                Permission::PersonsView,
                Permission::CasesViewStation, Permission::CasesViewRestricted, Permission::CasesAssign, Permission::CasesProgress,
                Permission::CasesManageLifecycle, Permission::CasesPrint,
                Permission::DocumentsView, Permission::DocumentsHold,
                Permission::SearchRecords, Permission::SearchIdentifiers, Permission::AccessRequest, Permission::AccessApprove,
                Permission::ExportsRequest, Permission::ExportsApprove, Permission::ReportsOperational,
            ],
            self::DistrictSupervisor => [
                Permission::PersonsView,
                Permission::CasesViewDistrict, Permission::CasesViewRestricted, Permission::CasesAssign, Permission::CasesProgress,
                Permission::CasesManageLifecycle, Permission::CasesPrint,
                Permission::DocumentsView, Permission::DocumentsHold, Permission::CourtVerify,
                Permission::SearchRecords, Permission::SearchIdentifiers, Permission::AccessRequest, Permission::AccessApprove,
                Permission::ExportsRequest, Permission::ExportsApprove,
                Permission::ReportsOperational, Permission::ReportsStatistics,
            ],
            self::NationalOfficer => [
                Permission::PersonsView, Permission::IdentifiersReveal,
                Permission::CasesViewNational, Permission::CasesPrint, Permission::DocumentsView,
                Permission::SearchRecords, Permission::SearchIdentifiers,
                Permission::ReportsOperational, Permission::ReportsStatistics,
            ],
            self::RecordsQualityReviewer => [
                Permission::PersonsView, Permission::PersonsCorrect, Permission::PersonsMerge, Permission::IdentifiersVerify,
                Permission::CasesViewDistrict, Permission::CasesCorrect, Permission::CourtVerify, Permission::SearchRecords,
            ],
            self::Auditor => [
                Permission::AuditView, Permission::UsersView, Permission::ReportsStatistics,
            ],
            self::ApplicationAdministrator => [
                Permission::OrganisationManage, Permission::CatalogueManage, Permission::UsersView,
            ],
            self::SecurityAdministrator => [
                Permission::UsersView, Permission::UsersManage, Permission::RolesManage, Permission::MfaReset,
                Permission::AuditView, Permission::AlertsManage,
            ],
            self::InfrastructureAdministrator => [],
        };
    }

    /**
     * Where the holder must be placed for the role to mean anything: a
     * station role with no station would simply see nothing.
     *
     * @return 'station'|'district'|null
     */
    public function requiredPlacement(): ?string
    {
        return match ($this) {
            self::RecordsOfficer, self::InvestigatingOfficer, self::StationCommander => 'station',
            self::DistrictSupervisor, self::RecordsQualityReviewer => 'district',
            default => null,
        };
    }

    /**
     * Nationwide access is always time-limited, so it has to be renewed on
     * purpose (§7.3, §23).
     */
    public function requiresExpiry(): bool
    {
        return $this === self::NationalOfficer;
    }
}
