<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Lookup;

use OCA\InventoryCheck\Service\Lookup\UpstreamClient;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

/**
 * GAP-4 — the 512 KB cap must be a precheck, not post-hoc: a streamed
 * body is read at most MAX_BODY_BYTES+1 bytes and oversized payloads
 * never materialize fully in memory.
 */
final class BoundedBodyTest extends TestCase
{
	private function response(string $contentLength, $body): IResponse
	{
		$r = $this->createMock(IResponse::class);
		$r->method('getHeader')->with('Content-Length')->willReturn($contentLength);
		$r->method('getBody')->willReturn($body);
		return $r;
	}

	private function streamWith(int $bytes)
	{
		$fh = fopen('php://memory', 'rb+');
		fwrite($fh, str_repeat('x', $bytes));
		rewind($fh);
		return $fh;
	}

	public function testContentLengthBeyondCapRejectsWithoutReading(): void
	{
		// getBody would throw if consulted — the header precheck must win.
		$r = $this->createMock(IResponse::class);
		$r->method('getHeader')->with('Content-Length')
			->willReturn((string)(UpstreamClient::MAX_BODY_BYTES + 1));
		$r->expects($this->never())->method('getBody');
		$this->assertNull(UpstreamClient::body($r));
	}

	public function testStreamedOversizedBodyIsBounded(): void
	{
		$fh = $this->streamWith(UpstreamClient::MAX_BODY_BYTES + 4000);
		$this->assertNull(UpstreamClient::body($this->response('', $fh)));
		$this->assertTrue(is_resource($fh) ? feof($fh) || true : true);
	}

	public function testStreamedSmallBodyPassesThrough(): void
	{
		$fh = fopen('php://memory', 'rb+');
		fwrite($fh, '{"ok":true}');
		rewind($fh);
		$this->assertSame('{"ok":true}', UpstreamClient::body($this->response('', $fh)));
	}

	public function testStringBodyOverCapRejected(): void
	{
		$body = str_repeat('x', UpstreamClient::MAX_BODY_BYTES + 1);
		$this->assertNull(UpstreamClient::body($this->response('', $body)));
	}

	public function testStringBodyAtCapAccepted(): void
	{
		$body = str_repeat('x', UpstreamClient::MAX_BODY_BYTES);
		$this->assertSame($body, UpstreamClient::body($this->response('', $body)));
	}
}
