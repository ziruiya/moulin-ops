<?php

namespace Maintenance\Case2\Before;

function journalDate(string $receivedAt): string
{
    return substr($receivedAt, 0, 10);
}
