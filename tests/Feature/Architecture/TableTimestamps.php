<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

/**
 * Every table's class, and what it therefore keeps: its timestamps, who it
 * belongs to, who acted on it, how it arrived and which request made it.
 * TableTimestampsTest reads the migrated schema against this.
 *
 * The classes (the owner's timestamps policy):
 *
 *   a  an entity, a mutable business record: created_at, updated_at,
 *      created_by, updated_by.
 *   b  an entity people may remove: as (a), plus deleted_at (SoftDeletes)
 *      and deleted_by. A unique index must still let a removed name or code
 *      be made again - it includes not_deleted, or the record is brought
 *      back as the same row, which 'unique_across_removed' says.
 *   c  a compliance or financial record: created_at, and updated_at only
 *      where its status legitimately changes ('updates'); created_by and its
 *      own lifecycle actors (issued_by ...). Never deleted_at: these are
 *      never deleted (ZATCA E-Invoicing Detailed Guidelines 5.4 p26 and the
 *      prohibited functions of 5.6 p27; p63: an invoice cannot be cancelled).
 *   d  an append-only log or event: created_at, or its own event time
 *      ('event'), and no updated_at. Never deleted_at.
 *   framework  Laravel's own tables, and a bare lock row. Exempt.
 *
 * Per table:
 *
 *   tenant      organization (org_id), branch (org_id and branch_id), either
 *               (org_id, nullable: the platform's own rows too), global, or
 *               parent:<column> - a row that only exists under another, and
 *               belongs to whoever owns it.
 *   actors      what records each actor, by role => column. A table may keep
 *               one under its own name (recorded_by is created_by). Lifecycle
 *               actors are listed by their own names.
 *   system      why a table keeps no actor columns: only the platform writes
 *               it, and the person is on the record it serves.
 *   parent      true when the parent row's actors are this row's (an
 *               invoice line is created by whoever created the invoice).
 *   channel     created_via and license_id: records that can arrive several
 *               ways (portal, api, pipeline, ...), and the licence they came
 *               through.
 *   external    the id an ERP knows the record by, unique per organization
 *               and source where 'external_unique' gives the index.
 *   correlation the request id, so one request can be traced end to end.
 *   legacy      a column the class does not allow, kept because dropping it
 *               needs the owner's decision, with why.
 *   arriving    a table another branch adds, classified ahead of its merge.
 *   retiring    a table another branch drops: its columns are not checked.
 */
final class TableTimestamps
{
    private const ENTITY = ['created_by' => 'created_by', 'updated_by' => 'updated_by'];

    private const REMOVABLE = ['created_by' => 'created_by', 'updated_by' => 'updated_by', 'deleted_by' => 'deleted_by'];

    private const CHANNEL = ['created_via', 'license_id'];

