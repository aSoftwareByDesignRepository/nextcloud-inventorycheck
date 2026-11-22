<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\ItemField;
use OCA\InventoryCheck\Db\ItemFieldMapper;
use OCA\InventoryCheck\Db\ItemFieldValueMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\ValidationException;

/**
 * IV-CUSTOM-FIELDS — admin def CRUD + value coercion/validation.
 * Office+ writes (same trust class as item edit, NN-02); everyone reads.
 * Required is enforced on write only — never on read (NN-03).
 */
class CustomFieldService
{
	public const MAX_DEFS_SOFT_CAP = 50;

	public function __construct(
		private readonly AccessControlService $access,
		private readonly ItemFieldMapper $fields,
		private readonly ItemFieldValueMapper $values,
		private readonly Clock $clock,
	) {
	}

	/* ── Def admin (app-admin surface) ─────────────────────────────── */

	/**
	 * Admins get the full def list (incl. inactive); writers/readers get
	 * active defs only — the same defs already merged into every item
	 * payload, so this is not a leak but the dialog's def source.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listDefs(string $actorUid): array
	{
		$defs = $this->access->isAppAdmin($actorUid)
			? $this->fields->all()
			: $this->fields->allActive();
		return array_map(static fn (ItemField $f) => $f->toApi(), $defs);
	}

	/** @return array<string, mixed> */
	public function createDef(string $actorUid, array $input): array
	{
		$this->access->requireAppAdmin($actorUid);
		$name = trim((string)($input['name'] ?? ''));
		$type = (string)($input['type'] ?? '');
		$this->assertValidDef($name, $type);
		if ($this->fields->activeNameExists($name)) {
			throw new ConflictException('field_name_exists');
		}
		if (count($this->fields->allActive()) >= self::MAX_DEFS_SOFT_CAP) {
			// ponytail: 50 defs, upgrade: virtual list — beyond the cap the
			// dialog/CSV surface degrades, so admins clean up first.
			throw new ConflictException('field_cap_reached');
		}
		$field = new ItemField();
		$field->setName($name);
		$field->setType($type);
		$field->setOptionsJson($this->encodeOptions($type, $input['options'] ?? null));
		$field->setRequired((bool)($input['required'] ?? false));
		$field->setPosition($this->fields->nextPosition());
		$field->setActive(true);
		$field->setCreatedAt($this->clock->now());
		return $this->fields->insert($field)->toApi();
	}

