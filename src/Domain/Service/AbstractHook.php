<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Service;

use Pollora\Hook\Domain\Contract\CallbackResolverInterface;
use Pollora\Hook\Domain\Contract\HookInterface;
use Pollora\Hook\Domain\Exceptions\CallbackAnalysisFailedException;
use Pollora\Hook\Domain\Exceptions\CallbackResolutionFailedException;
use Pollora\Hook\Domain\Exceptions\HookMethodNotFoundException;

/**
 * Abstract base class for hook management (Domain layer, framework-agnostic).
 *
 * Provides common functionality for managing hooks (actions and filters)
 * with dependency resolution and argument detection, but does NOT depend on Laravel or WordPress.
 *
 * This class is a POPO and does not depend on any framework collections or helpers.
 *
 * @phpstan-import-type HookCallback from \Pollora\Hook\Domain\Contract\HookInterface
 * @phpstan-import-type HookRegistration from \Pollora\Hook\Domain\Contract\HookInterface
 */
abstract class AbstractHook implements HookInterface
{
    /**
     * Collection of registered hooks.
     *
     * Structure:
     * [
     *    'hook_name' => [
     *        ['callback' => callable, 'priority' => int, 'args' => int],
     *        ...
     *    ],
     *    ...
     * ]
     *
     * @var array<string, list<HookRegistration>>
     */
    protected array $hooks = [];

    /**
     * Static cache for storing reflection results to improve performance.
     *
     * Structure:
     * [
     *    'cacheKey' => numberOfParameters
     * ]
     *
     * @var array<string, int>
     */
    protected static array $reflectionCache = [];

    /**
     * Optional callback resolver for dependency injection support.
     *
     * When set, class-based callbacks are resolved through this interface
     * instead of direct instantiation, enabling constructor injection.
     */
    protected ?CallbackResolverInterface $callbackResolver = null;

    /**
     * Registrations made by the last add() call, one per hook.
     *
     * @var list<array{hook: string, callback: HookCallback, priority: int, args: int}>
     */
    protected array $lastRegistrations = [];

    /**
     * Number of parameters of each closure, held by the closure itself.
     *
     * Keying a closure by its object ID would be wrong: PHP reuses the ID of a closure once it is freed, so a new
     * closure with another signature would inherit the count of a dead one, and WordPress would pass it too few
     * arguments (an ArgumentCountError) or too many. A WeakMap forgets an entry with its closure.
     *
     * @var \WeakMap<\Closure, int>|null
     */
    private static ?\WeakMap $closureArguments = null;

    /**
     * Set the callback resolver for dependency injection support.
     *
     * When a resolver is set, class-based callbacks are instantiated
     * through it instead of direct `new $className`, enabling
     * constructor dependency injection.
     */
    public function setCallbackResolver(CallbackResolverInterface $resolver): void
    {
        $this->callbackResolver = $resolver;
    }

    /**
     * Add one or multiple hooks with a callback.
     *
     * @param  string|list<string>  $hooks  Hook name or array of hook names
     * @param  HookCallback  $callback  Function, closure, class name, or class@method
     * @param  int  $priority  Optional. Priority of the hook (default: 10)
     * @param  int|null  $acceptedArgs  Optional. Number of arguments the callback accepts (default: auto-detected)
     * @return static The same instance, to chain calls such as async()
     *
     * @throws \Exception
     */
    public function add(string|array $hooks, callable|string|array $callback, int $priority = 10, ?int $acceptedArgs = null): self
    {
        $this->lastRegistrations = [];

        foreach ((array) $hooks as $hook) {
            $resolvedCallback = $this->resolveCallback($hook, $callback, $acceptedArgs);
            $this->addHookEvent($hook, $resolvedCallback['callable'], $priority, $resolvedCallback['args']);
            $this->lastRegistrations[] = [
                'hook' => $hook,
                'callback' => $resolvedCallback['callable'],
                'priority' => $priority,
                'args' => $resolvedCallback['args'],
            ];
        }

        return $this;
    }

