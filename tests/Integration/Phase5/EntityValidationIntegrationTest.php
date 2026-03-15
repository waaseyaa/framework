<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase5;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\User\User;
use Waaseyaa\Validation\Constraint\AllowedValues;
use Waaseyaa\Validation\Constraint\NotEmpty;
use Waaseyaa\Validation\Constraint\SafeMarkup;
use Waaseyaa\Validation\ConstraintFactory;

/**
 * Integration tests for waaseyaa/validation + waaseyaa/entity (User).
 *
 * Verifies that EntityValidator correctly validates User entity fields
 * using NotEmpty, AllowedValues, and SafeMarkup constraints.
 */
#[CoversNothing]
final class EntityValidationIntegrationTest extends TestCase
{
    private EntityValidator $entityValidator;

    protected function setUp(): void
    {
        // Build a Symfony Validator that knows about Waaseyaa custom constraint validators.
        $validator = Validation::createValidatorBuilder()
            ->getValidator();

        $this->entityValidator = new EntityValidator($validator);
    }

    // ---- NotEmpty constraint tests ----

    public function testNotEmptyPassesWithValidUserName(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'admin',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new NotEmpty()],
        ]);

        $this->assertCount(0, $violations, 'Valid user name should produce no violations.');
    }

    public function testNotEmptyFailsWithEmptyUserName(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => '',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new NotEmpty()],
        ]);

        $this->assertCount(1, $violations, 'Empty user name should produce one violation.');
        $this->assertSame('name', $violations->get(0)->getPropertyPath());
        $this->assertSame('This value must not be empty.', $violations->get(0)->getMessage());
    }

    public function testNotEmptyFailsWithNullUserName(): void
    {
        $user = new User([
            'uid' => 1,
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new NotEmpty()],
        ]);

        $this->assertCount(1, $violations, 'Null user name should produce one violation.');
        $this->assertSame('name', $violations->get(0)->getPropertyPath());
    }

    public function testNotEmptyFailsWithWhitespaceOnlyUserName(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => '   ',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new NotEmpty()],
        ]);

        $this->assertCount(1, $violations, 'Whitespace-only user name should produce one violation.');
    }

    // ---- AllowedValues constraint tests ----

    public function testAllowedValuesPassesWithValidStatus(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'admin',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'status' => [new AllowedValues(values: [0, 1])],
        ]);

        $this->assertCount(0, $violations, 'Status value 1 should be valid (0 or 1).');
    }

    public function testAllowedValuesPassesWithBlockedStatus(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'blocked',
            'status' => 0,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'status' => [new AllowedValues(values: [0, 1])],
        ]);

        $this->assertCount(0, $violations, 'Status value 0 should be valid (0 or 1).');
    }

    public function testAllowedValuesFailsWithInvalidStatus(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'invalid',
            'status' => 99,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'status' => [new AllowedValues(values: [0, 1])],
        ]);

        $this->assertCount(1, $violations, 'Status value 99 should be invalid.');
        $this->assertSame('status', $violations->get(0)->getPropertyPath());
    }

    // ---- SafeMarkup constraint tests ----

    public function testSafeMarkupPassesWithPlainText(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'John Doe',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new SafeMarkup()],
        ]);

        $this->assertCount(0, $violations, 'Plain text user name should be safe.');
    }

    public function testSafeMarkupFailsWithScriptTag(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => '<script>alert("xss")</script>',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new SafeMarkup()],
        ]);

        $this->assertCount(1, $violations, 'Script tag in user name should be flagged as dangerous.');
        $this->assertSame('name', $violations->get(0)->getPropertyPath());
        $this->assertSame('The text contains potentially dangerous markup.', $violations->get(0)->getMessage());
    }

    public function testSafeMarkupFailsWithEventHandler(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => '<img onerror="alert(1)" src="x">',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new SafeMarkup()],
        ]);

        $this->assertCount(1, $violations, 'Event handler attribute should be flagged as dangerous.');
    }

    public function testSafeMarkupFailsWithJavascriptUri(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => '<a href="javascript:alert(1)">click</a>',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new SafeMarkup()],
        ]);

        $this->assertCount(1, $violations, 'javascript: URI should be flagged as dangerous.');
    }

    // ---- Combined constraint tests ----

    public function testMultipleConstraintsOnSingleField(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => '',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new NotEmpty(), new SafeMarkup()],
        ]);

        // Empty string triggers NotEmpty; SafeMarkup allows empty strings (returns early).
        $this->assertCount(1, $violations, 'Only NotEmpty should fire for empty name.');
    }

    public function testMultipleFieldsValidatedTogether(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => '',
            'status' => 99,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new NotEmpty()],
            'status' => [new AllowedValues(values: [0, 1])],
        ]);

        $this->assertCount(2, $violations, 'Both name and status violations should be collected.');

        $paths = [];
        for ($i = 0; $i < $violations->count(); $i++) {
            $paths[] = $violations->get($i)->getPropertyPath();
        }
        $this->assertContains('name', $paths);
        $this->assertContains('status', $paths);
    }

    // ---- ConstraintFactory tests ----

    public function testConstraintFactoryCreatesNotEmpty(): void
    {
        $constraint = ConstraintFactory::required();
        $this->assertInstanceOf(NotEmpty::class, $constraint);

        $user = new User([
            'uid' => 1,
            'name' => '',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [$constraint],
        ]);

        $this->assertCount(1, $violations);
    }

    public function testConstraintFactoryCreatesAllowedValues(): void
    {
        $constraint = ConstraintFactory::allowedValues([0, 1]);
        $this->assertInstanceOf(AllowedValues::class, $constraint);

        $user = new User([
            'uid' => 1,
            'name' => 'test',
            'status' => 2,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'status' => [$constraint],
        ]);

        $this->assertCount(1, $violations);
    }

    public function testConstraintFactoryCreatesSafeMarkup(): void
    {
        $constraint = ConstraintFactory::safeMarkup();
        $this->assertInstanceOf(SafeMarkup::class, $constraint);

        $user = new User([
            'uid' => 1,
            'name' => '<script>alert("xss")</script>',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [$constraint],
        ]);

        $this->assertCount(1, $violations);
    }

    // ---- Valid entity passes all constraints ----

    public function testValidUserEntityPassesAllConstraints(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'John Doe',
            'mail' => 'john@example.com',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new NotEmpty(), new SafeMarkup()],
            'mail' => [new NotEmpty()],
            'status' => [new AllowedValues(values: [0, 1])],
        ]);

        $this->assertCount(0, $violations, 'A valid user should produce no violations.');
    }

    // ---- Custom error messages ----

    public function testCustomErrorMessageIsUsed(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => '',
            'status' => 1,
        ]);

        $violations = $this->entityValidator->validate($user, [
            'name' => [new NotEmpty(message: 'Username is required.')],
        ]);

        $this->assertCount(1, $violations);
        $this->assertSame('Username is required.', $violations->get(0)->getMessage());
    }
}