	/** @return array<string, mixed> */
	public function updateDef(string $actorUid, int $id, array $input): array
	{
		$this->access->requireAppAdmin($actorUid);
		$field = $this->fields->findById($id);
		if (array_key_exists('type', $input) && $input['type'] !== $field->getType()) {
			// Type is immutable — a type change invalidates stored values;
			// delete+recreate is the documented path (spec §9).
			throw new ValidationException('field_type_immutable');
		}
		if (array_key_exists('name', $input)) {
			$name = trim((string)$input['name']);
			if ($name === '' || mb_strlen($name) > 64) {
				throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'validation_failed']]);
			}
			if ($field->getActive() && $this->fields->activeNameExists($name, $id)) {
				throw new ConflictException('field_name_exists');
			}
			$field->setName($name);
		}
		if (array_key_exists('options', $input) && $field->getType() === 'select') {
			$field->setOptionsJson($this->encodeOptions('select', $input['options']));
		}
		if (array_key_exists('required', $input)) {
			$field->setRequired((bool)$input['required']);
		}
		if (array_key_exists('position', $input)) {
			$field->setPosition(max(0, (int)$input['position']));
		}
		if (array_key_exists('active', $input)) {
			$field->setActive((bool)$input['active']);
		}
		return $this->fields->update($field)->toApi();
	}

	/**
	 * NN-05: hard delete — the caller's confirm copy carries
	 * $affectedCount ("N values will be permanently removed").
	 */
	public function deleteDef(string $actorUid, int $id): int
	{
		$this->access->requireAppAdmin($actorUid);
		$field = $this->fields->findById($id);
		$affected = $this->values->countForField($id);
		$this->values->deleteForField($id);
		$this->fields->delete($field);
		return $affected;
	}

	public function valueCount(int $fieldId): int
	{
		// Uniform-404 contract like update/destroy — unknown id must not
		// answer a shaped 200 to a stale confirm dialog.
		$this->fields->findById($fieldId);
		return $this->values->countForField($fieldId);
	}

	/* ── Values on items ───────────────────────────────────────────── */

	/**
	 * Item-payload block: every active def merged with the item's stored
	 * value. Stale select options stay visible with a `stale` flag.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function payloadForItem(int $itemId): array
	{
		$defs = $this->fields->allActive();
		if ($defs === []) {
			return [];
		}
		$values = $this->values->valuesForItem($itemId);
		return array_map(
			static fn (ItemField $f) => self::mergeValue($f, $values[(int)$f->getId()] ?? null),
			$defs,
		);
	}

	/** @param array<int, array<int, ?string>> $valuesByItem batched variant for lists */
	public function payloadForItems(array $defs, array $valuesByItem, int $itemId): array
	{
		$values = $valuesByItem[$itemId] ?? [];
		return array_map(
			static fn (ItemField $f) => self::mergeValue($f, $values[(int)$f->getId()] ?? null),
			$defs,
		);
	}

	/** @return list<ItemField> */
	public function activeDefs(): array
	{
		return $this->fields->allActive();
	}

	/** @return array<int, array<int, ?string>> */
	public function valuesForItems(array $itemIds): array
	{
		return $this->values->valuesForItems($itemIds);
	}

	/**
	 * Coerce + validate a submitted `customValues` map (field_id → raw).
	 * Throws ValidationException with a fields map keyed `cf_<id>` so the
	 * UI renders inline errors per field (AC-16).
	 *
	 * @param array<string|int, mixed> $submitted
	 * @return array<int, ?string> normalized field_id → value (null = clear)
	 */
	public function validateValues(array $submitted): array
	{
		$defs = $this->fields->byIds(array_map('intval', array_keys($submitted)));
		$errors = [];
		$normalized = [];
		foreach ($submitted as $fieldId => $raw) {
			$fieldId = (int)$fieldId;
			$field = $defs[$fieldId] ?? null;
			if ($field === null || !$field->getActive()) {
				// Unknown/inactive defs are ignored, not errors — a def may
				// deactivate between dialog open and save.
				continue;
			}
			$cell = 'cf_' . $fieldId;
			try {
				$normalized[$fieldId] = $this->coerce($field, $raw);
			} catch (ValidationException $e) {
				$errors[] = ['field' => $cell, 'code' => $e->getMessage()];
			}
		}
		if ($errors !== []) {
			throw new ValidationException('validation_failed', '', $errors);
		}
		return $normalized;
	}

	/**
	 * Required check on write (NN-03): every *active required* def must
	 * have a non-empty value after merge. Called with the post-normalize
	 * map plus the item's current values (update path).
	 */
	public function assertRequiredSatisfied(array $normalized, array $existingValues): void
	{
		$missing = [];
		foreach ($this->fields->allActive() as $field) {
			if (!$field->getRequired()) {
				continue;
			}
			$id = (int)$field->getId();
			$val = array_key_exists($id, $normalized) ? $normalized[$id] : ($existingValues[$id] ?? null);
			if ($val === null || $val === '') {
				$missing[] = ['field' => 'cf_' . $id, 'code' => 'required'];
			}
		}
		if ($missing !== []) {
			throw new ValidationException('required', '', $missing);
		}
	}

	/** Persist a normalized map for an item (call inside the item's tx). */
	public function persistValues(int $itemId, array $normalized): void
	{
		foreach ($normalized as $fieldId => $value) {
			$this->values->set($itemId, $fieldId, $value);
		}
	}

	public function deleteForItem(int $itemId): void
	{
		$this->values->deleteForItem($itemId);
	}

	/**
	 * Field ids whose values join the `q` index: text + select only —
	 * number/date values are not text-searchable (AC-9).
	 *
	 * @return list<int>
	 */
	public function searchableFieldIds(): array
	{
		$ids = [];
		foreach ($this->fields->allActive() as $f) {
			if (in_array($f->getType(), ['text', 'select'], true)) {
				$ids[] = (int)$f->getId();
			}
		}
		return $ids;
	}

	/** @param IQueryBuilder $qb parent's builder — params bind on it */
	public function searchExistsSql(\OCP\DB\QueryBuilder\IQueryBuilder $qb, string $itemIdColumn, string $term): ?string
	{
		return $this->values->valueMatchExistsSql($qb, $itemIdColumn, $term, $this->searchableFieldIds());
	}

	/**
	 * CSV import column map: canonicalized header → active def. Uses the
	 * same pre-alias transform as Csv::canonicalizeHeader (lower +
	 * space/dash → underscore) so exported columns map back by name.
	 *
	 * @return array<string, ItemField>
	 */
	public function columnMap(): array
	{
		$map = [];
		foreach ($this->fields->allActive() as $f) {
			$map[strtolower(str_replace([' ', '-'], '_', trim($f->getName())))] = $f;
		}
		return $map;
	}

	/* ── Internals ─────────────────────────────────────────────────── */

	private function assertValidDef(string $name, string $type): void
	{
		if ($name === '' || mb_strlen($name) > 64) {
			throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'validation_failed']]);
		}
		if (!in_array($type, ItemField::TYPES, true)) {
			// NN-01 scope fence: exactly four types, nothing configurable.
			throw new ValidationException('validation_failed', '', [['field' => 'type', 'code' => 'validation_failed']]);
		}
	}

	private function encodeOptions(string $type, mixed $raw): ?string
	{
		if ($type !== 'select') {
			return null;
		}
		$options = [];
		$list = is_array($raw) ? $raw : (preg_split('/\R/', (string)$raw) ?: []);
		foreach ($list as $opt) {
			$opt = trim((string)$opt);
			if ($opt !== '' && !in_array($opt, $options, true)) {
				$options[] = $opt;
			}
		}
		if ($options === []) {
			throw new ValidationException('validation_failed', '', [['field' => 'options', 'code' => 'required']]);
		}
		return json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/**
	 * Coerce one submitted value to its storage form:
	 * text → ≤1000 chars; number → decimal string; date → epoch int
	 * string; select → must be a current option. '' → null (AC: empty ≠ "0").
	 */
	private function coerce(ItemField $field, mixed $raw): ?string
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		return match ($field->getType()) {
			'text' => $this->coerceText($raw),
			'number' => $this->coerceNumber($raw),
			'date' => $this->coerceDate($raw),
			'select' => $this->coerceSelect($field, $raw),
			default => throw new ValidationException('validation_failed'),
		};
	}

	private function coerceText(mixed $raw): string
	{
		$v = trim((string)$raw);
		if ($v === '' || mb_strlen($v) > ItemField::MAX_VALUE_CHARS) {
			throw new ValidationException('validation_failed');
		}
		return $v;
	}

	private function coerceNumber(mixed $raw): string
	{
		if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric(trim($raw)))) {
			throw new ValidationException('validation_failed');
		}
		return (string)(0 + $raw);
	}

	private function coerceDate(mixed $raw): string
	{
		if (is_int($raw) || (is_string($raw) && ctype_digit(trim($raw)))) {
			$epoch = (int)$raw;
		} elseif (is_string($raw) && strtotime($raw) !== false) {
			$epoch = (int)strtotime($raw);
		} else {
			throw new ValidationException('validation_failed');
		}
		return (string)$epoch;
	}

	private function coerceSelect(ItemField $field, mixed $raw): string
	{
		$v = (string)$raw;
		if (!in_array($v, $field->options(), true)) {
			throw new ValidationException('validation_failed');
		}
		return $v;
	}

	private static function mergeValue(ItemField $field, ?string $value): array
	{
		$api = $field->toApi();
		$api['value'] = $value;
		$api['stale'] = $value !== null && $field->getType() === 'select'
			&& !in_array($value, $field->options(), true);
		return $api;
	}
}