    /**
     * Remove a registered hook.
     *
     * @param  string  $hook  The hook name to remove
     * @param  HookCallback|null  $callback  Optional. Specific callback to remove
     * @param  int  $priority  Optional. Priority of the hook to remove. Default is 10.
     * @return self|false The Hook instance or false if the hook doesn't exist
     */
    public function remove(string $hook, callable|string|array|null $callback = null, int $priority = 10): self|false
    {
        // If $callback is null, retrieve the callback from our registered hooks
        if ($callback === null) {
            $hookData = $this->callbacks($hook);
            // If no hook exists with this name, return false
            if ($hookData === null || $hookData === []) {
                return false;
            }

            // Get the first hook data
            $firstHook = reset($hookData);
            // Extract callback details
            $callback = $firstHook['callback'];
            $priority = (int) $firstHook['priority'];
            // Notify subclasses before removing
            $this->removeHookEvent($hook, $callback, $priority);
            // Remove from our collection
            unset($this->hooks[$hook]);
        } else {
            // Always notify subclasses to perform platform-specific unregistration
            // (e.g., WordPress remove_action/remove_filter), even for hooks not
            // registered through this class (e.g., hooks added by WooCommerce core).
            // A request naming a handler targets the callback registered for it: the
            // instance of a [ClassName, 'method'] pair, the queuing callback of an asynchronous handler.
            $registeredCallback = $this->registeredCallbackFor($hook, $callback, $priority);
            $this->removeHookEvent($hook, $registeredCallback, $priority);

            // Also clean up our internal tracking if we have it
            if (isset($this->hooks[$hook])) {
                $hookCallbacks = $this->hooks[$hook];
                $filteredCallbacks = array_values(array_filter(
                    $hookCallbacks,
                    fn (array $item): bool => ! ($item['priority'] === $priority && $this->compareCallbacks($item['callback'], $registeredCallback))
                ));
                if ($filteredCallbacks === []) {
                    unset($this->hooks[$hook]);
                } else {
                    $this->hooks[$hook] = $filteredCallbacks;
                }
            }
        }

        return $this;
    }

    /**
     * Check if a hook exists.
     *
     * @param  string  $hook  The hook name to check
     * @param  HookCallback|null  $callback  Optional. Specific callback to check
     * @param  int|null  $priority  Optional. Specific priority to check
     * @return bool True if the hook exists, false otherwise
     */
    public function exists(string $hook, callable|string|array|null $callback = null, ?int $priority = null): bool
    {
        // If no specific callback is requested, just check if the hook name exists
        if ($callback === null) {
            return isset($this->hooks[$hook]) && ! empty($this->hooks[$hook]);
        }

        // If the hook doesn't exist at all, return false
        if (! isset($this->hooks[$hook])) {
            return false;
        }

        $callback = $this->registeredCallbackFor($hook, $callback, $priority);

        // Get all callbacks for this hook
        // Filter by callback and priority if specified
        foreach ($this->hooks[$hook] as $item) {
            if ($priority !== null && $item['priority'] !== $priority) {
                continue;
            }

            if ($item['callback'] === $callback) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear the reflection cache.
     *
     * This can be useful in testing environments or when memory usage is a concern.
     */
    public static function clearReflectionCache(): void
    {
        static::$reflectionCache = [];
        self::$closureArguments = null;
    }

    /**
     * Get the registered callbacks for a hook.
     *
     * @param  string  $hook  The hook name.
     * @return list<HookRegistration>|null Returns an array of callbacks or null if the hook doesn't exist
     */
    public function callbacks(string $hook): ?array
    {
        if (! isset($this->hooks[$hook])) {
            return null;
        }

        return $this->hooks[$hook];
    }

    /**
     * Every registration made through this instance, by hook name.
     *
     * WordPress's own `$wp_filter` holds the same callbacks, but no longer says
     * who registered them: a profiler reads this to mark the ones Pollora put
     * there, and the class and method they resolved to.
     *
     * @return array<string, list<HookRegistration>>
     */
    public function all(): array
    {
        return $this->hooks;
    }

    /**
     * Resolve the callback for the hook.
     *
     * @param  string  $hook  The hook name
     * @param  HookCallback  $callback  Function, closure, class name, or class@method
     * @param  int|null  $acceptedArgs  Optional. Number of arguments (if null, it will be auto-detected)
     * @return array{callable: HookCallback, args: int} Resolved callback and argument count
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    protected function resolveCallback(string $hook, callable|string|array $callback, ?int $acceptedArgs): array
    {
        // If the callback is a class name, instantiate it and resolve the method
        if (is_string($callback) && class_exists($callback)) {
            return $this->resolveClassMethodCallback($hook, $callback, $acceptedArgs);
        }

        // A [ClassName, 'method'] pair naming an instance method cannot be called
        // statically: instantiate the class, as for a class name
        if ($this->isInstanceMethodReference($callback)) {
            /** @var array{0: class-string, 1: string} $callback */
            return $this->resolveInstanceMethodCallback($callback[0], $callback[1], $acceptedArgs);
        }

        // If callback is already a callable (function or closure), detect argument count
        if (is_callable($callback)) {
            return [
                'callable' => $callback,
                'args' => $acceptedArgs ?? $this->detectArguments($callback),
            ];
        }

        // Store the callback as-is for deferred resolution.
        // WordPress does not validate callbacks at registration time — a function
        // or class may be defined later (e.g., by a plugin loaded after this call).
        return [
            'callable' => $callback,
            'args' => $acceptedArgs ?? 1,
        ];
    }

