<?php

namespace App\Enums;

enum LicenseStatus: string
{
    case Frozen = 'Frozen';
    case Active = 'Active';
    case Expired = 'Expired';
}

