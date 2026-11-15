<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ListRoles;

/**
 * One request for the tenant's roles (AC-23).
 *
 * It carries nothing. There is no page size here and no filter: AC-23 asks
 * for the roles of one shop ordered by key, and a shop's role list is tens
 * of rows, not the thousands `employees` can reach (AC-29, which is why
 * **that** list is paged and this one is not). A filter nobody asked for
 * would be a parameter every caller has to be told to omit.
 */
final class ListRoles {}
