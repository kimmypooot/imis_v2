<?php

namespace App\Enums;

/**
 * Stable, machine-readable identifiers for system-level roles.
 *
 * Role rows are still database records — future modules are free to create
 * additional roles through the roles management UI. This enum exists only
 * for roles the application logic itself needs to recognize by name.
 */
enum RoleName: string
{
    case SuperAdmin = 'super-admin';
}
