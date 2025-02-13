<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPaymentTypeCoupons extends Migration
{
    public function up(): void
    {
        Schema::table('igniter_coupons', function (Blueprint $table) {
            $table->string('payment_condition', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('igniter_coupons', function (Blueprint $table) {
            if (Schema::hasColumn('igniter_coupons', 'payment_condition')) {
                $table->dropColumn('payment_condition');
            }
        });
    }
};
