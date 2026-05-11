<?php

use Illuminate\Support\Facades\Broadcast;

// Private channel per customer — only the customer themselves can listen
Broadcast::channel('private-customer-{customerId}', function ($user, int $customerId) {
    return (int) $user->c_userid === $customerId;
});
