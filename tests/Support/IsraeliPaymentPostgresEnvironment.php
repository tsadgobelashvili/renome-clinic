<?php

// Standalone PostgreSQL integration support. Does not boot Laravel or load application credentials.

use App\Models\PartnerPatientPayment;
use App\Models\User;
use App\Policies\PartnerPatientPaymentPolicy;
use Illuminate\Auth\Access\Gate;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;

function israeliPostgresEnvironment(string $schema): Dispatcher
{
    if (getenv('ISRAELI_PAYMENT_DISPOSABLE_PG') !== 'yes' || ! preg_match('/^isr_lock_[a-f0-9]{12}$/', $schema)
        || ! getenv('ISRAELI_PAYMENT_PG_DATA_DIRECTORY')) {
        throw new RuntimeException('Requires a dedicated disposable PostgreSQL cluster and isolated test schema.');
    }
    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55448,
        'database' => 'renome_payment_concurrency', 'username' => 'codex_payment_test', 'password' => '',
        'charset' => 'utf8', 'prefix' => '', 'search_path' => $schema, 'sslmode' => 'disable']);
    $container = $capsule->getContainer();
    Container::setInstance($container);
    $container['config']['app.timezone'] = 'Asia/Tbilisi';
    $container->instance('db', $capsule->getDatabaseManager());
    $container->bind('db.schema', fn () => DB::connection()->getSchemaBuilder());
    $events = new Dispatcher($container);
    $capsule->setEventDispatcher($events);
    $capsule->bootEloquent();
    Model::clearBootedModels();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    $translator = new Translator(new ArrayLoader, 'en');
    $container->instance('translator', $translator);
    $validator = new Factory($translator, $container);
    $container->instance('validator', $validator);
    $container->instance(\Illuminate\Contracts\Validation\Factory::class, $validator);
    $owner = (new User)->forceFill(['id' => 1, 'name' => 'Synthetic Owner', 'role' => User::ROLE_OWNER, 'is_active' => true]);
    $auth = new class($owner) implements \Illuminate\Contracts\Auth\Factory {
        public function __construct(private User $owner) {}
        public function user(): User { return $this->owner; }
        public function id(): int { return $this->owner->id; }
        public function guard($name = null) { return $this; }
        public function shouldUse($name) {}
    };
    $container->instance(\Illuminate\Contracts\Auth\Factory::class, $auth);
    $gate = new Gate($container, fn () => $owner);
    $gate->policy(PartnerPatientPayment::class, PartnerPatientPaymentPolicy::class);
    $container->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
    $actual = str_replace('\\', '/', DB::selectOne('SHOW data_directory')->data_directory);
    if (strcasecmp($actual, str_replace('\\', '/', getenv('ISRAELI_PAYMENT_PG_DATA_DIRECTORY'))) !== 0
        || DB::connection()->getDatabaseName() !== 'renome_payment_concurrency') {
        throw new RuntimeException('Refusing access outside the owned disposable cluster.');
    }
    DB::statement("SET statement_timeout = '15s'");
    DB::statement("SET lock_timeout = '10s'");

    return $events;
}

function israeliPostgresFixtures(string $schema, array $receipts, array $expenses): void
{
    if (DB::table('information_schema.schemata')->where('schema_name', $schema)->exists()) {
        throw new RuntimeException('Refusing to reuse a schema or overwrite existing records.');
    }
    DB::statement('CREATE SCHEMA "'.$schema.'"');
    Schema::create('users', fn (Blueprint $table) => $table->id());
    Schema::create('patient_groups', function (Blueprint $table): void {
        $table->id(); $table->string('slug')->unique();
    });
    Schema::create('patients', function (Blueprint $table): void {
        $table->id(); $table->string('first_name'); $table->string('last_name');
        $table->unsignedBigInteger('patient_group_id'); $table->softDeletes();
    });
    Schema::create('expense_categories', function (Blueprint $table): void {
        $table->id(); $table->string('name'); $table->string('classification_dimension')->nullable();
        $table->string('classification_code')->nullable(); $table->unsignedBigInteger('parent_id')->nullable();
    });
    Schema::create('expense_subcategories', function (Blueprint $table): void {
        $table->id(); $table->unsignedBigInteger('expense_category_id'); $table->string('name');
    });
    Schema::create('bank_categories', function (Blueprint $table): void {
        $table->id(); $table->string('name'); $table->string('code')->nullable();
    });
    Schema::create('partner_patient_payments', function (Blueprint $table): void {
        $table->id(); $table->unsignedBigInteger('patient_id'); $table->unsignedBigInteger('visit_id')->nullable();
        $table->decimal('amount', 12, 2); $table->string('currency', 3); $table->string('payment_method');
        $table->timestamp('paid_at'); $table->text('notes')->nullable(); $table->timestamps(); $table->softDeletes();
    });
    Schema::create('partner_finance_transactions', function (Blueprint $table): void {
        $table->id(); foreach (['source', 'type', 'category', 'from_account', 'to_account', 'currency', 'from_currency', 'to_currency', 'notes', 'recipient'] as $column) {
            $table->string($column)->nullable();
        }
        foreach (['amount', 'from_amount', 'to_amount', 'exchange_rate'] as $column) { $table->decimal($column, 18, 6)->nullable(); }
        foreach (['created_by', 'expense_direction_id', 'expense_type_id', 'expense_category_id', 'expense_subcategory_id', 'purchase_product_id', 'purchase_product_group_id'] as $column) {
            $table->unsignedBigInteger($column)->nullable();
        }
        $table->timestamp('transacted_at'); $table->timestamps();
    });
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_17_120000_add_israeli_payment_cancellation_and_audits.php';
    DB::transaction(fn () => $migration->up());
    DB::table('users')->insert(['id' => 1]);
    DB::table('patient_groups')->insert(['id' => 1, 'slug' => 'israel-partner']);
    foreach ([53, 54] as $id) {
        DB::table('patients')->insert(['id' => $id, 'first_name' => 'Synthetic', 'last_name' => 'Patient', 'patient_group_id' => 1]);
    }
    foreach ($receipts as $index => $receipt) {
        $cancelled = $receipt['cancelled'] ?? false;
        unset($receipt['cancelled']);
        $row = array_replace(['id' => $index + 1, 'patient_id' => 53, 'amount' => 100, 'currency' => 'USD',
            'payment_method' => 'cash', 'paid_at' => '2025-01-01', 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01'], $receipt);
        if ($cancelled) {
            $row += ['lock_version' => 1, 'deleted_at' => '2025-02-01', 'cancelled_at' => '2025-02-01',
                'cancelled_by' => 1, 'cancellation_reason' => 'Synthetic prior cancellation'];
        }
        DB::table('partner_patient_payments')->insert($row);
        if ($cancelled) {
            DB::table('partner_payment_audits')->insert(['partner_patient_payment_id' => $row['id'], 'user_id' => 1,
                'actor_name' => 'Synthetic Owner', 'action' => 'cancelled', 'operation_key' => (string) \Illuminate\Support\Str::uuid(),
                'payload_hash' => str_repeat('0', 64), 'old_values' => json_encode(['amount' => $row['amount']]), 'new_values' => json_encode($row)]);
        }
    }
    foreach ($expenses as $expense) {
        DB::table('partner_finance_transactions')->insert(array_replace(['source' => 'israeli', 'type' => 'expense',
            'from_account' => 'cash', 'amount' => 50, 'currency' => 'USD', 'transacted_at' => '2025-01-01'], $expense));
    }
}
