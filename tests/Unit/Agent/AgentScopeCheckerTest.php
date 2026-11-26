<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Agent;

use OCA\InventoryCheck\Agent\AgentException;
use OCA\InventoryCheck\Agent\AgentScopeChecker;
use PHPUnit\Framework\TestCase;

/**
 * Scope vocabulary is exact-match only — the checks an attacker would
 * probe first (wildcards, prefix tricks, case games) must all fail closed.
 */
class AgentScopeCheckerTest extends TestCase
{
	public function testValidFormatsAccepted(): void
	{
		$this->assertTrue(AgentScopeChecker::isValidFormat('inventorycheck:time_entries:read'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('inventorycheck:invoices:write'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('a1_b2:c3_d4:write'));
	}

	public function testInvalidFormatsRejected(): void
	{
		$cases = [
			'',
			'inventorycheck:time_entries',                 // missing action
			'inventorycheck:time_entries:read:extra',      // too many segments
			'inventorycheck:Time_Entries:READ',            // case games
			'inventorycheck:*:read',                       // wildcard resource
			'inventorycheck:time_entries:*',               // wildcard action
			'*:*:*',
			'inventorycheck:*',                            // app-wide wildcard
			'inventorycheck :time_entries:read',           // embedded space
			"inventorycheck:time_entries:read\n",          // control chars
			'inventorycheck:time-entries:read',            // dash not allowed
			'inventorycheck::read',                        // empty resource
			':time_entries:read',                      // empty app
			'inventorycheck:time_entries:delete',          // v1 action set is read|write only
		];
		foreach ($cases as $scope) {
			$this->assertFalse(
				AgentScopeChecker::isValidFormat($scope),
				"scope should be invalid: " . var_export($scope, true)
			);
		}
	}

	public function testPrefixGrantIsNotSatisfaction(): void
	{
		// granted scope that is a string-prefix of the requirement must NOT
		// satisfy — exact match is the whole security model.
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['inventorycheck:time_ent'],
				'inventorycheck:time_entries:write',
			)
		);
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['inventorycheck:time_entries:write_extra'],
				'inventorycheck:time_entries:write',
			)
		);
	}

	public function testReadDoesNotSatisfyWrite(): void
	{
		$granted = ['inventorycheck:time_entries:read'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'inventorycheck:time_entries:write'),
			'read scope must never satisfy a write requirement'
		);
		$this->assertTrue(AgentScopeChecker::satisfied($granted, 'inventorycheck:time_entries:read'));
	}

	public function testPrefixMatchIsNotSatisfaction(): void
	{
		$granted = ['inventorycheck:time_entr:write', 'inventorycheck:time_entries_extended:write'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'inventorycheck:time_entries:write'),
			'prefix/partial matches must not satisfy'
		);
	}

	public function testValidateGrantRequestDeduplicatesAndValidates(): void
	{
		$declared = ['inventorycheck:a:read', 'inventorycheck:b:write'];
		$grants = AgentScopeChecker::validateGrantRequest(
			['inventorycheck:a:read', ' inventorycheck:a:read ', 'inventorycheck:b:write'],
			$declared,
		);
		$this->assertSame(['inventorycheck:a:read', 'inventorycheck:b:write'], $grants);
	}

	public function testValidateGrantRequestRejectsUndeclared(): void
	{
		$this->expectException(AgentException::class);
		AgentScopeChecker::validateGrantRequest(
			['inventorycheck:secret:read'],
			['inventorycheck:a:read'],
		);
	}

	public function testValidateGrantRequestRejectsWildcard(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['inventorycheck:*:read'], ['inventorycheck:a:read']);
			$this->fail('wildcard grant must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsEmpty(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['  '], ['inventorycheck:a:read']);
			$this->fail('empty grant list must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsNonString(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest([['nested']], ['inventorycheck:a:read']);
			$this->fail('non-string scope must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}
}
