<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('mediaman.tables.collections'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->integer('max_items')->nullable();
            $table->json('allowed_mime_types')->nullable();
            $table->string('fallback_url')->nullable();
            $table->string('fallback_path')->nullable();
            $table->timestamps();
        });

        $collection = resolve(config('mediaman.models.collection'));
        $collection->name = 'Default';
        $collection->save();

        Schema::create(config('mediaman.tables.media'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('disk');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type');
            $table->unsignedInteger('size');
            $table->json('custom_properties')->nullable();
            $table->timestamps();
        });

        Schema::create(config('mediaman.tables.collection_media'), function (Blueprint $table) {
            $table->unsignedBigInteger('collection_id')
                ->constraint(config('mediaman.tables.collections'))
                ->cascadeOnDelete();

            $table->unsignedBigInteger('media_id')
                ->constraint(config('mediaman.tables.media'))
                ->cascadeOnDelete();

            $table->primary(['collection_id', 'media_id']);
        });

        Schema::create(config('mediaman.tables.mediables'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('media_id')->index();
            $table->unsignedBigInteger('mediable_id')->index();
            $table->string('mediable_type');
            $table->string('channel');

            $table->foreign('media_id')
                ->references('id')
                ->on(config('mediaman.tables.media'))
                ->onDelete('cascade');

            $table->index(['mediable_type', 'mediable_id', 'channel']);
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mediaman.tables.collections'));
        Schema::dropIfExists(config('mediaman.tables.media'));
        Schema::dropIfExists(config('mediaman.tables.collection_media'));
        Schema::dropIfExists(config('mediaman.tables.mediables'));
        Schema::dropIfExists('subjects');
    }
};
