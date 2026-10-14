<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service\Lookup;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/**
 * Shared HTTP fakes for provider tests — records the request, replays a
 * queued response/exception. No real network, ever.
 */
final class FakeResponse implements IResponse
{
	public function __construct(
		private readonly int $status,
		private readonly string $body,
	) {
	}

	public function getBody()
	{
		return $this->body;
	}

	public function getStatusCode(): int
	{
		return $this->status;
	}

	public function getHeader(string $key): string
	{
		return '';
	}

	public function getHeaders(): array
	{
		return [];
	}
}

final class FakeClient implements IClient
{
	/** @var list<array{0:string,1:array}> */
	public array $requests = [];
	/** @var list<IResponse|\Throwable> */
	public array $queue;

	/**
	 * @param list<IResponse|\Throwable> $queue
	 */
	public function __construct(array $queue)
	{
		$this->queue = $queue;
	}

	public function get(string $uri, array $options = []): IResponse
	{
		$this->requests[] = [$uri, $options];
		$next = array_shift($this->queue);
		if ($next instanceof \Throwable) {
			throw $next;
		}
		if (!$next instanceof IResponse) {
			throw new \RuntimeException('FakeClient queue empty');
		}
		return $next;
	}

	private function notImplemented(): never
	{
		throw new \LogicException('not implemented in fake');
	}

	public function head(string $uri, array $options = []): IResponse
	{
		$this->notImplemented();
	}

	public function post(string $uri, array $options = []): IResponse
	{
		$this->notImplemented();
	}

	public function put(string $uri, array $options = []): IResponse
	{
		$this->notImplemented();
	}

	public function patch(string $uri, array $options = []): IResponse
	{
		$this->notImplemented();
	}

	public function delete(string $uri, array $options = []): IResponse
	{
		$this->notImplemented();
	}

	public function options(string $uri, array $options = []): IResponse
	{
		$this->notImplemented();
	}

	public function getResponseFromThrowable(\Throwable $e): IResponse
	{
		$this->notImplemented();
	}

	public function request(string $method, string $uri, array $options = []): IResponse
	{
		$this->notImplemented();
	}

	public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
	{
		$this->notImplemented();
	}

	public function getAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
	{
		$this->notImplemented();
	}

	public function headAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
	{
		$this->notImplemented();
	}

	public function postAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
	{
		$this->notImplemented();
	}

	public function putAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
	{
		$this->notImplemented();
	}

	public function deleteAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
	{
		$this->notImplemented();
	}

	public function optionsAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
	{
		$this->notImplemented();
	}
}

final class FakeClientService implements IClientService
{
	public function __construct(public readonly FakeClient $client)
	{
	}

	public function newClient(?callable $handler = null): IClient
	{
		return $this->client;
	}
}
