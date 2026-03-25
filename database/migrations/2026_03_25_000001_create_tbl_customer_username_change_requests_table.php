<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Disabled: username-change requests are stored in tbl_tickets/tbl_tickets_details.
        return;
    }

    public function down(): void
    {
        // no-op
    }
};