    /** @var array<string, array<string, mixed>> */
    public const TABLES = [
        // Laravel's own.
        'cache' => ['class' => 'framework'],
        'cache_locks' => ['class' => 'framework'],
        'failed_jobs' => ['class' => 'framework'],
        'job_batches' => ['class' => 'framework'],
        'jobs' => ['class' => 'framework'],
        'migrations' => ['class' => 'framework'],
        'password_reset_tokens' => ['class' => 'framework'],
        'sessions' => ['class' => 'framework'],
        // A row per chain held while one document is issued into it
        // (IssuingLifecycle): a mutex like cache_locks, not a record.
        'issuing_locks' => ['class' => 'framework', 'why' => 'a row lock, like cache_locks: it records nothing'],

        // Accounts and organizations.
        'users' => ['class' => 'b', 'tenant' => 'global', 'actors' => self::REMOVABLE,
            // Deleting an account anonymises it (AccountDeletion, PDPL), so a
            // deleted account's address is already gone from the index.
            'unique_across_removed' => [
                'users_email_unique' => 'a deleted account is anonymised; its address no longer holds the index',
                'users_pending_email_hash_unique' => 'a random token hash',
                'users_two_factor_enrolment_hash_unique' => 'a random token hash',
            ]],
        'organization_groups' => ['class' => 'b', 'tenant' => 'global', 'actors' => self::REMOVABLE],
        'organizations' => ['class' => 'a', 'tenant' => 'global', 'why' => 'the tenant itself; invoices outlive it (0310)',
            'actors' => self::ENTITY + ['reviewed_by' => 'reviewed_by']],
        // Ending a membership sets status removed; the row stays.
        'organization_user' => ['class' => 'a', 'tenant' => 'organization', 'actors' => self::ENTITY],
        'organization_invitations' => ['class' => 'b', 'tenant' => 'organization',
            'actors' => ['created_by' => 'invited_by', 'updated_by' => 'updated_by', 'deleted_by' => 'deleted_by'],
            'unique_across_removed' => ['organization_invitations_token_hash_unique' => 'a random token hash']],
        'compliance_profiles' => ['class' => 'b', 'tenant' => 'organization', 'actors' => self::REMOVABLE],
        'branches' => ['class' => 'b', 'tenant' => 'organization', 'actors' => self::REMOVABLE,
            'unique_across_removed' => [
                // The EGS unit's serial is in its certificate; a removed
                // branch's must not be issued again under another.
                'branches_org_device_serial_unique' => 'an EGS serial is never reused, removed or not',
                'branches_till_number_unique' => 'a till number is never reused (Branch::till_number counts removed branches)',
            ]],
        'organization_feature_overrides' => ['class' => 'a', 'tenant' => 'organization',
            'actors' => ['created_by' => 'created_by', 'updated_by' => 'set_by']],
        'settings' => ['class' => 'a', 'tenant' => 'global', 'actors' => self::ENTITY],
        'themes' => ['class' => 'a', 'tenant' => 'either', 'why' => 'reset empties the row rather than deleting it (ThemeService)',
            'actors' => self::ENTITY],

        // Invoices: the compliance record and what hangs off it.
        'invoices' => ['class' => 'c', 'updates' => true, 'tenant' => 'branch',
            // issued_by arrives with feat/history-branches (0760) and is then
            // required; until then it is listed in 'arriving_columns'.
            'actors' => ['created_by' => 'created_by', 'issued_by' => 'issued_by'],
            'arriving_columns' => ['issued_by' => 'feat/history-branches 0760_invoice_issued_by'],
            'channel' => self::CHANNEL, 'external' => 'erp_reference_id',
            // A pipeline call that sends its own number may reuse an ERP
            // reference (a note for the same order), and createdFor() takes
            // the oldest: not unique by design.
            'external_unique' => null],
        'invoice_lines' => ['class' => 'c', 'updates' => true, 'tenant' => 'parent:invoice_id', 'parent' => true],
        'invoice_charges' => ['class' => 'c', 'updates' => true, 'tenant' => 'parent:invoice_id', 'parent' => true],
        'invoice_prepayments' => ['class' => 'c', 'updates' => true, 'tenant' => 'parent:invoice_id', 'parent' => true],
        'invoice_sequences' => ['class' => 'a', 'tenant' => 'organization', 'actors' => self::ENTITY],
        'invoice_links' => ['class' => 'a', 'tenant' => 'organization', 'why' => 'revoked, not removed (revoked_at)',
            'actors' => self::ENTITY],
        'customers' => ['class' => 'b', 'tenant' => 'organization', 'actors' => self::REMOVABLE,
            'channel' => self::CHANNEL, 'external' => 'external_id',
            'external_unique' => ['org_id', 'license_id', 'external_id', 'not_deleted']],
        'products' => ['class' => 'b', 'tenant' => 'organization', 'actors' => self::REMOVABLE,
            'channel' => self::CHANNEL, 'external' => 'external_id',
            'external_unique' => ['org_id', 'license_id', 'external_id', 'not_deleted']],
        'business_qr_codes' => ['class' => 'b', 'tenant' => 'organization', 'actors' => self::REMOVABLE],
        // feat/governance moves presets into customizations and drops this.
        'charge_presets' => ['class' => 'b', 'tenant' => 'either', 'retiring' => 'feat/governance 0770_charge_preset_customizations drops it'],

        // Filing.
        'invoice_submissions' => ['class' => 'c', 'updates' => true, 'tenant' => 'organization',
            'actors' => ['created_by' => 'created_by'], 'correlation' => 'request_id',
            // Created with the table (0140) and never written: no code
            // deletes a submission or reads a deleted one. The model no
            // longer soft-deletes, so nothing can reach it.
            'legacy' => ['deleted_at' => 'unused since 0140; dropping it is the owner\'s decision']],
        'submission_state_logs' => ['class' => 'd', 'tenant' => 'parent:submission_id', 'parent' => true,
            'actors' => ['actor' => 'actor_id'], 'correlation' => 'request_id',
            'legacy' => ['updated_at' => 'written equal to created_at by SubmissionLedger and the filing job, never changed; dropping it is the owner\'s decision']],
        'submission_idempotency' => ['class' => 'a', 'tenant' => 'organization',
            'system' => 'the platform\'s record of a filing request it made, expired by expires_at'],
        'offline_queue' => ['class' => 'c', 'updates' => true, 'tenant' => 'organization', 'parent' => true,
            'system' => 'queued by the platform for an invoice; the person is on the invoice'],
        'hash_chain_history' => ['class' => 'c', 'tenant' => 'organization', 'parent' => true,
            'system' => 'written at issuance; who issued is invoices.issued_by'],
        'hash_chain_heads' => ['class' => 'c', 'updates' => true, 'tenant' => 'organization',
            'system' => 'the chain\'s head, moved by the platform at issuance'],
        'reporting_attempts' => ['class' => 'd', 'event' => 'attempted_at', 'tenant' => 'organization', 'correlation' => 'request_id'],
        'late_reports' => ['class' => 'c', 'updates' => true, 'tenant' => 'organization',
            'system' => 'opened by the platform when a report is late',
            'actors' => ['zatca_notified_by' => 'zatca_notified_by', 'zatca_renotified_by' => 'zatca_renotified_by']],
        'peppol_documents' => ['class' => 'c', 'updates' => true, 'tenant' => 'organization',
            'system' => 'sent or received by the platform; the person is on the invoice'],

        // Money.
        'payments' => ['class' => 'c', 'updates' => true, 'tenant' => 'organization',
            'actors' => ['created_by' => 'recorded_by'],
            // license_id here is the licence paid for, not the one it came through.
            'channel' => ['created_via']],

        // Audit and evidence.
        'audit_logs' => ['class' => 'd', 'tenant' => 'either', 'actors' => ['actor' => 'user_id'], 'correlation' => 'request_id',
            'legacy' => ['updated_at' => 'never written by the model; dropping it is the owner\'s decision']],

        // Licensing.
        'licenses' => ['class' => 'b', 'tenant' => 'global',
            'actors' => ['created_by' => 'issued_by', 'updated_by' => 'updated_by', 'deleted_by' => 'deleted_by'],
            'unique_across_removed' => ['licenses_api_key_unique' => 'a generated key, never issued twice']],
        'license_registrations' => ['class' => 'b', 'tenant' => 'global',
            'actors' => ['updated_by' => 'updated_by', 'deleted_by' => 'deleted_by', 'approved_by' => 'approved_by'],
            // An applicant has no account yet; who approved or rejected is
            // approved_by, and every step is in license_registration_audits.
            'system' => 'made by an applicant with no account',
            'unique_across_removed' => ['license_registrations_verification_token_unique' => 'a random token']],
        'license_registration_audits' => ['class' => 'd', 'tenant' => 'parent:registration_id', 'actors' => ['actor' => 'user_id'],
            'legacy' => ['updated_at' => 'never written by the model; dropping it is the owner\'s decision']],
        'license_audit_logs' => ['class' => 'd', 'tenant' => 'parent:license_id', 'actors' => ['actor' => 'actor_id']],
        'organization_licenses' => ['class' => 'a', 'tenant' => 'organization',
            'actors' => ['created_by' => 'linked_by', 'updated_by' => 'updated_by']],
        'license_usage' => ['class' => 'a', 'tenant' => 'parent:license_id', 'system' => 'counters the platform keeps per licence per day'],
        'license_rate_limits' => ['class' => 'a', 'tenant' => 'parent:license_id', 'system' => 'counters the platform keeps per licence per window'],
        'usage_events' => ['class' => 'd', 'event' => 'occurred_at', 'tenant' => 'parent:license_id', 'correlation' => 'request_id'],
        'allowance_warnings' => ['class' => 'd', 'event' => 'warned_at', 'tenant' => 'parent:license_id'],

        // Webhooks.
        'webhooks' => ['class' => 'b', 'tenant' => 'organization', 'actors' => self::REMOVABLE],
        'webhook_logs' => ['class' => 'd', 'tenant' => 'parent:webhook_id',
            'legacy' => ['updated_at' => 'never written by the model; dropping it is the owner\'s decision']],

        // Support.
        'support_tickets' => ['class' => 'a', 'tenant' => 'organization',
            'actors' => ['created_by' => 'opened_by', 'updated_by' => 'updated_by', 'assigned_to' => 'assigned_to']],
        'support_messages' => ['class' => 'd', 'tenant' => 'parent:ticket_id', 'actors' => ['author' => 'user_id'],
            'legacy' => ['updated_at' => 'never written by the model; dropping it is the owner\'s decision']],
        'support_attachments' => ['class' => 'd', 'tenant' => 'parent:message_id', 'parent' => true,
            'legacy' => ['updated_at' => 'never written by the model; dropping it is the owner\'s decision']],

        // Arriving with feat/history-branches.
        'saved_list_views' => ['class' => 'b', 'tenant' => 'either', 'actors' => self::REMOVABLE, 'arriving' => 'feat/history-branches 0750',
            'unique_across_removed' => ['saved_list_views_name_unique' => 'saving the name again restores the removed view']],
        'branch_user' => ['class' => 'b', 'tenant' => 'branch', 'actors' => self::REMOVABLE, 'arriving' => 'feat/history-branches 0780',
            'unique_across_removed' => ['branch_user_unique' => 'putting the person back restores the removed row']],

        // Arriving with feat/governance.
        'customizations' => ['class' => 'b', 'tenant' => 'either', 'actors' => self::REMOVABLE, 'arriving' => 'feat/governance 0750',
            'unique_across_removed' => ['customizations_key_holder_unique' => 'setting the value again restores the removed row']],
        'approval_policies' => ['class' => 'b', 'tenant' => 'global', 'actors' => self::REMOVABLE, 'arriving' => 'feat/governance 0790',
            'unique_across_removed' => ['approval_policies_action_unique' => 'changing the policy again restores the removed row']],
        'approval_requests' => ['class' => 'c', 'updates' => true, 'tenant' => 'global', 'arriving' => 'feat/governance 0790',
            'actors' => ['created_by' => 'created_by', 'requested_by' => 'requested_by', 'approved_by' => 'approved_by',
                'rejected_by' => 'rejected_by', 'withdrawn_by' => 'withdrawn_by']],
        'approval_decisions' => ['class' => 'd', 'tenant' => 'parent:request_id', 'actors' => ['actor' => 'user_id'], 'arriving' => 'feat/governance 0790'],
        'admin_roles' => ['class' => 'b', 'tenant' => 'global', 'actors' => self::REMOVABLE, 'arriving' => 'feat/governance 0800',
            'unique_across_removed' => ['admin_roles_key_unique' => 'a role key names the role in users.platform_role; a removed one is restored']],
        'admin_invitations' => ['class' => 'b', 'tenant' => 'global', 'actors' => self::REMOVABLE, 'arriving' => 'feat/governance 0840',
            'unique_across_removed' => ['admin_invitations_token_hash_unique' => 'a random token hash']],
    ];
}
