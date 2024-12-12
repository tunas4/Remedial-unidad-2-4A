<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('letras', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partida_id');
            $table->foreign('partida_id')->references('id')->on('partidas');
            $table->string('palabra');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('letras');
    }
};