    /**
     * Instantiate a class and resolve its method dynamically.
     *
     * @param  string  $hook  The hook name
     * @param  class-string  $className  The class name
     * @param  int|null  $acceptedArgs  Optional. Number of arguments (auto-detected if null)
     * @return array{callable: array{object, string}, args: int} Resolved class method callback and argument count
     *
     * @throws CallbackResolutionFailedException When the class cannot be built or has no method named after the hook
     */
    protected function resolveClassMethodCallback(string $hook, string $className, ?int $acceptedArgs): array
    {
        try {
            $instance = $this->resolveInstance($className);

            // Prepare the method name (similar to Laravel's Str::studly but without dependency)
            // A PCRE failure returns null: keep the name as it is, which no method will match
            $hook = preg_replace('/[^a-zA-Z0-9_]+/', '_', $hook) ?? $hook;
            $hookMethod = lcfirst($this->studly($hook));

            // If the method exists, return the callable
            if (method_exists($instance, $hookMethod)) {
                return [
                    'callable' => [$instance, $hookMethod],
                    'args' => $acceptedArgs ?? $this->detectArguments([$instance, $hookMethod]),
                ];
            }

            throw HookMethodNotFoundException::forMethod($className, $hookMethod);
        } catch (\Exception $exception) {
            throw CallbackResolutionFailedException::forClass($className, $exception);
        }
    }

    /**
     * Instantiate a class and bind one of its instance methods.
     *
     * @param  class-string  $className  The class name
     * @param  string  $method  The instance method to call
     * @param  int|null  $acceptedArgs  Optional. Number of arguments (auto-detected if null)
     * @return array{callable: array{object, string}, args: int} Resolved class method callback and argument count
     *
     * @throws CallbackResolutionFailedException When the class cannot be built
     */
    protected function resolveInstanceMethodCallback(string $className, string $method, ?int $acceptedArgs): array
    {
        try {
            $instance = $this->resolveInstance($className);
        } catch (\Throwable $throwable) {
            throw CallbackResolutionFailedException::forClass($className, $throwable);
        }

        return [
            'callable' => [$instance, $method],
            'args' => $acceptedArgs ?? $this->detectArguments([$instance, $method]),
        ];
    }

    /**
     * Resolve a class instance through the callback resolver, or instantiate it directly.
     *
     * @param  class-string  $className  The class name
     */
    protected function resolveInstance(string $className): object
    {
        return $this->callbackResolver instanceof CallbackResolverInterface
            ? $this->callbackResolver->resolve($className)
            : new $className;
    }

    /**
     * Detect the number of arguments a callable accepts using reflection.
     * Results are cached for improved performance on repeated calls.
     *
     * @param  callable|array<array-key, mixed>  $callback  The callable function/method
     * @return int Number of accepted arguments
     *
     * @throws CallbackAnalysisFailedException When reflection cannot read the callable
     */
    protected function detectArguments(callable|array $callback): int
    {
        if ($callback instanceof \Closure) {
            self::$closureArguments ??= new \WeakMap;

            return self::$closureArguments[$callback] ??= $this->countParameters($callback);
        }

        // Generate a unique cache key for this callback
        $cacheKey = $this->getCacheKeyForCallback($callback);

        // Return cached result if available
        if (isset(static::$reflectionCache[$cacheKey])) {
            return static::$reflectionCache[$cacheKey];
        }

        // Cache the result for future use
        return static::$reflectionCache[$cacheKey] = $this->countParameters($callback);
    }

