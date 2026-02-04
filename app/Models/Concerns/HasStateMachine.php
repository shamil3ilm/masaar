<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use InvalidArgumentException;

/**
 * Trait for implementing state machine pattern.
 * Models using this trait should define:
 * - getStateColumn(): string
 * - getStateTransitions(): array
 */
trait HasStateMachine
{
    /**
     * Get the current state.
     */
    public function getState(): string
    {
        return $this->{$this->getStateColumn()};
    }

    /**
     * Check if transition to new state is allowed.
     */
    public function canTransitionTo(string $newState): bool
    {
        $currentState = $this->getState();
        $transitions = $this->getStateTransitions();

        if (!isset($transitions[$currentState])) {
            return false;
        }

        return in_array($newState, $transitions[$currentState], true);
    }

    /**
     * Get allowed transitions from current state.
     */
    public function getAllowedTransitions(): array
    {
        $currentState = $this->getState();
        $transitions = $this->getStateTransitions();

        return $transitions[$currentState] ?? [];
    }

    /**
     * Transition to a new state.
     *
     * @throws InvalidArgumentException
     */
    public function transitionTo(string $newState, array $additionalData = []): bool
    {
        if (!$this->canTransitionTo($newState)) {
            $currentState = $this->getState();
            $allowed = implode(', ', $this->getAllowedTransitions());

            throw new InvalidArgumentException(
                "Invalid state transition from '{$currentState}' to '{$newState}'. " .
                "Allowed transitions: [{$allowed}]"
            );
        }

        $oldState = $this->getState();

        // Call before hook if exists
        $beforeMethod = 'onBefore' . str_replace(' ', '', ucwords(str_replace('_', ' ', $newState)));
        if (method_exists($this, $beforeMethod)) {
            $this->$beforeMethod($oldState);
        }

        // Update state
        $data = array_merge($additionalData, [
            $this->getStateColumn() => $newState,
        ]);

        $this->update($data);

        // Call after hook if exists
        $afterMethod = 'onAfter' . str_replace(' ', '', ucwords(str_replace('_', ' ', $newState)));
        if (method_exists($this, $afterMethod)) {
            $this->$afterMethod($oldState);
        }

        return true;
    }

    /**
     * Force set state (bypass transition rules - use with caution).
     */
    public function forceState(string $state): void
    {
        $this->update([
            $this->getStateColumn() => $state,
        ]);
    }

    /**
     * Check if the model is in a specific state.
     */
    public function isInState(string $state): bool
    {
        return $this->getState() === $state;
    }

    /**
     * Check if the model is in any of the given states.
     */
    public function isInAnyState(array $states): bool
    {
        return in_array($this->getState(), $states, true);
    }

    /**
     * Check if model is in a terminal (final) state.
     */
    public function isInTerminalState(): bool
    {
        return empty($this->getAllowedTransitions());
    }

    /**
     * Scope to filter by state.
     */
    public function scopeInState($query, string|array $states)
    {
        if (is_array($states)) {
            return $query->whereIn($this->getStateColumn(), $states);
        }

        return $query->where($this->getStateColumn(), $states);
    }

    /**
     * Override in model: Get the state column name.
     */
    abstract public function getStateColumn(): string;

    /**
     * Override in model: Define allowed state transitions.
     * Format: ['current_state' => ['allowed_state_1', 'allowed_state_2']]
     */
    abstract public function getStateTransitions(): array;
}
