<?php

namespace App\Http\Controllers\Display;

use App\Http\Controllers\Controller;
use App\Models\QueueToken;
use App\Models\ServiceQueue;
use App\Services\TokenDisplayService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TokenDisplayController extends Controller
{
    /**
     * Show the TV-optimized token display.
     *
     * This view intentionally avoids Livewire, Flux, Tailwind CSS, and Vite so
     * it renders correctly on legacy Android TV browsers such as Chrome 73.
     */
    public function tv(Request $request): View
    {
        $display = app(TokenDisplayService::class);
        $selectedQueue = $this->resolveSelectedQueue($request);

        return view('pages.display.token-display-tv', [
            'queues' => $display->primaryQueues(),
            'selectedQueue' => $selectedQueue,
            'waitingTokens' => $selectedQueue !== null ? $display->waitingTokens($selectedQueue) : new Collection,
            'servingTokens' => $selectedQueue !== null ? $display->servingTokens($selectedQueue) : new Collection,
            'fileCheckWaitingTokens' => $display->fileCheckWaitingTokens(),
            'fileCheckServingTokens' => $display->fileCheckServingTokens(),
            'pinVerified' => $this->pinVerified(),
        ]);
    }

    /**
     * Select a queue and redirect to the TV display.
     */
    public function selectQueue(Request $request): RedirectResponse
    {
        $request->validate([
            'queue' => ['required', 'integer', 'exists:service_queues,id'],
        ]);

        $queue = ServiceQueue::findOrFail($request->integer('queue'));

        abort_if(app(TokenDisplayService::class)->isFileCheckQueue($queue), 404);

        return redirect()->route('display.tokens.tv', [
            'queue' => $queue->id,
        ]);
    }

    /**
     * Verify the TV display PIN and unlock the controls.
     */
    public function verifyPin(Request $request): RedirectResponse
    {
        $request->validate([
            'pin' => ['required', 'string', 'size:4'],
            'queue' => ['nullable', 'integer', 'exists:service_queues,id'],
        ]);

        if ($request->string('pin')->value() !== config('display.pin')) {
            throw ValidationException::withMessages([
                'pin' => __('Invalid PIN.'),
            ]);
        }

        $request->session()->put('display_pin_verified', true);

        return redirect()->route('display.tokens.tv', [
            'queue' => $request->input('queue'),
        ]);
    }

    /**
     * Move a waiting token to serving.
     */
    public function startServing(Request $request): RedirectResponse
    {
        $queue = $this->requireQueue($request);
        $token = $this->requireBoardToken($request);

        app(TokenDisplayService::class)->startServing($token);

        return redirect()->route('display.tokens.tv', [
            'queue' => $queue->id,
        ]);
    }

    /**
     * Mark a serving token as served.
     */
    public function markServed(Request $request): RedirectResponse
    {
        $queue = $this->requireQueue($request);
        $token = $this->requireBoardToken($request);

        app(TokenDisplayService::class)->markServed($token);

        return redirect()->route('display.tokens.tv', [
            'queue' => $queue->id,
        ]);
    }

    /**
     * Call the next waiting token.
     */
    public function callNext(Request $request): RedirectResponse
    {
        $queue = $this->requireQueue($request);

        app(TokenDisplayService::class)->callNext($queue);

        return redirect()->route('display.tokens.tv', [
            'queue' => $queue->id,
        ]);
    }

    /**
     * Call the previous token.
     */
    public function callPrevious(Request $request): RedirectResponse
    {
        $queue = $this->requireQueue($request);

        app(TokenDisplayService::class)->callPrevious($queue);

        return redirect()->route('display.tokens.tv', [
            'queue' => $queue->id,
        ]);
    }

    /**
     * Lock the TV controls by clearing the verified PIN session.
     */
    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget('display_pin_verified');

        return redirect()->route('display.tokens.tv', [
            'queue' => $request->input('queue'),
        ]);
    }

    /**
     * Resolve the queue selected by the request.
     */
    private function resolveSelectedQueue(Request $request): ?ServiceQueue
    {
        $queueId = $request->input('queue');

        if ($queueId === null) {
            return null;
        }

        $queue = ServiceQueue::with([
            'service',
            'doctor',
            'tokens.patient',
            'tokens.invoiceItem.invoice.patient',
        ])->find($queueId);

        if ($queue === null || app(TokenDisplayService::class)->isFileCheckQueue($queue)) {
            return null;
        }

        return $queue;
    }

    /**
     * Require a selected queue and a verified PIN for an action.
     */
    private function requireQueue(Request $request): ServiceQueue
    {
        abort_if(! $this->pinVerified(), 403);

        $queue = $this->resolveSelectedQueue($request);

        abort_if($queue === null, 404);

        return $queue;
    }

    /**
     * Require a token that belongs on the current board.
     */
    private function requireBoardToken(Request $request): QueueToken
    {
        $request->validate([
            'token' => ['required', 'integer', 'exists:queue_tokens,id'],
        ]);

        $token = QueueToken::findOrFail($request->integer('token'));
        $display = app(TokenDisplayService::class);
        $selectedQueue = $this->resolveSelectedQueue($request);

        $allowedQueueIds = collect([$selectedQueue?->id])
            ->merge($display->fileCheckQueues()->modelKeys())
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        abort_unless(in_array((int) $token->service_queue_id, $allowedQueueIds, true), 404);

        return $token;
    }

    /**
     * Determine whether the display PIN has been verified this session.
     */
    private function pinVerified(): bool
    {
        return (bool) request()->session()->get('display_pin_verified', false);
    }
}