    /**
     * Add a single hook event.
     *
     * @param  string  $hook  The hook name
     * @param  HookCallback  $callback  The callback (may not be callable yet for deferred resolution)
     * @param  int  $priority  The priority of the hook
     * @param  int  $acceptedArgs  The number of arguments accepted by the callback
     */
    protected function addHookEvent(string $hook, callable|string|array $callback, int $priority, int $acceptedArgs): void
    {
        // Store hook details in an organized structure
        $hookData = [
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
            'args' => $acceptedArgs,
        ];

        // Make sure the hook array exists
        $this->hooks[$hook] ??= [];

        // Add this hook data to the array
        $this->hooks[$hook][] = $hookData;
    }

    /**
     * Replace a tracked callback by another, keeping its place in the hook's list.
     *
     * @param  string  $hook  The hook name
     * @param  int  $priority  The priority of the registration
     * @param  HookCallback  $current  The callback tracked today
     * @param  HookCallback  $replacement  The callback to track instead
     * @param  int  $acceptedArgs  The argument count of the replacement
     * @param  HookCallback|null  $handler  The handler the replacement stands for, so remove()
     *                                      and exists() still find it by its own callback; null for none
     */
    protected function replaceTrackedCallback(string $hook, int $priority, callable|string|array $current, callable|string|array $replacement, int $acceptedArgs, callable|string|array|null $handler): void
    {
        $registrations = $this->hooks[$hook] ?? [];

        foreach ($registrations as $index => $item) {
            if ($item['priority'] !== $priority || $item['callback'] !== $current) {
                continue;
            }

            $item['callback'] = $replacement;
            $item['args'] = $acceptedArgs;
            unset($item['handler']);

            if ($handler !== null) {
                $item['handler'] = $handler;
            }

            $registrations[$index] = $item;
            $this->hooks[$hook] = $registrations;

            return;
        }
    }

    /**
     * Remove a single hook event.
     *
     * Override in subclasses to perform platform-specific unregistration
     * (e.g., WordPress remove_action/remove_filter).
     *
     * @param  string  $hook  The hook name
     * @param  HookCallback  $callback  The callback to remove
     * @param  int  $priority  The priority of the hook
     */
    protected function removeHookEvent(string $hook, callable|string|array $callback, int $priority): void
    {
        // No-op in domain layer. Infrastructure subclasses override this.
    }

    /**
     * Read the number of parameters of a callable with reflection.
     *
     * @param  callable|array<array-key, mixed>  $callback  The callable function/method
     *
     * @throws CallbackAnalysisFailedException When reflection cannot read the callable
     */
    private function countParameters(callable|array $callback): int
    {
        try {
            if (is_array($callback)) {
                [$object, $method] = $callback;
                $reflection = new \ReflectionMethod($object, $method);
            } elseif (is_string($callback) && str_contains($callback, '::')) {
                // A static method written 'Class::method' is not a function name
                [$class, $staticMethod] = explode('::', $callback, 2);
                $reflection = new \ReflectionMethod($class, $staticMethod);
            } elseif (is_object($callback) && ! $callback instanceof \Closure) {
                // An invokable object: its __invoke() method is what WordPress calls
                $reflection = new \ReflectionMethod($callback, '__invoke');
            } else {
                // A closure or a function name
                $reflection = new \ReflectionFunction(\Closure::fromCallable($callback));
            }

            return $reflection->getNumberOfParameters();
        } catch (\ReflectionException $reflectionException) {
            throw CallbackAnalysisFailedException::fromReflection($reflectionException);
        }
    }

