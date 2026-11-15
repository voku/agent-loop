<?php

declare(strict_types=1);

namespace voku\AgentLoop\Decision;

enum DecisionDisposition: string
{
    case Suggest = 'suggest';
    case Abstain = 'abstain';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidResponse = 'invalid_response';
    case NotEnabled = 'not_enabled';
}
