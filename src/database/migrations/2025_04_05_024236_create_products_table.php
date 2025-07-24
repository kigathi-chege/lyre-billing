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
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->timestamps();

                $table->string("name");
                $table->string("slug")->unique()->index();
                $table->text("description")->nullable();
                $table->enum("pricing_model", config('app.product_pricing_models'))->default('free');

                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasColumn('products', 'status')) {
            Schema::table('products', function (Blueprint $table) {
                $table->enum('status', ['active', 'inactive'])->default('active');
            });
        }

        if (!Schema::hasColumn('products', 'max_product_entities')) {
            Schema::table('products', function (Blueprint $table) {
                $table->integer('max_product_entities')->default(1)->comment('The maximum number of product entities that can be created for this product, 0 means unlimited');
            });
        }

        if (!Schema::hasColumn('products', 'dynamic_entities')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('dynamic_entities')->default(false)->comment('Whether or not the product entities should be dynamically selected at the point of purchase');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
