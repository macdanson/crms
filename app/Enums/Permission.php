<?php

namespace App\Enums;

/**
 * Everything a role can be allowed to do (developer guide §7).
 *
 * A permission never decides access to a record on its own: policies and the
 * visibleTo() query scopes add the user's place in the organisation, their
 * case assignments and any approved exceptional access.
 */
enum Permission: string
{
    case OrganisationManage = 'organisation.manage';
    case CatalogueManage = 'catalogue.manage';

    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case MfaReset = 'users.mfa.reset';

    case AuditView = 'audit.view';
    case AlertsManage = 'alerts.manage';

    case PersonsView = 'persons.view';
    case PersonsCreate = 'persons.create';
    case PersonsCorrect = 'persons.correct';
    case PersonsMerge = 'persons.merge';
    case IdentifiersVerify = 'persons.identifiers.verify';
    case IdentifiersReveal = 'persons.identifiers.reveal';

    case CasesViewAssigned = 'cases.view.assigned';
    case CasesViewStation = 'cases.view.station';
    case CasesViewDistrict = 'cases.view.district';
    case CasesViewNational = 'cases.view.national';
    case CasesViewRestricted = 'cases.view.restricted';
    case CasesCreate = 'cases.create';
    case CasesCorrect = 'cases.correct';
    case CasesAssign = 'cases.assign';
    case CasesProgress = 'cases.progress';
    case CasesManageLifecycle = 'cases.lifecycle';
    case CasesLinkPersons = 'cases.link-persons';
    case CasesPrint = 'cases.print';

    case AccessRequest = 'access.request';
    case AccessApprove = 'access.approve';

    case SearchRecords = 'search.records';
    case SearchIdentifiers = 'search.identifiers';

    case DocumentsUpload = 'documents.upload';
    case DocumentsView = 'documents.view';
    case DocumentsHold = 'documents.hold';

    case CourtRecord = 'court.record';
    case CourtVerify = 'court.verify';

    case ExportsRequest = 'exports.request';
    case ExportsApprove = 'exports.approve';

    case ReportsOperational = 'reports.operational';
    case ReportsStatistics = 'reports.statistics';
}
