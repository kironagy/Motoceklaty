<?php

namespace Tests\Feature\Agent;

use App\Domain\Applications\Validators\FieldValidatorRegistry;
use App\Models\RequirementField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldValidatorsTest extends TestCase
{
    use RefreshDatabase;

    private function field(string $dataType, array $overrides = []): RequirementField
    {
        return RequirementField::create(array_merge([
            'key' => $dataType.'_field',
            'label' => 'Test',
            'data_type' => $dataType,
            'scope' => 'customer',
        ], $overrides));
    }

    public function test_national_id_validator(): void
    {
        $registry = app(FieldValidatorRegistry::class);
        $field = $this->field('national_id');

        $ok = $registry->for('national_id')->validate('29005132101234', $field);
        $this->assertTrue($ok->valid);
        $this->assertSame('29005132101234', $ok->normalized);
        $this->assertSame('1990-05-13', $ok->facts['birthdate']);

        $bad = $registry->for('national_id')->validate('123', $field);
        $this->assertFalse($bad->valid);
    }

    public function test_phone_validator_accepts_with_and_without_country_code(): void
    {
        $registry = app(FieldValidatorRegistry::class);
        $field = $this->field('phone');

        $this->assertSame('01012345678', $registry->for('phone')->validate('01012345678', $field)->normalized);
        $this->assertSame('01012345678', $registry->for('phone')->validate('+201012345678', $field)->normalized);
        $this->assertSame('01012345678', $registry->for('phone')->validate('201012345678', $field)->normalized);
        $this->assertFalse($registry->for('phone')->validate('0201234567', $field)->valid);
        $this->assertFalse($registry->for('phone')->validate('02123456789', $field)->valid);
    }

    public function test_date_validator(): void
    {
        $registry = app(FieldValidatorRegistry::class);
        $field = $this->field('date');

        $this->assertTrue($registry->for('date')->validate('2024-01-15', $field)->valid);
        $this->assertFalse($registry->for('date')->validate('not a date', $field)->valid);
    }

    public function test_money_validator(): void
    {
        $registry = app(FieldValidatorRegistry::class);
        $field = $this->field('money');

        $this->assertSame(1500.0, $registry->for('money')->validate('1,500', $field)->normalized);
        $this->assertFalse($registry->for('money')->validate('-5', $field)->valid);
        $this->assertFalse($registry->for('money')->validate('abc', $field)->valid);
    }

    public function test_integer_validator(): void
    {
        $registry = app(FieldValidatorRegistry::class);
        $field = $this->field('integer');

        $this->assertSame(42, $registry->for('integer')->validate('42', $field)->normalized);
        $this->assertFalse($registry->for('integer')->validate('4.2', $field)->valid);
    }

    public function test_enum_validator(): void
    {
        $registry = app(FieldValidatorRegistry::class);
        $field = $this->field('enum', ['enum_options' => ['a', 'b']]);

        $this->assertTrue($registry->for('enum')->validate('a', $field)->valid);
        $this->assertFalse($registry->for('enum')->validate('c', $field)->valid);
    }

    public function test_person_name_address_and_string_reject_empty(): void
    {
        $registry = app(FieldValidatorRegistry::class);

        foreach (['person_name', 'address', 'string'] as $type) {
            $field = $this->field($type);
            $this->assertTrue($registry->for($type)->validate('محمد أحمد', $field)->valid);
            $this->assertFalse($registry->for($type)->validate('   ', $field)->valid);
        }
    }
}
