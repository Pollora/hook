<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

use Pollora\Hook\Async\Exceptions\MissingReferencedObject;

/**
 * Runs a queued handler: resolves it, rebuilds its arguments, restores the
 * site and locale of the original request, calls it.
 *
 * A failure is reported and announced through the 'pollora/async/failed'
 * action; it never escapes, so one failing handler does not stop the others
 * a WP-Cron run holds.
 *
 * @internal
 */
final class AsyncRunner
{
    /** @var array<string, int> Registrations being executed, for the loop guard */
    private array $running = [];

    public function __construct(
        private readonly CallableDescriptor $descriptor,
        private readonly ArgumentNormalizer $normalizer,
    ) {}

    public function run(AsyncPayload $payload): void
    {
        try {
            $callable = $this->descriptor->resolve($payload->handler);
            $arguments = $this->normalizer->denormalize($payload->arguments, $payload->keepMissing);
            $captured = $this->normalizer->denormalize($payload->captured, $payload->keepMissing);
        } catch (MissingReferencedObject) {
            return;
        } catch (\Throwable $throwable) {
            $this->fail($payload, $throwable);

            return;
        }

        $key = QueuedHandler::keyFor($payload->hook, $payload->handler, $payload->priority);
        $this->running[$key] = ($this->running[$key] ?? 0) + 1;
        $leaveContext = $this->enterContext($payload);

        try {
            $this->call($callable, $arguments, $payload->context($captured));
        } catch (\Throwable $throwable) {
            $this->fail($payload, $throwable);
        } finally {
            $leaveContext();

            if (--$this->running[$key] === 0) {
                unset($this->running[$key]);
            }
        }
    }

    /**
     * Call a handler with the hook arguments and, when it asks for it, the context.
     *
     * @param  array<int|string, mixed>  $arguments
     */
    public function call(callable|string|array $callback, array $arguments, AsyncContext $context): void
    {
        if (! is_callable($callback)) {
            throw new \BadFunctionCallException(sprintf('The handler of %s is not callable.', $context->hook));
        }

        $callback(...HandlerArguments::for($callback, $arguments, $context));
    }

    /**
     * Whether a registration is being executed in this request.
     */
    public function isRunning(string $key): bool
    {
        return isset($this->running[$key]);
    }

    /**
     * Switch to the site and locale of the original request.
     *
     * @return \Closure(): void Switches back
     */
    private function enterContext(AsyncPayload $payload): \Closure
    {
        $blogId = $payload->origin['blogId'];
        $locale = $payload->origin['locale'];

        $switchedBlog = function_exists('is_multisite') && is_multisite() && $blogId !== get_current_blog_id()
            && switch_to_blog($blogId);

        $switchedLocale = function_exists('switch_to_locale') && determine_locale() !== $locale
            && switch_to_locale($locale);

        return static function () use ($switchedBlog, $switchedLocale): void {
            if ($switchedLocale) {
                restore_previous_locale();
            }

            if ($switchedBlog) {
                restore_current_blog();
            }
        };
    }

    private function fail(AsyncPayload $payload, \Throwable $throwable): void
    {
        Async::report($throwable, ['hook' => $payload->hook, 'handler' => $payload->handler, 'payload' => $payload->id]);

        if (function_exists('do_action')) {
            do_action('pollora/async/failed', $payload, $throwable);
        }
    }
}