    /**
     * Improved callback comparison for the remove method
     * that properly handles class-based callbacks.
     *
     * @param  HookCallback  $registeredCallback  The registered callback.
     * @param  HookCallback  $requestedCallback  The callback requested for removal.
     * @return bool True if callbacks match, false otherwise.
     */
    private function compareCallbacks(callable|string|array $registeredCallback, callable|string|array $requestedCallback): bool
    {
        // If the callbacks are identical, it's simple
        if ($registeredCallback === $requestedCallback) {
            return true;
        }

        // If it's not an array, they are different
        if (! is_array($registeredCallback) || ! is_array($requestedCallback)) {
            return false;
        }

        // If the arrays don't have the same size
        if (count($registeredCallback) !== count($requestedCallback)) {
            return false;
        }

        // For class methods [object, 'method'] or [class, 'method']
        if (count($registeredCallback) === 2) {
            $regObject = $registeredCallback[0];
            $reqObject = $requestedCallback[0];
            $regMethod = $registeredCallback[1];
            $reqMethod = $requestedCallback[1];

            // Check if methods match
            if ($regMethod !== $reqMethod) {
                return false;
            }

            // Compare objects/classes
            if (is_object($regObject) && is_string($reqObject)) {
                // Case where the registered callback has an object but the request has a class
                return $regObject instanceof $reqObject || $regObject::class === $reqObject;
            }

            if (is_string($regObject) && is_string($reqObject)) {
                // Case where both are class names
                return $regObject === $reqObject;
            }

            if (is_object($regObject) && is_object($reqObject)) {
                // Case where both are objects
                return $regObject::class === $reqObject::class;
            }
        }

        return false;
    }

    /**
     * Whether a callback is a [ClassName, 'method'] pair naming an existing instance method.
     *
     * Static methods and classes not loaded yet are left out: the former are
     * callable as they are, the latter keep their deferred resolution.
     *
     * @param  HookCallback  $callback  The callback to test
     */
    private function isInstanceMethodReference(callable|string|array $callback): bool
    {
        if (! is_array($callback) || count($callback) !== 2 || ! array_is_list($callback)) {
            return false;
        }

        [$className, $method] = $callback;

        if (! is_string($className) || ! is_string($method) || ! class_exists($className) || ! method_exists($className, $method)) {
            return false;
        }

        return ! (new \ReflectionMethod($className, $method))->isStatic();
    }

    /**
     * Find the callback registered through this class that a removal or lookup request refers to.
     *
     * A callback registered in place of a handler (asynchronous handlers) is found
     * by that handler. A [ClassName, 'method'] request for an instance method is
     * matched to the [$instance, 'method'] it was registered as. Any other request
     * is returned as is.
     *
     * @param  string  $hook  The hook name
     * @param  HookCallback  $callback  The requested callback
     * @param  int|null  $priority  Optional. Priority the registered callback must have
     * @return HookCallback
     */
    private function registeredCallbackFor(string $hook, callable|string|array $callback, ?int $priority = null): callable|string|array
    {
        $isInstanceMethodReference = $this->isInstanceMethodReference($callback);

        foreach ($this->hooks[$hook] ?? [] as $item) {
            if ($priority !== null && $item['priority'] !== $priority) {
                continue;
            }

            if (array_key_exists('handler', $item) && ($item['handler'] === $callback || $this->compareCallbacks($item['handler'], $callback))) {
                return $item['callback'];
            }

            if ($isInstanceMethodReference && $this->compareCallbacks($item['callback'], $callback)) {
                return $item['callback'];
            }
        }

        return $callback;
    }

    /**
     * Convert a string to StudlyCase.
     * A simple implementation to replace Laravel's Str::studly.
     */
    private function studly(string $value): string
    {
        $words = explode('_', $value);
        $studlyWords = array_map(ucfirst(...), $words);

        return implode('', $studlyWords);
    }

    /**
     * Generate a unique cache key for a callback.
     *
     * @param  callable|array<array-key, mixed>  $callback  The callback to generate a key for
     * @return string A unique identifier for the callback
     */
    private function getCacheKeyForCallback(callable|array $callback): string
    {
        if (is_array($callback)) {
            $object = $callback[0];
            $method = $callback[1];

            if (is_object($object)) {
                return $object::class.'::'.$method.'@'.spl_object_id($object);
            }

            return $object.'::'.$method;
        }

        if (is_string($callback)) {
            return $callback;
        }

        // Closures never reach this method: detectArguments() keys them by the closure itself.
        if (is_object($callback)) {
            return $callback::class.'::__invoke@'.spl_object_id($callback);
        }

        // Fallback for any other type of callable
        return serialize($callback);
    }
}
