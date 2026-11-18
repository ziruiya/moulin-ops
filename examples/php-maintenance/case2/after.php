<?php

namespace Maintenance\Case2\After;

use DateTimeImmutable;
use DateTimeZone;

function journalDate(string $receivedAt): string
{
    return (new DateTimeImmutable($receivedAt))
        ->setTimezone(new DateTimeZone('Asia/Tokyo'))
        ->format('Y-m-d');
}
