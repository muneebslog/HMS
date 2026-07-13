<?php

namespace App\Http\Controllers\Display;

use App\Http\Controllers\Controller;
use App\Models\QueueToken;
use App\Models\ServiceQueue;
use App\Services\TokenDisplayService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $selectedQueue = $this->resolveSelectedQueue($request);

        return view('pages.display.token-display-tv', [
            'queues' => $this->queues(),
            'selectedQueue' => $selectedQueue,
            'currentToken' => $selectedQueue !== null ? app(TokenDisplayService::class)->currentToken($selectedQueue) : null,
            'reservedTokens' => $selectedQueue !== null ? $this->reservedTokens($selectedQueue) : new Collection,
            'arrivedTokens' => $selectedQueue !== null ? $this->arrivedTokens($selectedQueue) : new Collection,
            'walkInTokens' => $selectedQueue !== null ? $this->walkInTokens($selectedQueue) : new Collection,
            'sidebarOpen' => $request->boolean('sidebar', auth()->check()),
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

        return redirect()->route('display.tokens.tv', [
            'queue' => $request->integer('queue'),
            'sidebar' => $request->boolean('sidebar', auth()->check() ? 1 : 0),
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
            'sidebar' => $request->boolean('sidebar', auth()->check() ? 1 : 0),
        ]);
    }

    /**
     * Skip the currently serving token and call the next one.
     */
    public function skipCurrent(Request $request): RedirectResponse
    {
        $queue = $this->requireQueue($request);

        app(TokenDisplayService::class)->skipCurrent($queue);

        return redirect()->route('display.tokens.tv', [
            'queue' => $queue->id,
            'sidebar' => $request->boolean('sidebar', auth()->check() ? 1 : 0),
        ]);
    }

    /**
     * Recall the currently serving token.
     */
    public function recallCurrent(Request $request): RedirectResponse
    {
        $queue = $this->requireQueue($request);

        return redirect()->route('display.tokens.tv', [
            'queue' => $queue->id,
            'sidebar' => $request->boolean('sidebar', auth()->check() ? 1 : 0),
        ]);
    }

    /**
     * Toggle the upcoming tokens sidebar visibility.
     */
    public function toggleSidebar(Request $request): RedirectResponse
    {
        $queue = $this->requireQueue($request);

        return redirect()->route('display.tokens.tv', [
            'queue' => $queue->id,
            'sidebar' => ! $request->boolean('sidebar'),
        ]);
    }

    /**
     * Get all open service queues for today.
     *
     * @return Collection<int, ServiceQueue>
     */
    private function queues(): Collection
    {
        return ServiceQueue::with(['service', 'doctor'])
            ->where('status', 'open')
            ->whereDate('date', Carbon::today())
            ->orderBy('opened_at')
            ->get();
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

        return ServiceQueue::with([
            'service',
            'doctor',
            'tokens.patient',
            'tokens.invoiceItem.invoice.patient',
        ])->find($queueId);
    }

    /**
     * Get the reserved tokens for the selected queue.
     *
     * @return Collection<int, QueueToken>
     */
    private function reservedTokens(ServiceQueue $queue): Collection
    {
        return QueueToken::with(['patient', 'invoiceItem.invoice.patient'])
            ->where('service_queue_id', $queue->id)
            ->where('status', 'reserved')
            ->orderBy('token_number')
            ->limit(8)
            ->get();
    }

    /**
     * Get the reserved patients who have arrived for the selected queue.
     *
     * @return Collection<int, QueueToken>
     */
    private function arrivedTokens(ServiceQueue $queue): Collection
    {
        return QueueToken::with(['patient', 'invoiceItem.invoice.patient'])
            ->where('service_queue_id', $queue->id)
            ->where('status', 'waiting')
            ->where('origin', 'reservation')
            ->orderBy('token_number')
            ->limit(8)
            ->get();
    }

    /**
     * Get the walk-in patients waiting for the selected queue.
     *
     * @return Collection<int, QueueToken>
     */
    private function walkInTokens(ServiceQueue $queue): Collection
    {
        return QueueToken::with(['patient', 'invoiceItem.invoice.patient'])
            ->where('service_queue_id', $queue->id)
            ->where('status', 'waiting')
            ->where('origin', 'walk_in')
            ->orderBy('token_number')
            ->limit(8)
            ->get();
    }

    /**
     * Require a selected queue for an action.
     */
    private function requireQueue(Request $request): ServiceQueue
    {
        abort_if(! auth()->check(), 403);

        $queue = $this->resolveSelectedQueue($request);

        abort_if($queue === null, 404);

        return $queue;
    }
}
