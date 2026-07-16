<?php

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * End-to-end proof that MoneyCast round-trips through a real Eloquent model via
 * $casts (not just called directly). Uses a throwaway table; DDL auto-commits on
 * MySQL, so the table is created/dropped explicitly rather than relying on the
 * RefreshDatabase transaction.
 */
beforeEach(function () {
    Schema::create('money_cast_probes', function ($table) {
        $table->id();
        $table->bigInteger('balance_kobo')->nullable();
        $table->char('balance_currency', 3)->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('money_cast_probes');
});

it('round-trips a Money through a real model using the cast in $casts', function () {
    $model = new MoneyCastProbe;
    $model->balance = Money::fromNaira('1234.56');
    $model->save();

    $fresh = MoneyCastProbe::query()->find($model->id);

    expect($fresh->balance)->toBeInstanceOf(Money::class)
        ->and($fresh->balance->toKobo())->toBe(123456)
        ->and($fresh->balance->currency)->toBe('NGN')
        // stored columns hold the raw integer + currency, not a serialized blob
        ->and((int) $fresh->getRawOriginal('balance_kobo'))->toBe(123456)
        ->and($fresh->getRawOriginal('balance_currency'))->toBe('NGN');
});

it('round-trips a null money attribute as null', function () {
    $model = new MoneyCastProbe;
    $model->balance = null;
    $model->save();

    $fresh = MoneyCastProbe::query()->find($model->id);

    expect($fresh->balance)->toBeNull()
        ->and($fresh->getRawOriginal('balance_kobo'))->toBeNull();
});

class MoneyCastProbe extends Model
{
    protected $table = 'money_cast_probes';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'balance' => MoneyCast::class.':balance_kobo,balance_currency',
    ];
}
