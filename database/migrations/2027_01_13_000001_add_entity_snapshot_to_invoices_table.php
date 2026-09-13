<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// الفاتورة على حساب مورد/زبون/موظف كانت تحفظ entity_type + entity_id بس بدون
// اسم أو رقم — أي تغيير لاحق على اسم/هاتف الكيان كان يغيّر عرض الفواتير
// القديمة تراجعياً (مخالف لقاعدة الـ snapshot في CLAUDE.md). أضفنا snapshot
// وفهرس لدعم "كشف حساب" (كل فواتير مورد/زبون/موظف معيّن) بسرعة.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'entity_name')) {
                $table->string('entity_name')->nullable()->after('entity_id');
            }
            if (! Schema::hasColumn('invoices', 'entity_number')) {
                $table->string('entity_number', 50)->nullable()->after('entity_name');
            }
        });

        if (! Schema::hasIndex('invoices', 'invoices_entity_idx')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index(['entity_type', 'entity_id'], 'invoices_entity_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        if (Schema::hasIndex('invoices', 'invoices_entity_idx')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropIndex('invoices_entity_idx');
            });
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['entity_name', 'entity_number']);
        });
    }
};
