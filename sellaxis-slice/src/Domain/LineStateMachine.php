<?php

declare(strict_types=1);

namespace Forgeline\Domain;

/**
 * The state machine for a single order line.
 *
 * This is the answer to stub case 3 (out-of-order status events) and the
 * backbone of cases 7, 8, 9, 13 as well. The design constraint, quoted
 * directly from the stubs: "State machine must order by occurred_at/state,
 * not arrival."
 *
 * The mechanism, precisely:
 *   1. Every incoming event proposes a transition FROM the line's CURRENT
 *      recorded state TO a new state.
 *   2. A transition is valid only if it appears in TRANSITIONS below for the
 *      line's current state.
 *   3. If valid, AND the event's occurred_at is not older than the line's
 *      last_event_at (i.e. it's not a stale replay), it is applied and the
 *      line's last_event_at advances to the event's occurred_at.
 *   4. If INVALID from the current state, the event is not discarded and
 *      not force-applied — it is held. Every time a line's state changes,
 *      the caller re-checks any held events for that line, because a
 *      transition that was invalid a moment ago may be valid now.
 *
 * Worked example matching case 3 exactly:
 *   - line starts in pending_acceptance.
 *   - "shipped" event arrives first (occurred_at 23:55:00). shipped is not
 *     a valid transition FROM pending_acceptance (see TRANSITIONS) -> HELD.
 *   - "accepted" event arrives second (occurred_at 23:52:00). accepted IS a
 *     valid transition from pending_acceptance -> APPLIED. last_event_at
 *     becomes 23:52:00.
 *   - The held "shipped" event is re-checked: shipped IS a valid transition
 *     from accepted -> APPLIED. last_event_at becomes 23:55:00.
 *   - Final state: shipped. Both events applied, in logical order, despite
 *     arriving in the opposite physical order. This is the whole point.
 */
final class LineStateMachine
{
    /** @var array<string, array<string,string>> current state => [event_type => resulting state] */
    private const TRANSITIONS = [
        'pending_acceptance' => [
            'order.line.accepted' => 'accepted',
            'order.line.refused'  => 'refused',
            'order.cancelled'     => 'cancelled',
        ],
        'accepted' => [
            'order.line.shipped' => 'shipped',
            'order.cancelled'    => 'cancelled', // case 8: cancel after reservation, before ship
        ],
        'shipped' => [
            // case 9: cancellation after shipment is NOT a state transition
            // on the line at all -- it must be routed to refund, never
            // silently turn a shipped line back into "cancelled" as if it
            // never happened. See OrderProcessor::handleCancellation.
            'order.refunded' => 'refunded',
        ],
        'refused' => [
            // a refused line can still be refunded if funds were captured
            // at placement and need to be returned (case 7).
            'order.refunded' => 'refunded',
        ],
        // cancelled, refunded: terminal. No outgoing transitions.
    ];

    /**
     * @return array{valid: bool, resultingState: ?string}
     */
    public function check(string $currentState, string $eventType): array
    {
        $resulting = self::TRANSITIONS[$currentState][$eventType] ?? null;
        return ['valid' => $resulting !== null, 'resultingState' => $resulting];
    }

    public function isTerminal(string $state): bool
    {
        return in_array($state, ['cancelled', 'refunded'], true);
    }

    /**
     * Cancellation is intentionally NOT a generic transition look-up at the
     * call site, because its correct handling depends on whether the line
     * has already shipped (case 9) -- a question the transition table alone
     * can't answer cleanly without conflating "cancel" and "return" into
     * one concept. Callers should special-case order.cancelled against a
     * shipped line: do not call check() and apply blindly; route to refund.
     */
    public function cancellationRequiresReturnFlow(string $currentState): bool
    {
        return in_array($currentState, ['shipped', 'refunded'], true);
    }
}
