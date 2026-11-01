<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\Supplier;
use OCA\InventoryCheck\Db\SupplierMapper;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\CodeRules;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** F-14 supplier registry — office+ read, office+ write (audited). */
class SupplierController extends Controller
{
	use PolicyAuditTrait;

	public function __construct(
		IRequest $request,
		private readonly SupplierMapper $suppliers,
		private readonly AccessControlService $access,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse(['data' => array_map(
			static fn (Supplier $s) => $s->toApi(),
			$this->suppliers->listActive(),
		)]);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$p = $this->request->getParams();
		$name = CodeRules::trim((string)($p['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 200) {
			throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'validation_failed']]);
		}
		$email = CodeRules::trim((string)($p['email'] ?? ''));
		if ($email !== '' && (mb_strlen($email) > 320 || !str_contains($email, '@') || preg_match('/[\r\n]/', $email))) {
			throw new ValidationException('validation_failed', '', [['field' => 'email', 'code' => 'validation_failed']]);
		}
		$note = CodeRules::trim((string)($p['note'] ?? ''));
		if (mb_strlen($note) > 512) {
			throw new ValidationException('validation_failed', '', [['field' => 'note', 'code' => 'validation_failed']]);
		}
		$s = new Supplier();
		$s->setName($name);
		$s->setEmail($email !== '' ? $email : null);
		$s->setNote($note !== '' ? $note : null);
		$s->setActive(true);
		$s = $this->suppliers->insert($s);
		$this->auditPolicyChange($uid, 'supplier_created', ['id' => $s->getId(), 'name' => $name]);
		return new JSONResponse($s->toApi(), 201);
	}
}
