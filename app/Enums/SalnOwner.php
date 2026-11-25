<?php

namespace App\Enums;

/**
 * Whose an entry is. The declarant's (including conjugal or community
 * property) prints on the form and AS-1; the spouse's exclusive property
 * and the children's print on AS-2.
 */
enum SalnOwner: string
{
    case Declarant = 'declarant';
    case Spouse = 'spouse';
    case Child = 'child';
}
