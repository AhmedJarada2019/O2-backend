<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();

            // SALE/EXPENSE/WITHDRAWAL/DEPOSIT/REFUND/CASH_DROP/VOID — نفس قيم
            // FinancialTransactionType بالفرونت اند حرفيًا، لتفادي طبقة تحويل
            $table->enum('type', ['SALE', 'EXPENSE', 'WITHDRAWAL', 'DEPOSIT', 'REFUND', 'CASH_DROP', 'VOID']);

            $table->decimal('amount', 10, 2);
            $table->text('reason')->nullable();

            $table->timestamp('timestamp')->useCurrent();
            $table->timestamps();

            $table->index(['branch_id', 'timestamp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
