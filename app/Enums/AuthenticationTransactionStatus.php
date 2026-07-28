<?php

namespace App\Enums;

enum AuthenticationTransactionStatus: string
{
    case Pending = 'pending';
    case ProviderSelected = 'provider_selected';
    case Authenticating = 'authenticating';
    case OrganizationRequired = 'organization_required';
    case Approved = 'approved';
    case Issuing = 'issuing';
    case Consumed = 'consumed';
    case Denied = 'denied';
}
