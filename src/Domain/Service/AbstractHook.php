<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Service;

use Pollora\Hook\Domain\Contract\CallbackResolverInterface;
use Pollora\Hook\Domain\Contract\HookInterface;

/**
 * Abstract base class for hook management (Domain layer, framework-agnostic).
 *
 * Provides common functionality for managing hooks (actions and filters)
 * with dependency resolution and argument detection, but does NOT depend on Laravel or WordPress.
 *
 * This class is a POPO and does not depend on any framework collections or helpers.
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
     */
    protected array $hooks = [];

    /**
     * Static cache for storing reflection results to improve performance.
     *
     * Structure:
     * [
     *    'cacheKey' => numberOfParameters
     * ]
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
     * @param  string|array  $hooks  Hook name or array of hook names
     * @param  callable|string|array  $callback  Function, closure, class name, or class@method
     * @param  int  $priority  Optional. Priority of the hook (default: 10)
     * @param  int|null  $acceptedArgs  Optional. Number of arguments the callback accepts (default: auto-detected)
     *
     * @throws \Exception
     */
    public function add(string|array $hooks, callable|string|array $callback, int $priority = 10, ?int $acceptedArgs = null): self
    {
        foreach ((array) $hooks as $hook) {
            $resolvedCallback = $this->resolveCallback($hook, $callback, $acceptedArgs);
            $this->addHookEvent($hook, $resolvedCallback['callable'], $priority, $resolvedCallback['args']);
        }

        return $this;
    }

    /**
     * Remove a registered hook.
     *
     * @param  string  $hook  The hook name to remove
     * @param  callable|string|array|null  $callback  Optional. Specific callback to remove
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
            $this->removeHookEvent($hook, $callback, $priority);

            // Also clean up our internal tracking if we have it
            if (isset($this->hooks[$hook])) {
                $hookCallbacks = $this->hooks[$hook];
                $filteredCallbacks = array_values(array_filter(
                    $hookCallbacks,
                    fn (array $item): bool => ! ($item['priority'] === $priority && $this->compareCallbacks($item['callback'], $callback))
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
     * @param  callable|null  $callback  Optional. Specific callback to check
     * @param  int|null  $priority  Optional. Specific priority to check
     * @return bool True if the hook exists, false otherwise
     */
    public function exists(string $hook, ?callable $callback = null, ?int $priority = null): bool
    {
        // If no specific callback is requested, just check if the hook name exists
        if ($callback === null) {
            return isset($this->hooks[$hook]) && ! empty($this->hooks[$hook]);
        }

        // If the hook doesn't exist at all, return false
        if (! isset($this->hooks[$hook])) {
            return false;
        }

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
    }

    /**
     * Get the registered callbacks for a hook.
     *
     * @param  string  $hook  The hook name.
     * @return array|null Returns an array of callbacks or null if the hook doesn't exist
     */
    public function callbacks(string $hook): ?array
    {
        if (! isset($this->hooks[$hook])) {
            return null;
        }

        return $this->hooks[$hook];
    }

    /**
     * Resolve the callback for the hook.
     *
     * @param  string  $hook  The hook name
     * @param  callable|string|array  $callback  Function, closure, class name, or class@method
     * @param  int|null  $acceptedArgs  Optional. Number of arguments (if null, it will be auto-detected)
     * @return array Resolved callback and argument count
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    protected function resolveCallback(string $hook, callable|string|array $callback, ?int $acceptedArgs): array
    {
        // If the callback is a class name, instantiate it and resolve the method
        if (is_string($callback) && class_exists($callback)) {
            return $this->resolveClassMethodCallback($hook, $callback, $acceptedArgs);
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
     * @param  string  $className  The class name
     * @param  int|null  $acceptedArgs  Optional. Number of arguments (auto-detected if null)
     * @return array Resolved class method callback and argument count
     *
     * @throws \RuntimeException
     */
    protected function resolveClassMethodCallback(string $hook, string $className, ?int $acceptedArgs): array
    {
        try {
            // Resolve through DI container if available, fallback to direct instantiation
            $instance = $this->callbackResolver instanceof CallbackResolverInterface
                ? $this->callbackResolver->resolve($className)
                : new $className;

            // Prepare the method name (similar to Laravel's Str::studly but without dependency)
            $hook = preg_replace('/[^a-zA-Z0-9_]+/', '_', $hook);
            $hookMethod = lcfirst($this->studly($hook));

            // If the method exists, return the callable
            if (method_exists($instance, $hookMethod)) {
                return [
                    'callable' => [$instance, $hookMethod],
                    'args' => $acceptedArgs ?? $this->detectArguments([$instance, $hookMethod]),
                ];
            }

            throw new \RuntimeException(sprintf("Method '%s' not found in class '%s'.", $hookMethod, $className));
        } catch (\Exception $exception) {
            throw new \RuntimeException(sprintf("Failed to resolve '%s': ", $className).$exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Detect the number of arguments a callable accepts using reflection.
     * Results are cached for improved performance on repeated calls.
     *
     * @param  callable|array  $callback  The callable function/method
     * @return int Number of accepted arguments
     *
     * @throws \RuntimeException
     */
    protected function detectArguments(callable|array $callback): int
    {
        // Generate a unique cache key for this callback
        $cacheKey = $this->getCacheKeyForCallback($callback);

        // Return cached result if available
        if (isset(static::$reflectionCache[$cacheKey])) {
            return static::$reflectionCache[$cacheKey];
        }

        try {
            if (is_array($callback)) {
                [$object, $method] = $callback;
                $reflection = new \ReflectionMethod($object, $method);
            } else {
                $reflection = new \ReflectionFunction($callback);
            }

            $paramCount = $reflection->getNumberOfParameters();

            // Cache the result for future use
            static::$reflectionCache[$cacheKey] = $paramCount;

            return $paramCount;
        } catch (\ReflectionException $reflectionException) {
            throw new \RuntimeException('Failed to analyze callable: '.$reflectionException->getMessage(), $reflectionException->getCode(), $reflectionException);
        }
    }

    /**
     * Add a single hook event.
     *
     * @param  string  $hook  The hook name
     * @param  callable|string|array  $callback  The callback (may not be callable yet for deferred resolution)
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
        if (! isset($this->hooks[$hook])) {
            $this->hooks[$hook] = [];
        }

        // Add this hook data to the array
        $this->hooks[$hook][] = $hookData;
    }

    /**
     * Remove a single hook event.
     *
     * Override in subclasses to perform platform-specific unregistration
     * (e.g., WordPress remove_action/remove_filter).
     *
     * @param  string  $hook  The hook name
     * @param  callable|string|array  $callback  The callback to remove
     * @param  int  $priority  The priority of the hook
     */
    protected function removeHookEvent(string $hook, callable|string|array $callback, int $priority): void
    {
        // No-op in domain layer. Infrastructure subclasses override this.
    }

    /**
     * Improved callback comparison for the remove method
     * that properly handles class-based callbacks.
     *
     * @param  callable|string|array  $registeredCallback  The registered callback.
     * @param  callable|string|array  $requestedCallback  The callback requested for removal.
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
     * @param  callable|array  $callback  The callback to generate a key for
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

        if ($callback instanceof \Closure) {
            // Closures are uniquely identified by their object ID
            return 'closure@'.spl_object_id($callback);
        }

        // Fallback for any other type of callable
        return serialize($callback);
    }
}
