<?php

namespace Modules\DemandNotice\Enums;

enum DemandNoticeStatus: string
{
    case GENERATED = 'GENERATED';
    case SENT = 'SENT';
}
