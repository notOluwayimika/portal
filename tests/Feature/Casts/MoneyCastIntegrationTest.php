<?php

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
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

/*
 * Serialization behaviour of the VIRTUAL cast key. `balance` is not a database
 * column — it is a virtual attribute backed by balance_kobo + balance_currency.
 * Laravel's attributesToArray() only casts keys present in $attributes, so the
 * virtual key never appears in raw model serialization; the raw columns do
 * (integer + char(3) — still never a decimal). Money therefore reaches the wire
 * through an API Resource (or accessor call) that explicitly reads the
 * attribute, which serialises via the VO's canonical jsonSerialize() shape.
 */
it('omits the virtual money key from raw model toArray()/json_encode but exposes the raw columns', function () {
    $model = new MoneyCastProbe;
    $model->balance = Money::fromNaira('1234.56');
    $model->save();

    $fresh = MoneyCastProbe::query()->find($model->id);
    $array = $fresh->toArray();

    expect($array)->not->toHaveKey('balance')
        ->and($array['balance_kobo'])->toBe(123456)
        ->and($array['balance_currency'])->toBe('NGN')
        ->and(json_decode(json_encode($fresh), true))->not->toHaveKey('balance');
});

it('serialises money through an API Resource reading the attribute, in the canonical wire shape', function () {
    $model = new MoneyCastProbe;
    $model->balance = Money::fromNaira('1234.56');
    $model->save();

    $resource = new class(MoneyCastProbe::query()->find($model->id)) extends JsonResource
    {
        public function toArray($request): array
        {
            return ['balance' => $this->balance];
        }
    };

    $json = json_decode($resource->toResponse(request())->getContent(), true);

    expect($json['data']['balance'])->toBe([
        'amount_minor' => 123456,
        'currency' => 'NGN',
    ]);
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
