<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // False keeps existing non-Saa9vi/local PILOS accounts outside the
            // subscription gate until Vendure explicitly manages the account.
            $table->boolean('entitlement_managed')->default(false);
            $table->timestamp('suspended_at')->nullable();
            $table->string('entitlement_plan', 32)->nullable();
            $table->unsignedBigInteger('entitlement_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'entitlement_managed',
                'suspended_at',
                'entitlement_plan',
                'entitlement_version',
            ]);
        });
    }
};
