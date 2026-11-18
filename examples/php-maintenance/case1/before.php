<?php

namespace Maintenance\Case1\Before;

function quantityLabel(array $row): string
{
    return empty($row['quantity']) ? 'missing' : (string) $row['quantity'];
}
