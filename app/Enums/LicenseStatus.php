<?php

namespace App\Enums;

enum LicenseStatus: string
{
    case Available = 'Available';
    case Active = 'Active';
    case Inactive = 'Inactive';
    case Expired = 'Expired';
}

