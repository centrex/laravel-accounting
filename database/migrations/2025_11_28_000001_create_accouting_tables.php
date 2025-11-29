<?php

declare(strict_types = 1);

// database/migrations/2025_11_28_000001_create_accounting_tables.php

use Centrex\LaravelAccounting\Enums\{AccountSubtype, AccountType};
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up()
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        // Chart of Accounts
        Schema::connection($connection)->create($prefix . 'accounts', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('type', AccountType::values());
            $table->enum('subtype', AccountSubtype::values())->nullable();

            // self-referencing parent (set null on delete to avoid accidental subtree deletion)
            $table->foreignId('parent_id')->nullable()->constrained($prefix . 'accounts')->onDelete('set null');

            $table->text('description')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->boolean('is_active')->default(true);
            $table->nullableMorphs('modelable');
            $table->nullableMorphs('modelable');
            $table->boolean('is_system')->default(false);
            $table->integer('level')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
            $table->index('code');
        });

        // Journal Entries
        Schema::connection($connection)->create($prefix . 'journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number')->unique();
            $table->date('date');
            $table->string('reference')->nullable();
            $table->string('type')->default('general'); // general, opening, closing, adjusting
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);

            // assuming users table is on the same connection; if not, replace with unsignedBigInteger + explicit foreign
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['date', 'status']);
            $table->index('entry_number');
        });

        // Journal Entry Lines (Double-Entry)
        Schema::connection($connection)->create($prefix . 'journal_entry_lines', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained($prefix . 'journal_entries')->onDelete('cascade');
            $table->foreignId('account_id')->constrained($prefix . 'accounts')->onDelete('restrict');
            $table->enum('type', ['debit', 'credit']);
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index(['journal_entry_id', 'account_id']);
            $table->index('account_id');
        });

        // Fiscal Years
        Schema::connection($connection)->create($prefix . 'fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_closed')->default(false);
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index('is_current');
        });

        // Fiscal Periods (Monthly)
        Schema::connection($connection)->create($prefix . 'fiscal_periods', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained($prefix . 'fiscal_years')->onDelete('cascade');
            $table->string('name'); // January 2024, Q1 2024, etc.
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->index(['fiscal_year_id', 'is_closed']);
        });

        // Account Balances (for performance)
        Schema::connection($connection)->create($prefix . 'account_balances', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('account_id')->constrained($prefix . 'accounts')->onDelete('cascade');
            $table->foreignId('fiscal_period_id')->constrained($prefix . 'fiscal_periods')->onDelete('cascade');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->decimal('balance', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'fiscal_period_id']);
        });

        // Customers
        Schema::connection($connection)->create($prefix . 'customers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->integer('payment_terms')->default(30); // days
            $table->boolean('is_active')->default(true);
            $table->nullableMorphs('modelable');
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        // Vendors/Suppliers
        Schema::connection($connection)->create($prefix . 'vendors', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->integer('payment_terms')->default(30);
            $table->boolean('is_active')->default(true);
            $table->nullableMorphs('modelable');
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        // Employees
        Schema::connection($connection)->create($prefix . 'employees', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->integer('payment_terms')->default(30); // days
            $table->boolean('is_active')->default(true);
            $table->nullableMorphs('modelable');
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        // Invoices
        Schema::connection($connection)->create($prefix . 'invoices', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('customer_id')->constrained($prefix . 'customers')->onDelete('restrict');
            $table->date('invoice_date');
            $table->date('due_date');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('BDT');
            $table->enum('status', ['draft', 'sent', 'paid', 'partial', 'overdue', 'void'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained($prefix . 'journal_entries')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'due_date']);
            $table->index('customer_id');
            $table->index('invoice_date');
            $table->index('due_date');
        });

        // Invoice Items
        Schema::connection($connection)->create($prefix . 'invoice_items', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('invoice_id')->constrained($prefix . 'invoices')->onDelete('cascade');
            $table->string('description');
            $table->nullableMorphs('itemable'); // product or service
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index(['itemable_type', 'itemable_id']);
            $table->index('invoice_id');
        });

        // Bills (Vendor Invoices)
        Schema::connection($connection)->create($prefix . 'bills', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('bill_number')->unique();
            $table->foreignId('vendor_id')->constrained($prefix . 'vendors')->onDelete('restrict');
            $table->date('bill_date');
            $table->date('due_date');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('BDT');
            $table->enum('status', ['draft', 'approved', 'paid', 'partial', 'overdue', 'void'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained($prefix . 'journal_entries')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'due_date']);
            $table->index('vendor_id');
            $table->index('bill_date');
        });

        // Bill Items
        Schema::connection($connection)->create($prefix . 'bill_items', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('bill_id')->constrained($prefix . 'bills')->onDelete('cascade');
            $table->string('description');
            $table->nullableMorphs('itemable'); // product or service
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index(['itemable_type', 'itemable_id']);
            $table->index('bill_id');
        });

        // Payments
        Schema::connection($connection)->create($prefix . 'payments', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->morphs('payable'); // invoice or bill
            $table->date('payment_date');
            $table->decimal('amount', 18, 2);
            $table->string('payment_method'); // cash, check, bank_transfer, card
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained($prefix . 'journal_entries')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['payable_type', 'payable_id']);
            $table->index('payment_date');
        });

        // Tax Rates
        Schema::connection($connection)->create($prefix . 'tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->decimal('rate', 5, 2);
            $table->boolean('is_compound')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // Payroll Accounts
        Schema::connection($connection)->create($prefix . 'payroll_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->boolean('is_active')->default(true);
            $table->text('particulars')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['code', 'is_active']);
            $table->index('code');
        });

        // Payroll Entries
        Schema::connection($connection)->create($prefix . 'payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number')->unique();
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->string('type'); // e.g., salary, bonus, deduction, tax
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['date', 'status']);
            $table->index('entry_number');
        });

        // Payroll Entry Lines
        Schema::connection($connection)->create($prefix . 'payroll_entry_lines', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained($prefix . 'payroll_entries')->onDelete('cascade');
            $table->foreignId('payroll_account_id')->constrained($prefix . 'payroll_accounts')->onDelete('restrict');
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index('payroll_account_id');
            $table->index('payroll_entry_id');
        });
    }

    public function down()
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        // drop in reverse order of creation to satisfy foreign keys
        Schema::connection($connection)->dropIfExists($prefix . 'payroll_entry_lines');
        Schema::connection($connection)->dropIfExists($prefix . 'payroll_entries');
        Schema::connection($connection)->dropIfExists($prefix . 'payroll_accounts');
        Schema::connection($connection)->dropIfExists($prefix . 'tax_rates');
        Schema::connection($connection)->dropIfExists($prefix . 'payments');
        Schema::connection($connection)->dropIfExists($prefix . 'bill_items');
        Schema::connection($connection)->dropIfExists($prefix . 'bills');
        Schema::connection($connection)->dropIfExists($prefix . 'invoice_items');
        Schema::connection($connection)->dropIfExists($prefix . 'invoices');
        Schema::connection($connection)->dropIfExists($prefix . 'vendors');
        Schema::connection($connection)->dropIfExists($prefix . 'customers');
        Schema::connection($connection)->dropIfExists($prefix . 'account_balances');
        Schema::connection($connection)->dropIfExists($prefix . 'fiscal_periods');
        Schema::connection($connection)->dropIfExists($prefix . 'fiscal_years');
        Schema::connection($connection)->dropIfExists($prefix . 'journal_entry_lines');
        Schema::connection($connection)->dropIfExists($prefix . 'journal_entries');
        Schema::connection($connection)->dropIfExists($prefix . 'accounts');
    }
};
