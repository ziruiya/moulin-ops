<?php

namespace Maintenance\Case1\After;

function quantityLabel(array $row): string
{
    $quantity = $row['quantity'] ?? null;

    return $quantity === null || $quantity === '' ? 'missing' : (string) $quantity;
}
