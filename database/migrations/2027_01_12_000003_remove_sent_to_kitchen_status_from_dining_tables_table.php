<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * تراجع عن قرار SENT_TO_KITCHEN (أصفر) — تم إلغاؤه قبل استخدامه فعلياً.
     * زر "طباعة" (mode=departments) بالكاشير يرجع يحوّل الطاولة مباشرة لأزرق
     * (BILL_PRINTED) بدل الأصفر. تحققنا: لا يوجد أي صف بهذه الحالة وقت الكتابة.
     */
    public function up(): void
    {
        DB::statement("UPDATE dining_tables SET status = 'AVAILABLE' WHERE status = 'SENT_TO_KITCHEN'");

        try {
            DB::statement("ALTER TABLE dining_tables DROP CONSTRAINT dining_tables_status_check");
        } catch (\Throwable $e) {
            // Constraint may not exist
        }

        DB::statement("ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_status_check CHECK (status IN ('AVAILABLE', 'OCCUPIED', 'PAYMENT_PENDING', 'BILL_PRINTED', 'PAID', 'CLEANING', 'HAS_ORDER', 'PENDING_CONFIRMATION', 'MERGED'))");
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE dining_tables DROP CONSTRAINT dining_tables_status_check");
        } catch (\Throwable $e) {
            // Constraint may not exist
        }

        DB::statement("ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_status_check CHECK (status IN ('AVAILABLE', 'OCCUPIED', 'PAYMENT_PENDING', 'BILL_PRINTED', 'SENT_TO_KITCHEN', 'PAID', 'CLEANING', 'HAS_ORDER', 'PENDING_CONFIRMATION', 'MERGED'))");
    }
};
