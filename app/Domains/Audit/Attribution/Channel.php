<?php

declare(strict_types=1);

namespace App\Domains\Audit\Attribution;

/**
 * The way a record arrived, kept beside it as created_via.
 *
 * An invoice, a customer or a payment can come from a person in the portal,
 * an operator in the console, an integration over the API or the pipeline,
 * or the platform itself. Which one it was is part of who did it: the same
 * person can issue from the portal and, through their ERP, from the API.
 */
enum Channel: string
{
    /** A person signed in to the customer portal. */
    case Portal = 'portal';

    /** A platform operator, in the admin console or its API. */
    case Admin = 'admin';

    /** An integration calling the API, with a person's token or a licence key. */
    case Api = 'api';

    /** The partner pipeline: create, sign and submit in one call. */
    case Pipeline = 'pipeline';

    /** The partner pipeline's batch call. */
    case Batch = 'batch';

    /** A file brought in: the portal's CSV import of invoices, customers and products. */
    case Import = 'import';

    /** A queued job, the scheduler or a command. */
    case System = 'system';

    /** A device at the edge, offline at the till. Not used yet. */
    case Edge = 'edge';
}
