<?php

namespace Codecov\LaravelCodecovOpenTelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\Trace\Span;
use OpenTelemetry\Trace\SpanStatus;
use OpenTelemetry\Trace\Tracer;

/**
 * Trace an incoming HTTP request.
 */
class Trace
{
    /**
     * @var Tracer OpenTelemetry Tracer
     */
    private $tracer;
    private $sampleRate;

    public function __construct(Tracer $tracer = null)
    {
        $this->tracer = $tracer;
        //For dev
        $this->sampleRate = 50;
        //for prod
        //$this->sampleRate = config('laravel_codecov_opentelemetry.sample_rate');
    }

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        //don't trace if there's no tracer
        if (!$this->tracer) {
            return $next($request);
        }

        $shouldSample = $this->sampleRate > rand(0, 100) ? true : false;

        // if (config('laravel_codecov_opentelemetry.tags.line_execution') && extension_loaded('pcov') && $shouldSample) {
        //     \pcov\start();
        // }

        //For Dev
        if (extension_loaded('pcov') && $shouldSample) {
            \Log::info('found pcov');
            \pcov\start();
        }

        $span = $this->tracer->startAndActivateSpan('http_'.strtolower($request->method()));
        $response = $next($request);

        $this->setSpanStatus($span, $response->status());
        $this->addConfiguredTags($span, $request, $response);

        // if (config('laravel_codecov_opentelemetry.tags.line_execution') && extension_loaded('pcov') && $shouldSample ) {
        //     \pcov\stop();
        //
        //     $span->setAttribute('codecov.type', 'bytes');
        //      $span->setAttribute('codecov.coverage', base64_encode($coverage));
        // }

        //FOR DEV
        if (extension_loaded('pcov') && $shouldSample) {
            \pcov\stop();
            $coverage = \pcov\collect();
            $span->setAttribute('codecov.type', 'bytes');
            $span->setAttribute('codecov.coverage', base64_encode(json_encode($coverage)));
        }

        $this->tracer->endActiveSpan();

        return $response;
    }

    private function setSpanStatus(Span $span, int $httpStatusCode)
    {
        switch ($httpStatusCode) {
            case 400:
                $span->setSpanStatus(SpanStatus::FAILED_PRECONDITION, SpanStatus::DESCRIPTION[SpanStatus::FAILED_PRECONDITION]);

                return;

            case 401:
                $span->setSpanStatus(SpanStatus::UNAUTHENTICATED, SpanStatus::DESCRIPTION[SpanStatus::UNAUTHENTICATED]);

                return;

            case 403:
                $span->setSpanStatus(SpanStatus::PERMISSION_DENIED, SpanStatus::DESCRIPTION[SpanStatus::PERMISSION_DENIED]);

                return;

            case 404:
                $span->setSpanStatus(SpanStatus::NOT_FOUND, SpanStatus::DESCRIPTION[SpanStatus::NOT_FOUND]);

                return;
        }

        if ($httpStatusCode >= 500 && $httpStatusCode < 600) {
            $span->setSpanStatus(SpanStatus::ERROR, SpanStatus::DESCRIPTION[SpanStatus::ERROR]);
        }

        if ($httpStatusCode >= 200 && $httpStatusCode < 300) {
            $span->setSpanStatus(SpanStatus::OK, SpanStatus::DESCRIPTION[SpanStatus::OK]);
        }
    }

    private function addConfiguredTags(Span $span, Request $request, $response)
    {
        $span->setAttribute('http.status_code', $response->status());
        $span->setAttribute('http.method', $request->method());
        $span->setAttribute('http.host', $request->root());
        $span->setAttribute('http.target', '/'.$request->path());
        $span->setAttribute('http.scheme', $request->secure() ? 'https' : 'http');
        $span->setAttribute('http.flavor', $_SERVER['SERVER_PROTOCOL']);
        $span->setAttribute('http.server_name', $request->server('SERVER_ADDR'));
        $span->setAttribute('http.user_agent', $request->userAgent());
        $span->setAttribute('net.host.port', $request->server('SERVER_PORT'));
        $span->setAttribute('net.peer.ip', $request->ip());
        $span->setAttribute('net.peer.port', $_SERVER['REMOTE_PORT']);
    }
}
