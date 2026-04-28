<?php

use Illuminate\Support\Facades\Broadcast;

// Private channel per customer — only the customer themselves can listen
Broadcast::channel('customer.{customerId}', function ($user, int $customerId) {
    return (int) $user->c_userid === $customerId;
});
