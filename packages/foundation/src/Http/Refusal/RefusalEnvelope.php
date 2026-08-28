<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Refusal;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

/**
 * Renders a {@see HttpRefusal} in the wire vocabulary the matched route
 * advertises, defaulting to the framework's JSON:API error envelope.
 *
 * An endpoint that advertises a non-JSON:API transport must refuse in that
 * transport's vocabulary, or the refusal is a shape its client cannot
 * interpret. Before this seam existed, `BodySizeLimitMiddleware`'s 413 and the
 * kernel's malformed-JSON 400 both answered ahead of the MCP endpoint's own
 * transport guard, so the endpoint's oversize-body and parse-error JSON-RPC
 * refusals were unreachable (#2594).
 *
 * The route declares the vocabulary as plain, cacheable route options — a
 * transport name plus a `reason => error code` map — so no upward import is
 * needed: Layer 0 never learns what MCP is, and MCP keeps ownership of its own
 * error codes. {@see \Waaseyaa\Routing\RouteBuilder::refusalTransport()} is the
 * declaration surface; the kernel resolves the options once during route
 * matching and puts the resulting envelope on the request as
 * {@see self::REQUEST_ATTRIBUTE}.
 *
 * Codes are never invented. A route that declares a transport but no code for
 * the reason at hand falls back to the JSON:API envelope, which is a shape
 * every client can at least read the status out of.
 *
 * @api
 */
final readonly class RefusalEnvelope
{
    // The JSON:API branch must be the framework's one JSON:API encoding, not a
    // second copy of it: `application/vnd.api+json` and the same encoding
    // options every other kernel-emitted JSON:API document uses.
    use JsonApiResponseTrait;

    /** Request attribute holding the resolved envelope for the matched route. */
    public const string REQUEST_ATTRIBUTE = '_refusal_envelope';

    /** Route option naming the transport vocabulary refusals are rendered in. */
    public const string TRANSPORT_OPTION = '_refusal_transport';

    /** Route option mapping a refusal reason to that transport's error code. */
    public const string CODES_OPTION = '_refusal_codes';

    /** JSON-RPC 2.0 error objects: `{"jsonrpc":"2.0","error":{...},"id":null}`. */
    public const string TRANSPORT_JSON_RPC = 'jsonrpc';

    /** The request body exceeded the kernel's configured size cap. */
    public const string REASON_PAYLOAD_TOO_LARGE = 'payload_too_large';

    /** The request body was not a decodable JSON document. */
    public const string REASON_PARSE_ERROR = 'parse_error';

    /** @param array<string, int> $codes reason => transport error code */
    private function __construct(
        private ?string $transport,
        private array $codes,
    ) {}

    /** The default envelope: the framework's JSON:API error document. */
    public static function jsonApi(): self
    {
        return new self(null, []);
    }

    /**
     * Resolve the envelope a matched route declares.
     *
     * Unknown, absent, or ill-typed options degrade to {@see self::jsonApi()};
     * a malformed route declaration must not be able to turn a refusal into a
     * server error.
     *
     * @param array<string, mixed> $options The matched route's options.
     */
    public static function fromRouteOptions(array $options): self
    {
        $transport = $options[self::TRANSPORT_OPTION] ?? null;
        if (!is_string($transport) || $transport === '') {
            return self::jsonApi();
        }

        $declared = $options[self::CODES_OPTION] ?? null;
        $codes = [];
        if (is_array($declared)) {
            foreach ($declared as $reason => $code) {
                if (is_string($reason) && is_int($code)) {
                    $codes[$reason] = $code;
                }
            }
        }

        return new self($transport, $codes);
    }

    /**
     * The envelope carried by a request, or the JSON:API default.
     *
     * Requests that never went through kernel route matching — unit-tested
     * middleware, sub-requests — carry no attribute and get the default.
     */
    public static function forRequest(Request $request): self
    {
        $envelope = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $envelope instanceof self ? $envelope : self::jsonApi();
    }

    public function refuse(HttpRefusal $refusal): JsonResponse
    {
        $code = $this->codes[$refusal->reason] ?? null;

        if ($this->transport === self::TRANSPORT_JSON_RPC && $code !== null) {
            $error = ['code' => $code, 'message' => $refusal->message()];
            if ($refusal->transportData !== []) {
                $error['data'] = $refusal->transportData;
            }

            return new JsonResponse(
                ['jsonrpc' => '2.0', 'error' => $error, 'id' => null],
                $refusal->status,
            );
        }

        $error = ['status' => (string) $refusal->status, 'title' => $refusal->title];
        if ($refusal->detail !== null) {
            $error['detail'] = $refusal->detail;
        }

        return $this->jsonApiResponse($refusal->status, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [$error],
        ]);
    }
}
